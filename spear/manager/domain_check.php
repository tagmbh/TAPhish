<?php
/**
 * Phase 3.54: look-alike domain validation + IDNA encoding.
 *
 * The homoglyph generator (homoglyph.php) produces look-alike candidates
 * including IDN / umlaut variants. Those candidates are only usable for
 * registration once they're punycode (IDNA) encoded, and some generated
 * strings aren't valid registrable names at all. This module validates a
 * candidate + returns its IDNA form so the SenderToolkit can drop the
 * unusable ones and show the operator the registrable (xn--…) form.
 *
 * Backing service: Hostpoint's public domain-check endpoint
 *   GET https://admin.hostpoint.ch/api/pub/domain/domain-check?domain=<d>
 *   -> {"name": "...", "nameIdna": "xn--...", "valid": bool, "idnaEncoded": bool}
 *
 * NOTE: this endpoint reports NAME VALIDITY + IDNA encoding only — it does
 * NOT report registration availability (a registered domain like google.ch
 * still returns valid:true). The UI labels this honestly: "valid registrable
 * name", not "available". Registration availability must be confirmed at the
 * registrar.
 *
 * The parser is pure + tested; the HTTP call uses an injectable seam.
 */

if (!function_exists('domain_check_parse')) {
    /**
     * Reduce the Hostpoint domain-check JSON to a normalized shape.
     *
     * @param mixed $raw decoded array or raw JSON string
     * @return array{ok:bool, valid:bool, name:string, name_idna:string, idna_encoded:bool, err?:string}
     */
    function domain_check_parse($raw): array
    {
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            if (!is_array($j)) {
                return ['ok' => false, 'valid' => false, 'name' => '', 'name_idna' => '', 'idna_encoded' => false, 'err' => 'non-JSON response'];
            }
            $raw = $j;
        }
        if (!is_array($raw) || !array_key_exists('valid', $raw)) {
            return ['ok' => false, 'valid' => false, 'name' => '', 'name_idna' => '', 'idna_encoded' => false, 'err' => 'unexpected shape'];
        }
        return [
            'ok'           => true,
            'valid'        => (bool) $raw['valid'],
            'name'         => (string) ($raw['name'] ?? ''),
            'name_idna'    => (string) ($raw['nameIdna'] ?? ($raw['name'] ?? '')),
            'idna_encoded' => (bool) ($raw['idnaEncoded'] ?? false),
        ];
    }
}

if (!function_exists('domain_check_local_idna')) {
    /**
     * Local IDNA fallback via ext-intl, so we can still encode + sanity
     * check umlaut domains when the Hostpoint endpoint is unreachable.
     * Returns the ascii (punycode) form or '' on failure.
     */
    function domain_check_local_idna(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') return '';
        if (!function_exists('idn_to_ascii')) {
            // No intl extension — only ASCII domains pass through.
            return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : '';
        }
        $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return is_string($ascii) ? $ascii : '';
    }
}

if (!function_exists('domain_check_one')) {
    /**
     * Validate + IDNA-encode a single candidate through the Hostpoint
     * endpoint, falling back to local IDNA if the call fails. The $http
     * seam keeps the unit suite offline.
     *
     * @param callable|null $http function(string $url):array{status:int,body:string}
     * @return array{domain:string, valid:bool, name_idna:string, idna_encoded:bool, source:string}
     */
    function domain_check_one(string $domain, ?callable $http = null): array
    {
        $domain = strtolower(trim($domain));
        $url = 'https://admin.hostpoint.ch/api/pub/domain/domain-check?domain=' . rawurlencode($domain);
        $fn  = $http ?? 'domain_check_default_http';
        $resp = $fn($url);
        $status = (int) ($resp['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            $parsed = domain_check_parse((string) ($resp['body'] ?? ''));
            if ($parsed['ok']) {
                return [
                    'domain'       => $domain,
                    'valid'        => $parsed['valid'],
                    'name_idna'    => $parsed['name_idna'] !== '' ? $parsed['name_idna'] : $domain,
                    'idna_encoded' => $parsed['idna_encoded'],
                    'source'       => 'hostpoint',
                ];
            }
        }
        // Fallback: local IDNA encode + a light validity heuristic.
        $idna = domain_check_local_idna($domain);
        $valid = $idna !== ''
            && preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $idna) === 1;
        return [
            'domain'       => $domain,
            'valid'        => $valid,
            'name_idna'    => $idna !== '' ? $idna : $domain,
            'idna_encoded' => $idna !== '' && $idna !== $domain,
            'source'       => 'local',
        ];
    }
}

if (!function_exists('domain_check_default_http')) {
    function domain_check_default_http(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) return ['status' => 0, 'body' => ''];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => (defined('BRAND_PRODUCT_NAME') ? BRAND_PRODUCT_NAME : 'TAPhish') . '/domain-check',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}

if (!function_exists('domain_check_filter_valid')) {
    /**
     * Pure filter: given homoglyph candidates [{domain, kind, score}, …]
     * and a map of domain => check-result, return only the candidates
     * whose name validated, enriched with name_idna + idna_encoded.
     * Preserves input order (already score-sorted).
     *
     * @param array<int,array{domain:string,kind:string,score:int}> $candidates
     * @param array<string,array{valid:bool,name_idna:string,idna_encoded:bool}> $checks
     * @return array<int,array<string,mixed>>
     */
    function domain_check_filter_valid(array $candidates, array $checks): array
    {
        $out = [];
        foreach ($candidates as $c) {
            $d = (string) ($c['domain'] ?? '');
            if (!isset($checks[$d]) || empty($checks[$d]['valid'])) {
                continue;
            }
            $out[] = $c + [
                'name_idna'    => (string) ($checks[$d]['name_idna'] ?? $d),
                'idna_encoded' => (bool) ($checks[$d]['idna_encoded'] ?? false),
            ];
        }
        return $out;
    }
}

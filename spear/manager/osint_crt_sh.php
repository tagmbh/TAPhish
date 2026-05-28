<?php
/**
 * crt.sh subdomain enumeration.
 *
 * Queries the crt.sh Certificate Transparency log search for any
 * certificates issued under *.<target-domain> and returns the deduplicated
 * set of subdomains observed there. Free, no API key required — useful for
 * scoping authorized red-team engagements (the operator's own
 * infrastructure, or a client domain they're authorized to test).
 *
 * crt.sh has no documented rate limit but is operated as a public service;
 * be conservative.
 *
 * The HTTP call is in osint_crt_sh_subdomains(); response shaping is in
 * osint_crt_sh_parse_response() and tested in isolation by
 * tests/OsintCrtShTest.php. No DB, no session.
 */

if (!function_exists('osint_crt_sh_normalize_name')) {
    /**
     * Lowercase, trim, strip leading wildcard ("*."), drop trailing dot.
     */
    function osint_crt_sh_normalize_name(string $name): string
    {
        $n = strtolower(trim($name));
        if (str_starts_with($n, '*.')) {
            $n = substr($n, 2);
        }
        return rtrim($n, '.');
    }
}

if (!function_exists('osint_crt_sh_is_subdomain_of')) {
    /**
     * True if $candidate is the target domain itself or a subdomain of it,
     * after both have been normalized. Defends against an attacker-supplied
     * crt.sh response trying to inject unrelated names.
     */
    function osint_crt_sh_is_subdomain_of(string $candidate, string $target): bool
    {
        $c = osint_crt_sh_normalize_name($candidate);
        $t = osint_crt_sh_normalize_name($target);
        if ($c === '' || $t === '') {
            return false;
        }
        if ($c === $t) {
            return true;
        }
        return str_ends_with($c, '.' . $t);
    }
}

if (!function_exists('osint_crt_sh_parse_response')) {
    /**
     * Reduce the crt.sh JSON array into a deduplicated, sorted list of
     * subdomain names that actually belong to the target domain.
     *
     * Returns:
     *   ok=false  with err on shape errors
     *   ok=true   with subdomains[] sorted alphabetically
     *
     * @param mixed $raw   Decoded JSON or raw string body.
     * @return array{
     *   ok: bool,
     *   subdomains?: string[],
     *   count?: int,
     *   err?: string,
     * }
     */
    function osint_crt_sh_parse_response($raw, string $target): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'err' => 'crt.sh returned non-JSON response'];
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return ['ok' => false, 'err' => 'crt.sh response was not an array'];
        }
        $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // crt.sh sometimes packs multiple SANs into name_value, newline-separated.
            $blobs = [];
            if (!empty($entry['name_value'])) {
                $blobs = array_merge($blobs, preg_split('/\r?\n/', (string) $entry['name_value']) ?: []);
            }
            if (!empty($entry['common_name'])) {
                $blobs[] = (string) $entry['common_name'];
            }
            foreach ($blobs as $blob) {
                $n = osint_crt_sh_normalize_name($blob);
                if ($n === '' || !osint_crt_sh_is_subdomain_of($n, $target)) {
                    continue;
                }
                $seen[$n] = true;
            }
        }
        $subdomains = array_keys($seen);
        sort($subdomains, SORT_STRING);
        return [
            'ok'         => true,
            'subdomains' => $subdomains,
            'count'      => count($subdomains),
        ];
    }
}

if (!function_exists('osint_crt_sh_subdomains')) {
    /**
     * Live call to crt.sh. Same return shape as the parser, plus a generic
     * error path for transport/TLS issues.
     */
    function osint_crt_sh_subdomains(string $domain): array
    {
        if (!function_exists('osint_hunter_is_valid_domain') || !osint_hunter_is_valid_domain($domain)) {
            return ['ok' => false, 'err' => 'Invalid domain format'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'err' => 'ext-curl not available on this PHP runtime'];
        }
        $target = osint_crt_sh_normalize_name($domain);
        $url = 'https://crt.sh/?' . http_build_query([
            'q'      => '%.' . $target,
            'output' => 'json',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => (defined('BRAND_PRODUCT_NAME') ? BRAND_PRODUCT_NAME : 'TAPhish') . '/osint',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errstr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            return ['ok' => false, 'err' => 'cURL error ' . $errno . ': ' . $errstr];
        }
        if ($status >= 400) {
            return ['ok' => false, 'err' => 'crt.sh HTTP ' . $status];
        }
        return osint_crt_sh_parse_response($body, $target);
    }
}

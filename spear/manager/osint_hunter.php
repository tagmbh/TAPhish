<?php
/**
 * Hunter.io OSINT integration.
 *
 * Provides domain-search functionality to discover company emails for
 * authorized red-team engagements. The TAPhish admin panel proxies the
 * call so the operator's API key never leaves their browser → their
 * server (it isn't persisted server-side; the JS layer sends it from
 * localStorage on each request).
 *
 * The HTTP call lives in osint_hunter_domain_search(); response shaping
 * is in osint_hunter_parse_domain_search() and is exercised in isolation
 * by tests/OsintHunterTest.php (no network calls in tests).
 *
 * Endpoint reference: https://hunter.io/api-documentation/v2#domain-search
 */

if (!function_exists('osint_hunter_is_valid_domain')) {
    /**
     * Light syntactic check on the operator-supplied domain. The
     * authoritative validation is the Hunter.io API itself, but this
     * blocks the obvious junk before we burn a quota call.
     */
    function osint_hunter_is_valid_domain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('osint_hunter_is_valid_api_key')) {
    /**
     * Hunter.io v2 keys are 40-char hex strings. Reject anything else
     * before issuing the request.
     */
    function osint_hunter_is_valid_api_key(string $key): bool
    {
        return preg_match('/^[a-f0-9]{40}$/', strtolower(trim($key))) === 1;
    }
}

if (!function_exists('osint_hunter_parse_domain_search')) {
    /**
     * Reduce the verbose Hunter.io payload to the shape we render in the
     * modal table and import as recipients.
     *
     * Returns:
     *   ok=false  with err set on transport / API errors
     *   ok=true   with domain, organization, results[] where each entry
     *             is {email, name, first_name, last_name, position,
     *             confidence, type}
     *
     * @param mixed $raw  Decoded JSON or raw string body.
     * @return array{
     *   ok: bool,
     *   domain?: ?string,
     *   organization?: ?string,
     *   results?: array<int, array{
     *     email: string,
     *     name: string,
     *     first_name: string,
     *     last_name: string,
     *     position: string,
     *     confidence: int,
     *     type: string,
     *   }>,
     *   err?: string,
     * }
     */
    function osint_hunter_parse_domain_search($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'err' => 'Hunter.io returned non-JSON response'];
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return ['ok' => false, 'err' => 'Hunter.io response was not an object'];
        }
        if (isset($raw['errors']) && is_array($raw['errors']) && $raw['errors'] !== []) {
            $first = $raw['errors'][0] ?? [];
            $msg = is_array($first) ? ($first['details'] ?? $first['code'] ?? 'unknown error') : 'unknown error';
            return ['ok' => false, 'err' => 'Hunter.io: ' . (string) $msg];
        }
        $data = $raw['data'] ?? null;
        if (!is_array($data)) {
            return ['ok' => false, 'err' => 'Hunter.io response missing data field'];
        }
        $emails = $data['emails'] ?? [];
        if (!is_array($emails)) {
            $emails = [];
        }
        $results = [];
        foreach ($emails as $e) {
            if (!is_array($e) || empty($e['value'])) {
                continue;
            }
            $first = (string) ($e['first_name'] ?? '');
            $last  = (string) ($e['last_name'] ?? '');
            $name  = trim($first . ' ' . $last);
            $results[] = [
                'email'      => (string) $e['value'],
                'name'       => $name,
                'first_name' => $first,
                'last_name'  => $last,
                'position'   => (string) ($e['position'] ?? ''),
                'confidence' => is_numeric($e['confidence'] ?? null) ? (int) $e['confidence'] : 0,
                'type'       => (string) ($e['type'] ?? ''),
            ];
        }
        return [
            'ok'           => true,
            'domain'       => isset($data['domain']) ? (string) $data['domain'] : null,
            'organization' => isset($data['organization']) ? (string) $data['organization'] : null,
            'results'      => $results,
        ];
    }
}

if (!function_exists('osint_hunter_domain_search')) {
    /**
     * Live call to Hunter.io. Returns the same shape as the parser, with
     * ok=false on transport / HTTP / shape errors.
     *
     * @return array{ok: bool, domain?: ?string, organization?: ?string,
     *                results?: array<int, array<string,mixed>>, err?: string}
     */
    function osint_hunter_domain_search(string $domain, string $apiKey, int $limit = 25): array
    {
        if (!osint_hunter_is_valid_domain($domain)) {
            return ['ok' => false, 'err' => 'Invalid domain format'];
        }
        if (!osint_hunter_is_valid_api_key($apiKey)) {
            return ['ok' => false, 'err' => 'Invalid Hunter.io API key format'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'err' => 'ext-curl not available on this PHP runtime'];
        }
        $limit = max(1, min(100, $limit));

        $url = 'https://api.hunter.io/v2/domain-search?'
            . http_build_query([
                'domain'  => $domain,
                'limit'   => $limit,
                'api_key' => $apiKey,
            ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
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
        // Even on non-2xx, Hunter.io returns a JSON `errors` array we can surface.
        $parsed = osint_hunter_parse_domain_search($body);
        if (!$parsed['ok'] && $status >= 400) {
            $parsed['err'] = $parsed['err'] ?? ('HTTP ' . $status);
        }
        return $parsed;
    }
}

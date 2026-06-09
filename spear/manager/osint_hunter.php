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

if (!function_exists('osint_hunter_classify_error')) {
    /**
     * Map a Hunter.io error object to a stable code (F4) so callers branch on
     * a token instead of regex-matching the human message. Hunter signals auth
     * failures with HTTP 401/403 (id=invalid_api_key), rate limits with 429.
     *
     * @param array<string,mixed> $first the first entry of the `errors` array
     */
    function osint_hunter_classify_error(array $first): string
    {
        $code = (int) ($first['code'] ?? 0);
        $id   = strtolower((string) ($first['id'] ?? ''));
        if ($code === 401 || $code === 403 || strpos($id, 'api_key') !== false
            || strpos($id, 'unauthor') !== false || strpos($id, 'forbidden') !== false) {
            return 'key_rejected';
        }
        if ($code === 429 || strpos($id, 'too_many') !== false || strpos($id, 'rate') !== false) {
            return 'rate_limited';
        }
        return 'api_error';
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
            $first = is_array($raw['errors'][0] ?? null) ? $raw['errors'][0] : [];
            $msg = $first['details'] ?? $first['code'] ?? 'unknown error';
            return ['ok' => false, 'err' => 'Hunter.io: ' . (string) $msg, 'err_code' => osint_hunter_classify_error($first)];
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
            return ['ok' => false, 'err' => 'Invalid domain format', 'err_code' => 'bad_domain'];
        }
        // Distinguish "no key configured" from "a key is set but malformed" so
        // the UI can tell the operator the right thing (F4).
        if (trim($apiKey) === '') {
            return ['ok' => false, 'err' => 'No Hunter.io API key configured', 'err_code' => 'no_key'];
        }
        if (!osint_hunter_is_valid_api_key($apiKey)) {
            return ['ok' => false, 'err' => 'Invalid Hunter.io API key format', 'err_code' => 'invalid_key'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'err' => 'ext-curl not available on this PHP runtime', 'err_code' => 'no_curl'];
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

// ---- Phase 3.13: email-finder --------------------------------------------

if (!function_exists('osint_hunter_parse_email_finder')) {
    /**
     * Reduce the Hunter.io /v2/email-finder response to the same row shape
     * that the parser uses for domain-search, so the JS layer renders both
     * in the same table.
     *
     * @param mixed $raw
     * @return array{
     *   ok: bool,
     *   domain?: ?string,
     *   organization?: ?string,
     *   results?: array<int, array<string,mixed>>,
     *   err?: string,
     * }
     */
    function osint_hunter_parse_email_finder($raw): array
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
            $first = is_array($raw['errors'][0] ?? null) ? $raw['errors'][0] : [];
            $msg = $first['details'] ?? $first['code'] ?? 'unknown error';
            return ['ok' => false, 'err' => 'Hunter.io: ' . (string) $msg, 'err_code' => osint_hunter_classify_error($first)];
        }
        $data = $raw['data'] ?? null;
        if (!is_array($data)) {
            return ['ok' => false, 'err' => 'Hunter.io response missing data field'];
        }
        if (empty($data['email'])) {
            return [
                'ok'           => true,
                'domain'       => isset($data['domain']) ? (string) $data['domain'] : null,
                'organization' => isset($data['organization']) ? (string) $data['organization'] : null,
                'results'      => [],
            ];
        }
        $first = (string) ($data['first_name'] ?? '');
        $last  = (string) ($data['last_name'] ?? '');
        return [
            'ok'           => true,
            'domain'       => isset($data['domain']) ? (string) $data['domain'] : null,
            'organization' => isset($data['organization']) ? (string) $data['organization'] : null,
            'results'      => [[
                'email'      => (string) $data['email'],
                'name'       => trim($first . ' ' . $last),
                'first_name' => $first,
                'last_name'  => $last,
                'position'   => (string) ($data['position'] ?? ''),
                'confidence' => is_numeric($data['score'] ?? null) ? (int) $data['score'] : 0,
                'type'       => 'finder',
            ]],
        ];
    }
}

if (!function_exists('osint_hunter_email_finder')) {
    /**
     * Live call to Hunter.io /v2/email-finder. Same return shape as
     * osint_hunter_domain_search() so the front end can render both in
     * the same modal.
     */
    function osint_hunter_email_finder(
        string $domain,
        string $firstName,
        string $lastName,
        string $apiKey
    ): array {
        if (!osint_hunter_is_valid_domain($domain)) {
            return ['ok' => false, 'err' => 'Invalid domain format'];
        }
        if (!osint_hunter_is_valid_api_key($apiKey)) {
            return ['ok' => false, 'err' => 'Invalid Hunter.io API key format'];
        }
        $firstName = trim($firstName);
        $lastName  = trim($lastName);
        if ($firstName === '' && $lastName === '') {
            return ['ok' => false, 'err' => 'At least one of first_name / last_name is required'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'err' => 'ext-curl not available on this PHP runtime'];
        }
        $url = 'https://api.hunter.io/v2/email-finder?'
            . http_build_query([
                'domain'     => $domain,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'api_key'    => $apiKey,
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
        $parsed = osint_hunter_parse_email_finder($body);
        if (!$parsed['ok'] && $status >= 400) {
            $parsed['err'] = $parsed['err'] ?? ('HTTP ' . $status);
        }
        return $parsed;
    }
}

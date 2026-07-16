<?php
// SSRF guard for operator-driven "fetch an external URL" features (currently the
// Import-HTML field-scraper in the web-tracker builder). Pure URL/IP validation;
// the DNS-resolution + safe-curl half lives in the caller (manager).

if (!function_exists('taphish_ip_is_public')) {
    /**
     * True only for a routable PUBLIC IP. Rejects private (10/8, 172.16/12,
     * 192.168/16, fc00::/7) and reserved (loopback, link-local 169.254/16,
     * 0.0.0.0, ::1, documentation, …) ranges via the SPL filter flags.
     */
    function taphish_ip_is_public(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}

if (!function_exists('taphish_fetch_url_precheck')) {
    /**
     * Structural SSRF precheck for a user-supplied fetch URL. Enforces an
     * http/https scheme and refuses obvious internal targets: literal private/
     * reserved IP hosts (v4 and bracketed v6) and localhost-ish names. A hostname
     * that passes here still MUST be DNS-resolved by the caller and every
     * resolved IP checked with taphish_ip_is_public() (blocks name→internal).
     *
     * @return array{ok:bool, host:string, error:string}
     */
    function taphish_fetch_url_precheck(string $url): array
    {
        $fail = static fn (string $e, string $h = ''): array => ['ok' => false, 'host' => $h, 'error' => $e];

        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return $fail('Enter a full http(s) URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return $fail('Only http and https URLs are allowed.');
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -10) === '.localhost') {
            return $fail('That host is not allowed.', $host);
        }
        // Bracketed IPv6 literal → strip brackets before validating.
        $bare = ($host !== '' && $host[0] === '[') ? trim($host, '[]') : $host;
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false && !taphish_ip_is_public($bare)) {
            return $fail('That address is private or reserved.', $host);
        }
        return ['ok' => true, 'host' => $host, 'error' => ''];
    }
}

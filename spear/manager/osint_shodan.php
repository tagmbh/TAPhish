<?php
/**
 * Shodan host-lookup OSINT.
 *
 * Adds a Shodan probe to the QuickStart Step 2 OSINT fan-out. Given
 * the engagement target domain, we resolve it to an A record, ask
 * Shodan's /shodan/host/{ip} endpoint, and surface the open-ports +
 * banners + last-update timestamp the operator needs to understand
 * the target's exposed surface before crafting a pretext.
 *
 * Read-only OSINT — no probes are issued from this host. Shodan
 * answers from its own scan history. The operator-supplied API key
 * is sent through the dispatcher; it isn't persisted server-side
 * (same pattern as osint_hunter).
 *
 * Parser is pure and exercised in tests/OsintShodanTest.php; the
 * live HTTP path mirrors osint_hunter for consistency.
 *
 * Endpoint reference: https://developer.shodan.io/api
 */

if (!function_exists('osint_shodan_is_valid_api_key')) {
    /**
     * Shodan API keys are 32-char alphanumeric strings. Reject
     * anything else before issuing the request so we don't burn quota.
     */
    function osint_shodan_is_valid_api_key(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9]{32}$/', trim($key)) === 1;
    }
}

if (!function_exists('osint_shodan_is_valid_domain_or_ip')) {
    /**
     * Accept either a domain name (we'll resolve it ourselves before
     * calling Shodan) or a literal IPv4 / IPv6.
     */
    function osint_shodan_is_valid_domain_or_ip(string $target): bool
    {
        $target = strtolower(trim($target));
        if ($target === '') return false;
        if (filter_var($target, FILTER_VALIDATE_IP) !== false) return true;
        if (strlen($target) > 253) return false;
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $target) === 1;
    }
}

if (!function_exists('osint_shodan_parse_host')) {
    /**
     * Reduce the verbose Shodan /shodan/host/{ip} payload to the small
     * shape the QuickStart lane renders.
     *
     * Returns:
     *   ok=false  with err set on transport / API errors
     *   ok=true   with ip, hostnames[], org, country, isp, os,
     *             open_ports[], last_update, top_services[],
     *             vulns[] (top 5 CVE IDs if Shodan provided them)
     *
     * @param mixed $raw
     * @return array{
     *   ok: bool,
     *   ip?: ?string,
     *   hostnames?: array<int,string>,
     *   org?: ?string,
     *   isp?: ?string,
     *   country?: ?string,
     *   os?: ?string,
     *   open_ports?: array<int,int>,
     *   last_update?: ?string,
     *   top_services?: array<int,array{port:int,product:string,banner:string}>,
     *   vulns?: array<int,string>,
     *   err?: string,
     * }
     */
    function osint_shodan_parse_host($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'err' => 'Shodan returned non-JSON response'];
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return ['ok' => false, 'err' => 'Shodan response was not an object'];
        }
        if (!empty($raw['error'])) {
            return ['ok' => false, 'err' => 'Shodan: ' . (string) $raw['error']];
        }
        $ip          = isset($raw['ip_str']) ? (string) $raw['ip_str'] : null;
        $hostnames   = (isset($raw['hostnames']) && is_array($raw['hostnames']))
            ? array_values(array_map('strval', $raw['hostnames']))
            : [];
        $open_ports  = (isset($raw['ports']) && is_array($raw['ports']))
            ? array_values(array_map('intval', $raw['ports']))
            : [];
        sort($open_ports);
        $services = [];
        if (isset($raw['data']) && is_array($raw['data'])) {
            foreach ($raw['data'] as $svc) {
                if (!is_array($svc)) continue;
                $banner = (string) ($svc['data'] ?? '');
                if ($banner !== '') {
                    $banner = strtok($banner, "\r\n") ?: '';
                    if (strlen($banner) > 200) $banner = substr($banner, 0, 200) . '...';
                }
                $services[] = [
                    'port'    => (int) ($svc['port'] ?? 0),
                    'product' => (string) ($svc['product'] ?? ''),
                    'banner'  => $banner,
                ];
                if (count($services) >= 8) break;
            }
        }
        $vulns = [];
        if (isset($raw['vulns']) && is_array($raw['vulns'])) {
            $vulns = array_values(array_map('strval', array_keys($raw['vulns'])));
            sort($vulns);
            $vulns = array_slice($vulns, 0, 5);
        }
        return [
            'ok'           => true,
            'ip'           => $ip,
            'hostnames'    => $hostnames,
            'org'          => isset($raw['org']) ? (string) $raw['org'] : null,
            'isp'          => isset($raw['isp']) ? (string) $raw['isp'] : null,
            'country'      => isset($raw['country_name']) ? (string) $raw['country_name'] : null,
            'os'           => isset($raw['os']) ? (string) $raw['os'] : null,
            'open_ports'   => $open_ports,
            'last_update'  => isset($raw['last_update']) ? (string) $raw['last_update'] : null,
            'top_services' => $services,
            'vulns'        => $vulns,
        ];
    }
}

if (!function_exists('osint_shodan_resolve_domain')) {
    /**
     * Resolve a hostname to a single IPv4 address. Injectable seam
     * lets tests pass a deterministic resolver. Returns '' on failure.
     */
    function osint_shodan_resolve_domain(string $domain, ?callable $resolver = null): string
    {
        $domain = trim($domain);
        if ($domain === '') return '';
        if (filter_var($domain, FILTER_VALIDATE_IP) !== false) return $domain;
        $fn = $resolver ?? 'gethostbyname';
        $ip = (string) $fn($domain);
        if ($ip === '' || $ip === $domain) return '';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
        return $ip;
    }
}

if (!function_exists('osint_shodan_host_lookup')) {
    /**
     * Live call to Shodan. Returns the same shape as the parser, with
     * ok=false on transport / HTTP / shape errors. Operator's API key
     * is read from the caller (not stored).
     */
    function osint_shodan_host_lookup(string $target, string $apiKey): array
    {
        if (!osint_shodan_is_valid_domain_or_ip($target)) {
            return ['ok' => false, 'err' => 'Invalid target (need domain or IP)'];
        }
        if (!osint_shodan_is_valid_api_key($apiKey)) {
            return ['ok' => false, 'err' => 'Invalid Shodan API key format (need 32 alphanumeric chars)'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'err' => 'ext-curl not available on this PHP runtime'];
        }
        $ip = osint_shodan_resolve_domain($target);
        if ($ip === '') {
            return ['ok' => false, 'err' => 'Could not resolve ' . $target . ' to an IP'];
        }
        $url = 'https://api.shodan.io/shodan/host/' . rawurlencode($ip)
            . '?' . http_build_query(['key' => $apiKey, 'minify' => 'true']);
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
        // Shodan returns 404 for "no information available for that IP" with
        // a JSON error message; let the parser surface that.
        $parsed = osint_shodan_parse_host($body);
        if (!$parsed['ok'] && $status >= 400 && empty($parsed['err'])) {
            $parsed['err'] = 'HTTP ' . $status;
        }
        // Preserve the resolved IP so the front end can display "$target → $ip".
        if (!isset($parsed['ip']) || $parsed['ip'] === null || $parsed['ip'] === '') {
            $parsed['ip'] = $ip;
        }
        return $parsed;
    }
}

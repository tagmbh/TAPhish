<?php
/**
 * Phase 3.43h: Toolset Checker — pre-engagement readiness probe.
 *
 * One-shot diagnostic that walks every external dependency the operator
 * actually needs to launch a campaign and reports green / amber / red
 * per check. Surfaces the answer to "is this install ready to send".
 *
 * Pure on the verdict mapping; DNS/HTTP/SMTP probes are injectable so
 * the suite can pin every output shape without a network.
 */

if (!function_exists('taphish_toolset_check_php_extension')) {
    function taphish_toolset_check_php_extension(string $name, ?callable $isLoaded = null): array
    {
        $isLoaded = $isLoaded ?? 'extension_loaded';
        $ok = (bool) $isLoaded($name);
        return [
            'key'      => 'ext-' . $name,
            'label'    => 'PHP ext: ' . $name,
            'status'   => $ok ? 'ok' : 'error',
            'detail'   => $ok ? 'loaded' : 'not loaded',
            'required' => true,
        ];
    }
}

if (!function_exists('taphish_toolset_check_php_version')) {
    function taphish_toolset_check_php_version(?string $version = null): array
    {
        $version = $version ?? PHP_VERSION;
        $ok = version_compare($version, '8.1', '>=');
        return [
            'key'    => 'php-version',
            'label'  => 'PHP version',
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $version . ($ok ? ' (>= 8.1 supported)' : ' (8.1+ recommended)'),
        ];
    }
}

if (!function_exists('taphish_toolset_check_writable_dirs')) {
    /**
     * Verify the small set of dirs TAPhish writes to actually accept
     * writes. Test via injectable file-test callbacks so the unit suite
     * can simulate read-only filesystems.
     */
    function taphish_toolset_check_writable_dirs(array $dirs, ?callable $isWritable = null): array
    {
        $isWritable = $isWritable ?? 'is_writable';
        $out = [];
        foreach ($dirs as $dir) {
            $ok = (bool) $isWritable($dir);
            $out[] = [
                'key'    => 'fs-' . md5($dir),
                'label'  => 'Writable: ' . $dir,
                'status' => $ok ? 'ok' : 'error',
                'detail' => $ok ? 'writable' : 'not writable — chmod the directory',
            ];
        }
        return $out;
    }
}

if (!function_exists('taphish_toolset_check_dns')) {
    /**
     * Generic DNS-presence check. Resolver returns the array of DNS
     * records like dns_get_record(). Each callsite picks the record
     * type it cares about; this helper just asks "did anything come
     * back".
     */
    function taphish_toolset_check_dns(string $key, string $label, string $domain, int $type, ?callable $resolver = null): array
    {
        $resolver = $resolver ?? 'dns_get_record';
        $records = @$resolver($domain, $type);
        $ok = is_array($records) && count($records) > 0;
        return [
            'key'    => $key,
            'label'  => $label,
            'status' => $ok ? 'ok' : 'warn',
            'detail' => $ok ? (count($records) . ' record(s)') : 'no records found',
        ];
    }
}

if (!function_exists('taphish_toolset_check_url_reachable')) {
    /**
     * "Probe this URL and tell me whether anyone is home." Used for the
     * webhook reachability check + /status liveness. Fetcher returns
     * `['ok' => bool, 'status' => int]`.
     */
    function taphish_toolset_check_url_reachable(string $key, string $label, string $url, ?callable $fetcher = null): array
    {
        if ($url === '') {
            return [
                'key'    => $key,
                'label'  => $label,
                'status' => 'warn',
                'detail' => 'not configured',
            ];
        }
        $fetcher = $fetcher ?? function (string $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => (defined('BRAND_PRODUCT_NAME') ? BRAND_PRODUCT_NAME : 'TAPhish') . '/toolset-check',
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['ok' => $code >= 200 && $code < 500, 'status' => $code];
        };
        $r = $fetcher($url);
        $status = $r['status'] ?? 0;
        $ok = !empty($r['ok']);
        return [
            'key'    => $key,
            'label'  => $label,
            'status' => $ok ? 'ok' : 'error',
            'detail' => $ok ? ('reachable (HTTP ' . $status . ')') : ('unreachable (HTTP ' . $status . ')'),
        ];
    }
}

if (!function_exists('taphish_toolset_run')) {
    /**
     * Run the full suite. Injectable resolver / fetcher / is_writable
     * means the test tier sees a deterministic output shape; the live
     * web-handler version passes the real callables.
     *
     * $opts:
     *   - sender_domain  (string|null): primary sender domain for SPF/DKIM/DMARC checks
     *   - webhook_url    (string|null): operator-configured first-capture webhook
     *   - status_url     (string|null): operator-facing /status endpoint
     *   - writable_dirs  (string[])
     *   - resolver       (callable|null)
     *   - fetcher        (callable|null)
     *   - is_writable    (callable|null)
     *   - is_loaded      (callable|null)
     *   - php_version    (string|null) — override for testing
     */
    function taphish_toolset_run(array $opts = []): array
    {
        $resolver   = $opts['resolver']    ?? null;
        $fetcher    = $opts['fetcher']     ?? null;
        $isWritable = $opts['is_writable'] ?? null;
        $isLoaded   = $opts['is_loaded']   ?? null;
        $phpVersion = $opts['php_version'] ?? null;

        $results = [];

        $results[] = taphish_toolset_check_php_version($phpVersion);
        foreach (['mysqli', 'curl', 'openssl', 'mbstring', 'gd', 'dom'] as $ext) {
            $results[] = taphish_toolset_check_php_extension($ext, $isLoaded);
        }

        if (!empty($opts['writable_dirs']) && is_array($opts['writable_dirs'])) {
            foreach (taphish_toolset_check_writable_dirs($opts['writable_dirs'], $isWritable) as $r) {
                $results[] = $r;
            }
        }

        $domain = (string) ($opts['sender_domain'] ?? '');
        if ($domain !== '') {
            $results[] = taphish_toolset_check_dns('dns-mx',    'MX for ' . $domain,    $domain, DNS_MX,  $resolver);
            $results[] = taphish_toolset_check_dns('dns-spf',   'SPF/TXT for ' . $domain, $domain, DNS_TXT, $resolver);
            $results[] = taphish_toolset_check_dns(
                'dns-dmarc',
                'DMARC for _dmarc.' . $domain,
                '_dmarc.' . $domain,
                DNS_TXT,
                $resolver
            );
        }

        $webhook = (string) ($opts['webhook_url'] ?? '');
        $results[] = taphish_toolset_check_url_reachable('webhook',  'First-capture webhook', $webhook, $fetcher);
        $status   = (string) ($opts['status_url']  ?? '');
        $results[] = taphish_toolset_check_url_reachable('status-url', '/status endpoint',     $status,  $fetcher);

        $summary = taphish_toolset_summarise($results);
        return [
            'results' => $results,
            'summary' => $summary,
        ];
    }
}

if (!function_exists('taphish_toolset_summarise')) {
    /**
     * Count results into ok / warn / error buckets and pick an overall
     * verdict the badge at the top of the page renders.
     */
    function taphish_toolset_summarise(array $results): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'error' => 0];
        foreach ($results as $r) {
            $s = $r['status'] ?? 'warn';
            if (!isset($counts[$s])) $counts[$s] = 0;
            $counts[$s]++;
        }
        $verdict = 'ready';
        if ($counts['error'] > 0) {
            $verdict = 'blocked';
        } elseif ($counts['warn'] > 0) {
            $verdict = 'caution';
        }
        return [
            'verdict' => $verdict,
            'counts'  => $counts,
            'total'   => count($results),
        ];
    }
}

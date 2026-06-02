<?php
/**
 * Phase 3.43b: lightweight public-web fingerprint for a target domain.
 *
 * Pulls a few non-invasive signals the operator would manually check at
 * the start of every engagement:
 *   - HTTPS reachability + status code
 *   - <title>
 *   - <meta name="generator">
 *   - robots.txt presence + any sitemap reference
 *   - /.well-known/ listings the host advertises (security.txt, etc.)
 *
 * Pure on the parsers; the HTTP fetch is injectable so tests don't need
 * a network. Output is the OSINT panel's "web fingerprint" card.
 */

if (!function_exists('taphish_web_parse_html')) {
    /**
     * Pull title + generator meta out of HTML. Pure, no DOM extension
     * required — small regex handles the 99% case (PHP DOMDocument would
     * choke on real-world malformed HTML and adds 1MB of object graph).
     */
    function taphish_web_parse_html(string $html): array
    {
        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $generator = '';
        if (preg_match('#<meta\s+[^>]*name=["\']generator["\'][^>]*>#i', $html, $m)) {
            if (preg_match('#content=["\']([^"\']+)["\']#i', $m[0], $mc)) {
                $generator = trim($mc[1]);
            }
        }
        return [
            'title'     => mb_substr($title, 0, 200),
            'generator' => mb_substr($generator, 0, 200),
        ];
    }
}

if (!function_exists('taphish_web_parse_robots')) {
    /**
     * Pull Sitemap: + Disallow lines out of robots.txt. Both are useful
     * pre-engagement intel — sitemaps disclose hidden URLs; long Disallow
     * lists hint at sensitive paths.
     */
    function taphish_web_parse_robots(string $robotsTxt): array
    {
        $sitemaps  = [];
        $disallows = [];
        $lines = preg_split('/\r\n|\r|\n/', $robotsTxt) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^Sitemap:\s*(\S+)/i', $line, $m)) {
                $sitemaps[] = $m[1];
            }
            if (preg_match('/^Disallow:\s*(\S+)/i', $line, $m)) {
                $disallows[] = $m[1];
            }
        }
        return [
            'sitemaps'      => array_values(array_unique($sitemaps)),
            'disallow_hits' => array_slice(array_values(array_unique($disallows)), 0, 25),
        ];
    }
}

if (!function_exists('taphish_web_parse_security_txt')) {
    /**
     * Parse /.well-known/security.txt. Returns the disclosed Contact:,
     * Policy:, and Expires: lines so the operator knows who handles
     * disclosure on the target side and whether it's stale.
     */
    function taphish_web_parse_security_txt(string $securityTxt): array
    {
        $out = ['contact' => [], 'policy' => [], 'expires' => null];
        $lines = preg_split('/\r\n|\r|\n/', $securityTxt) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^Contact:\s*(.+)/i', $line, $m)) {
                $out['contact'][] = trim($m[1]);
            } elseif (preg_match('/^Policy:\s*(.+)/i', $line, $m)) {
                $out['policy'][] = trim($m[1]);
            } elseif (preg_match('/^Expires:\s*(.+)/i', $line, $m)) {
                $out['expires'] = trim($m[1]);
            }
        }
        return $out;
    }
}

if (!function_exists('taphish_web_default_fetcher')) {
    /**
     * Default HTTP fetcher used when the caller doesn't inject one. Uses
     * cURL with a sane timeout + a clearly-tagged user agent so target
     * webmasters who notice the hit can identify TAPhish. Disabled in
     * the test suite by passing an injected fetcher.
     */
    function taphish_web_default_fetcher(string $url, int $timeoutSec = 6): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'TAPhish/3.43 OSINT pre-check (red-team, authorised use only)',
            CURLOPT_HEADER         => false,
        ]);
        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err   = curl_error($ch);
        curl_close($ch);
        return [
            'ok'     => $body !== false && $code >= 200 && $code < 400,
            'status' => $code,
            'body'   => is_string($body) ? $body : '',
            'error'  => $err,
        ];
    }
}

if (!function_exists('taphish_web_fingerprint')) {
    /**
     * Fan out a small bundle of fetches against the target domain. The
     * fetcher is injectable so the unit suite can pin parser behaviour
     * without DNS or curl. Each call caps body at 256 KB to keep memory
     * predictable on hostile / huge robots.txt files.
     */
    function taphish_web_fingerprint(string $domain, ?callable $fetcher = null): array
    {
        $domain = strtolower(trim(rtrim($domain, '.')));
        if ($domain === '') {
            return [
                'domain'       => '',
                'reachable'    => false,
                'status'       => 0,
                'title'        => '',
                'generator'    => '',
                'robots'       => ['present' => false, 'sitemaps' => [], 'disallow_hits' => []],
                'security_txt' => ['present' => false, 'contact' => [], 'policy' => [], 'expires' => null],
            ];
        }
        $fetcher = $fetcher ?? 'taphish_web_default_fetcher';

        $root = $fetcher('https://' . $domain . '/');
        $rob  = $fetcher('https://' . $domain . '/robots.txt');
        $sec  = $fetcher('https://' . $domain . '/.well-known/security.txt');

        $rootHtml = isset($root['body']) ? mb_substr((string) $root['body'], 0, 262144) : '';
        $robTxt   = isset($rob['body'])  ? mb_substr((string) $rob['body'],  0, 262144) : '';
        $secTxt   = isset($sec['body'])  ? mb_substr((string) $sec['body'],  0, 262144) : '';

        $html = taphish_web_parse_html($rootHtml);
        $robotsParsed = taphish_web_parse_robots($robTxt);
        $secParsed    = taphish_web_parse_security_txt($secTxt);

        return [
            'domain'    => $domain,
            'reachable' => !empty($root['ok']),
            'status'    => $root['status'] ?? 0,
            'title'     => $html['title'],
            'generator' => $html['generator'],
            'robots'    => [
                'present'       => !empty($rob['ok']),
                'sitemaps'      => $robotsParsed['sitemaps'],
                'disallow_hits' => $robotsParsed['disallow_hits'],
            ],
            'security_txt' => [
                'present' => !empty($sec['ok']),
                'contact' => $secParsed['contact'],
                'policy'  => $secParsed['policy'],
                'expires' => $secParsed['expires'],
            ],
        ];
    }
}

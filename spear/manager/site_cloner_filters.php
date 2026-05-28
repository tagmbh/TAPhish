<?php
/**
 * Pure helpers for the Site Cloner module: slug normalization, URL safety
 * checks, HTML rewriting, asset-URL collection.
 *
 * No DB, no session, no network. Side-effect-free. Tested in isolation via
 * tests/SiteClonerFiltersTest.php.
 */

if (!function_exists('clone_slugify')) {
    /**
     * Normalize an operator-supplied label into a filesystem- and URL-safe
     * slug: lower-case, [a-z0-9-]+, no leading/trailing dashes, capped length.
     */
    function clone_slugify(string $input, int $maxLen = 50): string
    {
        $s = strtolower($input);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if ($s === '') {
            return '';
        }
        if (strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen);
            $s = rtrim($s, '-');
        }
        return $s;
    }
}

if (!function_exists('clone_is_safe_url')) {
    /**
     * Returns [bool $ok, ?string $reason]. Validates scheme + host shape and
     * (unless $allowPrivate) refuses obvious SSRF targets.
     *
     * The caller is responsible for re-resolving the host at fetch time if
     * stricter DNS-rebinding protection is needed.
     */
    function clone_is_safe_url(string $url, bool $allowPrivate = false): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return [false, 'URL must be absolute with scheme and host'];
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [false, 'Only http and https schemes are allowed'];
        }
        $host = strtolower($parts['host']);
        if ($host === '' || strlen($host) > 253) {
            return [false, 'Host is empty or too long'];
        }

        if ($allowPrivate) {
            return [true, null];
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return [false, 'Localhost targets are blocked (set allowPrivate to override)'];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($isPublic === false) {
                return [false, 'Private or reserved IP targets are blocked (set allowPrivate to override)'];
            }
        }
        return [true, null];
    }
}

if (!function_exists('clone_strip_csp_meta')) {
    /**
     * Remove <meta http-equiv="Content-Security-Policy" ...> tags from HTML.
     * Returns [string $html, int $stripped].
     */
    function clone_strip_csp_meta(string $html): array
    {
        $pattern = '/<meta\s+[^>]*http-equiv\s*=\s*["\']?content-security-policy["\']?[^>]*>/i';
        $count = 0;
        $out = preg_replace($pattern, '', $html, -1, $count);
        return [$out ?? $html, (int) $count];
    }
}

if (!function_exists('clone_resolve_url')) {
    /**
     * Resolve a possibly-relative URL against a base URL. Returns absolute URL
     * or null if the input is unresolvable (javascript:, data:, fragment-only,
     * empty).
     */
    function clone_resolve_url(string $url, string $base): ?string
    {
        $url = trim($url);
        if ($url === '' || $url[0] === '#') {
            return null;
        }
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }
        $b = parse_url($base);
        if ($b === false || !isset($b['scheme'], $b['host'])) {
            return null;
        }
        $authority = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($url, '//')) {
            return $b['scheme'] . ':' . $url;
        }
        if ($url[0] === '/') {
            return $authority . $url;
        }
        $basePath = isset($b['path']) ? $b['path'] : '/';
        $dir = rtrim(substr($basePath, 0, strrpos($basePath, '/') + 1), '/');
        $combined = $dir . '/' . $url;
        $segments = [];
        foreach (explode('/', $combined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        return $authority . '/' . implode('/', $segments);
    }
}

if (!function_exists('clone_rewrite_html')) {
    /**
     * Rewrite HTML for hosting under the cloned-site directory.
     *
     * @param array{
     *   tracker_url?: ?string,
     *   download_css?: bool,
     *   download_images?: bool,
     * } $opts
     * @return array{html: string, css_assets: string[], img_assets: string[], warnings: string[]}
     */
    function clone_rewrite_html(string $html, string $baseUrl, array $opts = []): array
    {
        $warnings = [];
        [$html, $cspStripped] = clone_strip_csp_meta($html);
        if ($cspStripped > 0) {
            $warnings[] = "Stripped {$cspStripped} Content-Security-Policy meta tag(s)";
        }

        $cssAssets = [];
        $imgAssets = [];
        $downloadCss = $opts['download_css'] ?? true;
        $downloadImg = $opts['download_images'] ?? true;

        // Rewrite <link href="..."> (stylesheets and others)
        $html = preg_replace_callback(
            '/(<link\b[^>]*\bhref\s*=\s*)(["\'])([^"\']+)\2/i',
            function ($m) use ($baseUrl, &$cssAssets, $downloadCss) {
                $abs = clone_resolve_url($m[3], $baseUrl);
                if ($abs === null) {
                    return $m[0];
                }
                if ($downloadCss && stripos($m[0], 'stylesheet') !== false) {
                    $cssAssets[] = $abs;
                }
                return $m[1] . $m[2] . $abs . $m[2];
            },
            $html
        );

        // Rewrite <a href="...">, <form action="...">, <script src="...">,
        // <source src="...">, <iframe src="...">, <video src="...">, <audio src="...">
        $absoluteAttrPatterns = [
            '/(<a\b[^>]*\bhref\s*=\s*)(["\'])([^"\']+)\2/i',
            '/(<form\b[^>]*\baction\s*=\s*)(["\'])([^"\']+)\2/i',
            '/(<script\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)\2/i',
            '/(<source\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)\2/i',
            '/(<iframe\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)\2/i',
            '/(<video\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)\2/i',
            '/(<audio\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)\2/i',
        ];
        foreach ($absoluteAttrPatterns as $pat) {
            $html = preg_replace_callback(
                $pat,
                function ($m) use ($baseUrl) {
                    $abs = clone_resolve_url($m[3], $baseUrl);
                    if ($abs === null) {
                        return $m[0];
                    }
                    return $m[1] . $m[2] . $abs . $m[2];
                },
                $html
            );
        }

        // <img src="..."> — optionally collect for download
        $html = preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*)(["\'])([^"\']+)\2/i',
            function ($m) use ($baseUrl, &$imgAssets, $downloadImg) {
                $abs = clone_resolve_url($m[3], $baseUrl);
                if ($abs === null) {
                    return $m[0];
                }
                if ($downloadImg) {
                    $imgAssets[] = $abs;
                }
                return $m[1] . $m[2] . $abs . $m[2];
            },
            $html
        );

        // Optional tracker injection: insert just before </head>
        $trackerUrl = $opts['tracker_url'] ?? null;
        if (is_string($trackerUrl) && $trackerUrl !== '' && stripos($html, '</head>') !== false) {
            $trackerTag = '<script src="' . htmlspecialchars($trackerUrl, ENT_QUOTES | ENT_HTML5) . '"></script>';
            $html = preg_replace('#</head>#i', $trackerTag . '</head>', $html, 1);
            $warnings[] = 'Injected tracker script before </head>';
        }

        return [
            'html'        => $html,
            'css_assets'  => array_values(array_unique($cssAssets)),
            'img_assets'  => array_values(array_unique($imgAssets)),
            'warnings'    => $warnings,
        ];
    }
}

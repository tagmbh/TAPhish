<?php
/**
 * In-app landing deploy engine — FEATURE-R2.4, Phase 1.
 *
 * Same-account local-filesystem copy of a capture-landing into a look-alike
 * host docroot under ~/www/, replacing the manual deploy_hostpoint.sh. The
 * write step sits behind a swappable driver so a future 'sftp' driver for
 * external hosters slots in without touching render/resolve/verify.
 *
 * Design: docs/superpowers/specs/2026-07-18-in-app-landing-deploy-design.md
 *
 * All paths (wwwBase, sniperhostBase) are injected so the pure/IO functions
 * are testable against temp fixtures and never touch the real ~/www/.
 */

if (!function_exists('taphish_landing_deploy_protected_hosts')) {
    /** Hosts that must never be a deploy target — the app itself + its config. */
    function taphish_landing_deploy_protected_hosts(): array
    {
        return ['deepaudit.ch', 'config'];
    }
}

if (!function_exists('taphish_landing_deploy_valid_host')) {
    /**
     * A single safe path segment. The leading [a-z0-9] rule makes a leading dot
     * or a '..' segment impossible, and '/' is not in the class — so a valid
     * host can never traverse out of its base directory.
     */
    function taphish_landing_deploy_valid_host(string $host): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9.-]{0,62}$/', $host);
    }
}

if (!function_exists('taphish_landing_deploy_render')) {
    /**
     * Faithful to deploy_hostpoint.sh: substitute {{POST_URL}} everywhere and
     * drop the open-pixel beacon line (data-tracker src="{{TRACKER_URL_ATTR}}"),
     * which is unused on Hostpoint (rid/trackerId come from the URL). Unrelated
     * inline JS such as the screen_res beacon is left untouched.
     */
    function taphish_landing_deploy_render(string $html, string $postUrl): string
    {
        $html = str_replace('{{POST_URL}}', $postUrl, $html);
        $lines = preg_split('/\r\n|\r|\n/', $html);
        $kept = array_filter($lines, static function ($l) {
            return strpos($l, 'data-tracker src="{{TRACKER_URL_ATTR}}"') === false;
        });
        return implode("\n", $kept);
    }
}

if (!function_exists('taphish_landing_deploy_resolve_target')) {
    /**
     * Validate a deploy target host and return its absolute docroot. Targets
     * must be pre-provisioned directories directly under $wwwBase — never the
     * app/config, never a traversal. $wwwBase is injected so tests never touch
     * the real ~/www/. Returns ['ok'=>bool, 'docroot'=>?string, 'error'=>?string].
     */
    function taphish_landing_deploy_resolve_target(string $host, string $wwwBase): array
    {
        $err = static function (string $m): array {
            return ['ok' => false, 'docroot' => null, 'error' => $m];
        };
        if (!taphish_landing_deploy_valid_host($host)) {
            return $err('invalid host name');
        }
        if (in_array($host, taphish_landing_deploy_protected_hosts(), true)) {
            return $err('protected host');
        }
        $baseReal = realpath($wwwBase);
        if ($baseReal === false) {
            return $err('base directory missing');
        }
        $real = realpath($baseReal . '/' . $host);
        if ($real === false) {
            return $err('target host directory does not exist');
        }
        // Defense in depth: the resolved path must be a direct child of the base
        // (guards against a symlinked host dir pointing elsewhere).
        if ($real !== $baseReal . DIRECTORY_SEPARATOR . $host) {
            return $err('target escapes base');
        }
        return ['ok' => true, 'docroot' => $real, 'error' => null];
    }
}

if (!function_exists('taphish_landing_deploy_list_targets')) {
    /** Valid target hosts: directories directly under $wwwBase minus protected/invalid names. */
    function taphish_landing_deploy_list_targets(string $wwwBase): array
    {
        $baseReal = realpath($wwwBase);
        if ($baseReal === false) {
            return [];
        }
        $protected = taphish_landing_deploy_protected_hosts();
        $out = [];
        foreach (scandir($baseReal) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            if (!is_dir($baseReal . '/' . $e)) { continue; }
            if (in_array($e, $protected, true)) { continue; }
            if (!taphish_landing_deploy_valid_host($e)) { continue; }
            $out[] = $e;
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('taphish_landing_deploy_copy_tree')) {
    /** Recursively copy $src into $dst; returns the number of files copied. */
    function taphish_landing_deploy_copy_tree(string $src, string $dst): int
    {
        if (!is_dir($src)) { return 0; }
        if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) { return 0; }
        $n = 0;
        foreach (scandir($src) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $s = $src . '/' . $e;
            $d = $dst . '/' . $e;
            if (is_dir($s)) {
                $n += taphish_landing_deploy_copy_tree($s, $d);
            } elseif (@copy($s, $d)) {
                $n++;
            }
        }
        return $n;
    }
}

if (!function_exists('taphish_landing_deploy_write_local')) {
    /**
     * Local-filesystem write driver — THE SEAM a future 'sftp' driver replaces.
     * Writes the rendered index.html (backing up any existing one to
     * .bak-<stamp>), then copies learn.html + assets/ when provided.
     * $files = ['index_html' => string, 'learn_html_src' => ?path, 'assets_src' => ?dir]
     */
    function taphish_landing_deploy_write_local(array $files, string $docroot, string $stamp): array
    {
        if (!is_dir($docroot) && !@mkdir($docroot, 0755, true) && !is_dir($docroot)) {
            return ['ok' => false, 'written' => [], 'error' => 'cannot create docroot'];
        }
        $written = [];
        $idx = $docroot . '/index.html';
        if (is_file($idx)) {
            @copy($idx, $idx . '.bak-' . $stamp);
        }
        if (file_put_contents($idx, (string) ($files['index_html'] ?? '')) === false) {
            return ['ok' => false, 'written' => $written, 'error' => 'failed to write index.html'];
        }
        $written[] = 'index.html';
        if (!empty($files['learn_html_src']) && is_file($files['learn_html_src'])
            && @copy($files['learn_html_src'], $docroot . '/learn.html')) {
            $written[] = 'learn.html';
        }
        if (!empty($files['assets_src']) && is_dir($files['assets_src'])) {
            $n = taphish_landing_deploy_copy_tree($files['assets_src'], $docroot . '/assets');
            if ($n > 0) {
                $written[] = 'assets/(' . $n . ' files)';
            }
        }
        return ['ok' => true, 'written' => $written, 'error' => null];
    }
}

if (!function_exists('taphish_landing_deploy_list_sources')) {
    /**
     * Deployable landing sources: sub-dirs of sniperhost/library and
     * sniperhost/cloned that carry an index.html. Returns
     * [['kind'=>'library'|'cloned', 'name'=>slug], ...].
     */
    function taphish_landing_deploy_list_sources(string $sniperhostBase): array
    {
        $sources = [];
        foreach (['library', 'cloned'] as $kind) {
            $real = realpath($sniperhostBase . '/' . $kind);
            if ($real === false) { continue; }
            foreach (scandir($real) ?: [] as $e) {
                if ($e === '.' || $e === '..') { continue; }
                if (!is_dir($real . '/' . $e)) { continue; }
                if (!is_file($real . '/' . $e . '/index.html')) { continue; }
                $sources[] = ['kind' => $kind, 'name' => $e];
            }
        }
        return $sources;
    }
}

if (!function_exists('taphish_landing_deploy_resolve_source')) {
    /**
     * Validate a landing source (kind ∈ {library,cloned}, safe slug, must carry
     * an index.html directly under sniperhost/<kind>/). Mirrors resolve_target
     * so a crafted source name can't traverse out. Returns ['ok','dir'?,'error'?].
     */
    function taphish_landing_deploy_resolve_source(string $sniperhostBase, string $kind, string $name): array
    {
        $err = static function (string $m): array {
            return ['ok' => false, 'dir' => null, 'error' => $m];
        };
        if (!in_array($kind, ['library', 'cloned'], true)) {
            return $err('invalid source kind');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $name)) {
            return $err('invalid source name');
        }
        $baseReal = realpath($sniperhostBase . '/' . $kind);
        if ($baseReal === false) {
            return $err('source base missing');
        }
        $real = realpath($baseReal . '/' . $name);
        if ($real === false || $real !== $baseReal . DIRECTORY_SEPARATOR . $name) {
            return $err('source not found');
        }
        if (!is_file($real . '/index.html')) {
            return $err('source has no index.html');
        }
        return ['ok' => true, 'dir' => $real, 'error' => null];
    }
}

if (!function_exists('taphish_landing_deploy_run')) {
    /**
     * Orchestrate a local deploy: validate+resolve the target host, read the
     * source index.html, render it ({{POST_URL}} + beacon drop), then write the
     * landing (index + optional learn.html/assets). No network. Returns
     * ['ok'=>bool, 'docroot'=>?string, 'written'=>string[], 'error'=>?string].
     */
    function taphish_landing_deploy_run(string $sourceDir, string $host, string $wwwBase, string $postUrl, string $stamp): array
    {
        $target = taphish_landing_deploy_resolve_target($host, $wwwBase);
        if (!$target['ok']) {
            return ['ok' => false, 'docroot' => null, 'written' => [], 'error' => $target['error']];
        }
        $srcIndex = $sourceDir . '/index.html';
        if (!is_file($srcIndex)) {
            return ['ok' => false, 'docroot' => null, 'written' => [], 'error' => 'source index.html missing'];
        }
        $rendered = taphish_landing_deploy_render((string) file_get_contents($srcIndex), $postUrl);
        $files = ['index_html' => $rendered];
        if (is_file($sourceDir . '/learn.html')) { $files['learn_html_src'] = $sourceDir . '/learn.html'; }
        if (is_dir($sourceDir . '/assets'))      { $files['assets_src'] = $sourceDir . '/assets'; }
        $w = taphish_landing_deploy_write_local($files, $target['docroot'], $stamp);
        return [
            'ok'      => $w['ok'],
            'docroot' => $target['docroot'],
            'written' => $w['written'],
            'error'   => $w['error'],
        ];
    }
}

if (!function_exists('taphish_landing_deploy_verify')) {
    /**
     * IO: HEAD $url over HTTPS with cert verification. Returns
     * ['http_code'=>int, 'ssl_ok'=>bool]. An unreachable host yields code 0 /
     * ssl_ok false rather than throwing, so the caller can report it.
     */
    function taphish_landing_deploy_verify(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'ssl_ok' => false];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
        ]);
        curl_exec($ch);
        $code      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sslResult = (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
        $errno     = curl_errno($ch);
        // No curl_close(): a no-op since PHP 8.0 (handle is GC'd) and deprecated in 8.5.
        $sslOk = ($code > 0) && ($sslResult === 0) && ($errno === 0);
        return ['http_code' => $code, 'ssl_ok' => $sslOk];
    }
}

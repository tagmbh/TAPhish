<?php
/**
 * Phase 3.55 — operator-hosted site bundle.
 *
 * Zips a cloned landing page (sniperhost/cloned/<slug>/) into a downloadable
 * bundle with {{POST_URL}} / {{TRACKER_URL}} substituted, so the operator can
 * upload it to their own webspace on the look-alike domain. Reuses the
 * landing_library substitution (which handles JS-string vs HTML-attribute
 * contexts) so the POST/tracker URLs are escaped correctly per context.
 */

require_once(dirname(__FILE__) . '/landing_library.php');
require_once(dirname(__FILE__) . '/lookalike_deploy.php');

if (!function_exists('site_bundle_collect_files')) {
    /** Phase 3.55: recursively list relative file paths under $dir (sorted, '/'-joined). */
    function site_bundle_collect_files(string $dir): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($dir)), '/\\'));
            }
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('site_bundle_is_substitutable')) {
    /** Phase 3.55: only text assets get placeholder substitution (binary copied verbatim). */
    function site_bundle_is_substitutable(string $relPath): bool
    {
        return (bool) preg_match('/\.(html?|js|css|txt|json)$/i', $relPath);
    }
}

if (!function_exists('site_bundle_build')) {
    /**
     * Phase 3.55: build a downloadable zip of cloned/<slug>/ with POST/tracker
     * URLs substituted. Returns ['path'=>zipFile, 'files'=>[...relpaths]] or
     * null (bad slug, missing dir, empty, or no zip extension). $clonesRoot is
     * an injection seam so the unit suite stays offline.
     */
    function site_bundle_build(string $slug, string $postUrl, string $trackerUrl = '', ?string $clonesRoot = null): ?array
    {
        if (!lookalike_validate_vanity_slug($slug) || !class_exists('ZipArchive')) {
            return null;
        }
        $root = $clonesRoot !== null ? rtrim($clonesRoot, '/') : landing_library_clones_root();
        $src  = $root . '/' . $slug;
        if (!is_dir($src)) {
            return null;
        }
        $files = site_bundle_collect_files($src);
        if (!$files) {
            return null;
        }
        $zipPath = tempnam(sys_get_temp_dir(), 'taphish_bundle_');
        if ($zipPath === false) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return null;
        }
        foreach ($files as $rel) {
            $content = (string) file_get_contents($src . '/' . $rel);
            if (site_bundle_is_substitutable($rel)) {
                $content = landing_library_substitute_placeholders($content, $postUrl, $trackerUrl);
            }
            $zip->addFromString($slug . '/' . $rel, $content);
        }
        $zip->close();
        return ['path' => $zipPath, 'files' => $files];
    }
}

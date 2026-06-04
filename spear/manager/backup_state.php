<?php
/**
 * Phase 3.50b — State-dir snapshot helpers (pure).
 *
 * Decides which application state files go into a combined backup, sniffs the inner
 * archive format on restore, and maps archive paths back to real filesystem targets
 * while rejecting zip-slip. No filesystem/zip/crypto here — all injected.
 *
 * Container/crypto: spear/manager/backup_archive.php
 * Design: docs/superpowers/specs/2026-06-04-phase-3.50b-state-snapshot-design.md
 */

if (!function_exists('taphish_backup_state_manifest')) {
    /**
     * Build the [src => dest] file list for the state dirs.
     *
     * @param array<string,string> $roots   archivePrefix => absolute root dir
     * @param callable $lister  fn(string $absDir): string[]  relative file paths under it
     * @return array<int,array{src:string,dest:string}>
     */
    function taphish_backup_state_manifest(array $roots, callable $lister): array
    {
        $out = [];
        foreach ($roots as $prefix => $dir) {
            $prefix = trim(str_replace('\\', '/', (string) $prefix), '/');
            $base   = rtrim((string) $dir, '/');
            foreach ($lister($dir) as $rel) {
                $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
                if ($rel === '') {
                    continue;
                }
                $out[] = ['src' => $base . '/' . $rel, 'dest' => $prefix . '/' . $rel];
            }
        }
        return $out;
    }
}

if (!function_exists('taphish_backup_sniff_format')) {
    /**
     * Identify the inner payload by its first bytes.
     * @return string 'gzip' | 'zip' | 'unknown'
     */
    function taphish_backup_sniff_format(string $head): string
    {
        if (strncmp($head, "\x1f\x8b", 2) === 0) {
            return 'gzip';
        }
        if (strncmp($head, "PK\x03\x04", 4) === 0 || strncmp($head, "PK\x05\x06", 4) === 0) {
            return 'zip';
        }
        return 'unknown';
    }
}

if (!function_exists('taphish_backup_state_restore_target')) {
    /**
     * Map an archive path (e.g. 'state/cloned/x.html') back to an absolute path under
     * the matching root. Returns null on any zip-slip attempt or unknown prefix.
     *
     * @param array<string,string> $roots  archivePrefix => absolute root dir
     */
    function taphish_backup_state_restore_target(string $archivePath, array $roots): ?string
    {
        $p = str_replace('\\', '/', $archivePath);
        if ($p === '' || strpos($p, "\0") !== false) {
            return null;
        }
        foreach (explode('/', $p) as $seg) {
            if ($seg === '..') {
                return null;
            }
        }
        foreach ($roots as $prefix => $absRoot) {
            $pre = trim(str_replace('\\', '/', (string) $prefix), '/') . '/';
            if (strncmp($p, $pre, strlen($pre)) === 0) {
                $rel = substr($p, strlen($pre));
                if ($rel === '') {
                    return null;
                }
                $base   = rtrim((string) $absRoot, '/');
                $target = $base . '/' . $rel;
                if (strncmp($target, $base . '/', strlen($base) + 1) !== 0) {
                    return null;
                }
                return $target;
            }
        }
        return null;
    }
}

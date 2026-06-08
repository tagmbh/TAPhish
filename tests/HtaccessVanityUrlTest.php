<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the Phase 3.55 vanity-URL `.htaccess` rules.
 *
 * The 2026-06-08 production incident: the bare-slug-only rule matched only
 * `/p/foo` and `/p/foo/` — every sub-path under it (`assets/style.css`,
 * `js/foo.js`, `img/logo.png`) fell through to the generic clean-URL
 * `^([^\.]+)$ $1.php` rewrite and 404'd. Every cloned landing rendered
 * unstyled. Form POSTs survived because they use an absolute URL.
 *
 * Apache mod_rewrite can't be exercised directly from PHPUnit, but the
 * RewriteRule patterns are plain regex — applying them with `preg_match`
 * against representative URLs catches the exact class of regression that
 * just happened. Test pins:
 *
 *   1. both rules are present
 *   2. the bare-slug rule still matches /p/foo and /p/foo/
 *   3. the sub-path rule matches every common sub-path operators rely on
 *   4. neither rule matches outside the /p/ namespace
 *   5. the sub-path rule maps to the right cloned-landings location
 */
final class HtaccessVanityUrlTest extends TestCase
{
    private static string $htaccess = '';

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/../.htaccess';
        self::$htaccess = (string) @file_get_contents($path);
        if (self::$htaccess === '') {
            self::markTestSkipped('.htaccess not found at repo root: ' . $path);
        }
    }

    /** @return array<int, array{regex:string, target:string, flags:string}> */
    private static function rewriteRules(): array
    {
        $out = [];
        // RewriteRule <regex> <target> [<flags>]
        if (preg_match_all(
            '/^\s*RewriteRule\s+(\S+)\s+(\S+)\s*(\[[^\]]+\])?\s*$/m',
            self::$htaccess,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $row) {
                $out[] = ['regex' => $row[1], 'target' => $row[2], 'flags' => $row[3] ?? ''];
            }
        }
        return $out;
    }

    private static function vanityRules(): array
    {
        return array_values(array_filter(
            self::rewriteRules(),
            static fn (array $r): bool => str_starts_with($r['regex'], '^p/')
        ));
    }

    /**
     * Apply an apache regex to a request path. mod_rewrite anchors with `^`
     * and `$` explicitly in the source pattern; we delegate to PHP's PCRE
     * (same flavour) and ignore the [NC] case-insensitivity flag at this
     * level (every operator vanity URL is lowercase).
     */
    private static function rewriteMatch(string $regex, string $path, string &$target = '', string $replacement = ''): bool
    {
        // Apache regex → PHP PCRE: just wrap in delimiters. Apache uses POSIX
        // backslash sequences PHP already understands.
        $php = '#' . str_replace('#', '\#', $regex) . '#';
        $ok = preg_match($php, $path, $m) === 1;
        if ($ok && $replacement !== '') {
            // Apply $1, $2 replacements like mod_rewrite does
            $target = preg_replace_callback('/\$(\d+)/', static fn ($x): string => $m[(int) $x[1]] ?? '', $replacement);
        }
        return $ok;
    }

    public function testExactlyTwoVanityRulesPresent(): void
    {
        $rules = self::vanityRules();
        self::assertCount(
            2,
            $rules,
            'Phase 3.55 vanity-URL config must have BOTH a bare-slug rule AND a sub-path rule. Found: ' . count($rules)
        );
    }

    public function testBareSlugRuleMatchesSlugAndTrailingSlash(): void
    {
        $rules = self::vanityRules();
        // The bare-slug rule MUST come first — the generic .php-append rule
        // below it would 404 a path with no extension if the order flipped.
        $bare = $rules[0];

        $target = '';
        self::assertTrue(self::rewriteMatch($bare['regex'], 'p/foo',  $target, $bare['target']));
        self::assertSame('spear/sniperhost/cloned/foo/', $target);

        self::assertTrue(self::rewriteMatch($bare['regex'], 'p/foo/', $target, $bare['target']));
        self::assertSame('spear/sniperhost/cloned/foo/', $target);
    }

    public function testSubPathRuleCatchesEveryAssetClassOperatorsUse(): void
    {
        $rules = self::vanityRules();
        $sub = $rules[1];

        $cases = [
            // [input request path, expected rewrite destination]
            ['p/foo/assets/style.css',          'spear/sniperhost/cloned/foo/assets/style.css'],
            ['p/foo/assets/main.js',            'spear/sniperhost/cloned/foo/assets/main.js'],
            ['p/foo/img/logo.png',              'spear/sniperhost/cloned/foo/img/logo.png'],
            ['p/foo/favicon.ico',               'spear/sniperhost/cloned/foo/favicon.ico'],
            ['p/m365-login-eyll/assets/style.css', 'spear/sniperhost/cloned/m365-login-eyll/assets/style.css'],
            ['p/abc-123/path/to/deep/file.svg', 'spear/sniperhost/cloned/abc-123/path/to/deep/file.svg'],
        ];
        foreach ($cases as [$input, $expected]) {
            $target = '';
            self::assertTrue(
                self::rewriteMatch($sub['regex'], $input, $target, $sub['target']),
                "sub-path rule did NOT match $input — this is the 2026-06-08 regression class"
            );
            self::assertSame($expected, $target, "wrong target for $input");
        }
    }

    public function testVanityRulesDoNotMatchOutsidePNamespace(): void
    {
        $rules = self::vanityRules();
        foreach ($rules as $r) {
            foreach (['spear/Home', 'tmail', 'track.php', '/p2/foo', 'pa/foo'] as $stray) {
                self::assertFalse(
                    self::rewriteMatch($r['regex'], $stray),
                    "vanity rule {$r['regex']} wrongly matched $stray"
                );
            }
        }
    }

    public function testSlugRegexStillRejectsTraversalAttempts(): void
    {
        $rules = self::vanityRules();
        foreach ($rules as $r) {
            // Apache prevents `..` in request URI normalisation, but our regex
            // is the second layer of defence. Both rules must reject ../ etc.
            foreach (['p/../etc', 'p/foo../bar', 'p/.hidden', 'p/FOO/x.css' /* uppercase slug */, 'p/-foo/x'] as $bad) {
                self::assertFalse(
                    self::rewriteMatch($r['regex'], $bad),
                    "vanity rule {$r['regex']} wrongly accepted suspicious slug: $bad"
                );
            }
        }
    }

    public function testBareSlugRuleOrderedFirst(): void
    {
        // If the sub-path rule were first, the bare-slug rule could become
        // unreachable depending on the [L] flag interaction. Pin the order.
        $rules = self::vanityRules();
        $first  = $rules[0]['regex'];
        $second = $rules[1]['regex'];
        self::assertStringEndsWith('/?$',     $first,  'first vanity rule must be the bare-slug shape `…/?$`');
        self::assertStringEndsWith('/(.+)$',  $second, 'second vanity rule must be the sub-path shape `…/(.+)$`');
    }

    public function testBothVanityRulesUseLastFlagToShortCircuit(): void
    {
        // Without [L], Apache would continue evaluating subsequent rules
        // (the .php-append rule) — re-introducing the original bug.
        foreach (self::vanityRules() as $r) {
            self::assertStringContainsString(
                'L',
                $r['flags'],
                "vanity rule `{$r['regex']}` must carry the [L] flag so it short-circuits the generic clean-URL rule below"
            );
        }
    }
}

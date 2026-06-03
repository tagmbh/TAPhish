<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.46: landing-page library helper tests.
 *
 * The filesystem operations use a tempdir created in setUp so we
 * don't touch the real spear/sniperhost/library/ tree.
 */
final class LandingLibraryTest extends TestCase
{
    private string $root;
    private string $clones;

    protected function setUp(): void
    {
        $this->root   = sys_get_temp_dir() . '/taphish-llib-' . bin2hex(random_bytes(4));
        $this->clones = sys_get_temp_dir() . '/taphish-llib-clones-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        mkdir($this->clones, 0775, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
        self::rrmdir($this->clones);
    }

    private static function rrmdir(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (scandir($d) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $p = $d . '/' . $e;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    private function writeTemplate(string $slug, array $meta, array $files): void
    {
        $dir = $this->root . '/' . $slug;
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/meta.json', json_encode($meta));
        foreach ($files as $rel => $body) {
            $subdir = dirname($dir . '/' . $rel);
            if ($subdir !== $dir && !is_dir($subdir)) mkdir($subdir, 0775, true);
            file_put_contents($dir . '/' . $rel, $body);
        }
    }

    // ---- placeholder substitution ---------------------------------------

    public function testSubstitutesPostUrl(): void
    {
        $out = landing_library_substitute_placeholders(
            '<form action="{{POST_URL}}"></form>',
            'https://op.example/track.php'
        );
        self::assertStringContainsString('action="https://op.example/track.php"', $out);
    }

    public function testSubstitutesTrackerUrlWhenPresent(): void
    {
        $out = landing_library_substitute_placeholders(
            '<script data-tracker src="{{TRACKER_URL}}"></script>',
            'https://op.example/track.php',
            'https://op.example/track.js?rid=abc'
        );
        self::assertStringContainsString('src="https://op.example/track.js?rid=abc"', $out);
    }

    public function testStripsTrackerTagWhenNoUrlGiven(): void
    {
        $out = landing_library_substitute_placeholders(
            '<body><script data-tracker src="{{TRACKER_URL}}"></script>hi</body>',
            'p'
        );
        self::assertStringNotContainsString('data-tracker', $out);
        self::assertStringNotContainsString('{{TRACKER_URL}}', $out);
        self::assertStringContainsString('<body>hi</body>', $out);
    }

    public function testEscapesUrlsThatContainHtmlMetacharactersInAttrContext(): void
    {
        // {{POST_URL_ATTR}} is the attribute-context variant; HTML
        // entity encoding applies there (the JS-context default does
        // JS-string escaping instead — covered by separate tests).
        $out = landing_library_substitute_placeholders(
            '<form action="{{POST_URL_ATTR}}"></form>',
            'https://op.example/?a=1&b="><x>'
        );
        self::assertStringNotContainsString('"><x>', $out);
        self::assertStringContainsString('&amp;', $out);
    }

    public function testHandlesMultipleOccurrences(): void
    {
        $out = landing_library_substitute_placeholders(
            '<form action="{{POST_URL}}"><a href="{{POST_URL}}#x"></a></form>',
            'p'
        );
        self::assertSame(2, substr_count($out, 'action="p"') + substr_count($out, 'href="p#x"'));
    }

    // ---- library listing -------------------------------------------------

    public function testListReturnsEmptyForMissingRoot(): void
    {
        self::assertSame([], landing_library_list($this->root . '/nope'));
    }

    public function testListEnumeratesTemplates(): void
    {
        $this->writeTemplate('m365-login', [
            'name' => 'Microsoft 365 sign-in',
            'description' => 'Two-step credential collection',
            'pattern' => 'multi-step',
            'has_2fa' => true,
            'fields'  => ['email', 'password', 'code_2fa'],
        ], ['index.html' => '<html></html>']);
        $this->writeTemplate('vpn-portal', [
            'name' => 'Generic VPN portal',
            'pattern' => 'single-page',
            'has_2fa' => true,
        ], ['index.html' => '<html></html>']);

        $list = landing_library_list($this->root);
        self::assertCount(2, $list);
        self::assertSame('m365-login', $list[0]['slug']);
        self::assertTrue($list[0]['has_2fa']);
        self::assertSame(['email', 'password', 'code_2fa'], $list[0]['fields']);
    }

    public function testListSkipsTemplatesWithoutMeta(): void
    {
        mkdir($this->root . '/broken', 0775, true);
        file_put_contents($this->root . '/broken/index.html', '<html></html>');
        self::assertSame([], landing_library_list($this->root));
    }

    public function testListSkipsHiddenDirectories(): void
    {
        $this->writeTemplate('.git', ['name' => 'x'], ['index.html' => '']);
        $this->writeTemplate('real', ['name' => 'Real'], ['index.html' => '']);
        $list = landing_library_list($this->root);
        self::assertCount(1, $list);
        self::assertSame('real', $list[0]['slug']);
    }

    // ---- template file enumeration --------------------------------------

    public function testTemplateFilesReturnsRelativePaths(): void
    {
        $this->writeTemplate('t', ['name' => 't'], [
            'index.html'       => '',
            'step2.html'       => '',
            'assets/style.css' => '',
        ]);
        $files = landing_library_template_files('t', $this->root);
        self::assertContains('index.html', $files);
        self::assertContains('step2.html', $files);
        self::assertContains('assets/style.css', $files);
        self::assertNotContains('meta.json', $files);
    }

    public function testTemplateFilesReturnsEmptyForMissingSlug(): void
    {
        self::assertSame([], landing_library_template_files('nope', $this->root));
    }

    // ---- clone-to-path ---------------------------------------------------

    public function testCloneCopiesAndSubstitutes(): void
    {
        $this->writeTemplate('t', ['name' => 't'], [
            'index.html'       => '<form action="{{POST_URL}}"></form>',
            'assets/style.css' => 'body{color:red}',
        ]);
        $r = landing_library_clone_to_path(
            't', 'my-acme-clone', 'https://op/track.php', '', false,
            $this->root, $this->clones
        );
        self::assertTrue($r['ok'], $r['err'] ?? '');
        self::assertSame(2, $r['files']);
        self::assertTrue(is_file($this->clones . '/my-acme-clone/index.html'));
        self::assertStringContainsString('action="https://op/track.php"',
            (string) file_get_contents($this->clones . '/my-acme-clone/index.html'));
        // CSS is copied verbatim (no substitution on non-HTML files).
        self::assertSame('body{color:red}',
            (string) file_get_contents($this->clones . '/my-acme-clone/assets/style.css'));
    }

    public function testCloneRefusesExistingDestinationWithoutForce(): void
    {
        $this->writeTemplate('t', ['name' => 't'], ['index.html' => '']);
        mkdir($this->clones . '/dup', 0775, true);
        $r = landing_library_clone_to_path('t', 'dup', 'p', '', false, $this->root, $this->clones);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('already exists', $r['err']);
    }

    public function testCloneAcceptsForceOnExisting(): void
    {
        $this->writeTemplate('t', ['name' => 't'], ['index.html' => 'fresh']);
        mkdir($this->clones . '/dup', 0775, true);
        $r = landing_library_clone_to_path('t', 'dup', 'p', '', true, $this->root, $this->clones);
        self::assertTrue($r['ok']);
    }

    public function testCloneRejectsBadDestinationSlug(): void
    {
        $this->writeTemplate('t', ['name' => 't'], ['index.html' => '']);
        foreach (['', '-bad', 'with space', 'UPPER', 'too-' . str_repeat('x', 60)] as $bad) {
            $r = landing_library_clone_to_path('t', $bad, 'p', '', false, $this->root, $this->clones);
            self::assertFalse($r['ok'], "expected reject for slug: $bad");
        }
    }

    public function testCloneRejectsMissingSource(): void
    {
        $r = landing_library_clone_to_path('nope', 'fresh', 'p', '', false, $this->root, $this->clones);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('not found', $r['err']);
    }

    // ---- review sweep regression tests ----------------------------------

    public function testCloneRejectsPathTraversalInSourceSlug(): void
    {
        $this->writeTemplate('legit', ['name' => 'x'], ['index.html' => '']);
        foreach (['../etc', '../../usr', '/etc/passwd', 'foo/../bar'] as $bad) {
            $r = landing_library_clone_to_path($bad, 'fresh', 'p', '', false, $this->root, $this->clones);
            self::assertFalse($r['ok'], "expected reject for source: $bad");
            self::assertStringContainsString('Source slug', $r['err']);
        }
    }

    public function testSubstitutePostUrlDoesNotDoubleEncodeAmpersandForJsContext(): void
    {
        $html = 'var POST_URL = "{{POST_URL}}";';
        $out = landing_library_substitute_placeholders($html, 'https://h/track?a=1&b=2');
        self::assertStringContainsString('var POST_URL = "https://h/track?a=1&b=2";', $out);
        self::assertStringNotContainsString('&amp;', $out);
    }

    public function testSubstitutePostUrlEscapesAttrContext(): void
    {
        $html = '<form action="{{POST_URL_ATTR}}">';
        $out = landing_library_substitute_placeholders($html, 'a"><script>b');
        self::assertStringNotContainsString('"><script>', $out);
    }

    public function testSubstituteJsEscapesClosingScriptTagInjection(): void
    {
        $html = 'var X = "{{POST_URL}}";';
        $out = landing_library_substitute_placeholders($html, 'a</script>b');
        self::assertStringNotContainsString('</script>', $out);
    }

    public function testSubstituteJsEscapesBackslashAndQuote(): void
    {
        $html = 'var X = "{{POST_URL}}";';
        $out = landing_library_substitute_placeholders($html, 'a\\b"c');
        self::assertStringContainsString('var X = "a\\\\b\\"c";', $out);
    }

    public function testSubstituteStripsTrackerTagForBothAttrAndJsPlaceholders(): void
    {
        $h1 = '<body><script data-tracker src="{{TRACKER_URL}}"></script>x</body>';
        self::assertStringNotContainsString('data-tracker', landing_library_substitute_placeholders($h1, 'p'));
        $h2 = '<body><script data-tracker src="{{TRACKER_URL_ATTR}}"></script>x</body>';
        self::assertStringNotContainsString('data-tracker', landing_library_substitute_placeholders($h2, 'p'));
    }
}

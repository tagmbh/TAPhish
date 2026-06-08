<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the 2026-06-09 M365-login logo heal pass.
 *
 * Operators had cloned the m365-login library template while it still
 * shipped a literal "[Microsoft 365]" placeholder text. The library now
 * carries a proper M365 grid + wordmark SVG; the heal walks every
 * m365-login-* clone and patches the same SVG into existing index.html
 * files so already-launched landings stop showing literal bracket text.
 */
final class M365LogoHealTest extends TestCase
{
    private string $tmpRoot = '';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/m365heal-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        // Recursive rmdir
        $rrm = function (string $dir) use (&$rrm): void {
            if (!is_dir($dir)) return;
            foreach (new \DirectoryIterator($dir) as $f) {
                if ($f->isDot()) continue;
                $p = $f->getPathname();
                if ($f->isDir()) { $rrm($p); } else { @unlink($p); }
            }
            @rmdir($dir);
        };
        $rrm($this->tmpRoot);
    }

    private function libraryRoot(): string
    {
        return __DIR__ . '/../spear/sniperhost/library';
    }

    public function testLibraryItselfNoLongerContainsTheLiteralPlaceholder(): void
    {
        // The whole point of this PR: the source template ships a real SVG.
        $src = (string) @file_get_contents($this->libraryRoot() . '/m365-login/index.html');
        self::assertNotSame('', $src);
        self::assertStringContainsString('<svg', $src);
        self::assertStringContainsString('Microsoft', $src);
        self::assertStringNotContainsString('[Microsoft 365]', $src);
    }

    public function testHealReplacesPlaceholderInACloneWithLibrarySvg(): void
    {
        $clone = $this->tmpRoot . '/m365-login-test';
        @mkdir($clone, 0775, true);
        $html = '<!doctype html><div class="signin-logo">[Microsoft 365]</div>';
        file_put_contents($clone . '/index.html', $html);

        $touched = landing_library_heal_m365_logo($this->tmpRoot, $this->libraryRoot());
        self::assertSame(1, $touched);

        $after = (string) @file_get_contents($clone . '/index.html');
        self::assertStringContainsString('<svg', $after);
        self::assertStringNotContainsString('[Microsoft 365]', $after);
    }

    public function testHealIsIdempotent(): void
    {
        $clone = $this->tmpRoot . '/m365-login-test';
        @mkdir($clone, 0775, true);
        file_put_contents($clone . '/index.html', '<div class="signin-logo">[Microsoft 365]</div>');

        self::assertSame(1, landing_library_heal_m365_logo($this->tmpRoot, $this->libraryRoot()));
        // Second run touches nothing (placeholder no longer present)
        self::assertSame(0, landing_library_heal_m365_logo($this->tmpRoot, $this->libraryRoot()));
        // Third run also nothing
        self::assertSame(0, landing_library_heal_m365_logo($this->tmpRoot, $this->libraryRoot()));
    }

    public function testHealOnlyTouchesM365LoginSlugs(): void
    {
        // Other library clones may legitimately use a [Brand] string in
        // their own placeholder context. Only m365-login-* directories
        // should be touched.
        $m365  = $this->tmpRoot . '/m365-login-real';
        $sso   = $this->tmpRoot . '/sso-redirect-other';
        $vpn   = $this->tmpRoot . '/vpn-portal-other';
        foreach ([$m365, $sso, $vpn] as $d) {
            @mkdir($d, 0775, true);
            file_put_contents($d . '/index.html', '<div class="signin-logo">[Microsoft 365]</div>');
        }

        landing_library_heal_m365_logo($this->tmpRoot, $this->libraryRoot());

        self::assertStringNotContainsString('[Microsoft 365]', (string) file_get_contents($m365 . '/index.html'), 'm365 slug must be healed');
        self::assertStringContainsString('[Microsoft 365]', (string) file_get_contents($sso . '/index.html'), 'sso-redirect must NOT be touched');
        self::assertStringContainsString('[Microsoft 365]', (string) file_get_contents($vpn . '/index.html'), 'vpn-portal must NOT be touched');
    }

    public function testHealReturnsZeroOnEmptyClonesRoot(): void
    {
        self::assertSame(0, landing_library_heal_m365_logo($this->tmpRoot, $this->libraryRoot()));
    }

    public function testHealReturnsZeroWhenLibraryIsAlsoStillOnPlaceholder(): void
    {
        // Safety: never replace placeholder with placeholder, even if the
        // library template were rolled back. Use a fake library with the
        // OLD placeholder.
        $fakeLib = $this->tmpRoot . '/fake-library';
        @mkdir($fakeLib . '/m365-login', 0775, true);
        file_put_contents($fakeLib . '/m365-login/index.html', '<div class="signin-logo">[Microsoft 365]</div>');

        $clones = $this->tmpRoot . '/clones';
        @mkdir($clones . '/m365-login-c', 0775, true);
        file_put_contents($clones . '/m365-login-c/index.html', '<div class="signin-logo">[Microsoft 365]</div>');

        self::assertSame(0, landing_library_heal_m365_logo($clones, $fakeLib));
        // And the clone is untouched
        self::assertStringContainsString('[Microsoft 365]', (string) file_get_contents($clones . '/m365-login-c/index.html'));
    }

    public function testHealSkipsClonesWithoutThePlaceholder(): void
    {
        // Operator may have already manually edited their clone to add a
        // proper logo. We must NOT overwrite their custom work.
        $clone = $this->tmpRoot . '/m365-login-custom';
        @mkdir($clone, 0775, true);
        $custom = '<div class="signin-logo"><img src="custom-logo.png" alt="ACME"></div>';
        file_put_contents($clone . '/index.html', $custom);

        self::assertSame(0, landing_library_heal_m365_logo($this->tmpRoot, $this->libraryRoot()));
        self::assertSame($custom, (string) file_get_contents($clone . '/index.html'));
    }
}

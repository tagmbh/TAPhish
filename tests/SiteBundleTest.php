<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.55 — site-bundle builder. Uses a throwaway clones root with fixture
 * files (mirrors the LandingLibrary test pattern) and reads the produced zip
 * back to assert the file list + that {{POST_URL}} substitution was applied.
 */
final class SiteBundleTest extends TestCase
{
    private string $clones;

    protected function setUp(): void
    {
        $this->clones = sys_get_temp_dir() . '/taphish-bundle-' . bin2hex(random_bytes(4));
        mkdir($this->clones . '/m365-login/assets', 0775, true);
        file_put_contents(
            $this->clones . '/m365-login/index.html',
            '<form action="{{POST_URL}}"></form><script src="{{TRACKER_URL}}"></script>'
        );
        file_put_contents($this->clones . '/m365-login/assets/logo.png', "\x89PNG binary");
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->clones);
    }

    private static function rrmdir(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (scandir($d) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $d . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    public function testCollectFilesListsRelativePaths(): void
    {
        $files = site_bundle_collect_files($this->clones . '/m365-login');
        self::assertSame(['assets/logo.png', 'index.html'], $files);
    }

    public function testBuildSubstitutesAndZips(): void
    {
        $res = site_bundle_build('m365-login', 'https://ptbe.autodiscover.li/track.php', '', $this->clones);
        self::assertNotNull($res);
        self::assertSame(['assets/logo.png', 'index.html'], $res['files']);
        self::assertFileExists($res['path']);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($res['path']) === true);
        $html = $zip->getFromName('m365-login/index.html');
        $png  = $zip->getFromName('m365-login/assets/logo.png');
        $zip->close();
        @unlink($res['path']);

        self::assertStringContainsString('https://ptbe.autodiscover.li/track.php', $html);
        self::assertStringNotContainsString('{{POST_URL}}', $html);
        self::assertSame("\x89PNG binary", $png); // binary copied verbatim
    }

    public function testBadSlugOrMissingDirReturnsNull(): void
    {
        self::assertNull(site_bundle_build('Bad Slug', 'x', '', $this->clones));
        self::assertNull(site_bundle_build('does-not-exist', 'x', '', $this->clones));
    }
}

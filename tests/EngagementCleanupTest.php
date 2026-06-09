<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper coverage for the engagement cleanup CLI. The DB-touching
 * paths are integration-level and run live; the path-traversal guard and
 * the explicit-ids resolver are the ones we don't ever want to break.
 */
final class EngagementCleanupTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../spear/manager/engagement_cleanup.php';
    }

    // --- taphish_cleanup_safe_clone_path -----------------------------

    public function testSafeClonePathAcceptsCleanSlug(): void
    {
        self::assertSame(
            '/srv/cloned/m365-login-acme',
            taphish_cleanup_safe_clone_path('/srv/cloned', 'm365-login-acme')
        );
    }

    public function testSafeClonePathStripsTrailingSlashFromRoot(): void
    {
        self::assertSame(
            '/srv/cloned/foo',
            taphish_cleanup_safe_clone_path('/srv/cloned/', 'foo')
        );
    }

    public function testSafeClonePathRejectsDotDot(): void
    {
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', '..'));
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', '../etc'));
    }

    public function testSafeClonePathRejectsPathSeparators(): void
    {
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', 'foo/bar'));
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', 'foo\\bar'));
    }

    public function testSafeClonePathRejectsDotfilePrefix(): void
    {
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', '.htaccess'));
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', '.git'));
    }

    public function testSafeClonePathRejectsNullByte(): void
    {
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', "foo\0bar"));
    }

    public function testSafeClonePathRejectsEmptyAndDot(): void
    {
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', ''));
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', '   '));
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', '.'));
    }

    public function testSafeClonePathRejectsShellMetacharacters(): void
    {
        // The slug column is varchar — a hostile row could try shell-injection
        // shapes. The regex whitelist rejects anything outside [A-Za-z0-9._-].
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', 'foo;rm'));
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', 'foo bar'));
        self::assertNull(taphish_cleanup_safe_clone_path('/srv/cloned', 'foo$bar'));
    }

    // --- taphish_cleanup_rrmdir --------------------------------------

    public function testRrmdirRemovesNestedTree(): void
    {
        $root = sys_get_temp_dir() . '/cleanup-test-' . uniqid('', true);
        mkdir($root . '/a/b', 0700, true);
        file_put_contents($root . '/a/b/file.txt', 'x');
        file_put_contents($root . '/a/top.txt', 'y');

        self::assertTrue(taphish_cleanup_rrmdir($root));
        self::assertFalse(is_dir($root));
    }

    public function testRrmdirRefusesSymlinkRoot(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() unavailable');
        }
        $real = sys_get_temp_dir() . '/cleanup-real-' . uniqid('', true);
        mkdir($real);
        $link = $real . '-link';
        symlink($real, $link);

        self::assertFalse(taphish_cleanup_rrmdir($link));
        self::assertTrue(is_dir($real), 'real target untouched');

        @unlink($link);
        @rmdir($real);
    }
}

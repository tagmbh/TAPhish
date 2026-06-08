<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the 2026-06-09 stale-CSRF Safari freeze fix.
 *
 * If a client's window.TAPHISH_CSRF goes stale (multi-tab session rotation),
 * every dispatcher call returns 403, the JS polls again, and Safari freezes
 * under the console-error load. The fix:
 *
 *   - new "csrf_refresh" branch at the top of home_manager.php that returns
 *     the current session token. Session-gated (an unauthenticated attacker
 *     can never get a token), but bypasses csrf_require() — by definition we
 *     are recovering from a missing/stale token.
 *   - common_scripts.js intercepts dispatcher 403s, calls csrf_refresh once,
 *     replays the original request with the fresh token.
 *
 * The test pins the exact PHP wiring so a future cleanup can't accidentally
 * move the handler after csrf_require() — which would re-introduce the
 * deadlock.
 */
final class CsrfRefreshHandlerTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/../spear/manager/home_manager.php';
        self::$src = (string) @file_get_contents($path);
        if (self::$src === '') {
            self::markTestSkipped('home_manager.php not found');
        }
    }

    public function testCsrfRefreshHandlerExistsInDispatcher(): void
    {
        self::assertStringContainsString(
            "'csrf_refresh'",
            self::$src,
            'home_manager.php must handle the csrf_refresh action_type'
        );
    }

    public function testRefreshHandlerWiredBeforeCsrfRequireCall(): void
    {
        $refreshPos = strpos(self::$src, "'csrf_refresh'");
        $csrfReqPos = strpos(self::$src, 'csrf_require();');
        self::assertNotFalse($refreshPos);
        self::assertNotFalse($csrfReqPos);
        self::assertLessThan(
            $csrfReqPos,
            $refreshPos,
            'csrf_refresh handler MUST fire before csrf_require() — otherwise the very'
            . ' call that recovers from a stale token would itself be CSRF-rejected'
        );
    }

    public function testRefreshHandlerStillGatedBySession(): void
    {
        // The session check must happen BEFORE the csrf_refresh handler.
        // Otherwise an unauthenticated attacker could pull a session token
        // for any active session by simply POSTing {action_type:csrf_refresh}.
        $sessionPos = strpos(self::$src, 'isSessionValid');
        $refreshPos = strpos(self::$src, "'csrf_refresh'");
        self::assertNotFalse($sessionPos);
        self::assertLessThan(
            $refreshPos,
            $sessionPos,
            'session check MUST gate csrf_refresh (else unauthenticated attackers can pull tokens)'
        );
    }

    public function testRefreshHandlerReturnsTokenInJsonResponse(): void
    {
        // The response shape the JS expects: {result:'success', _csrf:'…'}.
        // Pin so a future refactor of the response key doesn't silently
        // break the auto-recovery on the JS side.
        self::assertMatchesRegularExpression(
            '/json_encode\(\s*\[\s*[\'"]result[\'"]\s*=>\s*[\'"]success[\'"]\s*,\s*[\'"]_csrf[\'"]\s*=>\s*csrf_token\(\)/i',
            self::$src,
            "refresh handler must echo {result:'success', _csrf: csrf_token()}"
        );
    }

    public function testRefreshHandlerExitsAfterEcho(): void
    {
        // The handler must `exit;` after echoing the token — otherwise the
        // request would fall through to csrf_require() and 403 the response
        // we just generated. Pin the exit.
        $refreshPos = strpos(self::$src, "'csrf_refresh'");
        $tail = substr(self::$src, $refreshPos, 600);
        // Must echo json_encode then exit;
        self::assertMatchesRegularExpression(
            '/echo\s+json_encode[^;]*;\s*exit;/is',
            $tail,
            'csrf_refresh branch must call exit; immediately after echoing the JSON response'
        );
    }

    public function testJsAutoRecoveryInstalledInCommonScripts(): void
    {
        $js = (string) @file_get_contents(__DIR__ . '/../spear/js/common_scripts.js');
        self::assertStringContainsString('csrf_refresh',     $js, 'JS must call the csrf_refresh action');
        self::assertStringContainsString('_taphishCsrfRetried', $js, 'JS must have a single-shot retry guard');
        self::assertStringContainsString('ajaxError',       $js, 'JS must install the ajaxError listener');
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    // --- prune_attempts --------------------------------------------------

    public function testPruneKeepsAttemptsWithinWindow(): void
    {
        $now = 1700000000;
        $res = login_throttle_prune_attempts(
            [$now - 100, $now - 10, $now - 600, $now - 250],
            $now,
            300
        );
        self::assertSame([$now - 250, $now - 100, $now - 10], $res);
    }

    public function testPruneEmpty(): void
    {
        self::assertSame([], login_throttle_prune_attempts([], 1700000000, 300));
    }

    public function testPruneSkipsNonNumeric(): void
    {
        $now = 1700000000;
        $res = login_throttle_prune_attempts([$now - 10, 'oops', null, $now - 5], $now, 300);
        self::assertSame([$now - 10, $now - 5], $res);
    }

    // --- should_block ----------------------------------------------------

    public function testShouldBlockWhenLockedOutInTheFuture(): void
    {
        $now = 1700000000;
        $state = ['attempts' => [], 'lockout_until' => $now + 60];
        $r = login_throttle_should_block($state, $now);
        self::assertTrue($r['blocked']);
        self::assertSame('locked_out', $r['reason']);
        self::assertSame(60, $r['retry_after']);
    }

    public function testShouldNotBlockWhenLockoutHasExpired(): void
    {
        $now = 1700000000;
        $state = ['attempts' => [], 'lockout_until' => $now - 1];
        self::assertFalse(login_throttle_should_block($state, $now)['blocked']);
    }

    public function testShouldBlockOnMaxAttemptsInWindow(): void
    {
        $now = 1700000000;
        $attempts = [];
        for ($i = 0; $i < 5; $i++) {
            $attempts[] = $now - $i;
        }
        $r = login_throttle_should_block(['attempts' => $attempts], $now, 5, 300);
        self::assertTrue($r['blocked']);
        self::assertSame('too_many_attempts', $r['reason']);
    }

    public function testShouldNotBlockBelowThreshold(): void
    {
        $now = 1700000000;
        $r = login_throttle_should_block(
            ['attempts' => [$now - 10, $now - 5]],
            $now, 5, 300
        );
        self::assertFalse($r['blocked']);
    }

    public function testShouldNotBlockWhenOldAttemptsExpireOutOfWindow(): void
    {
        $now = 1700000000;
        // 5 attempts but all > 300s ago — fall out of the window.
        $r = login_throttle_should_block(
            ['attempts' => [$now - 400, $now - 401, $now - 402, $now - 403, $now - 404]],
            $now, 5, 300
        );
        self::assertFalse($r['blocked']);
    }

    // --- record_failure --------------------------------------------------

    public function testRecordFailureAppendsTimestamp(): void
    {
        $now = 1700000000;
        $state = login_throttle_record_failure(
            ['attempts' => [$now - 10]],
            $now
        );
        self::assertSame([$now - 10, $now], $state['attempts']);
        self::assertSame(0, $state['lockout_until']);
    }

    public function testRecordFailureTriggersLockoutOnThresholdCross(): void
    {
        $now = 1700000000;
        $existing = [];
        for ($i = 1; $i <= 4; $i++) {
            $existing[] = $now - $i;
        }
        $state = login_throttle_record_failure(
            ['attempts' => $existing],
            $now,
            5,   // max
            300, // window
            900  // lockout
        );
        self::assertCount(5, $state['attempts']);
        self::assertSame($now + 900, $state['lockout_until']);
    }

    public function testRecordFailurePrunesOldAttemptsBeforeCount(): void
    {
        $now = 1700000000;
        // 4 old attempts + 1 in window. Without pruning we'd cross the
        // threshold; with pruning we shouldn't.
        $state = login_throttle_record_failure(
            ['attempts' => [$now - 500, $now - 500, $now - 500, $now - 500, $now - 10]],
            $now,
            5,
            300,
            900
        );
        self::assertSame(0, $state['lockout_until'], 'lockout should not fire — only 2 attempts are in window');
        self::assertSame([$now - 10, $now], $state['attempts']);
    }

    public function testRecordFailureWontShrinkActiveLockout(): void
    {
        $now = 1700000000;
        // Existing lockout extends to now+500. Recording one more failure
        // that crosses the threshold shouldn't shorten the lockout (it
        // would otherwise reset to now+900, which is fine here, but the
        // max() guarantees no shrinkage on smaller lockout windows).
        $state = login_throttle_record_failure(
            ['attempts' => [$now - 1, $now - 2, $now - 3, $now - 4], 'lockout_until' => $now + 1200],
            $now,
            5, 300, 900
        );
        self::assertGreaterThanOrEqual($now + 1200, $state['lockout_until']);
    }

    // --- clear -----------------------------------------------------------

    public function testClearResetsState(): void
    {
        self::assertSame(['attempts' => [], 'lockout_until' => 0], login_throttle_clear());
    }

    // --- client_ip extraction --------------------------------------------

    public function testClientIpPrefersXForwardedFor(): void
    {
        $ip = login_throttle_client_ip([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.1',
            'REMOTE_ADDR'          => '10.0.0.1',
        ]);
        self::assertSame('198.51.100.7', $ip);
    }

    public function testClientIpFallsBackToRemoteAddr(): void
    {
        self::assertSame('203.0.113.5', login_throttle_client_ip(['REMOTE_ADDR' => '203.0.113.5']));
    }

    public function testClientIpRejectsMalformed(): void
    {
        self::assertSame('unknown', login_throttle_client_ip([
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
            'REMOTE_ADDR'          => 'still-not-an-ip',
        ]));
    }

    public function testClientIpAcceptsIpv6(): void
    {
        self::assertSame('2001:db8::1', login_throttle_client_ip(['REMOTE_ADDR' => '2001:db8::1']));
    }
}

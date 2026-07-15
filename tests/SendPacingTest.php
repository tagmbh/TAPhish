<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards for the send-loop pacing config, so a malformed cadence can't crash
 * the cron mid-campaign (PHP 8 DivisionByZeroError / rand() ValueError).
 */
final class SendPacingTest extends TestCase
{
    public function testEmptyIntervalMeansNoDelay(): void
    {
        self::assertSame([0, 0], taphish_msg_interval_bounds_ms(''));
        self::assertSame([0, 0], taphish_msg_interval_bounds_ms(null));
    }

    public function testSingleValueIsFixedDelay(): void
    {
        self::assertSame([5000, 5000], taphish_msg_interval_bounds_ms('5'));
    }

    public function testRangeParsesToMillis(): void
    {
        self::assertSame([3000, 7000], taphish_msg_interval_bounds_ms('3-7'));
    }

    public function testReversedRangeIsReordered(): void
    {
        // Used to produce rand(7000, 3000) → ValueError (min > max) on PHP 8.
        self::assertSame([3000, 7000], taphish_msg_interval_bounds_ms('7-3'));
    }

    public function testNonNumericIntervalIsSafe(): void
    {
        self::assertSame([0, 0], taphish_msg_interval_bounds_ms('x-y'));
    }

    public function testBoundsAreUsableByRandWithoutError(): void
    {
        foreach (['', '5', '3-7', '7-3', 'garbage', '-'] as $iv) {
            [$lo, $hi] = taphish_msg_interval_bounds_ms($iv);
            self::assertLessThanOrEqual($hi, $lo);
            $n = rand($lo, $hi);          // must not throw
            self::assertGreaterThanOrEqual($lo, $n);
        }
    }

    public function testAntifloodLimitSanitises(): void
    {
        self::assertSame(0, taphish_antiflood_limit_sane(0));
        self::assertSame(0, taphish_antiflood_limit_sane(-4));
        self::assertSame(0, taphish_antiflood_limit_sane('abc'));
        self::assertSame(0, taphish_antiflood_limit_sane(null));
        self::assertSame(25, taphish_antiflood_limit_sane('25'));
        self::assertSame(25, taphish_antiflood_limit_sane(25));
    }
}

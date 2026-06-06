<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class DashboardMetricsTest extends TestCase
{
    public function testOpenRateBasic(): void
    {
        $m = taphish_home_metrics_rates(100, 40);
        self::assertSame(100, $m['sent']);
        self::assertSame(40, $m['opened']);
        self::assertSame(40.0, $m['open_rate']);
        // click_rate omitted entirely when no captured count is supplied (forward-compat)
        self::assertArrayNotHasKey('click_rate', $m);
        self::assertArrayNotHasKey('captured', $m);
    }

    public function testOpenRateRoundsToOneDecimal(): void
    {
        self::assertSame(33.3, taphish_home_metrics_rates(3, 1)['open_rate']);
        self::assertSame(66.7, taphish_home_metrics_rates(3, 2)['open_rate']);
    }

    public function testZeroSentYieldsNullRateNotDivByZero(): void
    {
        $m = taphish_home_metrics_rates(0, 0);
        self::assertNull($m['open_rate']); // JS renders null as "—"
        self::assertSame(0, $m['sent']);
    }

    public function testClickRateIncludedWhenCapturedProvided(): void
    {
        $m = taphish_home_metrics_rates(100, 40, 12);
        self::assertSame(12, $m['captured']);
        self::assertSame(12.0, $m['click_rate']);
        self::assertSame(40.0, $m['open_rate']);
    }

    public function testClickRateNullWhenNothingSent(): void
    {
        $m = taphish_home_metrics_rates(0, 0, 0);
        self::assertNull($m['click_rate']);
        self::assertNull($m['open_rate']);
        self::assertSame(0, $m['captured']);
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class CampaignCompletionTest extends TestCase
{
    // --- auto_complete_should_trigger -------------------------------------

    public function testTriggersAt100PercentEngagement(): void
    {
        self::assertTrue(auto_complete_should_trigger(10, 10, 100));
    }

    public function testTriggersAboveThreshold(): void
    {
        self::assertTrue(auto_complete_should_trigger(8, 10, 75));
        self::assertTrue(auto_complete_should_trigger(75, 100, 75));
    }

    public function testDoesNotTriggerBelowThreshold(): void
    {
        self::assertFalse(auto_complete_should_trigger(7, 10, 75));
        self::assertFalse(auto_complete_should_trigger(0, 10, 75));
    }

    public function testZeroThresholdDisablesCheck(): void
    {
        self::assertFalse(auto_complete_should_trigger(10, 10, 0));
        self::assertFalse(auto_complete_should_trigger(100, 100, 0));
    }

    public function testNegativeThresholdDisables(): void
    {
        self::assertFalse(auto_complete_should_trigger(10, 10, -5));
    }

    public function testZeroTotalNeverTriggers(): void
    {
        // Don't complete an empty campaign — that's a config bug, not a win.
        self::assertFalse(auto_complete_should_trigger(0, 0, 100));
        self::assertFalse(auto_complete_should_trigger(0, 0, 1));
    }

    public function testRejectsImpossibleOpenedCount(): void
    {
        self::assertFalse(auto_complete_should_trigger(-1, 10, 50));
        self::assertFalse(auto_complete_should_trigger(11, 10, 50));
    }

    public function testHandlesLargeNumbers(): void
    {
        self::assertTrue(auto_complete_should_trigger(50000, 100000, 50));
        self::assertFalse(auto_complete_should_trigger(49999, 100000, 50));
    }

    public function testRoundingAtBoundary(): void
    {
        // 7/10 = 70%. At threshold 70 → trigger. At 71 → no.
        self::assertTrue(auto_complete_should_trigger(7, 10, 70));
        self::assertFalse(auto_complete_should_trigger(7, 10, 71));
    }

    // --- auto_complete_clamp_threshold ------------------------------------

    public function testClampReturnsDefaultOnNonNumeric(): void
    {
        self::assertSame(100, auto_complete_clamp_threshold(null));
        self::assertSame(100, auto_complete_clamp_threshold(''));
        self::assertSame(100, auto_complete_clamp_threshold('abc'));
    }

    public function testClampAcceptsValidIntegers(): void
    {
        self::assertSame(50, auto_complete_clamp_threshold(50));
        self::assertSame(0, auto_complete_clamp_threshold(0));
        self::assertSame(100, auto_complete_clamp_threshold(100));
    }

    public function testClampAcceptsNumericStrings(): void
    {
        self::assertSame(75, auto_complete_clamp_threshold('75'));
        self::assertSame(75, auto_complete_clamp_threshold('75.4'));
    }

    public function testClampsOutOfRange(): void
    {
        self::assertSame(100, auto_complete_clamp_threshold(150));
        self::assertSame(0, auto_complete_clamp_threshold(-20));
        self::assertSame(100, auto_complete_clamp_threshold(99999));
    }

    // --- auto_complete_canonical_metric (Phase 3.15) ---------------------

    public function testCanonicalMetricAcceptsAllowedValues(): void
    {
        self::assertSame('opens', auto_complete_canonical_metric('opens'));
        self::assertSame('opens_clicks', auto_complete_canonical_metric('opens_clicks'));
        self::assertSame('opens_clicks_submits', auto_complete_canonical_metric('opens_clicks_submits'));
    }

    public function testCanonicalMetricNormalizesUnknownAndMissing(): void
    {
        self::assertSame('opens', auto_complete_canonical_metric(null));
        self::assertSame('opens', auto_complete_canonical_metric(''));
        self::assertSame('opens', auto_complete_canonical_metric('clicks_only'));
        self::assertSame('opens', auto_complete_canonical_metric(42));
    }

    // --- auto_complete_signals_for_metric --------------------------------

    public function testSignalsForOpensOnly(): void
    {
        self::assertSame(
            ['opens' => true, 'clicks' => false, 'submits' => false],
            auto_complete_signals_for_metric('opens')
        );
    }

    public function testSignalsForOpensClicks(): void
    {
        self::assertSame(
            ['opens' => true, 'clicks' => true, 'submits' => false],
            auto_complete_signals_for_metric('opens_clicks')
        );
    }

    public function testSignalsForFullMetric(): void
    {
        self::assertSame(
            ['opens' => true, 'clicks' => true, 'submits' => true],
            auto_complete_signals_for_metric('opens_clicks_submits')
        );
    }

    public function testSignalsForUnknownFallsBackToOpens(): void
    {
        self::assertSame(
            ['opens' => true, 'clicks' => false, 'submits' => false],
            auto_complete_signals_for_metric('nonsense')
        );
    }
}

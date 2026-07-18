<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The dispatcher-side campaign map that feeds taphish_analytics_build: each
 * campaign becomes a "wave" (its name) in a "cohort" (its user-group name), so
 * by-wave = per-send and by-cohort = per-group work generically for ANY
 * engagement — no engagement-specific name parsing.
 */
final class AnalyticsCampaignMapTest extends TestCase
{
    public function testMapsNameToWaveAndGroupToCohort(): void
    {
        $rows = [
            ['campaign_id' => 'c1', 'campaign_name' => 'K1 M365', 'user_group_name' => 'Cohort A'],
            ['campaign_id' => 'c2', 'campaign_name' => 'K2 HR', 'user_group_name' => 'Cohort B'],
        ];
        $map = taphish_analytics_campaign_map($rows);
        self::assertSame('K1 M365', $map['c1']['wave']);
        self::assertSame('Cohort A', $map['c1']['cohort']);
        self::assertSame('c1', $map['c1']['slot']);
        self::assertSame('K2 HR', $map['c2']['wave']);
        self::assertSame('Cohort B', $map['c2']['cohort']);
    }

    public function testMissingGroupFallsBackToQuestionMark(): void
    {
        $map = taphish_analytics_campaign_map([
            ['campaign_id' => 'c9', 'campaign_name' => 'Solo', 'user_group_name' => ''],
        ]);
        self::assertSame('Solo', $map['c9']['wave']);
        self::assertSame('?', $map['c9']['cohort']);
    }

    public function testEmptyInputEmptyMap(): void
    {
        self::assertSame([], taphish_analytics_campaign_map([]));
    }
}

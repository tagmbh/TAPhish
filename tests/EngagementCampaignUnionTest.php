<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P1.3 — the engagement hub lists ALL of an engagement's work, not just mail
 * campaigns: mail campaigns + web trackers + quick trackers, each tagged with a
 * `type` so the client can render a Type badge and the right dashboard link.
 *
 * taphish_engagement_campaigns_normalize is the pure merge: it tags + normalises
 * each type's rows and concatenates them (mail, then web, then quick — each
 * already date-desc from its SQL ORDER BY). Cross-type date sorting is left to
 * the client DataTable, so this stays free of tz/format parsing.
 */
final class EngagementCampaignUnionTest extends TestCase
{
    public function testTagsEachTypeAndConcatenatesInOrder(): void
    {
        $out = taphish_engagement_campaigns_normalize(
            [['campaign_id' => 'm1', 'campaign_name' => 'Mail One', 'camp_status' => '2', 'scheduled_time' => 't', 'date' => 'd1']],
            [['tracker_id' => 'w1', 'tracker_name' => 'Web One', 'active' => 1, 'date' => 'd2']],
            [['tracker_id' => 'q1', 'tracker_name' => 'Quick One', 'active' => 0, 'date' => 'd3']]
        );
        self::assertSame(['mail', 'web', 'quick'], array_column($out, 'type'));
        self::assertSame(['m1', 'w1', 'q1'], array_column($out, 'id'));
        self::assertSame(['Mail One', 'Web One', 'Quick One'], array_column($out, 'name'));
    }

    public function testPreservesTypeSpecificFields(): void
    {
        $out = taphish_engagement_campaigns_normalize(
            [['campaign_id' => 'm1', 'campaign_name' => 'M', 'camp_status' => '4', 'scheduled_time' => 'ts', 'date' => 'd']],
            [['tracker_id' => 'w1', 'tracker_name' => 'W', 'active' => 1, 'date' => 'd']],
            []
        );
        self::assertSame('4', $out[0]['camp_status']);
        self::assertSame('ts', $out[0]['scheduled_time']);
        self::assertSame(1, $out[1]['active']);
    }

    public function testCoercesIdAndNameToStringActiveToInt(): void
    {
        $out = taphish_engagement_campaigns_normalize(
            [],
            [['tracker_id' => 123, 'tracker_name' => 456, 'active' => '1', 'date' => 'd']],
            []
        );
        self::assertSame('123', $out[0]['id']);
        self::assertSame('456', $out[0]['name']);
        self::assertSame(1, $out[0]['active']);
    }

    public function testEmptyInputsYieldEmpty(): void
    {
        self::assertSame([], taphish_engagement_campaigns_normalize([], [], []));
    }

    public function testEveryRowHasTheCommonShape(): void
    {
        $out = taphish_engagement_campaigns_normalize(
            [['campaign_id' => 'm', 'campaign_name' => 'M', 'camp_status' => '0', 'date' => 'd']],
            [['tracker_id' => 'w', 'tracker_name' => 'W', 'active' => 0, 'date' => 'd']],
            [['tracker_id' => 'q', 'tracker_name' => 'Q', 'active' => 1, 'date' => 'd']]
        );
        foreach ($out as $row) {
            self::assertArrayHasKey('type', $row);
            self::assertArrayHasKey('id', $row);
            self::assertArrayHasKey('name', $row);
            self::assertArrayHasKey('date', $row);
        }
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * R2.2 — pure tests for the campaign-list engagement filter + annotation.
 * The DB SELECT / dispatcher wiring is exercised structurally elsewhere.
 */
final class CampaignFilterTest extends TestCase
{
    private function rows(): array
    {
        return [
            ['campaign_id' => 'a', 'engagement_id' => 3],
            ['campaign_id' => 'b', 'engagement_id' => 3],
            ['campaign_id' => 'c', 'engagement_id' => 7],
            ['campaign_id' => 'd', 'engagement_id' => null], // unscoped/legacy
        ];
    }

    public function testFilterByEngagementKeepsOnlyMatching(): void
    {
        $out = taphish_campaigns_filter_by_engagement($this->rows(), 3);
        self::assertSame(['a', 'b'], array_column($out, 'campaign_id'));
    }

    public function testFilterZeroOrNullReturnsAll(): void
    {
        self::assertCount(4, taphish_campaigns_filter_by_engagement($this->rows(), 0));
        self::assertCount(4, taphish_campaigns_filter_by_engagement($this->rows(), null));
    }

    public function testFilterUnknownEngagementReturnsEmpty(): void
    {
        self::assertSame([], taphish_campaigns_filter_by_engagement($this->rows(), 999));
    }

    public function testFilterReindexesResult(): void
    {
        // filtering must not leave holes in the array keys (JSON would emit an object)
        $out = taphish_campaigns_filter_by_engagement($this->rows(), 7);
        self::assertSame([0], array_keys($out));
    }

    public function testAnnotateAddsEngagementNameFromMap(): void
    {
        $map = [3 => 'Example Org — Awareness 2026', 7 => 'Acme Q3'];
        $out = taphish_campaigns_annotate_engagement($this->rows(), $map);
        self::assertSame('Example Org — Awareness 2026', $out[0]['engagement_name']);
        self::assertSame('Acme Q3', $out[2]['engagement_name']);
    }

    public function testAnnotateUnscopedRowGetsEmptyName(): void
    {
        $out = taphish_campaigns_annotate_engagement($this->rows(), [3 => 'X']);
        self::assertSame('', $out[3]['engagement_name']); // engagement_id null => ''
    }

    public function testAnnotateUnknownIdFallsBackToHashId(): void
    {
        $out = taphish_campaigns_annotate_engagement($this->rows(), []); // empty map
        self::assertSame('#3', $out[0]['engagement_name']);
    }
}

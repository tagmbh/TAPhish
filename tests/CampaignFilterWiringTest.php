<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * R2.2 — structural guards for the campaign-list engagement filter wiring.
 * The pure filter/annotate logic is covered by CampaignFilterTest.
 */
final class CampaignFilterWiringTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testManagerSelectsEngagementIdAndFilters(): void
    {
        $m = $this->f('manager/mail_campaign_manager.php');
        self::assertStringContainsString('campaign_filters.php', $m, 'loads the pure helpers');
        self::assertStringContainsString('camp_status,engagement_id FROM tb_core_mailcamp_list', $m, 'SELECT carries engagement_id');
        self::assertStringContainsString('taphish_campaigns_annotate_engagement', $m, 'annotates rows');
        self::assertStringContainsString('taphish_campaigns_filter_by_engagement', $m, 'scopes to engagement');
        self::assertStringContainsString('getCampaignList($conn, isset($POSTJ[\'engagement_id\'])', $m, 'dispatch passes the filter');
    }

    public function testClientPassesFilterAndPopulatesIt(): void
    {
        $js = $this->f('js/mail_campaign.js');
        self::assertStringContainsString('engagement_id: parseInt($(\'#campaign_engagement_filter\')', $js, 'list request scoped');
        self::assertStringContainsString('function pullEngagementFilter', $js, 'populates the filter');
        self::assertStringContainsString('.on(\'change\', function () { loadTableCampaignList', $js, 'change reloads the list');
    }

    public function testPageHasTheFilterSelect(): void
    {
        $page = $this->f('MailCampaignList.php');
        self::assertStringContainsString('id="campaign_engagement_filter"', $page, 'filter select present');
        self::assertStringContainsString('All engagements', $page, 'default "all" option');
    }
}

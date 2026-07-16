<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P1.1 — the campaign builder must let the operator scope a campaign to an
 * engagement, and must actually SEND that engagement_id on save (the server
 * already persists it in saveMailCampaignAction, both INSERT and UPDATE paths).
 * These guards lock the client wiring so it can't silently regress to the old
 * "engagement_id always NULL" behaviour that made non-wizard campaigns invisible
 * in the Engagements hub.
 */
final class EngagementSelectorTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = dirname(__DIR__) . '/spear/' . $rel;
        self::assertFileExists($path);
        return file_get_contents($path);
    }

    public function testBuilderFormHasEngagementSelector(): void
    {
        self::assertStringContainsString(
            'id="engagementSelector"',
            $this->read('MailCampaignList.php'),
            'Campaign builder form must expose an engagement selector'
        );
    }

    public function testBuilderPopulatesEngagementsFromList(): void
    {
        self::assertStringContainsString(
            'list_engagements',
            $this->read('js/mail_campaign.js'),
            'Builder must populate the engagement selector from list_engagements'
        );
    }

    public function testSavePayloadSendsEngagementId(): void
    {
        // Within the save_campaign_list payload, engagement_id must be sent.
        self::assertMatchesRegularExpression(
            '/save_campaign_list[\s\S]{0,500}engagement_id/',
            $this->read('js/mail_campaign.js'),
            'save_campaign_list payload must include engagement_id'
        );
    }
}

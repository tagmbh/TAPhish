<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Locks the single canonical campaign-status decoder (spear/js/camp_status.js).
 *
 * Before this, mail_campaign.js and engagement_view.js each decoded camp_status
 * independently and mail_campaign.js's switch had NO case for 5 (tz-deferred),
 * so deferred campaigns rendered an "undefined" badge. The authoritative codes
 * are SET by the scheduler (spear/core/mail_campaign_cron.php):
 *   0 draft · 1 scheduled · 2 in-progress · 3 completed/stopped ·
 *   4 mail sent+tracking · 5 tz-deferred. (6 is never set — reserved.)
 *
 * These tests guard both the completeness of the map and that the two former
 * decoders now delegate to it, so the decoders can't diverge again.
 */
final class CampStatusDecoderTest extends TestCase
{
    private function js(string $rel): string
    {
        $path = dirname(__DIR__) . '/spear/js/' . $rel;
        self::assertFileExists($path, "$rel must exist");
        return file_get_contents($path);
    }

    public function testCanonicalMapCoversEveryRealStatusCode(): void
    {
        $src = $this->js('camp_status.js');
        // Every code the scheduler can set (0..5) must have a map entry with a label.
        foreach (range(0, 5) as $code) {
            self::assertMatchesRegularExpression(
                '/\b' . $code . ':\s*\{[^}]*label:/',
                $src,
                "camp_status.js is missing a map entry for status code $code"
            );
        }
    }

    public function testDeferredStatusIsLabelledNotUndefined(): void
    {
        // The actual bug: status 5 fell through mail_campaign.js's switch → undefined.
        self::assertMatchesRegularExpression(
            "/5:\s*\{[^}]*label:\s*'Deferred'/",
            $this->js('camp_status.js'),
            'Status 5 (tz-deferred) must carry a real label, not fall through to undefined'
        );
    }

    public function testUnknownCodeHasSafeFallback(): void
    {
        // An unexpected/future code must degrade gracefully, never throw/blank.
        self::assertStringContainsString(
            "'unknown'",
            $this->js('camp_status.js'),
            'Decoder must have a fallback entry for unrecognised status codes'
        );
    }

    public function testMailCampaignListDelegatesToCanonicalDecoder(): void
    {
        $src = $this->js('mail_campaign.js');
        self::assertStringContainsString('campStatus.badge(', $src, 'mail_campaign.js must render status via the canonical decoder');
        self::assertStringNotContainsString(
            'switch (value.camp_status)',
            $src,
            'mail_campaign.js must not keep its own (incomplete) camp_status switch'
        );
    }

    public function testEngagementViewDelegatesToCanonicalDecoder(): void
    {
        self::assertStringContainsString(
            'campStatus.label(',
            $this->js('engagement_view.js'),
            'engagement_view.js must decode camp_status via the canonical decoder'
        );
    }
}

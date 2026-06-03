<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.47: pure-reducer tests for the engagement report.
 *
 * DB facade + TCPDF render need mysqli + the lib bundle, integration
 * tier territory. Everything here runs offline.
 */
final class EngagementReportTest extends TestCase
{
    // ---- recipient counts by domain --------------------------------------

    public function testRecipientCountsBucketsByDomain(): void
    {
        $out = engagement_report_recipient_counts_by_domain([
            'a@acme.com', 'b@acme.com', 'c@other.com', 'd@acme.com',
        ]);
        self::assertSame([
            ['domain' => 'acme.com',  'count' => 3],
            ['domain' => 'other.com', 'count' => 1],
        ], $out);
    }

    public function testRecipientCountsStripsDisplayNames(): void
    {
        $out = engagement_report_recipient_counts_by_domain([
            'Alice <a@acme.com>',
            'Bob <b@acme.com>',
        ]);
        self::assertSame(2, $out[0]['count']);
        self::assertSame('acme.com', $out[0]['domain']);
    }

    public function testRecipientCountsLowercasesDomain(): void
    {
        $out = engagement_report_recipient_counts_by_domain([
            'a@ACME.COM', 'b@Acme.Com',
        ]);
        self::assertSame('acme.com', $out[0]['domain']);
        self::assertSame(2, $out[0]['count']);
    }

    public function testRecipientCountsSortsByCountThenDomain(): void
    {
        $out = engagement_report_recipient_counts_by_domain([
            'a@b.com', 'a@a.com', 'b@b.com', 'c@a.com', 'a@c.com',
        ]);
        // a.com=2, b.com=2, c.com=1 → a.com before b.com (alphabetical tiebreak), c.com last
        self::assertSame('a.com', $out[0]['domain']);
        self::assertSame('b.com', $out[1]['domain']);
        self::assertSame('c.com', $out[2]['domain']);
    }

    public function testRecipientCountsIgnoresMalformedAddresses(): void
    {
        $out = engagement_report_recipient_counts_by_domain([
            '', '   ', 'not-an-email', '@nodomain', 'a@',
        ]);
        self::assertSame([], $out);
    }

    // ---- capture timeline -----------------------------------------------

    public function testCaptureTimelineBucketsByUtcDate(): void
    {
        $rows = [
            ['time' => 1735_689_600_000], // 2025-01-01 UTC
            ['time' => 1735_689_600_000],
            ['time' => 1735_776_000_000], // 2025-01-02 UTC
        ];
        $out = engagement_report_capture_timeline($rows);
        self::assertCount(2, $out);
        self::assertSame('2025-01-01', $out[0]['date']);
        self::assertSame(2, $out[0]['count']);
        self::assertSame('2025-01-02', $out[1]['date']);
        self::assertSame(1, $out[1]['count']);
    }

    public function testCaptureTimelineExcludesScannersByDefault(): void
    {
        $rows = [
            ['time' => 1, 'is_scanner' => 0],
            ['time' => 1, 'is_scanner' => 1],
            ['time' => 1, 'is_scanner' => 1],
        ];
        $out = engagement_report_capture_timeline($rows);
        self::assertSame(1, $out[0]['count']);
    }

    public function testCaptureTimelineCanIncludeScanners(): void
    {
        $rows = [
            ['time' => 1, 'is_scanner' => 1],
            ['time' => 1, 'is_scanner' => 0],
        ];
        $out = engagement_report_capture_timeline($rows, false);
        self::assertSame(2, $out[0]['count']);
    }

    public function testCaptureTimelineCountsRowsWith2fa(): void
    {
        $rows = [
            ['time' => 1, 'code_2fa' => '123456'],
            ['time' => 1, 'is_2fa_capture' => 1],
            ['time' => 1, 'code_2fa' => ''],
        ];
        $out = engagement_report_capture_timeline($rows);
        self::assertSame(3, $out[0]['count']);
        self::assertSame(2, $out[0]['with_2fa']);
    }

    public function testCaptureTimelineSkipsZeroTimestamps(): void
    {
        $rows = [['time' => 0], ['time' => 1735_689_600_000]];
        $out = engagement_report_capture_timeline($rows);
        self::assertCount(1, $out);
    }

    // ---- scanner breakdown ----------------------------------------------

    public function testScannerBreakdownGroupsByVendor(): void
    {
        $rows = [
            ['is_scanner' => 1, 'scanner_reason' => 'SafeLinks'],
            ['is_scanner' => 1, 'scanner_reason' => 'SafeLinks'],
            ['is_scanner' => 1, 'scanner_reason' => 'Proofpoint'],
            ['is_scanner' => 0, 'scanner_reason' => 'Should not count'],
        ];
        $out = engagement_report_scanner_breakdown($rows);
        self::assertSame([
            ['vendor' => 'SafeLinks',  'count' => 2],
            ['vendor' => 'Proofpoint', 'count' => 1],
        ], $out);
    }

    public function testScannerBreakdownBucketsBlankAsUnclassified(): void
    {
        $rows = [
            ['is_scanner' => 1, 'scanner_reason' => ''],
            ['is_scanner' => 1, 'scanner_reason' => null],
        ];
        $out = engagement_report_scanner_breakdown($rows);
        self::assertSame('unclassified', $out[0]['vendor']);
        self::assertSame(2, $out[0]['count']);
    }

    // ---- 2FA summary ----------------------------------------------------

    public function testTwoFaSummaryCountsCapturesAndDistinctUsers(): void
    {
        $rows = [
            ['tracker_id' => 'T1', 'rid' => 'r1', 'code_2fa' => '123456'],
            ['tracker_id' => 'T1', 'rid' => 'r1', 'code_2fa' => '654321'], // same user, second submit
            ['tracker_id' => 'T1', 'rid' => 'r2', 'is_2fa_capture' => 1],
            ['tracker_id' => 'T1', 'rid' => 'r3', 'code_2fa' => ''],
        ];
        $out = engagement_report_2fa_summary($rows);
        self::assertSame(4, $out['total_captures']);
        self::assertSame(3, $out['with_2fa']);          // 3 rows had 2FA markers
        self::assertSame(2, $out['distinct_users_with_2fa']); // r1, r2 (r3 has no code)
    }

    public function testTwoFaSummaryCountsRepeatWebhooks(): void
    {
        $rows = [
            ['code_2fa' => '1', 'repeat_webhook_sent' => 1],
            ['code_2fa' => '2', 'repeat_webhook_sent' => 0],
        ];
        $out = engagement_report_2fa_summary($rows);
        self::assertSame(1, $out['repeat_webhooks_fired']);
    }

    public function testTwoFaSummaryHandlesEmptyInput(): void
    {
        $out = engagement_report_2fa_summary([]);
        self::assertSame(0, $out['total_captures']);
        self::assertSame(0, $out['with_2fa']);
        self::assertSame(0, $out['distinct_users_with_2fa']);
        self::assertSame(0, $out['repeat_webhooks_fired']);
    }

    // ---- sender posture summary -----------------------------------------

    public function testSenderPostureSummaryPicksRecommendationVerdict(): void
    {
        $out = engagement_report_sender_posture_summary([
            'acme.com' => ['recommendation' => ['verdict' => 'hardened', 'message' => 'p=reject']],
            'other.com' => ['verdict' => 'wide-open', 'note' => 'no DMARC'],
        ]);
        self::assertSame('acme.com', $out[0]['domain']);
        self::assertSame('hardened', $out[0]['verdict']);
        self::assertSame('p=reject', $out[0]['note']);
        self::assertSame('other.com', $out[1]['domain']);
        self::assertSame('wide-open', $out[1]['verdict']);
    }

    public function testSenderPostureSummaryHandlesEmpty(): void
    {
        self::assertSame([], engagement_report_sender_posture_summary([]));
    }
}

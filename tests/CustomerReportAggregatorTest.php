<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class CustomerReportAggregatorTest extends TestCase
{
    // ---- customer_report_format_pct -----------------------------------

    public function testFormatPctHappy(): void
    {
        self::assertSame('1 / 4 (25.0%)', customer_report_format_pct(1, 4));
        self::assertSame('12 / 45 (26.7%)', customer_report_format_pct(12, 45));
    }

    public function testFormatPctZeroDenominator(): void
    {
        self::assertSame('0 / 0 (—)', customer_report_format_pct(0, 0));
        self::assertSame('5 / 0 (—)', customer_report_format_pct(5, 0));
    }

    public function testFormatPctFullAndNone(): void
    {
        self::assertSame('10 / 10 (100.0%)', customer_report_format_pct(10, 10));
        self::assertSame('0 / 10 (0.0%)', customer_report_format_pct(0, 10));
    }

    // ---- customer_report_parse_open_times -----------------------------

    public function testParseOpensFromJsonString(): void
    {
        $r = customer_report_parse_open_times('[1748432000000, 1748432500000]');
        self::assertSame([1748432000000, 1748432500000], $r);
    }

    public function testParseOpensFromArray(): void
    {
        $r = customer_report_parse_open_times([100, '200', 300]);
        self::assertSame([100, 200, 300], $r);
    }

    public function testParseOpensFromEmpty(): void
    {
        self::assertSame([], customer_report_parse_open_times(null));
        self::assertSame([], customer_report_parse_open_times(''));
        self::assertSame([], customer_report_parse_open_times('[]'));
        self::assertSame([], customer_report_parse_open_times([]));
    }

    public function testParseOpensSkipsNonNumeric(): void
    {
        self::assertSame([100, 200], customer_report_parse_open_times([100, 'oops', 200, null]));
    }

    public function testParseOpensFromMalformedJson(): void
    {
        self::assertSame([], customer_report_parse_open_times('not-json'));
    }

    // ---- customer_report_compute_kpis ---------------------------------

    public function testKpisOnEmptySet(): void
    {
        $k = customer_report_compute_kpis([]);
        self::assertSame(0, $k['recipients']);
        self::assertSame(0, $k['sent']);
        self::assertSame(0, $k['opened']);
        self::assertSame('0 / 0 (—)', $k['send_success_rate']);
        self::assertSame('0 / 0 (—)', $k['open_rate_of_sent']);
    }

    public function testKpisOnTypicalCampaign(): void
    {
        $rows = [
            ['sending_status' => 2, 'mail_open_times' => '[100,200]'], // sent + opened (2 opens)
            ['sending_status' => 2, 'mail_open_times' => '[300]'],     // sent + opened (1 open)
            ['sending_status' => 2, 'mail_open_times' => null],        // sent, not opened
            ['sending_status' => 3, 'mail_open_times' => null],        // failed
            ['sending_status' => 1, 'mail_open_times' => null],        // in-progress
        ];
        $k = customer_report_compute_kpis($rows);
        self::assertSame(5, $k['recipients']);
        self::assertSame(3, $k['sent']);
        self::assertSame(1, $k['failed']);
        self::assertSame(1, $k['in_progress']);
        self::assertSame(2, $k['opened']);
        self::assertSame(3, $k['total_opens']);
        self::assertSame('3 / 5 (60.0%)', $k['send_success_rate']);
        self::assertSame('2 / 3 (66.7%)', $k['open_rate_of_sent']);
        self::assertSame('2 / 5 (40.0%)', $k['open_rate_of_total']);
    }

    public function testKpisIgnoresUnknownStatus(): void
    {
        $rows = [['sending_status' => 99, 'mail_open_times' => null]];
        $k = customer_report_compute_kpis($rows);
        self::assertSame(1, $k['recipients']);
        self::assertSame(0, $k['sent']);
        self::assertSame(0, $k['failed']);
        self::assertSame(0, $k['in_progress']);
    }

    // ---- customer_report_recipient_rows -------------------------------

    public function testRecipientRowsProjectsExpectedFields(): void
    {
        $rows = [[
            'user_email'     => 'alice@example.com',
            'user_name'      => 'Alice',
            'sending_status' => 2,
            'send_time'      => '1700000000000',
            'mail_open_times' => '[1700000100000, 1700000200000]',
        ]];
        $out = customer_report_recipient_rows($rows);
        self::assertCount(1, $out);
        $r = $out[0];
        self::assertSame('alice@example.com', $r['email']);
        self::assertSame('Alice', $r['name']);
        self::assertSame('Sent', $r['status']);
        self::assertSame(1700000000000, $r['send_time_ms']);
        self::assertSame(1700000100000, $r['first_open_ms']);
        self::assertSame(2, $r['open_count']);
    }

    public function testRecipientRowsSortsOpenersFirstThenSent(): void
    {
        $rows = [
            ['user_email' => 'failed@x',     'sending_status' => 3, 'mail_open_times' => null],
            ['user_email' => 'late-opener@x','sending_status' => 2, 'mail_open_times' => '[300]'],
            ['user_email' => 'early-open@x', 'sending_status' => 2, 'mail_open_times' => '[100]'],
            ['user_email' => 'unopened@x',   'sending_status' => 2, 'mail_open_times' => null],
            ['user_email' => 'inprog@x',     'sending_status' => 1, 'mail_open_times' => null],
        ];
        $emails = array_column(customer_report_recipient_rows($rows), 'email');
        self::assertSame(
            ['early-open@x', 'late-opener@x', 'unopened@x', 'inprog@x', 'failed@x'],
            $emails
        );
    }

    public function testRecipientRowsHandlesMissingFields(): void
    {
        $out = customer_report_recipient_rows([['sending_status' => 2]]);
        self::assertSame('', $out[0]['email']);
        self::assertSame('', $out[0]['name']);
        self::assertNull($out[0]['send_time_ms']);
        self::assertNull($out[0]['first_open_ms']);
    }

    // ---- customer_report_format_timestamp -----------------------------

    public function testFormatTimestamp(): void
    {
        // 1779625920000 ms = 2026-05-24 12:32 UTC per gmdate('Y-m-d H:i', ts/1000)
        self::assertSame('2026-05-24 12:32 UTC', customer_report_format_timestamp(1779625920000));
    }

    public function testFormatTimestampNull(): void
    {
        self::assertSame('—', customer_report_format_timestamp(null));
    }

    // Phase 3.45a: scanner-aware KPI computation.

    public function testKpisExcludeScannerOpensFromOpenedCount(): void
    {
        $rows = [
            ['sending_status' => 2, 'mail_open_times' => '[1700000000000]', 'is_scanner' => 0],
            ['sending_status' => 2, 'mail_open_times' => '[1700000000000]', 'is_scanner' => 1],
            ['sending_status' => 2, 'mail_open_times' => null,              'is_scanner' => 0],
        ];
        $kpis = customer_report_compute_kpis($rows);
        self::assertSame(1, $kpis['opened']);
        self::assertSame(3, $kpis['recipients']);
        self::assertSame(1, $kpis['scanner_hit_count']);
    }

    public function testKpisCountScannerHitsEvenWithoutOpens(): void
    {
        $rows = [
            ['sending_status' => 2, 'mail_open_times' => null, 'is_scanner' => 1],
            ['sending_status' => 2, 'mail_open_times' => null, 'is_scanner' => 1],
        ];
        $kpis = customer_report_compute_kpis($rows);
        self::assertSame(0, $kpis['opened']);
        self::assertSame(2, $kpis['scanner_hit_count']);
    }

    public function testKpisBackwardCompatibleWhenIsScannerKeyMissing(): void
    {
        $rows = [
            ['sending_status' => 2, 'mail_open_times' => '[1700000000000]'],
            ['sending_status' => 2, 'mail_open_times' => null],
        ];
        $kpis = customer_report_compute_kpis($rows);
        self::assertSame(1, $kpis['opened']);
        self::assertSame(0, $kpis['scanner_hit_count']);
    }
}

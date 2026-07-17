<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 2 — unified Reports generator. One type-aware report page picks any
 * tracker (web or quick) and branches on its type. taphish_report_config is the
 * pure per-type contract: which manager + action feeds the table, whether a page
 * selector applies (web only), and the fixed column dictionary. Dicts are copied
 * verbatim from quick_tracker_report.js:4 / web_tracker_report_functions.js:4 so
 * the unified client renders identical column labels.
 */
final class ReportConfigTest extends TestCase
{
    public function testQuickConfig(): void
    {
        $c = taphish_report_config('quick');
        self::assertSame('manager/quick_tracker_manager', $c['manager']);
        self::assertSame('get_quick_tracker_data', $c['action']);
        self::assertFalse($c['hasPageSelector']);
        self::assertSame('Mail Client/Browser', $c['dict']['mail_client']);
        self::assertSame('HTTP Headers', $c['dict']['all_headers']);
        self::assertArrayNotHasKey('session_id', $c['dict'], 'quick has no session id column');
        self::assertArrayNotHasKey('screen_res', $c['dict'], 'quick has no screen-res column');
    }

    public function testWebConfig(): void
    {
        $c = taphish_report_config('web');
        self::assertSame('manager/tracker_report_manager', $c['manager']);
        self::assertSame('get_table_webpage_visit_form_submission', $c['action']);
        self::assertTrue($c['hasPageSelector']);
        self::assertSame('Session ID', $c['dict']['session_id']);
        self::assertSame('Screen Res', $c['dict']['screen_res']);
        self::assertArrayNotHasKey('mail_client', $c['dict'], 'web has no mail-client column');
    }

    public function testSharedColumnsPresentInBoth(): void
    {
        foreach (['quick', 'web'] as $t) {
            $d = taphish_report_config($t)['dict'];
            foreach (['rid', 'public_ip', 'time', 'country', 'city', 'isp', 'coordinates'] as $col) {
                self::assertArrayHasKey($col, $d, "$t must expose $col");
            }
        }
    }

    public function testUnknownTypeIsNull(): void
    {
        self::assertNull(taphish_report_config('bogus'));
        self::assertNull(taphish_report_config(''));
    }
}

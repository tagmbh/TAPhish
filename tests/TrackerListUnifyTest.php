<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P2.1 — the unified Tracker List shows web + quick trackers in one table with a
 * Type column. taphish_tracker_list_normalize is the pure merge: type-tag each
 * row into a common shape {type, tracker_id, tracker_name, active, date,
 * start_time, stop_time, engagement_id} and concatenate web then quick (each
 * already date-desc from SQL; the client DataTable sorts across types).
 */
final class TrackerListUnifyTest extends TestCase
{
    public function testTagsBothTypesAndConcatenatesWebThenQuick(): void
    {
        $out = taphish_tracker_list_normalize(
            [['tracker_id' => 'w1', 'tracker_name' => 'Web A', 'active' => 1, 'date' => 'd1', 'start_time' => 's', 'stop_time' => null, 'engagement_id' => 3]],
            [['tracker_id' => 'q1', 'tracker_name' => 'Quick A', 'active' => 0, 'date' => 'd2', 'start_time' => null, 'stop_time' => null, 'engagement_id' => null]]
        );
        self::assertSame(['web', 'quick'], array_column($out, 'type'));
        self::assertSame(['w1', 'q1'], array_column($out, 'tracker_id'));
        self::assertSame(['Web A', 'Quick A'], array_column($out, 'tracker_name'));
    }

    public function testCoercesActiveToIntAndEngagementIdNullable(): void
    {
        $out = taphish_tracker_list_normalize(
            [['tracker_id' => 'w', 'tracker_name' => 'W', 'active' => '1', 'date' => 'd', 'engagement_id' => '5']],
            [['tracker_id' => 'q', 'tracker_name' => 'Q', 'active' => 0, 'date' => 'd', 'engagement_id' => null]]
        );
        self::assertSame(1, $out[0]['active']);
        self::assertSame(5, $out[0]['engagement_id']);
        self::assertSame(0, $out[1]['active']);
        self::assertNull($out[1]['engagement_id']);
    }

    public function testEveryRowHasTheCommonShape(): void
    {
        $out = taphish_tracker_list_normalize(
            [['tracker_id' => 'w', 'tracker_name' => 'W', 'active' => 1, 'date' => 'd']],
            [['tracker_id' => 'q', 'tracker_name' => 'Q', 'active' => 1, 'date' => 'd']]
        );
        foreach ($out as $row) {
            foreach (['type', 'tracker_id', 'tracker_name', 'active', 'date', 'start_time', 'stop_time', 'engagement_id'] as $k) {
                self::assertArrayHasKey($k, $row);
            }
        }
    }

    public function testEmptyInputsYieldEmpty(): void
    {
        self::assertSame([], taphish_tracker_list_normalize([], []));
    }

    public function testScannerHideFilterPredicate(): void
    {
        // P2.2a: opt-in scanner-hide for tracker report feeds. A hit is visible
        // unless we're hiding scanners AND the row is flagged is_scanner=1. A
        // missing is_scanner (e.g. web page-visits) is treated as human → visible.
        self::assertTrue(taphish_hit_is_visible(['is_scanner' => 0], true));
        self::assertTrue(taphish_hit_is_visible(['is_scanner' => 1], false));   // not hiding
        self::assertFalse(taphish_hit_is_visible(['is_scanner' => 1], true));    // hide + scanner
        self::assertTrue(taphish_hit_is_visible([], true));                      // no flag → visible
        self::assertFalse(taphish_hit_is_visible(['is_scanner' => '1'], true));  // string coercion
        self::assertTrue(taphish_hit_is_visible(['is_scanner' => '0'], true));
    }

    public function testNavUnifiesTrackersGroup(): void
    {
        // P2.4: one Trackers group replaces the Quick + Web groups + the stray
        // Web Tracker Report leaf. The unified list is in nav; the old fragmented
        // destinations are removed from the sidebar (pages still reachable via
        // the list's per-row links).
        $menu = file_get_contents(dirname(__DIR__) . '/spear/z_menu.php');
        self::assertStringContainsString('/spear/Trackers"', $menu, 'unified All-Trackers list must be in nav');
        self::assertStringNotContainsString('/spear/TrackerList"', $menu, 'old Web Tracker list nav removed');
        self::assertStringNotContainsString('/spear/QuickTrackerReport"', $menu, 'old Quick Reports nav removed');
        self::assertStringNotContainsString('/spear/TrackerReport"', $menu, 'stray Web Tracker Report leaf removed');
    }

    public function testScannerToggleWiredInBothReportViews(): void
    {
        $spear = dirname(__DIR__) . '/spear';
        foreach (['js/quick_tracker_report.js', 'js/web_tracker_report_functions.js'] as $js) {
            self::assertStringContainsString('hide_scanner', file_get_contents($spear . '/' . $js), "$js must send hide_scanner");
        }
        foreach (['QuickTrackerReport.php', 'TrackerReport.php'] as $php) {
            self::assertStringContainsString('cb_hide_scanner', file_get_contents($spear . '/' . $php), "$php must have the scanner toggle");
        }
    }

    public function testUnifiedPageAndActionAreWired(): void
    {
        $spear = dirname(__DIR__) . '/spear';
        $page = file_get_contents($spear . '/Trackers.php');
        self::assertStringContainsString('table_all_trackers', $page, 'page must have the unified table');
        self::assertStringContainsString('trackers_unified.js', $page, 'page must load the unified client');
        self::assertStringContainsString('z_navboot.php', $page, 'sidebar page must load the nav bootstrap');

        $js = file_get_contents($spear . '/js/trackers_unified.js');
        self::assertStringContainsString('list_all_trackers', $js, 'client must call the unified feed');
        self::assertStringContainsString('order.dt search.dt', $js, 'client must use the real DataTables event namespace');
        self::assertStringNotContainsString('aaSorting', $js, 'client must not reuse the malformed sort');

        self::assertStringContainsString(
            "'list_all_trackers'",
            file_get_contents($spear . '/manager/authz.php'),
            'list_all_trackers must be registered in the RBAC policy'
        );
    }
}

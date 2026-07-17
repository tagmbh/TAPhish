<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 2 — structural guards for the unified Reports generator. Behaviour is
 * verified by the live demo (a quick tracker + a web tracker); these lock the
 * page/client wiring and keep the JS REPORT_CONFIG in lockstep with the pure
 * taphish_report_config contract.
 */
final class ReportUnifyTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testUnifiedReportPageIsWired(): void
    {
        $page = $this->f('TrackerReports.php');
        self::assertStringContainsString('table_tracker_report', $page, 'page must have the results table');
        self::assertStringContainsString('tracker_reports_unified.js', $page, 'page must load the unified client');
        self::assertStringContainsString('z_navboot.php', $page, 'sidebar page must load the nav bootstrap');
        self::assertStringContainsString('reportTypeSelector', $page, 'page must have the web page selector');
        self::assertStringContainsString('cb_hide_scanner', $page, 'page must have the scanner-hide toggle');
        self::assertStringContainsString('<th>Type</th>', $page, 'picker must show the tracker type');
    }

    public function testUnifiedClientIsTypeAware(): void
    {
        $js = $this->f('js/tracker_reports_unified.js');
        self::assertStringContainsString('list_all_trackers', $js, 'client must use the unified tracker feed');
        self::assertStringContainsString('function trackerSelected(type, tracker_id)', $js, 'client must pick by type');
        self::assertStringContainsString('function loadResults()', $js, 'client must have one results loader');
        self::assertStringContainsString('order.dt search.dt', $js, 'client must use the real DataTables event namespace');
        self::assertStringNotContainsString('aaSorting', $js, 'client must not reuse the malformed sort');
    }

    public function testJsReportConfigMatchesPhpContract(): void
    {
        // The JS REPORT_CONFIG must carry the same feeds as the pure PHP contract
        // so the unified client drives the correct endpoints.
        $js = $this->f('js/tracker_reports_unified.js');
        foreach (['quick', 'web'] as $type) {
            $c = taphish_report_config($type);
            self::assertStringContainsString("'" . $c['manager'] . "'", $js, "$type manager must be in the client");
            self::assertStringContainsString("'" . $c['action'] . "'", $js, "$type action must be in the client");
        }
    }

    public function testReportsNavRegistered(): void
    {
        $menu = $this->f('z_menu.php');
        self::assertStringContainsString('/spear/TrackerReports', $menu, 'unified Reports must be in nav');
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — structural guards for the dashboard single-page fold. Behaviour is
 * verified by the live demo (both branches: with tracker + email-only); these
 * lock the tracker-optional wiring so it can't silently regress back to
 * "web tracker mandatory".
 */
final class DashboardFoldTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testCampaignSelectedIsTrackerOptional(): void
    {
        $js = $this->f('js/web_mail_campaign_dashboard.js');
        self::assertStringContainsString("function campaignSelected(campaign_id,tracker_id='',", $js, 'tracker_id must default to empty');
        self::assertStringContainsString('var hasTracker', $js, 'must compute hasTracker');
        self::assertStringContainsString('if (hasTracker && data.webtracker_info)', $js, 'web branch must be gated on a present tracker');
        self::assertStringContainsString('toggleWebSections(hasTracker)', $js, 'web sections must be toggled by tracker presence');
    }

    public function testWebColumnsFilteredOutInEmailOnly(): void
    {
        $js = $this->f('js/web_mail_campaign_dashboard.js');
        // In the no-tracker view the web-derived columns are dropped from the
        // selected set so the result table shows mail data only.
        self::assertMatchesRegularExpression(
            "/g_tracker_id === ''.*allReportColListSelected = allReportColListSelected\.filter/s",
            $js,
            'email-only view must filter web columns out of the result table'
        );
    }

    public function testRefreshDashboardNeedsOnlyACampaign(): void
    {
        $js = $this->f('js/web_mail_campaign_dashboard.js');
        self::assertStringNotContainsString("g_campaign_id != '' && g_tracker_id != ''", $js, 'refresh must not require a tracker');
    }

    public function testShellHasTheShowWebToggleAndWebOnlySections(): void
    {
        $php = $this->f('WebMailCmpDashboard.php');
        self::assertStringContainsString('id="cb_show_web"', $php, 'the Show-web-tracker toggle must exist');
        self::assertStringContainsString('web-only-section', $php, 'web-only sections must be tagged for toggling');
        self::assertStringContainsString('id="web_tracker_selector_col"', $php, 'the tracker selector must be in a toggleable container');
        self::assertStringContainsString('Campaign Dashboard', $php, 'page renamed to the single Campaign Dashboard');
    }

    public function testExportUsesTheEmailOnlyColumnFilter(): void
    {
        // Review finding #1: the export must apply the same email-only column
        // filter as the on-screen table, else an email-only campaign exports 6
        // blank web columns. Factored into a shared helper used by BOTH sites.
        $js = $this->f('js/web_mail_campaign_dashboard.js');
        self::assertStringContainsString('function dropWebColsIfEmailOnly()', $js, 'shared email-only column filter helper');
        self::assertSame(2, substr_count($js, 'dropWebColsIfEmailOnly();'), 'filter applied to BOTH the result table and the export');
    }

    public function testStaleTrackerHidesWebSectionsAndWarns(): void
    {
        // Review finding #2: a supplied tracker_id that no longer resolves must
        // re-hide the web sections (else the web charts spin forever) and warn.
        // `toggleWebSections(false)` and the toast text are unique to this branch.
        $js = $this->f('js/web_mail_campaign_dashboard.js');
        self::assertStringContainsString('toggleWebSections(false)', $js, 'no-tracker-resolve branch must re-hide web sections');
        self::assertStringContainsString('if (hasTracker)', $js, 'warning is gated on a supplied-but-unresolved tracker');
        self::assertStringContainsString('Web tracker not found', $js, 'must warn when a supplied tracker did not resolve');
    }

    public function testShellDeepLinkAcceptsCampaignAlone(): void
    {
        $php = $this->f('WebMailCmpDashboard.php');
        // The authenticated deep-link must open a campaign with NO tracker
        // (email-only); the old form required both mcamp AND tracker. (The
        // separate public-share path still requires all three — not asserted here.)
        self::assertStringContainsString("if(isset(\$_GET['mcamp'])){", $php, 'deep-link opens on a campaign alone');
        self::assertStringContainsString("isset(\$_GET['tracker']) ? doFilter", $php, 'the tracker is read optionally');
    }
}

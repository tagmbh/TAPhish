<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for the Engagement Analytics page — the consolidated
 * Campaign/Engagement funnel view over the tested engagement_analytics.php core.
 * Behaviour is verified by the live demo; these lock the page/action/authz wiring.
 */
final class EngagementAnalyticsPageTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testPageWired(): void
    {
        $page = $this->f('EngagementAnalytics.php');
        self::assertStringContainsString('analytics_body', $page, 'results container');
        self::assertStringContainsString('analytics_engagement_selector', $page, 'engagement picker');
        self::assertStringContainsString('engagement_analytics.js', $page, 'loads the client');
        self::assertStringContainsString('z_navboot.php', $page, 'sidebar nav bootstrap');
    }

    public function testClientCallsTheAnalyticsAction(): void
    {
        $js = $this->f('js/engagement_analytics.js');
        self::assertStringContainsString('engagement_analytics_summary', $js, 'client calls the analytics action');
        self::assertStringContainsString('list_engagements', $js, 'client lists engagements');
        self::assertStringContainsString('repeat_offenders', $js, 'renders repeat offenders');
        self::assertStringContainsString('by_wave', $js, 'renders by-wave rollup');
        self::assertStringContainsString('by_cohort', $js, 'renders by-cohort rollup');
    }

    public function testActionRegisteredAndAuthzGated(): void
    {
        self::assertStringContainsString(
            'engagement_analytics_summary',
            $this->f('manager/userlist_campaignlist_mailtemplate_manager.php'),
            'dispatcher handles the action'
        );
        self::assertStringContainsString(
            'taphish_engagement_analytics(',
            $this->f('manager/userlist_campaignlist_mailtemplate_manager.php'),
            'dispatcher calls the gather fn'
        );
        self::assertMatchesRegularExpression(
            "/'engagement_analytics_summary'\s*=>\s*\['super-admin', 'operator'\]/",
            $this->f('manager/authz.php'),
            'action is operator-tier RBAC-gated (carries emails)'
        );
    }

    public function testNavRegistered(): void
    {
        self::assertStringContainsString('/spear/EngagementAnalytics', $this->f('z_menu.php'), 'nav entry present');
    }
}

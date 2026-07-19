<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * R2.3 — structural guards for the captured-credentials table wiring. The pure
 * row/redaction logic is covered by EngagementCredsTableTest. These lock the
 * gather + operator-tier action + authz + reveal-on-click UI so plaintext
 * credentials can't be exposed unauthenticated/unauthorised.
 */
final class CredsTableWiringTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testGatherFunctionDefinedAndPullsCaptureColumns(): void
    {
        $core = $this->f('manager/engagement_analytics.php');
        self::assertStringContainsString('function taphish_engagement_creds_table', $core, 'gather defined');
        self::assertStringContainsString('form_field_data, code_2fa', $core, 'selects the capture columns');
        self::assertStringContainsString('taphish_analytics_creds_rows', $core, 'delegates to the tested pure core');
    }

    public function testActionRegisteredAndOperatorGated(): void
    {
        $mgr = $this->f('manager/userlist_campaignlist_mailtemplate_manager.php');
        self::assertStringContainsString('"engagement_creds_table"', $mgr, 'action dispatched');
        self::assertStringContainsString('taphish_engagement_creds_table($conn, $id, true)', $mgr, 'reveal=true, operator-gated');

        $authz = $this->f('manager/authz.php');
        self::assertMatchesRegularExpression(
            "/'engagement_creds_table'\\s*=>\\s*\\['super-admin', 'operator'\\]/",
            $authz,
            'creds table is operator-tier only'
        );
    }

    public function testClientRevealsOnClickAndEscapes(): void
    {
        $js = $this->f('js/engagement_analytics.js');
        self::assertStringContainsString('engagement_creds_table', $js, 'client calls the action');
        self::assertStringContainsString('reveal_creds_btn', $js, 'reveal button (not auto-loaded)');
        self::assertStringContainsString('function renderCredsTable', $js, 'renders the table');
        self::assertStringContainsString('esc(fields[k])', $js, 'escapes captured values');
    }
}

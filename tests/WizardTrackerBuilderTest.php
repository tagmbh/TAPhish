<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.57: pure-helper tests for the Quick-Start full-funnel wizard
 * builders in spear/manager/wizard_tracker_builder.php. DB-free + session-
 * free; loaded via tests/Support/helpers_shim.php.
 */
final class WizardTrackerBuilderTest extends TestCase
{
    // --- taphish_wizard_build_minimal_tracker ----------------------------

    public function testMinimalTrackerReturnsExpectedTopLevelKeys(): void
    {
        $out = taphish_wizard_build_minimal_tracker('trk123', 'My Tracker', 'https://host/track.php');
        self::assertSame(['tracker_step_data', 'content_html', 'content_js'], array_keys($out));
        self::assertIsString($out['tracker_step_data']);
        self::assertIsString($out['content_html']);
        self::assertIsString($out['content_js']);
    }

    public function testTrackerStepDataIsValidJsonWithSchemaShape(): void
    {
        $out = taphish_wizard_build_minimal_tracker('trk123', 'My Tracker', 'https://host/track.php');
        $data = json_decode($out['tracker_step_data'], true);
        self::assertIsArray($data, 'tracker_step_data must be valid JSON');

        self::assertArrayHasKey('start', $data);
        self::assertArrayHasKey('trackers', $data);
        self::assertArrayHasKey('web_forms', $data);

        self::assertArrayHasKey('count', $data['web_forms']);
        self::assertArrayHasKey('data', $data['web_forms']);
        self::assertSame(1, $data['web_forms']['count']);
        self::assertIsArray($data['web_forms']['data']);
        self::assertCount(1, $data['web_forms']['data']);

        // start block carries the human label + normalized webhook base.
        self::assertSame('My Tracker', $data['start']['tb_tracker_name']);
        self::assertSame('https://host', $data['start']['tb_webhook_url']);
    }

    public function testTrackerStepDataNormalizesWebhookBase(): void
    {
        // trailing /track.php, /track, and slashes are stripped to a bare base.
        foreach (['https://host/track.php', 'https://host/track', 'https://host/track/', 'https://host/'] as $url) {
            $out  = taphish_wizard_build_minimal_tracker('trk', 'T', $url);
            $data = json_decode($out['tracker_step_data'], true);
            self::assertSame('https://host', $data['start']['tb_webhook_url'], "base for $url");
            self::assertSame('https://host/#', $data['web_forms']['data'][0]['page_url']);
        }
    }

    public function testContentJsEmbedsTrackerIdAndWebhookTrackEndpoint(): void
    {
        $out = taphish_wizard_build_minimal_tracker('trkABC9', 'Label', 'https://host/track.php');
        $js  = $out['content_js'];

        // tracker_id is embedded verbatim in the script.
        self::assertStringContainsString('var tracker_id = "trkABC9";', $js);
        // posts to "<base>/track" (normalized, single /track).
        self::assertStringContainsString('var webhook = "https://host/track";', $js);
        // reads rid from the URL query string.
        self::assertStringContainsString('rid=', $js);
        self::assertMatchesRegularExpression('/window\.location\.search\.match/', $js);
        // posts both a page visit and a form submit.
        self::assertStringContainsString('XMLHttpRequest', $js);
        self::assertStringContainsString('"POST", webhook', $js);
    }

    public function testContentHtmlIsValidJsonObject(): void
    {
        $out  = taphish_wizard_build_minimal_tracker('trk', 'T', 'https://host/track.php');
        $html = json_decode($out['content_html'], true);
        self::assertIsArray($html);
        self::assertArrayHasKey(0, $html);
        self::assertSame('', $html[0]);
    }

    public function testEmptyTrackerNameDoesNotBreak(): void
    {
        $out  = taphish_wizard_build_minimal_tracker('trk', '', 'https://host/track.php');
        $data = json_decode($out['tracker_step_data'], true);
        self::assertIsArray($data);
        self::assertSame('', $data['start']['tb_tracker_name']);
    }

    public function testSpecialCharsInNameSurviveJsonRoundTrip(): void
    {
        $name = 'Acme "Login" <Phish> & Co — café';
        $out  = taphish_wizard_build_minimal_tracker('trk', $name, 'https://host/track.php');
        $data = json_decode($out['tracker_step_data'], true);
        self::assertIsArray($data, 'special chars must still produce valid JSON');
        self::assertSame($name, $data['start']['tb_tracker_name']);
    }

    public function testBlankWebhookUrlNormalizesToEmptyBaseAndTrackOnly(): void
    {
        $out  = taphish_wizard_build_minimal_tracker('trk', 'T', '');
        $data = json_decode($out['tracker_step_data'], true);
        self::assertIsArray($data);
        self::assertSame('', $data['start']['tb_webhook_url']);
        // JS still posts to "/track".
        self::assertStringContainsString('var webhook = "/track";', $out['content_js']);
    }

    public function testTrackerStepDataTrackersIsJsonObjectNotArray(): void
    {
        $out = taphish_wizard_build_minimal_tracker('trk', 'T', 'https://host/track.php');
        // stdClass encodes to {} (object), not [] — important for editor compat.
        self::assertStringContainsString('"trackers":{}', $out['tracker_step_data']);
    }

    public function testWebhookAndIdAreEscapedAndCannotBreakOutOfJsLiteral(): void
    {
        // A hostile host/name must not be able to close the JS string literal
        // or open a </script> — both interpolated values are JSON-encoded.
        $out = taphish_wizard_build_minimal_tracker(
            'trk',
            'T',
            'https://evil"+alert(1)+"x</script><script>evil()</script>/track.php'
        );
        $js = $out['content_js'];
        // The raw break-out payload must not survive verbatim.
        self::assertStringNotContainsString('"+alert(1)+"', $js);
        self::assertStringNotContainsString('</script>', $js);
        // Quote + angle bracket are hex-escaped inside the literal instead.
        self::assertStringContainsString('"', $js);
        self::assertStringContainsString('<', $js);
        // The tracker_id is still embedded (as a JSON literal).
        self::assertStringContainsString('var tracker_id = "trk";', $js);
    }

    // --- taphish_wizard_build_campaign_data ------------------------------

    private function fullRefs(): array
    {
        return [
            'user_group_id'      => 'ug-7',
            'user_group_name'    => 'Acme Targets',
            'mail_template_id'   => 'tmpl-3',
            'mail_template_name' => 'Password Reset',
            'sender_list_id'     => 'snd-1',
            'sender_name'        => 'IT Helpdesk',
        ];
    }

    public function testCampaignDataLinksIdsAndNames(): void
    {
        $c = taphish_wizard_build_campaign_data($this->fullRefs());

        self::assertSame(['id' => 'ug-7', 'name' => 'Acme Targets'], $c['user_group']);
        self::assertSame(['id' => 'tmpl-3', 'name' => 'Password Reset'], $c['mail_template']);
        self::assertSame(['id' => 'snd-1', 'name' => 'IT Helpdesk'], $c['mail_sender']);
    }

    public function testCampaignDataDefaults(): void
    {
        $c = taphish_wizard_build_campaign_data($this->fullRefs());

        self::assertNull($c['mail_template_b']);
        self::assertSame(['id' => 'default', 'name' => 'default'], $c['mail_config']);
        self::assertSame('0000-0000', $c['msg_interval']);
        self::assertSame('2', $c['msg_fail_retry']);
        self::assertSame('Created by Quick-Start Wizard', $c['notes']);
    }

    public function testCampaignDataOptionalOverrides(): void
    {
        $refs = $this->fullRefs() + [
            'notes'          => 'Custom note',
            'msg_interval'   => '0010-0030',
            'msg_fail_retry' => '5',
        ];
        $c = taphish_wizard_build_campaign_data($refs);

        self::assertSame('Custom note', $c['notes']);
        self::assertSame('0010-0030', $c['msg_interval']);
        self::assertSame('5', $c['msg_fail_retry']);
    }

    public function testCampaignDataMissingRefsDoNotFatalAndYieldEmptyStrings(): void
    {
        $c = taphish_wizard_build_campaign_data([]);

        self::assertSame(['id' => '', 'name' => ''], $c['user_group']);
        self::assertSame(['id' => '', 'name' => ''], $c['mail_template']);
        self::assertSame(['id' => '', 'name' => ''], $c['mail_sender']);
        // defaults still applied even with no refs at all.
        self::assertNull($c['mail_template_b']);
        self::assertSame('0000-0000', $c['msg_interval']);
        self::assertSame('2', $c['msg_fail_retry']);
        self::assertSame('Created by Quick-Start Wizard', $c['notes']);
    }

    public function testCampaignDataCoercesNonStringRefsToStrings(): void
    {
        $c = taphish_wizard_build_campaign_data([
            'user_group_id'    => 7,
            'mail_template_id' => 3,
            'sender_list_id'   => 1,
            'msg_fail_retry'   => 4,
        ]);
        self::assertSame('7', $c['user_group']['id']);
        self::assertSame('3', $c['mail_template']['id']);
        self::assertSame('1', $c['mail_sender']['id']);
        self::assertSame('4', $c['msg_fail_retry']);
    }
}

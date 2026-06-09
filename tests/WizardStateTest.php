<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.56: pure tests for the wizard-state normalizer. DB-bound
 * progress persistence + the boot migration live in the integration
 * tier.
 */
final class WizardStateTest extends TestCase
{
    public function testNormalizeClampsStep(): void
    {
        self::assertSame(1, taphish_wizard_state_normalize(['step' => 0])['step']);
        self::assertSame(1, taphish_wizard_state_normalize(['step' => -3])['step']);
        self::assertSame(7, taphish_wizard_state_normalize(['step' => 99])['step']);
        self::assertSame(4, taphish_wizard_state_normalize(['step' => 4])['step']);
    }

    public function testNormalizeDefaultsStepToOne(): void
    {
        self::assertSame(1, taphish_wizard_state_normalize([])['step']);
    }

    public function testNormalizeWhitelistsKeys(): void
    {
        $out = taphish_wizard_state_normalize([
            'step' => 3,
            'target_domain' => 'acme.com',
            'dkim_selector' => 's1',
            'landing_slug'  => 'acme-login',
            'pretext_id'    => 12,
            'secret'        => 'should not survive',
            'private_key'   => 'NOPE',
        ]);
        // Phase 3.57 added the full-funnel keys to the whitelist.
        self::assertSame([
            'step', 'target_domain', 'dkim_selector', 'landing_slug', 'pretext_id',
            'sender_list_id', 'user_group_id', 'mail_template_id', 'tracker_id',
            'clone_slug', 'landing_url', 'campaign_type',
        ], array_keys($out));
        self::assertArrayNotHasKey('secret', $out);
        self::assertArrayNotHasKey('private_key', $out);
    }

    // --- Phase 3.57: full-funnel keys -------------------------------------

    public function testNormalizeSenderListIdAlnumDashCappedAt64(): void
    {
        $out = taphish_wizard_state_normalize([
            'sender_list_id' => 'Sender_List 99!@#-OK',
        ]);
        // strip everything but [A-Za-z0-9-] (note: keeps case)
        self::assertSame('SenderList99-OK', $out['sender_list_id']);

        $long = taphish_wizard_state_normalize(['sender_list_id' => str_repeat('a', 200)]);
        self::assertSame(64, strlen($long['sender_list_id']));
    }

    public function testNormalizeUserGroupAndMailTemplateIdsRawTrimmedTo64(): void
    {
        // user_group_id / mail_template_id are NOT pattern-filtered, only length-capped.
        $out = taphish_wizard_state_normalize([
            'user_group_id'    => 'group:weird/value with spaces',
            'mail_template_id' => 'tmpl#42',
        ]);
        self::assertSame('group:weird/value with spaces', $out['user_group_id']);
        self::assertSame('tmpl#42', $out['mail_template_id']);

        self::assertSame(64, strlen(taphish_wizard_state_normalize([
            'user_group_id' => str_repeat('g', 100),
        ])['user_group_id']));
        self::assertSame(64, strlen(taphish_wizard_state_normalize([
            'mail_template_id' => str_repeat('t', 100),
        ])['mail_template_id']));
    }

    public function testNormalizeTrackerIdAlnumOnlyCappedAt32(): void
    {
        $out = taphish_wizard_state_normalize([
            'tracker_id' => 'abc-123_XYZ!@# def',
        ]);
        // tracker_id strips dashes too — only [A-Za-z0-9].
        self::assertSame('abc123XYZdef', $out['tracker_id']);

        self::assertSame(32, strlen(taphish_wizard_state_normalize([
            'tracker_id' => str_repeat('A', 50),
        ])['tracker_id']));
    }

    public function testNormalizeCloneSlugLowercaseDashCappedAt61(): void
    {
        $out = taphish_wizard_state_normalize([
            'clone_slug' => 'My-Clone Slug!!',
        ]);
        self::assertSame('my-cloneslug', $out['clone_slug']);

        self::assertSame(61, strlen(taphish_wizard_state_normalize([
            'clone_slug' => str_repeat('z', 100),
        ])['clone_slug']));
    }

    public function testNormalizeLandingUrlTrimmedCappedAt512(): void
    {
        $out = taphish_wizard_state_normalize([
            'landing_url' => '  https://host/login?x=1  ',
        ]);
        self::assertSame('https://host/login?x=1', $out['landing_url']);

        $long = taphish_wizard_state_normalize([
            'landing_url' => 'https://h/' . str_repeat('p', 1000),
        ]);
        self::assertSame(512, strlen($long['landing_url']));
    }

    public function testNormalizeCampaignTypeWhitelist(): void
    {
        self::assertSame('', taphish_wizard_state_normalize([])['campaign_type']);
        self::assertSame('', taphish_wizard_state_normalize(['campaign_type' => ''])['campaign_type']);
        self::assertSame('mail_landing', taphish_wizard_state_normalize(['campaign_type' => 'mail_landing'])['campaign_type']);
        // garbage value falls back to ''
        self::assertSame('', taphish_wizard_state_normalize(['campaign_type' => 'rce_payload'])['campaign_type']);
        self::assertSame('', taphish_wizard_state_normalize(['campaign_type' => 'mail'])['campaign_type']);
    }

    public function testNormalizeNewKeysDefaultToEmptyStrings(): void
    {
        $out = taphish_wizard_state_normalize([]);
        foreach (['sender_list_id', 'user_group_id', 'mail_template_id', 'tracker_id', 'clone_slug', 'landing_url', 'campaign_type'] as $k) {
            self::assertSame('', $out[$k], "default for $k");
        }
    }

    public function testNormalizeSanitizesSelectorAndSlug(): void
    {
        $out = taphish_wizard_state_normalize([
            'dkim_selector' => 'S1!@#Bad',
            'landing_slug'  => 'ACME Login!',
        ]);
        // lowercase, strip non [a-z0-9-]
        self::assertSame('s1bad', $out['dkim_selector']);
        self::assertSame('acmelogin', $out['landing_slug']);
    }

    public function testNormalizeCapsLengths(): void
    {
        $out = taphish_wizard_state_normalize([
            'target_domain' => str_repeat('a', 400),
            'dkim_selector' => str_repeat('s', 40),
            'landing_slug'  => str_repeat('x', 100),
        ]);
        self::assertLessThanOrEqual(253, strlen($out['target_domain']));
        self::assertLessThanOrEqual(16,  strlen($out['dkim_selector']));
        self::assertLessThanOrEqual(61,  strlen($out['landing_slug']));
    }

    public function testNormalizePretextIdNonNegativeInt(): void
    {
        self::assertSame(0, taphish_wizard_state_normalize(['pretext_id' => -5])['pretext_id']);
        self::assertSame(7, taphish_wizard_state_normalize(['pretext_id' => '7'])['pretext_id']);
    }

    public function testEncodeRoundTripsThroughJson(): void
    {
        $json = taphish_wizard_state_encode(['step' => 5, 'target_domain' => 'x.com']);
        $back = json_decode($json, true);
        self::assertSame(5, $back['step']);
        self::assertSame('x.com', $back['target_domain']);
    }

    public function testSchemaAndProgressHelpersDefined(): void
    {
        self::assertTrue(function_exists('taphish_engagement_ensure_wizard_columns'));
        self::assertTrue(function_exists('taphish_engagement_set_wizard_progress'));
        self::assertTrue(function_exists('taphish_wizard_resume_payload'));
    }

    public function testResumePayloadFreshStartOnNullOrEmptyRow(): void
    {
        $fresh = ['id' => 0, 'step' => 1, 'state' => '{}'];
        self::assertSame($fresh, taphish_wizard_resume_payload(null));
        self::assertSame($fresh, taphish_wizard_resume_payload([]));
    }

    public function testResumePayloadReadsRowAndClampsStep(): void
    {
        $p = taphish_wizard_resume_payload(['id' => '42', 'wizard_step' => 4, 'wizard_state' => '{"step":4}']);
        self::assertSame(42, $p['id']);
        self::assertSame(4, $p['step']);
        self::assertSame('{"step":4}', $p['state']);

        self::assertSame(1, taphish_wizard_resume_payload(['id' => 1, 'wizard_step' => 0])['step']);
        self::assertSame(7, taphish_wizard_resume_payload(['id' => 1, 'wizard_step' => 50])['step']);
    }

    public function testResumePayloadDefaultsBlankStateToEmptyObject(): void
    {
        self::assertSame('{}', taphish_wizard_resume_payload(['id' => 1, 'wizard_step' => 2])['state']);
        self::assertSame('{}', taphish_wizard_resume_payload(['id' => 1, 'wizard_step' => 2, 'wizard_state' => ''])['state']);
        self::assertSame('{}', taphish_wizard_resume_payload(['id' => 1, 'wizard_state' => null])['state']);
    }
}

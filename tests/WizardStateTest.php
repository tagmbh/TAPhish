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
        self::assertSame(['step', 'target_domain', 'dkim_selector', 'landing_slug', 'pretext_id'], array_keys($out));
        self::assertArrayNotHasKey('secret', $out);
        self::assertArrayNotHasKey('private_key', $out);
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
    }
}

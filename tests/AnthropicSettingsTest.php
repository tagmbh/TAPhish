<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for spear/manager/anthropic_settings.php.
 *
 * The set/get DB helpers need mysqli + the secret_at_rest envelope and
 * are exercised in integration. Here we pin the format-validator and the
 * mask: both are the public contract the Settings card / UI relies on.
 */
final class AnthropicSettingsTest extends TestCase
{
    public function testValidatorAcceptsRealLookingKey(): void
    {
        // Synthetic; not a live key. Matches the shape Anthropic emits:
        // sk-ant-… + url-safe chars + 40+ length.
        $k = 'sk-ant-api03-' . str_repeat('aA0_-', 16);
        self::assertTrue(taphish_anthropic_validate_api_key($k));
    }

    public function testValidatorRejectsWrongPrefix(): void
    {
        self::assertFalse(taphish_anthropic_validate_api_key('sk-prod-foobarbazquxquuxetc1234567890123456789012345'));
        self::assertFalse(taphish_anthropic_validate_api_key('SK-ANT-' . str_repeat('a', 50)));
    }

    public function testValidatorRejectsTooShort(): void
    {
        self::assertFalse(taphish_anthropic_validate_api_key('sk-ant-short'));
        self::assertFalse(taphish_anthropic_validate_api_key(''));
    }

    public function testValidatorRejectsForbiddenCharsInPayload(): void
    {
        // The character class is [A-Za-z0-9_-]; a `$` in the middle is not
        // trimmable and must fail validation. Pinning this so a future
        // regex relax can't quietly accept characters that don't belong
        // in a header value.
        $bad = 'sk-ant-api03-' . str_repeat('a', 30) . '$' . str_repeat('a', 10);
        self::assertFalse(taphish_anthropic_validate_api_key($bad));
    }

    public function testValidatorTrimsWhitespace(): void
    {
        $k = 'sk-ant-api03-' . str_repeat('aA0_-', 16);
        self::assertTrue(taphish_anthropic_validate_api_key("\t " . $k . "\n"));
    }

    public function testMaskPreservesEnoughForRecognition(): void
    {
        // 16 prefix chars + eight bullets. `sk-ant-api03-` is 13 chars
        // (including the trailing dash); position 13..15 picks up the
        // first 3 chars of the operator's actual key payload — enough
        // to distinguish "which key" without enabling reuse.
        $k = 'sk-ant-api03-7YGX' . str_repeat('a', 80);
        $masked = taphish_anthropic_mask_api_key($k);
        self::assertSame('sk-ant-api03-7YG' . str_repeat("\xe2\x80\xa2", 8), $masked);
        self::assertStringStartsWith('sk-ant-api03-', $masked);
        // No characters from position 16 onwards leak.
        self::assertStringNotContainsString(substr($k, 16), $masked);
    }

    public function testMaskEmptyStringStaysEmpty(): void
    {
        self::assertSame('', taphish_anthropic_mask_api_key(''));
        self::assertSame('', taphish_anthropic_mask_api_key('   '));
    }
}

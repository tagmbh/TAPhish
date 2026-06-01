<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for Phase 3.31 TOTP recovery codes.
 *
 * The DB-touching helpers (ensure_schema, store, consume, remaining,
 * invalidate) require a live mysqli; those belong in an integration
 * suite. Format, normalization, and structural validation are pure
 * and covered here.
 */
final class TotpRecoveryCodesTest extends TestCase
{
    public function testGenerateReturnsRequestedCount(): void
    {
        $codes = totp_generate_recovery_codes(10);
        self::assertCount(10, $codes);
    }

    public function testGenerateReturnsEmptyArrayForZeroOrNegative(): void
    {
        self::assertSame([], totp_generate_recovery_codes(0));
        self::assertSame([], totp_generate_recovery_codes(-3));
    }

    public function testGeneratedCodesMatchFormat(): void
    {
        $codes = totp_generate_recovery_codes(10);
        foreach ($codes as $c) {
            self::assertMatchesRegularExpression(
                '/^[a-z2-7]{5}-[a-z2-7]{5}$/',
                $c,
                "Bad format: $c"
            );
        }
    }

    public function testGeneratedCodesAreUnique(): void
    {
        $codes = totp_generate_recovery_codes(20);
        self::assertSame($codes, array_values(array_unique($codes)));
    }

    public function testNormalizeStripsDashAndWhitespace(): void
    {
        self::assertSame('abcde23456', totp_normalize_recovery_code('abcde-23456'));
        self::assertSame('abcde23456', totp_normalize_recovery_code('  abcde 23456  '));
        self::assertSame('abcde23456', totp_normalize_recovery_code("abcde-\n23456"));
    }

    public function testNormalizeLowercases(): void
    {
        self::assertSame('abcde23456', totp_normalize_recovery_code('ABCDE-23456'));
        self::assertSame('abcde23456', totp_normalize_recovery_code('AbCdE-23456'));
    }

    public function testLooksValidAcceptsTenLowercaseAlphabetChars(): void
    {
        self::assertTrue(totp_recovery_code_looks_valid('abcde23456'));
        self::assertTrue(totp_recovery_code_looks_valid('aaaaaaaaaa'));
        self::assertTrue(totp_recovery_code_looks_valid('2345672345'));
    }

    public function testLooksValidRejectsWrongLength(): void
    {
        self::assertFalse(totp_recovery_code_looks_valid('abcde2345'));   // 9
        self::assertFalse(totp_recovery_code_looks_valid('abcde234567')); // 11
        self::assertFalse(totp_recovery_code_looks_valid(''));
    }

    public function testLooksValidRejectsOutOfAlphabet(): void
    {
        // 0, 1, 8, 9 are NOT in the alphabet (Crockford-style avoidance
        // of ambiguous chars). Mixed alphanumeric input should fail.
        self::assertFalse(totp_recovery_code_looks_valid('abcde01234'));
        self::assertFalse(totp_recovery_code_looks_valid('abcde234!9'));
        self::assertFalse(totp_recovery_code_looks_valid('ABCDE23456')); // upper, must be normalized first
    }

    public function testNormalizeThenValidateRoundTrip(): void
    {
        // The two helpers are designed to compose: any generated code,
        // after dash stripping + lowercasing, must structurally pass.
        foreach (totp_generate_recovery_codes(10) as $c) {
            self::assertTrue(
                totp_recovery_code_looks_valid(totp_normalize_recovery_code($c)),
                "Normalized form of $c failed look-valid"
            );
        }
    }
}

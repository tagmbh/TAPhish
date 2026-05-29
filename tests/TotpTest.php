<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    // The RFC 4226/6238 test secret in base32 ("12345678901234567890" ASCII).
    private const RFC_SECRET_B32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    // --- base32 round-trip ----------------------------------------------

    public function testBase32RoundTripPreservesBytes(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $bytes = random_bytes($i + 1);
            self::assertSame(
                $bytes,
                totp_base32_decode(totp_base32_encode($bytes)),
                "round-trip mismatch at length " . ($i + 1)
            );
        }
    }

    public function testBase32EncodingMatchesRfcVector(): void
    {
        // RFC 4648 vector: "foobar" → "MZXW6YTBOI======"
        self::assertSame('MZXW6YTBOI======', totp_base32_encode('foobar'));
    }

    public function testBase32DecodeAcceptsLowercaseAndMissingPadding(): void
    {
        self::assertSame('foobar', totp_base32_decode('mzxw6ytboi'));
    }

    public function testBase32DecodeRejectsInvalidAlphabet(): void
    {
        self::assertNull(totp_base32_decode('NOTA1VALID'));
    }

    // --- code generation: pin against the RFC 6238 reference table ------

    public function testRfc6238ReferenceVectors(): void
    {
        // RFC 6238 Appendix B (SHA-1). Time T = floor(unix / 30).
        // The table lists (unix_time, T_hex, TOTP).
        $vectors = [
            [59,          '94287082'],
            [1111111109,  '07081804'],
            [1111111111,  '14050471'],
            [1234567890,  '89005924'],
            [2000000000,  '69279037'],
            [20000000000, '65353130'],
        ];
        foreach ($vectors as [$t, $expected]) {
            // The RFC table uses 8-digit codes; pass digits=8 to match.
            self::assertSame(
                $expected,
                totp_code_for_time(self::RFC_SECRET_B32, $t, 30, 8),
                "TOTP mismatch at t=$t"
            );
        }
    }

    public function testCodeIsSixDigitsByDefault(): void
    {
        $c = totp_code_for_time(self::RFC_SECRET_B32, 59);
        self::assertMatchesRegularExpression('/^\d{6}$/', $c);
    }

    public function testCodeForTimeReturnsNullOnBadSecret(): void
    {
        self::assertNull(totp_code_for_time('THIS-IS-NOT-BASE32!', 59));
        self::assertNull(totp_code_for_time('', 59));
    }

    // --- verify_code ----------------------------------------------------

    public function testVerifyAcceptsCurrentCode(): void
    {
        $t = 1234567890;
        $code = totp_code_for_time(self::RFC_SECRET_B32, $t);
        self::assertTrue(totp_verify_code(self::RFC_SECRET_B32, $code, $t));
    }

    public function testVerifyAcceptsOneStepDrift(): void
    {
        $t = 1234567890;
        $codeJustBefore = totp_code_for_time(self::RFC_SECRET_B32, $t - 30);
        $codeJustAfter  = totp_code_for_time(self::RFC_SECRET_B32, $t + 30);
        self::assertTrue(totp_verify_code(self::RFC_SECRET_B32, $codeJustBefore, $t));
        self::assertTrue(totp_verify_code(self::RFC_SECRET_B32, $codeJustAfter, $t));
    }

    public function testVerifyRejectsBeyondTolerance(): void
    {
        $t = 1234567890;
        $codeWayAfter = totp_code_for_time(self::RFC_SECRET_B32, $t + 120);
        self::assertFalse(totp_verify_code(self::RFC_SECRET_B32, $codeWayAfter, $t));
    }

    public function testVerifyRejectsMalformedCode(): void
    {
        $t = 1234567890;
        self::assertFalse(totp_verify_code(self::RFC_SECRET_B32, '12345', $t));
        self::assertFalse(totp_verify_code(self::RFC_SECRET_B32, '1234567', $t));
        self::assertFalse(totp_verify_code(self::RFC_SECRET_B32, 'abcdef', $t));
        self::assertFalse(totp_verify_code(self::RFC_SECRET_B32, '', $t));
    }

    public function testVerifyStripsWhitespaceFromCode(): void
    {
        $t = 1234567890;
        $code = totp_code_for_time(self::RFC_SECRET_B32, $t);
        // Authenticator apps display codes as "XXX XXX" — operator pastes
        // with the space.
        $withSpace = substr($code, 0, 3) . ' ' . substr($code, 3);
        self::assertTrue(totp_verify_code(self::RFC_SECRET_B32, $withSpace, $t));
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $t = 1234567890;
        $code = totp_code_for_time(self::RFC_SECRET_B32, $t);
        $otherSecret = totp_base32_encode(str_repeat("X", 20));
        self::assertFalse(totp_verify_code($otherSecret, $code, $t));
    }

    // --- generate_secret ------------------------------------------------

    public function testGenerateSecretIsBase32And32Chars(): void
    {
        $s = totp_generate_secret();
        self::assertSame(32, strlen($s));
        self::assertMatchesRegularExpression('/^[A-Z2-7]+={0,6}$/', $s);
    }

    public function testGenerateSecretIsUniqueAcrossCalls(): void
    {
        self::assertNotSame(totp_generate_secret(), totp_generate_secret());
    }

    public function testGeneratedSecretIsVerifiable(): void
    {
        $s = totp_generate_secret();
        $t = 1700000000;
        $code = totp_code_for_time($s, $t);
        self::assertNotNull($code);
        self::assertTrue(totp_verify_code($s, $code, $t));
    }

    // --- provisioning_uri -----------------------------------------------

    public function testProvisioningUriShape(): void
    {
        $u = totp_provisioning_uri('JBSWY3DPEHPK3PXP', 'alice@example.com', 'TAPhish');
        self::assertStringStartsWith('otpauth://totp/TAPhish%3Aalice%40example.com?', $u);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $u);
        self::assertStringContainsString('issuer=TAPhish', $u);
        self::assertStringContainsString('algorithm=SHA1', $u);
        self::assertStringContainsString('digits=6', $u);
        self::assertStringContainsString('period=30', $u);
    }
}

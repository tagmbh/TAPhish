<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * R2.3 — pure tests for the per-recipient captured-credentials table + redaction.
 * Uses SYNTHETIC captures only (never real data). The whole table is served only
 * to the operator RBAC tier; redaction is defence-in-depth for lower tiers.
 */
final class EngagementCredsTableTest extends TestCase
{
    private function recipients(): array
    {
        return [
            ['rid' => 'r1', 'email' => 'a@example.test', 'name' => 'A', 'wave' => 'W1', 'cohort' => 'K1', 'credentials' => true],
            ['rid' => 'r2', 'email' => 'b@example.test', 'name' => 'B', 'wave' => 'W1', 'cohort' => 'K1', 'credentials' => false], // clicked but no submit
            ['rid' => 'r3', 'email' => 'c@example.test', 'name' => 'C', 'wave' => 'W2', 'cohort' => 'K2', 'credentials' => true],
        ];
    }

    private function captures(): array
    {
        return [
            'r1' => ['fields' => ['username' => 'alice', 'password' => 's3cret!'], 'otp' => '123456'],
            'r3' => ['fields' => ['email' => 'c@example.test', 'password' => 'hunter2'], 'otp' => ''],
        ];
    }

    public function testOnlySubmittersAppearWithRevealedValues(): void
    {
        $out = taphish_analytics_creds_rows($this->recipients(), $this->captures(), true);
        self::assertSame(['a@example.test', 'c@example.test'], array_column($out, 'email')); // r2 excluded
        self::assertSame('alice', $out[0]['fields']['username']);
        self::assertSame('s3cret!', $out[0]['fields']['password']);
        self::assertSame('123456', $out[0]['otp']);
        self::assertTrue($out[0]['has_otp']);
        self::assertFalse($out[1]['has_otp']); // r3 had empty otp
    }

    public function testNotRevealedRedactsValuesButKeepsFieldNamesAndCaptureFlags(): void
    {
        $out = taphish_analytics_creds_rows($this->recipients(), $this->captures(), false);
        // field names preserved, values redacted (no plaintext leaks)
        self::assertArrayHasKey('password', $out[0]['fields']);
        self::assertStringNotContainsString('s3cret', $out[0]['fields']['password']);
        self::assertStringNotContainsString('alice', json_encode($out[0]['fields']));
        self::assertStringNotContainsString('123456', $out[0]['otp']);
        self::assertTrue($out[0]['has_otp']); // still know OTP was captured
    }

    public function testMissingCaptureYieldsEmptyFieldsNoOtp(): void
    {
        $recipients = [['rid' => 'rX', 'email' => 'x@example.test', 'credentials' => true]];
        $out = taphish_analytics_creds_rows($recipients, [], true);
        self::assertSame([], $out[0]['fields']);
        self::assertSame('', $out[0]['otp']);
        self::assertFalse($out[0]['has_otp']);
    }

    public function testRedactValuesKeepsKeysRedactsValues(): void
    {
        $r = taphish_analytics_redact_values(['password' => 'longsecret', 'blank' => '']);
        self::assertArrayHasKey('password', $r);
        self::assertSame('', $r['blank']);
        self::assertStringNotContainsString('secret', $r['password']);
        self::assertMatchesRegularExpression('/^•+$/u', $r['password']);
    }
}

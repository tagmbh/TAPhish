<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.42: pure-helper tests for the webhook payload builder.
 *
 * Schema migration, first-capture check, HTTP dispatcher, and the
 * encrypted URL storage all need a mysqli + filesystem and live in
 * the future integration tier.
 */
final class CaptureAlertingTest extends TestCase
{
    public function testPayloadCarriesHeadline(): void
    {
        $p = taphish_capture_webhook_payload([
            'campaign'        => 'Q3-finance',
            'recipient_name'  => 'Test User',
            'recipient_email' => 't@example.com',
            'captured_at'     => 1735_686_000_000, // 2025-01-01
            'page'            => 1,
            'ip'              => '203.0.113.42',
            'has_2fa'         => true,
        ]);
        self::assertStringContainsString('Q3-finance', $p['text']);
        self::assertStringContainsString('Test User', $p['text']);
        self::assertStringContainsString('+2FA', $p['text']);
        self::assertSame($p['text'], $p['content']); // discord mirror
    }

    public function testPayloadHandlesMissingName(): void
    {
        $p = taphish_capture_webhook_payload([
            'campaign'        => 'Q3-finance',
            'recipient_name'  => '',
            'recipient_email' => 't@example.com',
            'captured_at'     => 1735_686_000_000,
            'page'            => 1,
            'ip'              => '203.0.113.42',
            'has_2fa'         => false,
        ]);
        self::assertStringContainsString('t@example.com', $p['text']);
        self::assertStringNotContainsString('+2FA', $p['text']);
    }

    public function testPayloadHandlesMissingEmail(): void
    {
        $p = taphish_capture_webhook_payload([
            'campaign'        => 'C',
            'recipient_email' => '',
            'captured_at'     => 0,
            'page'            => 0,
            'ip'              => '',
        ]);
        self::assertStringContainsString('unknown recipient', $p['text']);
    }

    public function testPayloadIncludesAllRequiredFields(): void
    {
        $p = taphish_capture_webhook_payload([
            'campaign'        => 'X',
            'recipient_email' => 'r@e',
            'captured_at'     => 0,
            'page'            => 2,
            'ip'              => '',
        ]);
        self::assertArrayHasKey('text',    $p);
        self::assertArrayHasKey('content', $p);
        self::assertArrayHasKey('fields',  $p);
        $fieldNames = array_column($p['fields'], 'name');
        foreach (['Campaign', 'Recipient', 'IP', 'Captured', '2FA code?', 'Page'] as $required) {
            self::assertContains($required, $fieldNames, "Missing field: $required");
        }
    }

    public function testCapturedAtTimestampFormatsAsIso(): void
    {
        $p = taphish_capture_webhook_payload([
            'campaign'        => 'X',
            'recipient_email' => 'r@e',
            'captured_at'     => 1735_686_000_000, // 2025-01-01 00:00:00 UTC
            'page'            => 0,
            'ip'              => '',
        ]);
        $capturedField = null;
        foreach ($p['fields'] as $f) {
            if ($f['name'] === 'Captured') { $capturedField = $f; break; }
        }
        self::assertNotNull($capturedField);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $capturedField['value']
        );
    }

    public function testEmptyTimestampShowsDash(): void
    {
        $p = taphish_capture_webhook_payload([
            'campaign'        => 'X',
            'recipient_email' => 'r@e',
            'captured_at'     => 0,
            'page'            => 0,
            'ip'              => '',
        ]);
        $capturedField = null;
        foreach ($p['fields'] as $f) {
            if ($f['name'] === 'Captured') { $capturedField = $f; break; }
        }
        self::assertSame('—', $capturedField['value']);
    }

    public function testHas2faFieldRendersYesOrNo(): void
    {
        foreach ([true => 'yes', false => 'no'] as $flag => $expected) {
            $p = taphish_capture_webhook_payload([
                'campaign'        => 'X',
                'recipient_email' => 'r@e',
                'captured_at'     => 0,
                'page'            => 0,
                'ip'              => '',
                'has_2fa'         => $flag,
            ]);
            $field = null;
            foreach ($p['fields'] as $f) {
                if ($f['name'] === '2FA code?') { $field = $f; break; }
            }
            self::assertSame($expected, $field['value']);
        }
    }
}

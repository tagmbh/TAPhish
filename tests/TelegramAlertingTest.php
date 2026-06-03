<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.53: pure-helper tests for Telegram bot alerting.
 *
 * Validators, message formatter, config serialize/deserialize, mask,
 * and the injectable-HTTP send path. DB + envelope round-trip live in
 * the integration tier.
 */
final class TelegramAlertingTest extends TestCase
{
    // ---- token validation ------------------------------------------------

    public function testValidTokenAccepted(): void
    {
        self::assertTrue(taphish_telegram_validate_token('123456789:AAE' . str_repeat('a', 32)));
    }

    public function testInvalidTokensRejected(): void
    {
        foreach (['', 'nope', '123:short', 'noColonButLong' . str_repeat('a', 40), ':missingid' . str_repeat('a', 35)] as $t) {
            self::assertFalse(taphish_telegram_validate_token($t), "expected invalid: $t");
        }
    }

    // ---- chat id validation ----------------------------------------------

    public function testValidChatIds(): void
    {
        self::assertTrue(taphish_telegram_validate_chat_id('123456'));
        self::assertTrue(taphish_telegram_validate_chat_id('-1001234567890')); // group
        self::assertTrue(taphish_telegram_validate_chat_id('@my_channel'));
    }

    public function testInvalidChatIds(): void
    {
        foreach (['', '@ab', 'not a chat', '12.34', '@bad-char!'] as $c) {
            self::assertFalse(taphish_telegram_validate_chat_id($c), "expected invalid: $c");
        }
    }

    // ---- capture formatter -----------------------------------------------

    public function testFormatCaptureCarriesCoreFields(): void
    {
        $msg = taphish_telegram_format_capture([
            'campaign'        => 'Q3-finance',
            'recipient_name'  => 'Test User',
            'recipient_email' => 't@example.com',
            'captured_at'     => 1735_689_600_000,
            'page'            => 2,
            'ip'              => '203.0.113.9',
            'has_2fa'         => true,
        ]);
        self::assertStringContainsString('New capture', $msg);
        self::assertStringContainsString('[+2FA]', $msg);
        self::assertStringContainsString('Q3-finance', $msg);
        self::assertStringContainsString('Test User', $msg);
        self::assertStringContainsString('t@example.com', $msg);
        self::assertStringContainsString('Page: 2', $msg);
        self::assertStringContainsString('203.0.113.9', $msg);
        self::assertStringContainsString('UTC', $msg);
    }

    public function testFormatCaptureRepeatHeadline(): void
    {
        $msg = taphish_telegram_format_capture([
            'campaign'    => 'C', 'recipient_email' => 'r@e',
            'captured_at' => 0, 'page' => 0, 'ip' => '', 'is_repeat' => true,
        ]);
        self::assertStringContainsString('Repeat capture', $msg);
    }

    public function testFormatCaptureHandlesMissingNameAndEmpties(): void
    {
        $msg = taphish_telegram_format_capture([
            'campaign' => '', 'recipient_email' => '', 'captured_at' => 0, 'page' => 0, 'ip' => '',
        ]);
        self::assertStringContainsString('unknown recipient', $msg);
        self::assertStringContainsString('(unnamed)', $msg);
        // No page / IP / captured lines when those are empty.
        self::assertStringNotContainsString('Page:', $msg);
        self::assertStringNotContainsString('IP:', $msg);
    }

    public function testFormatCaptureIsPlainTextNoMarkdown(): void
    {
        // Operator-controlled fields must not be interpreted as markup —
        // we send plain text. A campaign name with markdown chars stays
        // literal.
        $msg = taphish_telegram_format_capture([
            'campaign' => '*bold*_under_', 'recipient_email' => 'r@e',
            'captured_at' => 0, 'page' => 0, 'ip' => '',
        ]);
        self::assertStringContainsString('*bold*_under_', $msg);
    }

    // ---- config serialize / deserialize ----------------------------------

    public function testConfigRoundTrip(): void
    {
        $s = taphish_telegram_config_serialize('123:' . str_repeat('a', 35), '@chan');
        $back = taphish_telegram_config_deserialize($s);
        self::assertSame('123:' . str_repeat('a', 35), $back['token']);
        self::assertSame('@chan', $back['chat_id']);
    }

    public function testConfigDeserializeRejectsJunk(): void
    {
        self::assertNull(taphish_telegram_config_deserialize(null));
        self::assertNull(taphish_telegram_config_deserialize(''));
        self::assertNull(taphish_telegram_config_deserialize('not json'));
        self::assertNull(taphish_telegram_config_deserialize(json_encode(['token' => 'x']))); // missing chat_id
    }

    public function testMaskTokenShowsBotIdPrefixOnly(): void
    {
        self::assertSame('', taphish_telegram_mask_token(''));
        $masked = taphish_telegram_mask_token('123456789:AAE' . str_repeat('a', 32));
        self::assertStringStartsWith('123456789:', $masked);
        self::assertStringContainsString('•', $masked);
        self::assertStringNotContainsString('AAE', $masked);
    }

    // ---- send via injected HTTP ------------------------------------------

    public function testSendPostsToTelegramApiAndReturnsTrueOn2xx(): void
    {
        $captured = [];
        $fake = function ($url, $fields) use (&$captured) {
            $captured = ['url' => $url, 'fields' => $fields];
            return ['status' => 200, 'body' => '{"ok":true}'];
        };
        $ok = taphish_telegram_send('123456789:AAE' . str_repeat('a', 32), '-100123', 'hello', $fake);
        self::assertTrue($ok);
        self::assertStringContainsString('/sendMessage', $captured['url']);
        self::assertStringContainsString('api.telegram.org/bot', $captured['url']);
        self::assertSame('-100123', $captured['fields']['chat_id']);
        self::assertSame('hello', $captured['fields']['text']);
    }

    public function testSendReturnsFalseOnNon2xx(): void
    {
        $fake = fn() => ['status' => 401, 'body' => ''];
        self::assertFalse(taphish_telegram_send('123456789:AAE' . str_repeat('a', 32), '123', 'x', $fake));
    }

    public function testSendRejectsBadTokenOrChatBeforeNetwork(): void
    {
        $called = false;
        $fake = function () use (&$called) { $called = true; return ['status' => 200, 'body' => '']; };
        self::assertFalse(taphish_telegram_send('bad', '123', 'x', $fake));
        self::assertFalse(taphish_telegram_send('123456789:AAE' . str_repeat('a', 32), '', 'x', $fake));
        self::assertFalse($called, 'HTTP seam must not be hit on validation failure');
    }

    public function testSendRejectsEmptyText(): void
    {
        $fake = fn() => ['status' => 200, 'body' => ''];
        self::assertFalse(taphish_telegram_send('123456789:AAE' . str_repeat('a', 32), '123', '   ', $fake));
    }
}

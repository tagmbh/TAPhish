<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class AiLandingPageTest extends TestCase
{
    // --- model whitelist --------------------------------------------------

    public function testDefaultModelInAllowedList(): void
    {
        self::assertContains(ai_landing_default_model(), ai_landing_allowed_models());
    }

    public function testNormalizeAcceptsAllowed(): void
    {
        foreach (ai_landing_allowed_models() as $m) {
            self::assertSame($m, ai_landing_normalize_model($m));
        }
    }

    public function testNormalizeFallsBackToDefaultOnGarbage(): void
    {
        self::assertSame(ai_landing_default_model(), ai_landing_normalize_model(null));
        self::assertSame(ai_landing_default_model(), ai_landing_normalize_model(''));
        self::assertSame(ai_landing_default_model(), ai_landing_normalize_model('gpt-4'));
        self::assertSame(ai_landing_default_model(), ai_landing_normalize_model(42));
    }

    // --- API key shape ----------------------------------------------------

    public function testApiKeyValidPrefix(): void
    {
        // 90-char-ish synthetic key with the right prefix
        $key = 'sk-ant-' . str_repeat('A', 90);
        self::assertTrue(ai_landing_is_valid_api_key($key));
    }

    public function testApiKeyRejectsWrongPrefix(): void
    {
        self::assertFalse(ai_landing_is_valid_api_key('sk-xx-' . str_repeat('A', 90)));
        self::assertFalse(ai_landing_is_valid_api_key(str_repeat('A', 90)));
    }

    public function testApiKeyRejectsLength(): void
    {
        self::assertFalse(ai_landing_is_valid_api_key('sk-ant-short'));
        self::assertFalse(ai_landing_is_valid_api_key('sk-ant-' . str_repeat('A', 300)));
    }

    // --- system prompt invariants ----------------------------------------

    public function testSystemPromptPinsHtmlOnlyOutput(): void
    {
        $p = ai_landing_build_system_prompt();
        self::assertStringContainsString('raw HTML', $p);
        self::assertStringContainsString('DOCTYPE html', $p);
        self::assertStringContainsString('authorized', $p);
    }

    // --- ai_landing_extract_html -----------------------------------------

    public function testExtractStripsHtmlFence(): void
    {
        $raw = "```html\n<!DOCTYPE html><html><body>Hi</body></html>\n```";
        $r = ai_landing_extract_html($raw);
        self::assertStringStartsWith('<!DOCTYPE html>', $r);
        self::assertStringContainsString('<body>Hi</body>', $r);
    }

    public function testExtractStripsGenericFence(): void
    {
        $raw = "```\n<!DOCTYPE html><html></html>\n```";
        $r = ai_landing_extract_html($raw);
        self::assertStringStartsWith('<!DOCTYPE html>', $r);
    }

    public function testExtractCutsCommentaryPrelude(): void
    {
        $raw = "Sure, here's the page:\n<!DOCTYPE html><html></html>";
        $r = ai_landing_extract_html($raw);
        self::assertStringStartsWith('<!DOCTYPE html>', $r);
    }

    public function testExtractTrimsWhitespace(): void
    {
        $raw = "  \n\n<!DOCTYPE html><html></html>\n\n  ";
        $r = ai_landing_extract_html($raw);
        self::assertStringStartsWith('<!DOCTYPE html>', $r);
        self::assertStringEndsWith('</html>', $r);
    }

    public function testExtractReturnsEmptyOnEmpty(): void
    {
        self::assertSame('', ai_landing_extract_html(''));
        self::assertSame('', ai_landing_extract_html("   \n\n  "));
    }

    public function testExtractLeavesAlreadyCleanHtml(): void
    {
        $raw = "<!DOCTYPE html><html><body><h1>Login</h1></body></html>";
        self::assertSame($raw, ai_landing_extract_html($raw));
    }

    // --- ai_landing_parse_response ---------------------------------------

    public function testParseHappyPath(): void
    {
        $raw = json_encode([
            'id'    => 'msg_01',
            'type'  => 'message',
            'model' => 'claude-3-5-haiku-latest',
            'content' => [
                ['type' => 'text', 'text' => '<!DOCTYPE html><html><body>Hi</body></html>'],
            ],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 45],
        ]);
        $r = ai_landing_parse_response($raw);
        self::assertTrue($r['ok']);
        self::assertStringStartsWith('<!DOCTYPE html>', $r['html']);
        self::assertSame('claude-3-5-haiku-latest', $r['model']);
        self::assertSame(120, $r['input_tokens']);
        self::assertSame(45, $r['output_tokens']);
    }

    public function testParseConcatenatesMultipleTextBlocks(): void
    {
        $raw = json_encode([
            'content' => [
                ['type' => 'text', 'text' => '<!DOCTYPE html><html>'],
                ['type' => 'text', 'text' => '<body>Hi</body></html>'],
            ],
        ]);
        $r = ai_landing_parse_response($raw);
        self::assertTrue($r['ok']);
        self::assertStringContainsString('<body>Hi</body>', $r['html']);
    }

    public function testParseSurfacesErrorEnvelope(): void
    {
        $raw = json_encode([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'Invalid API key'],
        ]);
        $r = ai_landing_parse_response($raw);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Invalid API key', $r['err']);
    }

    public function testParseRejectsMissingContent(): void
    {
        $r = ai_landing_parse_response(json_encode(['meta' => []]));
        self::assertFalse($r['ok']);
        self::assertStringContainsString('content', $r['err']);
    }

    public function testParseRejectsNoTextBlocks(): void
    {
        $r = ai_landing_parse_response(json_encode([
            'content' => [['type' => 'image_url', 'url' => 'x']],
        ]));
        self::assertFalse($r['ok']);
    }

    public function testParseRejectsNonJsonBody(): void
    {
        $r = ai_landing_parse_response('<html>503</html>');
        self::assertFalse($r['ok']);
    }

    public function testParseRejectsEmptyExtractedHtml(): void
    {
        $r = ai_landing_parse_response(json_encode([
            'content' => [['type' => 'text', 'text' => '   ']],
        ]));
        self::assertFalse($r['ok']);
    }
}

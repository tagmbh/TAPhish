<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for spear/manager/url_query_parse.php.
 *
 * Pins the parsing contract used by filterQRBarCode() and the tracker
 * endpoints (tmail.php / qt.php) when reading incoming open / click URLs.
 */
final class UrlQueryParseTest extends TestCase
{
    public function testParsesSimpleQuery(): void
    {
        $out = taphish_url_query_parse('https://example.com/tk?mid=abc&rid=42');
        self::assertSame(['mid' => 'abc', 'rid' => '42'], $out);
    }

    public function testHtmlEncodedAmpersandIsDecoded(): void
    {
        // Real campaign URLs in mail bodies are often HTML-encoded — `&` becomes
        // `&amp;`. Without html_entity_decode the second param would be lost.
        $out = taphish_url_query_parse('https://example.com/tk?mid=abc&amp;rid=42');
        self::assertSame(['mid' => 'abc', 'rid' => '42'], $out);
    }

    public function testNoQueryReturnsEmptyArrayNotNull(): void
    {
        // Callers do `array_key_exists()` on the result — null would error
        // in PHP 8.5 strict mode.
        self::assertSame([], taphish_url_query_parse('https://example.com/path'));
        self::assertSame([], taphish_url_query_parse('https://example.com/path?'));
        self::assertSame([], taphish_url_query_parse('not-even-a-url'));
        self::assertSame([], taphish_url_query_parse(''));
    }

    public function testArrayBracketsBecomeNestedArrays(): void
    {
        // parse_str behaviour — c[]=x&c[]=y → ['c'=>['x','y']]. Pin so a
        // future switch to a stricter parser is a deliberate decision.
        $out = taphish_url_query_parse('https://e.com/?c[]=x&c[]=y&d=z');
        self::assertSame(['c' => ['x', 'y'], 'd' => 'z'], $out);
    }

    public function testQrBarcodeStyleUrlRoundtrip(): void
    {
        // Exactly the shape filterQRBarCode() consumes — type + content + name.
        $out = taphish_url_query_parse('https://e.com/?type=qr_b64&content=hello&name=code.png');
        self::assertSame('qr_b64',     $out['type']);
        self::assertSame('hello',      $out['content']);
        self::assertSame('code.png',   $out['name']);
    }

    public function testUrlEncodedValuesAreDecoded(): void
    {
        // parse_str decodes percent-encoded values.
        $out = taphish_url_query_parse('https://e.com/?x=hello%20world&y=a%26b');
        self::assertSame('hello world', $out['x']);
        self::assertSame('a&b',         $out['y']);
    }

    public function testFragmentIsIgnored(): void
    {
        // A URL fragment (#section) is not part of the query.
        $out = taphish_url_query_parse('https://e.com/p?a=1#fragment-not-counted');
        self::assertSame(['a' => '1'], $out);
    }

    public function testQueryOnlyInputIsTolerated(): void
    {
        // Callers sometimes pass just the query string (no scheme/host).
        // parse_url returns the whole input as path → query is null →
        // graceful empty array.
        self::assertSame([], taphish_url_query_parse('a=1&b=2'));
    }
}

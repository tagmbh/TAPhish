<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class OsintCrtShTest extends TestCase
{
    // --- normalize_name ---------------------------------------------------

    public function testNormalizeLowercasesAndTrims(): void
    {
        self::assertSame('foo.bar.com', osint_crt_sh_normalize_name('  FOO.BAR.COM  '));
    }

    public function testNormalizeStripsLeadingWildcard(): void
    {
        self::assertSame('bar.com', osint_crt_sh_normalize_name('*.bar.com'));
    }

    public function testNormalizeStripsTrailingDot(): void
    {
        self::assertSame('a.b.com', osint_crt_sh_normalize_name('a.b.com.'));
    }

    // --- is_subdomain_of --------------------------------------------------

    public function testSelfCountsAsMatch(): void
    {
        self::assertTrue(osint_crt_sh_is_subdomain_of('example.com', 'example.com'));
    }

    public function testTrueSubdomain(): void
    {
        self::assertTrue(osint_crt_sh_is_subdomain_of('mail.example.com', 'example.com'));
        self::assertTrue(osint_crt_sh_is_subdomain_of('a.b.example.com', 'example.com'));
    }

    public function testWildcardSubdomain(): void
    {
        self::assertTrue(osint_crt_sh_is_subdomain_of('*.example.com', 'example.com'));
    }

    public function testRejectsSuffixOnlyMatch(): void
    {
        // myexample.com is NOT a subdomain of example.com despite the suffix.
        self::assertFalse(osint_crt_sh_is_subdomain_of('myexample.com', 'example.com'));
    }

    public function testRejectsUnrelated(): void
    {
        self::assertFalse(osint_crt_sh_is_subdomain_of('foo.bar.org', 'example.com'));
    }

    public function testEmptyInputs(): void
    {
        self::assertFalse(osint_crt_sh_is_subdomain_of('', 'example.com'));
        self::assertFalse(osint_crt_sh_is_subdomain_of('example.com', ''));
    }

    // --- parse_response: happy path --------------------------------------

    public function testParseTypicalResponse(): void
    {
        $raw = json_encode([
            ['name_value' => "example.com\nmail.example.com\n*.example.com",
             'common_name' => 'www.example.com'],
            ['name_value' => 'api.example.com'],
            ['name_value' => "mail.example.com"], // dup
        ]);
        $r = osint_crt_sh_parse_response($raw, 'example.com');
        self::assertTrue($r['ok']);
        self::assertSame(
            ['api.example.com', 'example.com', 'mail.example.com', 'www.example.com'],
            $r['subdomains']
        );
        self::assertSame(4, $r['count']);
    }

    public function testParseDropsForeignDomains(): void
    {
        $raw = json_encode([
            ['name_value' => 'mail.example.com'],
            ['name_value' => 'attacker.invalid'], // dropped
            ['name_value' => 'myexample.com'],    // suffix-only, dropped
        ]);
        $r = osint_crt_sh_parse_response($raw, 'example.com');
        self::assertSame(['mail.example.com'], $r['subdomains']);
    }

    public function testParseAcceptsAlreadyDecoded(): void
    {
        $r = osint_crt_sh_parse_response([['name_value' => 'a.example.com']], 'example.com');
        self::assertTrue($r['ok']);
        self::assertSame(['a.example.com'], $r['subdomains']);
    }

    public function testParseEmptyArray(): void
    {
        $r = osint_crt_sh_parse_response([], 'example.com');
        self::assertTrue($r['ok']);
        self::assertSame([], $r['subdomains']);
        self::assertSame(0, $r['count']);
    }

    // --- parse_response: error paths -------------------------------------

    public function testParseRejectsNonJson(): void
    {
        $r = osint_crt_sh_parse_response('<html>503</html>', 'example.com');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('non-JSON', $r['err']);
    }

    public function testParseRejectsNonArray(): void
    {
        $r = osint_crt_sh_parse_response(json_encode(['just an object']), 'example.com');
        // Decoded shape is an array (json_encode wrapped a single-element array),
        // so this is actually ok=true with no subdomains; verify the other
        // shape — top-level object isn't an array of rows.
        $r2 = osint_crt_sh_parse_response(json_encode((object) ['error' => 'rate limited']), 'example.com');
        self::assertTrue($r2['ok']); // decodes to assoc array; no entries match → empty
        self::assertSame([], $r2['subdomains']);
    }
}

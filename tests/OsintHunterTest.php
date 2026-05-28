<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class OsintHunterTest extends TestCase
{
    // --- domain validation ------------------------------------------------

    public function testValidDomainsAccepted(): void
    {
        foreach (['example.com', 'a.b.c.d', 'foo-bar.example.co.uk', 'xn--bcher-kva.de'] as $d) {
            self::assertTrue(osint_hunter_is_valid_domain($d), "Expected valid: $d");
        }
    }

    public function testInvalidDomainsRejected(): void
    {
        foreach (['', 'no-tld', '.com', 'a..b.com', 'example com', 'http://x.com'] as $d) {
            self::assertFalse(osint_hunter_is_valid_domain($d), "Expected invalid: $d");
        }
    }

    public function testDomainCaseInsensitive(): void
    {
        self::assertTrue(osint_hunter_is_valid_domain('EXAMPLE.COM'));
    }

    public function testDomainLengthCap(): void
    {
        self::assertFalse(osint_hunter_is_valid_domain(str_repeat('a', 254) . '.com'));
    }

    // --- API key validation ----------------------------------------------

    public function testApiKeyAcceptsHex40(): void
    {
        self::assertTrue(osint_hunter_is_valid_api_key(str_repeat('a', 40)));
        self::assertTrue(osint_hunter_is_valid_api_key('0123456789abcdef0123456789abcdef01234567'));
    }

    public function testApiKeyAcceptsUppercase(): void
    {
        self::assertTrue(osint_hunter_is_valid_api_key(strtoupper(str_repeat('a', 40))));
    }

    public function testApiKeyRejectsWrongLengthOrNonHex(): void
    {
        self::assertFalse(osint_hunter_is_valid_api_key(str_repeat('a', 39)));
        self::assertFalse(osint_hunter_is_valid_api_key(str_repeat('a', 41)));
        self::assertFalse(osint_hunter_is_valid_api_key(str_repeat('z', 40)));
        self::assertFalse(osint_hunter_is_valid_api_key(''));
    }

    // --- parser: happy path ----------------------------------------------

    public function testParseTypicalResponse(): void
    {
        $raw = json_encode([
            'data' => [
                'domain'       => 'example.com',
                'organization' => 'Example Corp',
                'emails' => [
                    [
                        'value'      => 'alice@example.com',
                        'first_name' => 'Alice',
                        'last_name'  => 'Adams',
                        'position'   => 'CEO',
                        'confidence' => 95,
                        'type'       => 'personal',
                    ],
                    [
                        'value'      => 'sales@example.com',
                        'first_name' => null,
                        'last_name'  => null,
                        'position'   => null,
                        'confidence' => 80,
                        'type'       => 'generic',
                    ],
                ],
            ],
        ]);
        $r = osint_hunter_parse_domain_search($raw);
        self::assertTrue($r['ok']);
        self::assertSame('example.com', $r['domain']);
        self::assertSame('Example Corp', $r['organization']);
        self::assertCount(2, $r['results']);

        self::assertSame('alice@example.com', $r['results'][0]['email']);
        self::assertSame('Alice Adams', $r['results'][0]['name']);
        self::assertSame('CEO', $r['results'][0]['position']);
        self::assertSame(95, $r['results'][0]['confidence']);
        self::assertSame('personal', $r['results'][0]['type']);

        self::assertSame('sales@example.com', $r['results'][1]['email']);
        self::assertSame('', $r['results'][1]['name']);
        self::assertSame('', $r['results'][1]['position']);
    }

    public function testParseAcceptsAlreadyDecodedArray(): void
    {
        $payload = ['data' => ['domain' => 'x.com', 'emails' => []]];
        $r = osint_hunter_parse_domain_search($payload);
        self::assertTrue($r['ok']);
        self::assertSame([], $r['results']);
    }

    // --- parser: error paths ---------------------------------------------

    public function testParseSurfacesHunterErrorArray(): void
    {
        $raw = json_encode([
            'errors' => [
                ['id' => 'invalid_api_key', 'code' => 401, 'details' => 'Invalid API key'],
            ],
        ]);
        $r = osint_hunter_parse_domain_search($raw);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Invalid API key', $r['err']);
    }

    public function testParseRejectsNonJsonString(): void
    {
        $r = osint_hunter_parse_domain_search('<html>503</html>');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('non-JSON', $r['err']);
    }

    public function testParseRejectsMissingDataField(): void
    {
        $r = osint_hunter_parse_domain_search(json_encode(['meta' => []]));
        self::assertFalse($r['ok']);
        self::assertStringContainsString('missing data', $r['err']);
    }

    public function testParseSkipsMalformedEmailRows(): void
    {
        $raw = json_encode([
            'data' => [
                'emails' => [
                    ['value' => 'ok@example.com', 'confidence' => 60],
                    ['value' => '',              'confidence' => 40], // dropped
                    'not-an-object',                                 // dropped
                    null,                                            // dropped
                ],
            ],
        ]);
        $r = osint_hunter_parse_domain_search($raw);
        self::assertTrue($r['ok']);
        self::assertCount(1, $r['results']);
        self::assertSame('ok@example.com', $r['results'][0]['email']);
    }

    public function testParseNormalizesNonNumericConfidence(): void
    {
        $raw = json_encode([
            'data' => [
                'emails' => [
                    ['value' => 'x@x.com', 'confidence' => 'high'],
                ],
            ],
        ]);
        $r = osint_hunter_parse_domain_search($raw);
        self::assertTrue($r['ok']);
        self::assertSame(0, $r['results'][0]['confidence']);
    }

    // --- email-finder parser (Phase 3.13) --------------------------------

    public function testFinderParseHappyPath(): void
    {
        $raw = json_encode([
            'data' => [
                'email'        => 'alice.adams@example.com',
                'first_name'   => 'Alice',
                'last_name'    => 'Adams',
                'position'     => 'CEO',
                'score'        => 92,
                'domain'       => 'example.com',
                'organization' => 'Example Corp',
            ],
        ]);
        $r = osint_hunter_parse_email_finder($raw);
        self::assertTrue($r['ok']);
        self::assertSame('Example Corp', $r['organization']);
        self::assertCount(1, $r['results']);
        $row = $r['results'][0];
        self::assertSame('alice.adams@example.com', $row['email']);
        self::assertSame('Alice Adams', $row['name']);
        self::assertSame('CEO', $row['position']);
        self::assertSame(92, $row['confidence']);
        self::assertSame('finder', $row['type']);
    }

    public function testFinderParseEmptyResult(): void
    {
        $raw = json_encode([
            'data' => ['email' => null, 'domain' => 'example.com'],
        ]);
        $r = osint_hunter_parse_email_finder($raw);
        self::assertTrue($r['ok']);
        self::assertSame([], $r['results']);
    }

    public function testFinderParseSurfacesErrorArray(): void
    {
        $raw = json_encode([
            'errors' => [['id' => 'invalid_api_key', 'details' => 'Invalid API key']],
        ]);
        $r = osint_hunter_parse_email_finder($raw);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Invalid API key', $r['err']);
    }

    public function testFinderParseRejectsMissingDataField(): void
    {
        $r = osint_hunter_parse_email_finder(json_encode(['meta' => []]));
        self::assertFalse($r['ok']);
        self::assertStringContainsString('missing data', $r['err']);
    }

    public function testFinderParseRejectsNonJsonBody(): void
    {
        $r = osint_hunter_parse_email_finder('<html>503</html>');
        self::assertFalse($r['ok']);
    }

    public function testFinderParseNormalizesNonNumericScore(): void
    {
        $raw = json_encode([
            'data' => ['email' => 'x@x.com', 'score' => 'high'],
        ]);
        $r = osint_hunter_parse_email_finder($raw);
        self::assertSame(0, $r['results'][0]['confidence']);
    }
}

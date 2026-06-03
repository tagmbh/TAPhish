<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.54: domain validation + IDNA tests. Pure parser + filter +
 * the injectable-HTTP one-shot. No network.
 */
final class DomainCheckTest extends TestCase
{
    public function testParseValidAsciiResponse(): void
    {
        $r = domain_check_parse('{"name":"texti1color.ch","nameIdna":"texti1color.ch","valid":true,"idnaEncoded":false}');
        self::assertTrue($r['ok']);
        self::assertTrue($r['valid']);
        self::assertSame('texti1color.ch', $r['name_idna']);
        self::assertFalse($r['idna_encoded']);
    }

    public function testParseIdnResponseCarriesPunycode(): void
    {
        $r = domain_check_parse('{"name":"müller.ch","nameIdna":"xn--mller-kva.ch","valid":true,"idnaEncoded":true}');
        self::assertTrue($r['valid']);
        self::assertSame('xn--mller-kva.ch', $r['name_idna']);
        self::assertTrue($r['idna_encoded']);
    }

    public function testParseInvalidName(): void
    {
        $r = domain_check_parse('{"name":"x.ch","nameIdna":"x.ch","valid":false,"idnaEncoded":false}');
        self::assertTrue($r['ok']);
        self::assertFalse($r['valid']);
    }

    public function testParseRejectsJunk(): void
    {
        self::assertFalse(domain_check_parse('not json')['ok']);
        self::assertFalse(domain_check_parse('{"no":"valid-key"}')['ok']);
    }

    public function testCheckOneUsesInjectedHttp(): void
    {
        $fake = function ($url) {
            self::assertStringContainsString('domain-check?domain=m%C3%BCller.ch', $url);
            return ['status' => 200, 'body' => '{"name":"müller.ch","nameIdna":"xn--mller-kva.ch","valid":true,"idnaEncoded":true}'];
        };
        $r = domain_check_one('müller.ch', $fake);
        self::assertTrue($r['valid']);
        self::assertSame('xn--mller-kva.ch', $r['name_idna']);
        self::assertSame('hostpoint', $r['source']);
    }

    public function testCheckOneFallsBackToLocalOnHttpFailure(): void
    {
        $fake = fn() => ['status' => 0, 'body' => ''];
        $r = domain_check_one('example.ch', $fake);
        self::assertSame('local', $r['source']);
        self::assertTrue($r['valid']);
    }

    public function testFilterValidDropsInvalidAndEnriches(): void
    {
        $candidates = [
            ['domain' => 'good.ch',    'kind' => 'homoglyph', 'score' => 90],
            ['domain' => 'bad.ch',     'kind' => 'typo',      'score' => 70],
            ['domain' => 'idn.ch',     'kind' => 'homoglyph', 'score' => 88],
        ];
        $checks = [
            'good.ch' => ['valid' => true,  'name_idna' => 'good.ch',         'idna_encoded' => false],
            'bad.ch'  => ['valid' => false, 'name_idna' => 'bad.ch',          'idna_encoded' => false],
            'idn.ch'  => ['valid' => true,  'name_idna' => 'xn--idn-xyz.ch',  'idna_encoded' => true],
        ];
        $out = domain_check_filter_valid($candidates, $checks);
        self::assertCount(2, $out);
        self::assertSame('good.ch', $out[0]['domain']);
        self::assertSame('idn.ch',  $out[1]['domain']);
        self::assertSame('xn--idn-xyz.ch', $out[1]['name_idna']);
        self::assertTrue($out[1]['idna_encoded']);
    }

    public function testFilterValidDropsUncheckedDomains(): void
    {
        $out = domain_check_filter_valid(
            [['domain' => 'unchecked.ch', 'kind' => 'typo', 'score' => 50]],
            []
        );
        self::assertSame([], $out);
    }
}

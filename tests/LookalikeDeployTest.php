<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.55 — pure tests for the look-alike DNS-record builder + vanity-slug
 * validator. No DNS, no network: the builder is string construction over the
 * reused dkim_helper / domain_check helpers.
 */
final class LookalikeDeployTest extends TestCase
{
    public function testVanitySlugValidation(): void
    {
        self::assertTrue(lookalike_validate_vanity_slug('texti1color-login'));
        self::assertTrue(lookalike_validate_vanity_slug('a'));
        self::assertTrue(lookalike_validate_vanity_slug('m365'));
        self::assertFalse(lookalike_validate_vanity_slug(''));
        self::assertFalse(lookalike_validate_vanity_slug('-leading'));
        self::assertFalse(lookalike_validate_vanity_slug('Upper'));
        self::assertFalse(lookalike_validate_vanity_slug('has space'));
        self::assertFalse(lookalike_validate_vanity_slug('dot.dot'));
        self::assertFalse(lookalike_validate_vanity_slug(str_repeat('a', 42)));
    }

    private function recordsByType(array $records): array
    {
        $by = [];
        foreach ($records as $r) {
            $by[$r['type']][] = $r;
        }
        return $by;
    }

    public function testOperatorModeEmitsARecordAndMailAuth(): void
    {
        $records = lookalike_build_dns_records('texti1color.ch', [
            'mode'       => 'operator',
            'subdomain'  => 'login',
            'a_record'   => '203.0.113.10',
            'selector'   => 's1',
            'dkim_pubkey'=> 'MIIBpubkey',
            'dmarc_rua'  => 'soc@example.com',
        ]);
        // every record carries the four keys
        foreach ($records as $r) {
            self::assertArrayHasKey('type', $r);
            self::assertArrayHasKey('host', $r);
            self::assertArrayHasKey('value', $r);
            self::assertArrayHasKey('note', $r);
        }
        $by = $this->recordsByType($records);

        self::assertArrayHasKey('A', $by);
        self::assertSame('login.texti1color.ch.', $by['A'][0]['host']);
        self::assertSame('203.0.113.10', $by['A'][0]['value']);

        // SPF at apex, DKIM at selector._domainkey, DMARC at _dmarc
        $txtHosts = array_column($by['TXT'], 'host');
        self::assertContains('texti1color.ch.', $txtHosts);
        self::assertContains('s1._domainkey.texti1color.ch.', $txtHosts);
        self::assertContains('_dmarc.texti1color.ch.', $txtHosts);

        // DKIM value uses the supplied public key
        $dkim = array_values(array_filter($by['TXT'], fn($r) => $r['host'] === 's1._domainkey.texti1color.ch.'))[0];
        self::assertStringContainsString('v=DKIM1; k=rsa; p=MIIBpubkey', $dkim['value']);

        // DMARC default monitoring policy + rua
        $dmarc = array_values(array_filter($by['TXT'], fn($r) => $r['host'] === '_dmarc.texti1color.ch.'))[0];
        self::assertStringContainsString('v=DMARC1; p=none', $dmarc['value']);
        self::assertStringContainsString('rua=mailto:soc@example.com', $dmarc['value']);
    }

    public function testHostedModeEmitsCnameToTarget(): void
    {
        $records = lookalike_build_dns_records('texti1color.ch', [
            'mode'         => 'hosted',
            'subdomain'    => 'login',
            'cname_target' => 'ptbe.autodiscover.li',
        ]);
        $by = $this->recordsByType($records);
        self::assertArrayHasKey('CNAME', $by);
        self::assertSame('login.texti1color.ch.', $by['CNAME'][0]['host']);
        self::assertSame('ptbe.autodiscover.li.', $by['CNAME'][0]['value']);
        self::assertArrayNotHasKey('A', $by);
    }

    public function testDkimPlaceholderWhenNoPubkey(): void
    {
        $records = lookalike_build_dns_records('texti1color.ch', ['mode' => 'operator', 'a_record' => '203.0.113.10']);
        $by = $this->recordsByType($records);
        $dkim = array_values(array_filter($by['TXT'], fn($r) => str_contains($r['host'], '_domainkey')))[0];
        self::assertStringContainsString('<public-key>', $dkim['value']);
    }

    public function testIdnDomainHostsArePunycoded(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('intl extension not available');
        }
        $records = lookalike_build_dns_records('tëxtilcolor.ch', ['mode' => 'operator', 'a_record' => '203.0.113.10']);
        foreach ($records as $r) {
            self::assertStringContainsString('xn--', $r['host'], 'IDN host should be punycoded: ' . $r['host']);
        }
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.46-pre: pure-helper tests for the Shodan OSINT integration.
 *
 * Live HTTP path + curl wiring live in the integration tier; here we
 * cover validation, the response parser, and the injectable-resolver
 * seam.
 */
final class OsintShodanTest extends TestCase
{
    public function testApiKeyAcceptsAlphanumeric32(): void
    {
        self::assertTrue(osint_shodan_is_valid_api_key(str_repeat('a', 32)));
        self::assertTrue(osint_shodan_is_valid_api_key('Abc123' . str_repeat('x', 26)));
    }

    public function testApiKeyRejectsWrongLength(): void
    {
        self::assertFalse(osint_shodan_is_valid_api_key(str_repeat('a', 31)));
        self::assertFalse(osint_shodan_is_valid_api_key(str_repeat('a', 33)));
        self::assertFalse(osint_shodan_is_valid_api_key(''));
    }

    public function testApiKeyRejectsSpecialChars(): void
    {
        self::assertFalse(osint_shodan_is_valid_api_key(str_repeat('a', 31) . '-'));
        self::assertFalse(osint_shodan_is_valid_api_key(str_repeat('a', 31) . ' '));
    }

    public function testTargetAcceptsDomainOrIp(): void
    {
        self::assertTrue(osint_shodan_is_valid_domain_or_ip('example.com'));
        self::assertTrue(osint_shodan_is_valid_domain_or_ip('1.2.3.4'));
        self::assertTrue(osint_shodan_is_valid_domain_or_ip('::1'));
        self::assertTrue(osint_shodan_is_valid_domain_or_ip('2001:db8::1'));
    }

    public function testTargetRejectsJunk(): void
    {
        self::assertFalse(osint_shodan_is_valid_domain_or_ip(''));
        self::assertFalse(osint_shodan_is_valid_domain_or_ip('no-tld'));
        self::assertFalse(osint_shodan_is_valid_domain_or_ip('http://x.com'));
    }

    public function testParseSurfacesShodanErrorMessage(): void
    {
        $r = osint_shodan_parse_host(json_encode([
            'error' => 'No information available for that IP.',
        ]));
        self::assertFalse($r['ok']);
        self::assertStringContainsString('No information available', $r['err']);
    }

    public function testParseRejectsNonJsonPayload(): void
    {
        $r = osint_shodan_parse_host('not json');
        self::assertFalse($r['ok']);
    }

    public function testParseHappyPathExtractsCorePorts(): void
    {
        $r = osint_shodan_parse_host(json_encode([
            'ip_str'       => '203.0.113.42',
            'hostnames'    => ['login.example.com', 'www.example.com'],
            'org'          => 'Example Org',
            'isp'          => 'Example ISP',
            'country_name' => 'Switzerland',
            'os'           => null,
            'ports'        => [443, 22, 80],
            'last_update'  => '2026-05-31T12:00:00.000000',
            'data'         => [
                ['port' => 22,  'product' => 'OpenSSH', 'data' => "SSH-2.0-OpenSSH_8.4\r\nMore"],
                ['port' => 443, 'product' => 'nginx',   'data' => "HTTP/1.1 200 OK"],
            ],
            'vulns'        => [
                'CVE-2023-1234' => ['cvss' => 9.8],
                'CVE-2022-9999' => ['cvss' => 7.5],
            ],
        ]));
        self::assertTrue($r['ok']);
        self::assertSame('203.0.113.42', $r['ip']);
        self::assertSame(['login.example.com', 'www.example.com'], $r['hostnames']);
        self::assertSame('Example Org', $r['org']);
        self::assertSame([22, 80, 443], $r['open_ports']); // sorted
        self::assertCount(2, $r['top_services']);
        self::assertSame(22, $r['top_services'][0]['port']);
        self::assertSame('OpenSSH', $r['top_services'][0]['product']);
        // banner gets truncated at the first newline
        self::assertStringNotContainsString("\n", $r['top_services'][0]['banner']);
        self::assertSame(['CVE-2022-9999', 'CVE-2023-1234'], $r['vulns']); // sorted, capped
    }

    public function testParseHandlesMissingOptionalFields(): void
    {
        $r = osint_shodan_parse_host(json_encode([
            'ip_str' => '1.2.3.4',
            'ports'  => [],
        ]));
        self::assertTrue($r['ok']);
        self::assertSame([], $r['hostnames']);
        self::assertSame([], $r['open_ports']);
        self::assertSame([], $r['top_services']);
        self::assertSame([], $r['vulns']);
        self::assertNull($r['org']);
    }

    public function testParseCapsTopServicesAtEight(): void
    {
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = ['port' => 1000 + $i, 'product' => 'p' . $i, 'data' => 'banner'];
        }
        $r = osint_shodan_parse_host(json_encode([
            'ip_str' => '1.2.3.4',
            'ports'  => [],
            'data'   => $rows,
        ]));
        self::assertCount(8, $r['top_services']);
    }

    public function testParseTruncatesLongBanners(): void
    {
        $long = str_repeat('A', 500);
        $r = osint_shodan_parse_host(json_encode([
            'ip_str' => '1.2.3.4',
            'data'   => [['port' => 80, 'product' => 'nginx', 'data' => $long]],
        ]));
        self::assertLessThanOrEqual(203, strlen($r['top_services'][0]['banner']));
        self::assertStringEndsWith('...', $r['top_services'][0]['banner']);
    }

    public function testResolveDomainPassesThroughLiteralIp(): void
    {
        self::assertSame('1.2.3.4', osint_shodan_resolve_domain('1.2.3.4'));
        self::assertSame('::1',     osint_shodan_resolve_domain('::1'));
    }

    public function testResolveDomainUsesInjectedResolver(): void
    {
        $resolver = static fn(string $d): string => '198.51.100.7';
        self::assertSame('198.51.100.7', osint_shodan_resolve_domain('example.com', $resolver));
    }

    public function testResolveDomainReturnsEmptyOnFailure(): void
    {
        // gethostbyname() returns the input domain string on failure;
        // simulate that with the injected resolver.
        $resolver = static fn(string $d): string => $d;
        self::assertSame('', osint_shodan_resolve_domain('does-not-resolve.example', $resolver));
    }

    public function testResolveDomainRejectsBogusReturn(): void
    {
        $resolver = static fn(string $d): string => 'not-an-ip';
        self::assertSame('', osint_shodan_resolve_domain('example.com', $resolver));
    }

    public function testLiveLookupBailsOnInvalidInputBeforeNetwork(): void
    {
        $r = osint_shodan_host_lookup('', 'x');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Invalid target', $r['err']);

        $r = osint_shodan_host_lookup('example.com', 'short-key');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Invalid Shodan API key', $r['err']);
    }
}

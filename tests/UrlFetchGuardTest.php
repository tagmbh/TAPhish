<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P2.0 — SSRF guard for the Import-HTML fetch (web_tracker_generator's
 * getHTMLContent). That feature legitimately fetches an EXTERNAL page to import
 * its form fields, so the landing-probe guard (same-host only) doesn't fit — we
 * need a "public URL only" guard: http(s) scheme, and the host must not be a
 * private / loopback / link-local / reserved address (blocks
 * http://169.254.169.254/, http://127.0.0.1/, http://192.168.x/, localhost, …).
 * The DNS-resolution half is done in the manager; these are the pure parts.
 */
final class UrlFetchGuardTest extends TestCase
{
    public function testPublicIpv4IsPublic(): void
    {
        self::assertTrue(taphish_ip_is_public('8.8.8.8'));
        self::assertTrue(taphish_ip_is_public('1.1.1.1'));
    }

    public function testPrivateAndReservedIpv4AreNotPublic(): void
    {
        foreach (['10.0.0.5', '172.16.3.4', '192.168.1.1', '127.0.0.1', '169.254.169.254', '0.0.0.0'] as $ip) {
            self::assertFalse(taphish_ip_is_public($ip), "$ip must be rejected");
        }
    }

    public function testIpv6LoopbackRejectedPublicAllowed(): void
    {
        self::assertFalse(taphish_ip_is_public('::1'));
        self::assertTrue(taphish_ip_is_public('2001:4860:4860::8888'));
    }

    public function testGarbageIpIsNotPublic(): void
    {
        self::assertFalse(taphish_ip_is_public('not-an-ip'));
        self::assertFalse(taphish_ip_is_public(''));
    }

    public function testPrecheckAcceptsPublicHttpUrls(): void
    {
        $r = taphish_fetch_url_precheck('https://login.microsoftonline.com/common');
        self::assertTrue($r['ok']);
        self::assertSame('login.microsoftonline.com', $r['host']);
    }

    public function testPrecheckRejectsNonHttpSchemes(): void
    {
        foreach (['ftp://example.com', 'file:///etc/passwd', 'gopher://x', 'ssh://h'] as $u) {
            self::assertFalse(taphish_fetch_url_precheck($u)['ok'], "$u must be rejected");
        }
    }

    public function testPrecheckRejectsLiteralInternalIps(): void
    {
        foreach (['http://169.254.169.254/latest/meta-data', 'http://127.0.0.1/', 'http://192.168.0.1/', 'http://[::1]/'] as $u) {
            self::assertFalse(taphish_fetch_url_precheck($u)['ok'], "$u must be rejected");
        }
    }

    public function testPrecheckRejectsLocalhostNames(): void
    {
        self::assertFalse(taphish_fetch_url_precheck('http://localhost/x')['ok']);
        self::assertFalse(taphish_fetch_url_precheck('http://printer.local/')['ok']);
    }

    public function testPrecheckAllowsPublicLiteralIp(): void
    {
        self::assertTrue(taphish_fetch_url_precheck('http://8.8.8.8/')['ok']);
    }

    public function testPrecheckRejectsGarbage(): void
    {
        self::assertFalse(taphish_fetch_url_precheck('not a url')['ok']);
        self::assertFalse(taphish_fetch_url_precheck('http://')['ok']);
        self::assertFalse(taphish_fetch_url_precheck('')['ok']);
    }
}

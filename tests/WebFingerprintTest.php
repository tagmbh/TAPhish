<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class WebFingerprintTest extends TestCase
{
    public function testParseHtmlPullsTitle(): void
    {
        $html = '<html><head><title>  Acme Bank Login  </title></head><body></body></html>';
        $p = taphish_web_parse_html($html);
        self::assertSame('Acme Bank Login', $p['title']);
    }

    public function testParseHtmlDecodesEntities(): void
    {
        $p = taphish_web_parse_html('<title>Q&amp;A &#x2014; Acme</title>');
        self::assertSame('Q&A — Acme', $p['title']);
    }

    public function testParseHtmlPicksGenerator(): void
    {
        $html = '<meta name="generator" content="WordPress 6.5.2" />';
        $p = taphish_web_parse_html($html);
        self::assertSame('WordPress 6.5.2', $p['generator']);
    }

    public function testParseHtmlIgnoresMissingMeta(): void
    {
        $p = taphish_web_parse_html('<html><head></head></html>');
        self::assertSame('', $p['title']);
        self::assertSame('', $p['generator']);
    }

    public function testParseRobotsPullsSitemapAndDisallow(): void
    {
        $txt = "# top comment\nUser-agent: *\nDisallow: /admin\nDisallow: /private\nSitemap: https://acme.test/sitemap.xml\n";
        $p = taphish_web_parse_robots($txt);
        self::assertSame(['https://acme.test/sitemap.xml'], $p['sitemaps']);
        self::assertSame(['/admin', '/private'], $p['disallow_hits']);
    }

    public function testParseRobotsDedupesAndCapsDisallow(): void
    {
        $lines = "User-agent: *\n";
        for ($i = 0; $i < 40; $i++) {
            $lines .= "Disallow: /path-$i\n";
        }
        $p = taphish_web_parse_robots($lines);
        self::assertSame(25, count($p['disallow_hits']));
    }

    public function testParseSecurityTxtPullsContactPolicyExpires(): void
    {
        $txt = "Contact: mailto:soc@acme.test\nPolicy: https://acme.test/policy\nExpires: 2027-01-01T00:00:00Z\n";
        $p = taphish_web_parse_security_txt($txt);
        self::assertSame(['mailto:soc@acme.test'], $p['contact']);
        self::assertSame(['https://acme.test/policy'], $p['policy']);
        self::assertSame('2027-01-01T00:00:00Z', $p['expires']);
    }

    public function testFingerprintRespectsInjectedFetcher(): void
    {
        $fetcher = function (string $url) {
            if (str_ends_with($url, '/')) {
                return ['ok' => true, 'status' => 200, 'body' => '<title>Acme</title><meta name="generator" content="WordPress 6.5">', 'error' => ''];
            }
            if (str_ends_with($url, '/robots.txt')) {
                return ['ok' => true, 'status' => 200, 'body' => "Disallow: /admin\nSitemap: https://acme.test/sitemap.xml\n", 'error' => ''];
            }
            if (str_ends_with($url, '/security.txt')) {
                return ['ok' => true, 'status' => 200, 'body' => "Contact: mailto:soc@acme.test\nExpires: 2028-01-01T00:00:00Z\n", 'error' => ''];
            }
            return ['ok' => false, 'status' => 404, 'body' => '', 'error' => ''];
        };

        $r = taphish_web_fingerprint('acme.test', $fetcher);
        self::assertSame('acme.test', $r['domain']);
        self::assertTrue($r['reachable']);
        self::assertSame('Acme', $r['title']);
        self::assertSame('WordPress 6.5', $r['generator']);
        self::assertTrue($r['robots']['present']);
        self::assertSame(['https://acme.test/sitemap.xml'], $r['robots']['sitemaps']);
        self::assertSame(['/admin'], $r['robots']['disallow_hits']);
        self::assertTrue($r['security_txt']['present']);
        self::assertSame('2028-01-01T00:00:00Z', $r['security_txt']['expires']);
    }

    public function testFingerprintFailsCleanlyOnEmptyDomain(): void
    {
        $r = taphish_web_fingerprint('', fn () => ['ok' => true, 'status' => 200, 'body' => '', 'error' => '']);
        self::assertFalse($r['reachable']);
        self::assertSame(0, $r['status']);
    }

    public function testFingerprintReportsUnreachable(): void
    {
        $fetcher = fn () => ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'connection refused'];
        $r = taphish_web_fingerprint('acme.test', $fetcher);
        self::assertFalse($r['reachable']);
        self::assertFalse($r['robots']['present']);
        self::assertFalse($r['security_txt']['present']);
    }
}

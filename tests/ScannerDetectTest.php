<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.40: pure-helper tests for the URL-scanner classifier.
 *
 * Only the classifier itself is in scope. The DNS-PTR resolver is a
 * thin gethostbyaddr() wrapper and lives outside the unit suite.
 */
final class ScannerDetectTest extends TestCase
{
    // ---- Real visitor baseline ----

    public function testRealConsumerChromeReportsReal(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
            'b178-50-12-89-104.dynamic.swisscom.net',
            45
        );
        self::assertSame('real', $r['kind']);
    }

    public function testRealMobileSafariReportsReal(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            '',
            120
        );
        self::assertSame('real', $r['kind']);
    }

    // ---- UA-marker scanner hits ----

    public function testM365SafeLinksReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 (Windows NT 10.0) ... SafeLinks/3.0',
            '',
            -1
        );
        self::assertSame('scanner', $r['kind']);
        self::assertStringContainsString('safelinks', $r['reason']);
    }

    public function testProofpointUrlDefenseReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'ProofPoint URLDefense 1.0',
            '',
            -1
        );
        self::assertSame('scanner', $r['kind']);
    }

    public function testMimecastReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Mimecast Link Check 2.0',
            '',
            -1
        );
        self::assertSame('scanner', $r['kind']);
    }

    public function testSlackImgproxyReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Slack-ImgProxy 1.171 (+https://api.slack.com/robots)',
            '',
            -1
        );
        self::assertSame('scanner', $r['kind']);
    }

    public function testWhatsappPreviewReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'WhatsApp/2.24',
            '',
            -1
        );
        self::assertSame('scanner', $r['kind']);
    }

    // ---- PTR-based scanner hits ----

    public function testAwsCompoundedPtrReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 …',
            'ec2-3-12-44-181.compute-1.amazonaws.com',
            -1
        );
        self::assertSame('scanner', $r['kind']);
        self::assertStringContainsString('amazonaws', $r['reason']);
    }

    public function testAzureWebsitesPtrReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 …',
            'something.azurewebsites.net',
            -1
        );
        self::assertSame('scanner', $r['kind']);
    }

    public function testGoogleUserContentPtrReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 …',
            '6.92.245.34.bc.googleusercontent.com',
            -1
        );
        self::assertSame('scanner', $r['kind']);
    }

    public function testShodanPtrReportsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 …',
            'scan-25.shodan.io',
            -1
        );
        self::assertSame('scanner', $r['kind']);
    }

    // ---- Timing signal ----

    public function testHitWithinFiveSecondsOfSendIsScanner(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
            '',
            2
        );
        self::assertSame('scanner', $r['kind']);
        self::assertStringContainsString('within 2s', $r['reason']);
    }

    public function testHitTwoMinutesAfterSendIsNotScannerByTiming(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
            '',
            120
        );
        self::assertSame('real', $r['kind']);
    }

    // ---- Suspect bucket ----

    public function testMissingUaReportsSuspect(): void
    {
        $r = taphish_classify_visitor('', '', -1);
        self::assertSame('suspect', $r['kind']);
        self::assertStringContainsString('missing', $r['reason']);
    }

    public function testCurlScriptUaReportsSuspect(): void
    {
        $r = taphish_classify_visitor('curl/8.4.0', '', -1);
        self::assertSame('suspect', $r['kind']);
    }

    public function testPythonRequestsReportsSuspect(): void
    {
        $r = taphish_classify_visitor('python-requests/2.31.0', '', -1);
        self::assertSame('suspect', $r['kind']);
    }

    public function testGoHttpClientReportsSuspect(): void
    {
        $r = taphish_classify_visitor('Go-http-client/1.1', '', -1);
        self::assertSame('suspect', $r['kind']);
    }

    // ---- Empty PTR doesn't false-positive ----

    public function testEmptyPtrIsIgnored(): void
    {
        $r = taphish_classify_visitor(
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
            '',
            -1
        );
        self::assertSame('real', $r['kind']);
    }

    // Phase 3.45a: schema migration + KPI-filter helper.

    public function testEnsureSchemaHelperIsDefined(): void
    {
        // Pure-helper tier: the function exists and is callable; the
        // actual DDL is exercised at boot in session_manager.
        self::assertTrue(function_exists('taphish_scanner_ensure_schema'));
    }

    public function testShouldFilterScannerInKpisDefaultsTrue(): void
    {
        self::assertTrue(taphish_should_filter_scanner_in_kpis([]));
    }

    public function testShouldFilterScannerInKpisRespectsExplicitFalse(): void
    {
        self::assertFalse(taphish_should_filter_scanner_in_kpis(['include_scanner_hits' => true]));
    }

    public function testShouldFilterScannerInKpisRespectsExplicitFalseyZero(): void
    {
        self::assertTrue(taphish_should_filter_scanner_in_kpis(['include_scanner_hits' => 0]));
        self::assertTrue(taphish_should_filter_scanner_in_kpis(['include_scanner_hits' => '']));
    }
}

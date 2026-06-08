<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for spear/manager/mail_client_detect.php.
 *
 * Pins the User-Agent → mail-client-name contract used by the campaign
 * dashboard. Includes a couple of explicitly-documented quirks (last-match-
 * wins ordering); the tests lock current behaviour so a future re-order is a
 * deliberate decision, not an accidental regression.
 */
final class MailClientDetectTest extends TestCase
{
    public function testUnknownUaYieldsLiteralUnknown(): void
    {
        self::assertSame('unknown', taphish_mail_client_from_ua('totally-not-a-browser'));
        self::assertSame('unknown', taphish_mail_client_from_ua(''));
    }

    public function testFirefoxOnWindows(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0';
        self::assertSame('Firefox', taphish_mail_client_from_ua($ua));
    }

    public function testInternetExplorer11Trident(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Trident/7.0; rv:11.0) like Gecko';
        self::assertSame('Internet Explorer', taphish_mail_client_from_ua($ua));
    }

    public function testChromeOverridesSafari(): void
    {
        // Chrome's UA contains "Chrome" AND "Safari". Because /chrome/ comes
        // after /safari/ in the pattern list, the last match wins → Chrome.
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';
        self::assertSame('Chrome', taphish_mail_client_from_ua($ua));
    }

    public function testEdgeOverridesChrome(): void
    {
        // Edge's UA contains "Chrome" + "Safari" + "Edge". Edge is the last
        // match → Edge.
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36 Edge/138.0.0.0';
        self::assertSame('Edge', taphish_mail_client_from_ua($ua));
    }

    public function testSafariOnMacIsMisclassifiedAsAppleMail(): void
    {
        // QUIRK #1: a modern Safari UA on a Mac contains BOTH "Safari" AND
        // "Macintosh.*AppleWebKit". /Macintosh.*AppleWebKit/ comes after
        // /safari/ in the pattern list → last match wins → "Apple Mail".
        // This is wrong — a real Safari open on a Mac is reported as Apple
        // Mail in the dashboard. Pinned here so a future fix is a deliberate
        // decision (the file header documents two valid fix paths).
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.4 Safari/605.1.15';
        self::assertSame('Apple Mail', taphish_mail_client_from_ua($ua));
    }

    public function testRealAppleMailUa(): void
    {
        // Apple Mail sends a UA without "Safari/Version" — just AppleWebKit
        // on Macintosh. Correctly identified.
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko)';
        self::assertSame('Apple Mail', taphish_mail_client_from_ua($ua));
    }

    public function testOutlookDesktopUa(): void
    {
        // Outlook for Windows
        $ua = 'Mozilla/4.0 (compatible; MSIE 7.0; MSOffice 14)';
        // Both /msie|trident/ AND /Microsoft Outlook|MSOffice/ match; the
        // Outlook pattern is later → wins.
        self::assertSame('Microsoft Outlook', taphish_mail_client_from_ua($ua));
    }

    public function testGoogleImageProxyDetectedAsGmail(): void
    {
        // When the recipient opens a mail in Gmail web, the open-tracking
        // pixel is fetched by Google's image proxy on Google's behalf.
        $ua = 'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)';
        // QUIRK: /firefox/, /chrome/, /mobile/ may also match. Gmail comes
        // late in the list, so it correctly wins for known Google-proxy UAs.
        self::assertSame('Gmail', taphish_mail_client_from_ua($ua));
    }

    public function testThunderbirdUa(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64; rv:115.0) Gecko/20100101 Thunderbird/115.5.0';
        // Firefox + Thunderbird both match — Thunderbird is later → wins.
        self::assertSame('Thunderbird', taphish_mail_client_from_ua($ua));
    }

    public function testHandheldBrowserCatchesGenericMobile(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 10) Mobile';
        // /mobile/ matches; no later pattern matches → Handheld Browser.
        self::assertSame('Handheld Browser', taphish_mail_client_from_ua($ua));
    }

    public function testRoundcubeWebmailUa(): void
    {
        $ua = 'Roundcube Webmail/1.6.0';
        self::assertSame('Roundcube', taphish_mail_client_from_ua($ua));
    }

    public function testHordeWebmailUa(): void
    {
        $ua = 'Horde Application Framework';
        self::assertSame('Horde', taphish_mail_client_from_ua($ua));
    }

    public function testPatternListIsExposedForInspection(): void
    {
        // The pattern list is documented contract — exposed via a pure
        // accessor so dashboard tooling could surface it.
        $patterns = taphish_mail_client_patterns();
        self::assertSame('Internet Explorer', $patterns['/msie|trident/i']);
        self::assertSame('Apple Mail',        $patterns['/Macintosh.*AppleWebKit/i']);
        self::assertSame('Microsoft Outlook', $patterns['/Microsoft Outlook|MSOffice/i']);
        self::assertGreaterThanOrEqual(18, count($patterns));
    }
}

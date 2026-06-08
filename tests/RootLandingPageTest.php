<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pins the public docroot landing (index.php) shape.
 *
 * Until 2026-06-08 the root URL of the operator host returned a 302 →
 * `/spear/`, advertising the bare domain as "operator login here" to every
 * passing crawler / DNS classifier / phishing-reputation blocklist. That's
 * the same signal Swisscom Internet Guard et al. flag. The fix flipped the
 * root to a legitimate Security Awareness Training homepage; this test
 * locks the design intent so a future change doesn't silently re-introduce
 * the redirect.
 */
final class RootLandingPageTest extends TestCase
{
    private static string $src = '';
    private static string $rendered = '';

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__ . '/../index.php';
        self::$src = (string) @file_get_contents($path);
        if (self::$src === '') {
            self::markTestSkipped('index.php not found at repo root: ' . $path);
        }
        // Render the PHP to a string so we can inspect the actual served HTML.
        ob_start();
        // header() calls inside index.php are fine — phpunit's CLI SAPI tolerates them.
        // We `include` so $_SERVER state doesn't matter.
        include $path;
        self::$rendered = (string) ob_get_clean();
    }

    public function testNoLocationRedirectAnywhereInSource(): void
    {
        // The original bug. Any header('Location: …') or HTTP-equiv refresh
        // at the root would re-introduce the "this is an admin login"
        // signal that landed us in reputation blocklists.
        self::assertStringNotContainsStringIgnoringCase(
            "Location:",
            self::$src,
            'Root index.php must not emit a Location: redirect — that was the 2026-06-08 reputation regression.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/http-equiv\s*=\s*["\']refresh["\']/i',
            self::$src,
            'No meta-refresh redirect either.'
        );
    }

    public function testRendersValidHtmlWithExpectedTitle(): void
    {
        self::assertStringContainsString('<!doctype html>', strtolower(self::$rendered));
        self::assertStringContainsString('<title>T-Alpha Security Awareness Training</title>', self::$rendered);
    }

    public function testHasMetaDescriptionDeclaringCybersecurityTraining(): void
    {
        // The meta description is what reputation classifiers + search
        // engines see first. It MUST identify the platform as security
        // awareness training, not just any old website.
        self::assertMatchesRegularExpression(
            '/<meta\s+name=["\']description["\']\s+content=["\'][^"\']*(security|cybersecurity)[^"\']*(awareness|training|simulation)/i',
            self::$rendered,
            'meta description must identify the platform as cybersecurity awareness training'
        );
    }

    public function testEmitsSchemaOrgProfessionalServiceJsonLd(): void
    {
        self::assertStringContainsString('application/ld+json', self::$rendered, 'must emit a JSON-LD block');
        self::assertStringContainsString('"@type": "ProfessionalService"', self::$rendered);
        self::assertStringContainsString('"serviceType": "Cybersecurity Awareness Training"', self::$rendered);
        self::assertStringContainsString('"name": "T-Alpha GmbH"', self::$rendered);
    }

    public function testJsonLdParsesCleanlyAndDeclaresTAlpha(): void
    {
        // Extract + decode the JSON-LD — a syntax error here would make
        // crawlers ignore the schema and lose the classification benefit.
        $ok = preg_match(
            '#<script\s+type=["\']application/ld\+json["\']>\s*(\{.*?\})\s*</script>#is',
            self::$rendered,
            $m
        );
        self::assertSame(1, $ok, 'JSON-LD block not found');
        $data = json_decode($m[1], true);
        self::assertIsArray($data, 'JSON-LD must parse: ' . json_last_error_msg());
        self::assertSame('ProfessionalService', $data['@type'] ?? null);
        self::assertSame('CH', $data['areaServed'] ?? null);
        self::assertSame('T-Alpha GmbH', $data['parentOrganization']['name'] ?? null);
    }

    public function testHasOperatorPortalFooterLink(): void
    {
        // Operators still need to reach /spear/. The link doesn't have to be
        // prominent, but it MUST exist somewhere on the page.
        self::assertMatchesRegularExpression(
            '#<a [^>]*href=["\']spear/?["\'][^>]*>#i',
            self::$rendered,
            'operator portal link to /spear/ must exist on the public landing'
        );
    }

    public function testIncludesExplicitRecipientNotice(): void
    {
        // A human who lands here after clicking a simulation email needs an
        // immediate, honest explanation — both for educational outcome and
        // to reduce panic / support load.
        self::assertMatchesRegularExpression(
            '/simulation email|training email/i',
            self::$rendered
        );
        self::assertStringContainsString('IT security team or HR', self::$rendered);
    }

    public function testRobotsAllowedSoClassifiersCanReadTheContent(): void
    {
        // Whole point of the change: classifiers MUST be able to crawl and
        // read the legitimate content. Robots:noindex would make us look
        // suspicious again.
        self::assertMatchesRegularExpression(
            '/<meta\s+name=["\']robots["\']\s+content=["\']index,\s*follow["\']/i',
            self::$rendered
        );
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pre-send guard for the live mail dispatcher.
 *
 * Wired into spear/core/mail_campaign_cron.php right after
 * filterKeywords() expands the merge tokens. The guard inspects the
 * fully-rendered body and refuses to ship the mail (status 3 = error)
 * when the CTA still points at:
 *
 *  - the operator-edit marker (`REPLACE-WITH-LANDING-URL` /
 *    `REPLACE-WITH-TRACKER-URL`) → operator hit Launch without binding
 *    a real landing URL to the campaign
 *  - the open-tracking pixel endpoint (`/tmail?mid=…`) → recipient
 *    would click the CTA and land on a 1×1 transparent image
 *  - the legacy SniperHost fallback page `lp_pages/oops.html` → 404
 *
 * Bodies that are LEGITIMATELY using the open pixel as an `<img src>`
 * (i.e. the `{{TRACKER}}` token's expansion) MUST pass — only `<a href>`
 * misuse is rejected.
 */
final class MailBodyPreSendGuardTest extends TestCase
{
    public function testCleanBodyPasses(): void
    {
        $body = '<p>Hi {{FNAME}},</p>'
              . '<p><a href="https://ptbe.autodiscover.li/p/m365-login-x/?rid=ABC">Sign in</a></p>'
              . '<img src="https://ptbe.autodiscover.li/tmail?mid=mc1&rid=ABC"/>';
        self::assertNull(taphish_mail_body_is_unsafe_to_send($body));
    }

    public function testBodyWithLandingMarkerIsRefused(): void
    {
        $body = '<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Click</a></p>';
        self::assertNotNull(taphish_mail_body_is_unsafe_to_send($body));
        self::assertStringContainsString('REPLACE-WITH-LANDING-URL', (string) taphish_mail_body_is_unsafe_to_send($body));
    }

    public function testBodyWithLegacyTrackerMarkerIsRefused(): void
    {
        $body = '<p><a href="https://example.com/REPLACE-WITH-TRACKER-URL">Click</a></p>';
        self::assertNotNull(taphish_mail_body_is_unsafe_to_send($body));
    }

    public function testBodyWithCtaPointingAtOpenPixelIsRefused(): void
    {
        // This is the actual production failure mode (PR #120 regression):
        // {{TRACKINGURL}} got substituted into <a href=...>, so after
        // filterKeywords the rendered body looks like this. Guard must
        // catch it even though no operator-edit marker is present.
        $body = '<p><a href="https://ptbe.autodiscover.li/tmail?mid=5v4zkmv073&rid=471dn0e3yv">Sign in</a></p>';
        $reason = taphish_mail_body_is_unsafe_to_send($body);
        self::assertNotNull($reason);
        self::assertStringContainsString('open-tracking pixel', (string) $reason);
    }

    public function testBodyWithOpenPixelInImgSrcIsAllowed(): void
    {
        // The legitimate {{TRACKER}} use-case — open-pixel as an <img src>,
        // never as a clickable CTA — must pass.
        $body = '<p><a href="https://ptbe.autodiscover.li/p/landing/">Sign in</a></p>'
              . '<img src="https://ptbe.autodiscover.li/tmail?mid=mc1&rid=ABC"/>';
        self::assertNull(taphish_mail_body_is_unsafe_to_send($body));
    }

    public function testBodyWithLegacySniperHostFallbackIsRefused(): void
    {
        // 2026-06-09: a boarding-pass template the operator had wired up
        // pointed at /spear/sniperhost/lp_pages/oops.html — the legacy
        // SniperHost "page missing" fallback (404 in current install).
        $body = '<p><a href="https://ptbe.autodiscover.li/spear/sniperhost/lp_pages/oops.html?RID=npkvma0whl">View pass</a></p>';
        self::assertNotNull(taphish_mail_body_is_unsafe_to_send($body));
    }

    public function testGuardIsCaseInsensitive(): void
    {
        $body = '<p><a HREF="HTTPS://EXAMPLE.COM/replace-with-landing-url">Click</a></p>';
        self::assertNotNull(taphish_mail_body_is_unsafe_to_send($body));
    }

    public function testGuardDoesNotMatchMarkerInsidePlainText(): void
    {
        // Edge case: an operator quoting documentation in the body that
        // mentions the marker string AS PROSE rather than a CTA href.
        // We accept the false-positive trade-off here — the marker text
        // appearing anywhere is grounds to refuse, because in practice
        // recipients don't see operator documentation in their inbox.
        // This test pins that decision so a future relaxation is explicit.
        $body = '<p>This is just prose mentioning REPLACE-WITH-LANDING-URL.</p>';
        self::assertNotNull(taphish_mail_body_is_unsafe_to_send($body));
    }

    public function testGuardCoversAllSeedBodiesPostHotfix(): void
    {
        // The 2026-06-09 hotfix restored the operator-edit marker in every
        // seed body. The guard must refuse to send any unedited seed —
        // otherwise the regression that broke 5 of the 7 verification mails
        // tonight would resurface.
        foreach (taphish_pretext_seeds() as $s) {
            self::assertNotNull(
                taphish_mail_body_is_unsafe_to_send($s['body']),
                "Seed '{$s['name']}' must be refused by the pre-send guard before any operator edit."
            );
        }
    }

    public function testExtractCtaLandingUrlsReturnsClickableLinks(): void
    {
        $body = '<p><a href="https://ptbe.autodiscover.li/p/m365-x/?rid=ABC">Sign in</a></p>'
              . '<img src="https://ptbe.autodiscover.li/tmail?mid=mc1&rid=ABC"/>';
        $urls = taphish_mail_body_extract_cta_landing_urls($body);
        self::assertSame(['https://ptbe.autodiscover.li/p/m365-x/?rid=ABC'], $urls);
    }

    public function testExtractCtaLandingUrlsSkipsOpenPixelHref(): void
    {
        // Even if (incorrectly) wrapped in an <a href> — the existing
        // CTA guard catches this; the extractor must NOT also surface it
        // to the landing probe, because /tmail isn't a landing.
        $body = '<p><a href="https://ptbe.autodiscover.li/tmail?mid=mc1&rid=ABC">Tracking</a></p>';
        self::assertSame([], taphish_mail_body_extract_cta_landing_urls($body));
    }

    public function testExtractCtaLandingUrlsSkipsKnownTrustAnchors(): void
    {
        // m365-login redirects to login.microsoftonline.com after capture;
        // probing it from the cron is wasted work AND would always 200.
        $body = '<p><a href="https://ptbe.autodiscover.li/p/m365-x/">Sign in</a></p>'
              . '<p><a href="https://login.microsoftonline.com/">Help</a></p>';
        self::assertSame(['https://ptbe.autodiscover.li/p/m365-x/'], taphish_mail_body_extract_cta_landing_urls($body));
    }

    public function testExtractCtaLandingUrlsDeduplicates(): void
    {
        $body = '<p><a href="https://x.example/p/y/">A</a><a href="https://x.example/p/y/">B</a></p>';
        self::assertSame(['https://x.example/p/y/'], taphish_mail_body_extract_cta_landing_urls($body));
    }

    public function testExtractCtaLandingUrlsSkipsAnchorsAndJavascript(): void
    {
        $body = '<p><a href="#">Top</a><a href="javascript:void(0)">JS</a><a href="mailto:a@b">Mail</a>'
              . '<a href="https://x.example/p/y/">Real</a></p>';
        self::assertSame(['https://x.example/p/y/'], taphish_mail_body_extract_cta_landing_urls($body));
    }

    public function testUnsafePatternsListIsNonEmpty(): void
    {
        $patterns = taphish_mail_body_unsafe_patterns();
        self::assertNotEmpty($patterns);
        foreach ($patterns as $p) {
            self::assertArrayHasKey('needle', $p);
            self::assertArrayHasKey('reason', $p);
            self::assertNotSame('', $p['needle']);
            self::assertNotSame('', $p['reason']);
        }
    }
}

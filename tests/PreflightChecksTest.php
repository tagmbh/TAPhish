<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.45d: pure-side tests for the Quick-Start Launch pre-flight gates.
 */
final class PreflightChecksTest extends TestCase
{
    public function testScopeGatePassesWhenAllInScope(): void
    {
        $r = taphish_preflight_scope_gate(['a@acme.test', 'b@hr.acme.test'], ['acme.test']);
        self::assertTrue($r['ok']);
    }

    public function testScopeGateRejectsOutOfScope(): void
    {
        $r = taphish_preflight_scope_gate(['a@acme.test', 'b@notallowed.example'], ['acme.test']);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('outside scope', $r['reason']);
    }

    public function testScopeGateRejectsEmptyAllowlist(): void
    {
        $r = taphish_preflight_scope_gate(['a@acme.test'], []);
        self::assertFalse($r['ok']);
    }

    // --- F2: landing-probe SSRF guard ------------------------------------

    public function testLandingProbeAllowsClonedPathOnSameHost(): void
    {
        self::assertTrue(taphish_landing_url_is_probeable(
            'https://phish.example/spear/sniperhost/cloned/m365/', 'phish.example'
        ));
        self::assertTrue(taphish_landing_url_is_probeable(
            'https://phish.example/p/m365/', 'phish.example'
        ));
    }

    public function testLandingProbeAllowsSameHostWithPort(): void
    {
        self::assertTrue(taphish_landing_url_is_probeable(
            'http://127.0.0.1:8099/spear/sniperhost/cloned/m365/', '127.0.0.1:8099'
        ));
    }

    public function testLandingProbeBlocksDifferentHost(): void
    {
        // The classic SSRF target — a different host than the request.
        self::assertFalse(taphish_landing_url_is_probeable(
            'http://169.254.169.254/latest/meta-data/', 'phish.example'
        ));
        self::assertFalse(taphish_landing_url_is_probeable(
            'http://internal-admin/spear/sniperhost/cloned/x/', 'phish.example'
        ));
    }

    public function testLandingProbeBlocksNonClonedPathOnSameHost(): void
    {
        // Same host but not a cloned-landing path — e.g. an internal endpoint.
        self::assertFalse(taphish_landing_url_is_probeable(
            'https://phish.example/manager/secret', 'phish.example'
        ));
    }

    public function testLandingProbeBlocksNonHttpSchemeAndEmptyHost(): void
    {
        self::assertFalse(taphish_landing_url_is_probeable('file:///etc/passwd', 'phish.example'));
        self::assertFalse(taphish_landing_url_is_probeable('https://phish.example/p/x/', ''));
    }

    public function testScopeGateRejectsEmptyRecipients(): void
    {
        $r = taphish_preflight_scope_gate([], ['acme.test']);
        self::assertFalse($r['ok']);
    }

    public function testDmarcGateBlocksRealDomainWhenReject(): void
    {
        $r = taphish_preflight_dmarc_gate('reject', 'acme.test', 'acme.test');
        self::assertFalse($r['ok']);
    }

    public function testDmarcGatePassesWhenSenderIsLookalike(): void
    {
        $r = taphish_preflight_dmarc_gate('reject', 'acme-corp.test', 'acme.test');
        self::assertTrue($r['ok']);
    }

    public function testDmarcGatePassesWhenPolicyIsNone(): void
    {
        $r = taphish_preflight_dmarc_gate('none', 'acme.test', 'acme.test');
        self::assertTrue($r['ok']);
    }

    public function testRecipientCountGate(): void
    {
        self::assertFalse(taphish_preflight_recipient_count_gate(0)['ok']);
        self::assertTrue(taphish_preflight_recipient_count_gate(1)['ok']);
        self::assertTrue(taphish_preflight_recipient_count_gate(100)['ok']);
    }

    public function testSenderReachableGatePassesOnOkProbe(): void
    {
        $r = taphish_preflight_sender_reachable_gate(fn () => ['ok' => true]);
        self::assertTrue($r['ok']);
    }

    public function testSenderReachableGateDegradesToOkOnNullProbe(): void
    {
        // No probe wired → ok-with-note (consistent with the webhook +
        // landing gates), so a fully-configured campaign stays launchable.
        $r = taphish_preflight_sender_reachable_gate(null);
        self::assertTrue($r['ok']);
        self::assertNotNull($r['reason']);
    }

    public function testSenderReachableGateFailsOnFailingProbe(): void
    {
        $r = taphish_preflight_sender_reachable_gate(fn () => ['ok' => false, 'error' => 'timeout']);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('timeout', $r['reason']);
    }

    public function testWebhookGateAllowsEmptyAsOptional(): void
    {
        $r = taphish_preflight_webhook_gate('');
        self::assertTrue($r['ok']);
    }

    public function testWebhookGatePassesOnOkProbe(): void
    {
        $r = taphish_preflight_webhook_gate('https://hooks.test/x', fn () => ['ok' => true, 'status' => 200]);
        self::assertTrue($r['ok']);
    }

    public function testWebhookGateFailsOn5xx(): void
    {
        $r = taphish_preflight_webhook_gate('https://hooks.test/x', fn () => ['ok' => false, 'status' => 502]);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('502', $r['reason']);
    }

    public function testLandingGateRejectsEmptyUrl(): void
    {
        $r = taphish_preflight_landing_gate('', null);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('No landing page', $r['reason']);
    }

    public function testLandingGatePassesWithoutProbeWhenUrlSet(): void
    {
        // The wizard preflight can run without a network probe (sandbox
        // boxes, CI, etc.). A URL with no probe is "configured but not
        // verified" — fine for a pre-flight badge.
        $r = taphish_preflight_landing_gate('https://ptbe.autodiscover.li/p/m365-x/', null);
        self::assertTrue($r['ok']);
    }

    public function testLandingGateFailsOn404Probe(): void
    {
        $probe = fn ($url) => ['ok' => false, 'status' => 404, 'body' => '', 'error' => ''];
        $r = taphish_preflight_landing_gate('https://ptbe.autodiscover.li/p/missing/', $probe);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('404', $r['reason']);
    }

    public function testLandingGateFailsWhenBodyHasNoForm(): void
    {
        // Caught the boarding-pass case where the legacy /spear/sniperhost/
        // lp_pages/oops.html fallback was 200 but had no form to submit to.
        $probe = fn ($url) => ['ok' => true, 'status' => 200, 'body' => '<html><body>Static brochure</body></html>', 'error' => ''];
        $r = taphish_preflight_landing_gate('https://ptbe.autodiscover.li/p/x/', $probe);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('no <form>', $r['reason']);
    }

    public function testLandingGatePassesOn200WithForm(): void
    {
        $probe = fn ($url) => ['ok' => true, 'status' => 200, 'body' => '<html><body><form><input/></form></body></html>', 'error' => ''];
        $r = taphish_preflight_landing_gate('https://ptbe.autodiscover.li/p/m365-x/', $probe);
        self::assertTrue($r['ok']);
    }

    public function testMailBodyGateRefusesUneditedSeedBody(): void
    {
        // The exact failure mode the operator hit tonight: hit Launch with
        // a pretext-library template whose CTA was still the marker URL,
        // and the campaign would silently ship a broken link.
        $body = '<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Click</a></p>';
        $r = taphish_preflight_mail_body_gate($body);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('Mail CTA gate refused', $r['reason']);
    }

    public function testMailBodyGateRefusesEmptyBody(): void
    {
        self::assertFalse(taphish_preflight_mail_body_gate('')['ok']);
    }

    public function testMailBodyGatePassesOnRenderedSafeBody(): void
    {
        $body = '<p>Hi Jane,</p>'
              . '<p><a href="https://ptbe.autodiscover.li/p/m365-x/?rid=ABC">Click</a></p>'
              . '<img src="https://ptbe.autodiscover.li/tmail?mid=mc1&rid=ABC"/>';
        self::assertTrue(taphish_preflight_mail_body_gate($body)['ok']);
    }

    public function testRunAllAllGreen(): void
    {
        $okLandingProbe = fn ($u) => ['ok' => true, 'status' => 200, 'body' => '<form><input/></form>', 'error' => ''];
        $r = taphish_preflight_run_all([
            'recipient_emails'   => ['a@acme.test'],
            'scope_allowlist'    => ['acme.test'],
            'target_dmarc_policy'=> 'none',
            'sender_domain'      => 'acme-corp.test',
            'target_domain'      => 'acme.test',
            'sender_probe'       => fn () => ['ok' => true],
            'webhook_url'        => '',
            'landing_url'        => 'https://ptbe.autodiscover.li/p/m365-x/',
            'landing_probe'      => $okLandingProbe,
            'rendered_mail_body' => '<p>Hi {{FNAME}}</p><p><a href="https://ptbe.autodiscover.li/p/m365-x/?rid=R">Click</a></p>',
        ]);
        self::assertTrue($r['ok'], json_encode($r['gates']));
        self::assertSame(7, count($r['gates']));
    }

    public function testRunAllShortCircuitsOnAnyFail(): void
    {
        $r = taphish_preflight_run_all([
            'recipient_emails'   => ['a@notallowed.example'],
            'scope_allowlist'    => ['acme.test'],
            'sender_probe'       => fn () => ['ok' => true],
        ]);
        self::assertFalse($r['ok']);
    }

    public function testRunAllFailsIfLandingMisconfigured(): void
    {
        // Confirms the run_all aggregation surfaces landing problems even
        // when every other gate would have passed. This is the gate that
        // matters for tonight's Intranet credential-submit failure.
        $r = taphish_preflight_run_all([
            'recipient_emails'   => ['a@acme.test'],
            'scope_allowlist'    => ['acme.test'],
            'target_dmarc_policy'=> 'none',
            'sender_domain'      => 'acme-corp.test',
            'target_domain'      => 'acme.test',
            'sender_probe'       => fn () => ['ok' => true],
            'webhook_url'        => '',
            'landing_url'        => '',   // <- operator forgot to bind
            'landing_probe'      => null,
            'rendered_mail_body' => '<p>Hi</p><p><a href="https://x.example/p/y/">Click</a></p>',
        ]);
        self::assertFalse($r['ok']);
        self::assertFalse($r['gates']['landing']['ok']);
    }

    public function testRunAllFailsIfMailBodyHasMarker(): void
    {
        $okLandingProbe = fn ($u) => ['ok' => true, 'status' => 200, 'body' => '<form><input/></form>', 'error' => ''];
        $r = taphish_preflight_run_all([
            'recipient_emails'   => ['a@acme.test'],
            'scope_allowlist'    => ['acme.test'],
            'target_dmarc_policy'=> 'none',
            'sender_domain'      => 'acme-corp.test',
            'target_domain'      => 'acme.test',
            'sender_probe'       => fn () => ['ok' => true],
            'webhook_url'        => '',
            'landing_url'        => 'https://ptbe.autodiscover.li/p/m365-x/',
            'landing_probe'      => $okLandingProbe,
            'rendered_mail_body' => '<p><a href="https://example.com/REPLACE-WITH-LANDING-URL">Click</a></p>',
        ]);
        self::assertFalse($r['ok']);
        self::assertFalse($r['gates']['mail_body']['ok']);
    }
}

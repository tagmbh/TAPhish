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

    public function testSenderReachableGateFailsOnNullProbe(): void
    {
        $r = taphish_preflight_sender_reachable_gate(null);
        self::assertFalse($r['ok']);
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

    public function testRunAllAllGreen(): void
    {
        $r = taphish_preflight_run_all([
            'recipient_emails'   => ['a@acme.test'],
            'scope_allowlist'    => ['acme.test'],
            'target_dmarc_policy'=> 'none',
            'sender_domain'      => 'acme-corp.test',
            'target_domain'      => 'acme.test',
            'sender_probe'       => fn () => ['ok' => true],
            'webhook_url'        => '',
        ]);
        self::assertTrue($r['ok']);
        self::assertSame(5, count($r['gates']));
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
}

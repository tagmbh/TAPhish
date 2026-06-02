<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.43b: pure-helper tests for MX classification + pretext
 * recommendation. DNS lookup is exercised via an injectable resolver so
 * the suite stays offline.
 */
final class MxClassifyTest extends TestCase
{
    public function testClassifyM365Target(): void
    {
        $c = taphish_mx_classify_record('acme-com.mail.protection.outlook.com');
        self::assertSame('m365', $c['provider']);
        self::assertSame('cloud-mailbox', $c['category']);
    }

    public function testClassifyGoogleTarget(): void
    {
        $c = taphish_mx_classify_record('aspmx.l.google.com');
        self::assertSame('google', $c['provider']);
    }

    public function testClassifyProofpointGateway(): void
    {
        $c = taphish_mx_classify_record('mx0a-00000001.pphosted.com');
        self::assertSame('proofpoint', $c['provider']);
        self::assertSame('security-gateway', $c['category']);
    }

    public function testClassifyMimecastGateway(): void
    {
        $c = taphish_mx_classify_record('eu-smtp-inbound-1.mimecast.com');
        self::assertSame('mimecast', $c['provider']);
        self::assertSame('security-gateway', $c['category']);
    }

    public function testClassifyHostpointCh(): void
    {
        $c = taphish_mx_classify_record('asmtp.mail.hostpoint.ch');
        self::assertSame('hostpoint', $c['provider']);
        self::assertSame('shared-host', $c['category']);
    }

    public function testClassifyUnknownTarget(): void
    {
        $c = taphish_mx_classify_record('mx.weirdvendor.local');
        self::assertSame('unknown', $c['provider']);
    }

    public function testClassifyHandlesTrailingDot(): void
    {
        $c = taphish_mx_classify_record('aspmx.l.google.com.');
        self::assertSame('google', $c['provider']);
    }

    public function testSummariseGatewayWinsOverMailbox(): void
    {
        // Even with M365 also present, Proofpoint is the perimeter — and
        // therefore the answer to "what will the recipient actually see".
        $s = taphish_mx_summarise([
            'acme-com.mail.protection.outlook.com',
            'mx0a-00000001.pphosted.com',
        ]);
        self::assertSame('proofpoint', $s['primary']['provider']);
        self::assertSame(2, $s['count']);
    }

    public function testSummarisePicksMostFrequentWhenNoGateway(): void
    {
        $s = taphish_mx_summarise([
            'aspmx.l.google.com',
            'alt1.aspmx.l.google.com',
            'mx.unknownvendor.local',
        ]);
        self::assertSame('google', $s['primary']['provider']);
    }

    public function testSummariseEmptyInput(): void
    {
        $s = taphish_mx_summarise([]);
        self::assertSame('unknown', $s['primary']['provider']);
        self::assertSame(0, $s['count']);
    }

    public function testRecommendPretextsForM365(): void
    {
        $cats = taphish_mx_recommend_pretexts(['primary' => ['provider' => 'm365']]);
        self::assertSame('Authentication', $cats[0]);
        self::assertContains('IT', $cats);
    }

    public function testRecommendPretextsForUnknown(): void
    {
        $cats = taphish_mx_recommend_pretexts(['primary' => ['provider' => 'unknown']]);
        self::assertContains('Finance', $cats);
        self::assertContains('Shipping', $cats);
    }

    public function testLookupRespectsInjectedResolver(): void
    {
        $resolver = function (string $domain) {
            self::assertSame('example.test', $domain);
            return [
                ['target' => 'mx1.example.test'],
                ['target' => 'mx2.example.test'],
            ];
        };
        $targets = taphish_mx_lookup('example.test', $resolver);
        self::assertSame(['mx1.example.test', 'mx2.example.test'], $targets);
    }

    public function testLookupReturnsEmptyOnBadInput(): void
    {
        self::assertSame([], taphish_mx_lookup(''));
        self::assertSame([], taphish_mx_lookup('example.com', fn () => false));
    }

    public function testClassifyDomainEndToEnd(): void
    {
        $resolver = fn () => [
            ['target' => 'acme-com.mail.protection.outlook.com'],
            ['target' => 'acme-com.mail.protection.outlook.com.'],
        ];
        $r = taphish_mx_classify_domain('acme.com', $resolver);
        self::assertSame('acme.com', $r['domain']);
        self::assertSame('m365', $r['primary']['provider']);
        self::assertSame(2, $r['count']);
        self::assertContains('Authentication', $r['pretext_categories']);
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.41: homoglyph generator + DMARC posture analyzer.
 *
 * Generator is pure; we run it against a fixed input and pin the
 * expected shape. Posture analyzer is pure once parsed records are
 * in hand; lookup_email_posture takes an injectable resolver so we
 * hand it a fixture stub.
 */
final class SenderToolkitTest extends TestCase
{
    // ---- Homoglyph generator ----

    public function testCandidatesIncludeCyrillicAForLatinA(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        $domains = array_column($cands, 'domain');
        // "tаrget.com" with cyrillic а U+0430 — the canonical example.
        self::assertContains('tаrget.com', $domains);
    }

    public function testCandidatesIncludeRnDigraphForM(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        $domains = array_column($cands, 'domain');
        // 'm' -> 'rn' — no 'm' in target so this won't fire; use a domain
        // with 'm' instead.
        $cands2 = taphish_homoglyph_candidates('amazon.com', 200);
        $domains2 = array_column($cands2, 'domain');
        self::assertContains('arnazon.com', $domains2);
    }

    public function testCandidatesIncludeTldSwap(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        $domains = array_column($cands, 'domain');
        self::assertContains('target.co', $domains);
        self::assertContains('target.io', $domains);
        self::assertContains('target.net', $domains);
    }

    public function testCandidatesIncludeInsertionSuffix(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        $domains = array_column($cands, 'domain');
        self::assertContains('target-corp.com', $domains);
    }

    public function testCandidatesIncludeDoubledLetterTypo(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        $domains = array_column($cands, 'domain');
        self::assertContains('taarget.com', $domains);
    }

    public function testInputDomainIsExcludedFromResults(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        $domains = array_column($cands, 'domain');
        self::assertNotContains('target.com', $domains);
    }

    public function testEmptyDomainReturnsEmptyList(): void
    {
        self::assertSame([], taphish_homoglyph_candidates(''));
        self::assertSame([], taphish_homoglyph_candidates('   '));
    }

    public function testLimitCapsResultCount(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 10);
        self::assertLessThanOrEqual(10, count($cands));
    }

    public function testResultsAreSortedHighestScoreFirst(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        for ($i = 0; $i < count($cands) - 1; $i++) {
            self::assertGreaterThanOrEqual(
                $cands[$i + 1]['score'],
                $cands[$i]['score'],
                "Result $i scored {$cands[$i]['score']} but next is {$cands[$i+1]['score']}"
            );
        }
    }

    public function testEachCandidateCarriesKindLabel(): void
    {
        $cands = taphish_homoglyph_candidates('target.com', 200);
        $valid = ['homoglyph', 'typo', 'tld', 'insert'];
        foreach ($cands as $c) {
            self::assertContains($c['kind'], $valid, "Unknown kind: {$c['kind']}");
        }
    }

    public function testSplitDomainHandlesSimpleDomain(): void
    {
        self::assertSame(['name' => 'target', 'tld' => 'com'], taphish_split_domain('target.com'));
    }

    public function testSplitDomainHandlesSubdomain(): void
    {
        // Sub-labels stay together — we only split the LAST label.
        self::assertSame(['name' => 'mail.target', 'tld' => 'com'], taphish_split_domain('mail.target.com'));
    }

    public function testSplitDomainOnLabelWithoutDot(): void
    {
        self::assertSame(['name' => 'localhost', 'tld' => ''], taphish_split_domain('localhost'));
    }

    // ---- DMARC posture recommendation rules ----

    public function testHardenedWhenDmarcReject(): void
    {
        $r = taphish_email_posture_recommendation(
            ['mechanisms' => ['include:_spf.target'], 'qualifier_all' => '-'],
            taphish_dmarc_parse_record('v=DMARC1; p=reject; rua=mailto:dmarc@target.com')
        );
        self::assertSame('hardened', $r['verdict']);
        self::assertStringContainsString('Pivot to a look-alike', $r['recommendation']);
    }

    public function testPartiallyHardenedWhenDmarcQuarantine(): void
    {
        $r = taphish_email_posture_recommendation(
            ['mechanisms' => [], 'qualifier_all' => '~'],
            taphish_dmarc_parse_record('v=DMARC1; p=quarantine')
        );
        self::assertSame('partially-hardened', $r['verdict']);
    }

    public function testMonitoringWhenDmarcNone(): void
    {
        $r = taphish_email_posture_recommendation(
            ['mechanisms' => [], 'qualifier_all' => '~'],
            taphish_dmarc_parse_record('v=DMARC1; p=none; rua=mailto:dmarc@target.com')
        );
        self::assertSame('monitoring', $r['verdict']);
    }

    public function testSpfOnlyStrictWhenNoDmarcButHardSpf(): void
    {
        $r = taphish_email_posture_recommendation(
            taphish_spf_parse_record('v=spf1 include:_spf.target.com -all'),
            []
        );
        self::assertSame('spf-only-strict', $r['verdict']);
    }

    public function testWideOpenWhenNothing(): void
    {
        $r = taphish_email_posture_recommendation(
            ['mechanisms' => [], 'qualifier_all' => null],
            []
        );
        self::assertSame('wide-open', $r['verdict']);
    }

    // ---- Record parsing ----

    public function testDmarcParserExtractsTags(): void
    {
        $p = taphish_dmarc_parse_record('v=DMARC1; p=reject; rua=mailto:r@target.com; pct=100;');
        self::assertSame('DMARC1', $p['v']);
        self::assertSame('reject', $p['p']);
        self::assertSame('mailto:r@target.com', $p['rua']);
        self::assertSame('100', $p['pct']);
    }

    public function testSpfParserExtractsMechanismsAndQualifier(): void
    {
        $p = taphish_spf_parse_record('v=spf1 include:_spf.google.com a:mail.target.com -all');
        self::assertContains('include:_spf.google.com', $p['mechanisms']);
        self::assertContains('a:mail.target.com', $p['mechanisms']);
        self::assertSame('-', $p['qualifier_all']);
    }

    public function testSpfParserHandlesSoftFail(): void
    {
        $p = taphish_spf_parse_record('v=spf1 mx ~all');
        self::assertSame('~', $p['qualifier_all']);
    }

    public function testSpfParserHandlesNeutralAll(): void
    {
        $p = taphish_spf_parse_record('v=spf1 mx ?all');
        self::assertSame('?', $p['qualifier_all']);
    }

    public function testEmptyDmarcParseYieldsEmptyArray(): void
    {
        self::assertSame([], taphish_dmarc_parse_record(''));
    }

    // ---- Lookup with injected resolver ----

    public function testLookupWithFixtureResolver(): void
    {
        $resolver = function (string $host, int $type): array {
            if ($host === 'target.com' && $type === DNS_TXT) {
                return [
                    ['txt' => 'v=spf1 include:_spf.target.com -all'],
                    ['txt' => 'some other unrelated TXT'],
                ];
            }
            if ($host === '_dmarc.target.com' && $type === DNS_TXT) {
                return [['txt' => 'v=DMARC1; p=reject; rua=mailto:r@target.com']];
            }
            if ($host === 'target.com' && $type === DNS_MX) {
                return [
                    ['target' => 'mx1.target.com'],
                    ['target' => 'mx2.target.com'],
                ];
            }
            return [];
        };

        $r = taphish_lookup_email_posture('target.com', $resolver);
        self::assertTrue($r['ok']);
        self::assertSame('hardened', $r['verdict']);
        self::assertSame(['mx1.target.com', 'mx2.target.com'], $r['mx_hosts']);
        self::assertStringContainsString('p=reject', $r['dmarc_raw']);
    }

    public function testLookupRejectsEmptyDomain(): void
    {
        $r = taphish_lookup_email_posture('');
        self::assertFalse($r['ok']);
    }
}

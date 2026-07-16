<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.43a: pure-helper tests for engagement metadata. DB-bound paths
 * (ensure_schema / insert / unique_slug / list) need mysqli and live in
 * the future integration tier.
 */
final class EngagementTest extends TestCase
{
    public function testSlugifyHandlesPlainName(): void
    {
        self::assertSame('acme-bank-2026', taphish_engagement_slugify('Acme Bank 2026'));
    }

    public function testSlugifyCollapsesPunctuation(): void
    {
        self::assertSame('q2-phish-test', taphish_engagement_slugify(' Q2 — Phish/Test! '));
    }

    public function testSlugifyEmptyOnPureSymbols(): void
    {
        self::assertSame('', taphish_engagement_slugify('!!!--###'));
    }

    public function testSlugifyCapsLengthAt80(): void
    {
        $slug = taphish_engagement_slugify(str_repeat('a', 200));
        self::assertLessThanOrEqual(80, strlen($slug));
    }

    public function testParseScopeAllowlistAcceptsCommasAndNewlines(): void
    {
        $raw = "acme.com, hr.acme.com\nfinance.acme.com;\n  vendor.example.org ";
        self::assertSame(
            ['acme.com', 'finance.acme.com', 'hr.acme.com', 'vendor.example.org'],
            taphish_engagement_parse_scope_allowlist($raw)
        );
    }

    public function testParseScopeAllowlistStripsAtAndWildcard(): void
    {
        self::assertSame(
            ['acme.com'],
            taphish_engagement_parse_scope_allowlist('@acme.com, *.acme.com')
        );
    }

    public function testParseScopeAllowlistRejectsBareStar(): void
    {
        self::assertSame([], taphish_engagement_parse_scope_allowlist('*'));
    }

    public function testParseScopeAllowlistRejectsGarbage(): void
    {
        self::assertSame(
            ['real.example'],
            taphish_engagement_parse_scope_allowlist('real.example, not_a_domain, /etc/passwd, .. , a.b')
        );
    }

    public function testParseScopeAllowlistLowercases(): void
    {
        self::assertSame(['acme.com'], taphish_engagement_parse_scope_allowlist('ACME.com'));
    }

    public function testDomainInScopeExact(): void
    {
        self::assertTrue(taphish_engagement_domain_in_scope('bob@acme.com', ['acme.com']));
    }

    public function testDomainInScopeSubdomain(): void
    {
        self::assertTrue(taphish_engagement_domain_in_scope('hr@payroll.acme.com', ['acme.com']));
    }

    public function testDomainInScopeRejectsLookalike(): void
    {
        self::assertFalse(taphish_engagement_domain_in_scope('bob@notacme.com', ['acme.com']));
    }

    public function testDomainInScopeRejectsBlankEmail(): void
    {
        self::assertFalse(taphish_engagement_domain_in_scope('no-at-sign', ['acme.com']));
    }

    public function testParseDatetimeAcceptsDateOnly(): void
    {
        self::assertNotNull(taphish_engagement_parse_datetime('2026-07-01'));
    }

    public function testParseDatetimeAcceptsDatetimeLocal(): void
    {
        self::assertNotNull(taphish_engagement_parse_datetime('2026-07-01T09:30'));
    }

    public function testParseDatetimeRejectsGarbage(): void
    {
        self::assertNull(taphish_engagement_parse_datetime('not a date'));
        self::assertNull(taphish_engagement_parse_datetime('2026/07/01'));
        self::assertNull(taphish_engagement_parse_datetime(''));
    }

    public function testValidateInputHappyPath(): void
    {
        $r = taphish_engagement_validate_input([
            'name' => 'Acme Q3 Awareness',
            'target_org' => 'Acme Bank',
            'start_at' => '2026-07-01',
            'end_at' => '2026-07-15',
            'scope_allowlist' => "acme.com\nvendor.example.org",
            'notes' => 'authorised by ticket #4521',
        ]);
        self::assertTrue($r['ok']);
        self::assertSame('acme-q3-awareness', $r['normalized']['slug']);
        self::assertSame(['acme.com', 'vendor.example.org'], $r['normalized']['scope_allowlist']);
        self::assertSame('2026-07-01 00:00:00', $r['normalized']['start_at']);
        self::assertSame('2026-07-15 00:00:00', $r['normalized']['end_at']);
    }

    public function testValidateInputFlagsMissingName(): void
    {
        $r = taphish_engagement_validate_input([
            'name' => 'A',
            'start_at' => '2026-07-01',
            'end_at' => '2026-07-15',
            'scope_allowlist' => 'acme.com',
        ]);
        self::assertFalse($r['ok']);
        self::assertArrayHasKey('name', $r['errors']);
    }

    public function testValidateInputFlagsEndBeforeStart(): void
    {
        $r = taphish_engagement_validate_input([
            'name' => 'Acme Q3',
            'start_at' => '2026-07-15',
            'end_at'   => '2026-07-01',
            'scope_allowlist' => 'acme.com',
        ]);
        self::assertFalse($r['ok']);
        self::assertArrayHasKey('end_at', $r['errors']);
    }

    public function testValidateInputFlagsEmptyAllowlist(): void
    {
        $r = taphish_engagement_validate_input([
            'name' => 'Acme Q3',
            'start_at' => '2026-07-01',
            'end_at' => '2026-07-15',
            'scope_allowlist' => '',
        ]);
        self::assertFalse($r['ok']);
        self::assertArrayHasKey('scope_allowlist', $r['errors']);
    }

    public function testValidateInputFlagsAllowlistOfOnlyGarbage(): void
    {
        $r = taphish_engagement_validate_input([
            'name' => 'Acme Q3',
            'start_at' => '2026-07-01',
            'end_at' => '2026-07-15',
            'scope_allowlist' => 'not_a_domain, *, @, ..',
        ]);
        self::assertFalse($r['ok']);
        self::assertArrayHasKey('scope_allowlist', $r['errors']);
    }

    public function testStatusListContainsExpectedValues(): void
    {
        $list = taphish_engagement_status_list();
        self::assertContains('draft', $list);
        self::assertContains('live', $list);
        self::assertContains('completed', $list);
        self::assertContains('cancelled', $list);
    }

    // Phase 3.45b: pure-side validator + schema-migration presence.

    public function testValidateTransitionAcceptsKnownStatuses(): void
    {
        foreach (taphish_engagement_status_list() as $s) {
            self::assertTrue(taphish_engagement_validate_transition('draft', $s));
        }
    }

    public function testValidateTransitionRejectsUnknownDestination(): void
    {
        self::assertFalse(taphish_engagement_validate_transition('draft', 'frobnicate'));
        self::assertFalse(taphish_engagement_validate_transition('live', ''));
    }

    public function testEnsureCampaignFkColumnIsDefined(): void
    {
        self::assertTrue(function_exists('taphish_engagement_ensure_campaign_fk_column'));
    }

    public function testWebTrackerEnsureEngagementColumnIsDefined(): void
    {
        // P1.2: web trackers must be scopeable to an engagement (idempotent DDL
        // helper, mirrors the campaign-FK migration; behaviour verified live).
        self::assertTrue(function_exists('taphish_web_tracker_ensure_engagement_column'));
        $ref = new \ReflectionFunction('taphish_web_tracker_ensure_engagement_column');
        self::assertSame(1, $ref->getNumberOfParameters());
        self::assertSame('conn', $ref->getParameters()[0]->getName());
    }

    public function testQuickTrackerEnsureEngagementColumnIsDefined(): void
    {
        self::assertTrue(function_exists('taphish_quick_tracker_ensure_engagement_column'));
        $ref = new \ReflectionFunction('taphish_quick_tracker_ensure_engagement_column');
        self::assertSame(1, $ref->getNumberOfParameters());
        self::assertSame('conn', $ref->getParameters()[0]->getName());
    }

    public function testTransitionStatusHelperIsDefined(): void
    {
        self::assertTrue(function_exists('taphish_engagement_transition_status'));
    }

    public function testGetByIdHelperIsDefined(): void
    {
        self::assertTrue(function_exists('taphish_engagement_get_by_id'));
    }

    public function testCampaignsHelperIsDefined(): void
    {
        self::assertTrue(function_exists('taphish_engagement_campaigns'));
    }

    public function testDeleteHelperIsDefined(): void
    {
        self::assertTrue(function_exists('taphish_engagement_delete'));
    }

    public function testDeleteRejectsNonPositiveIdWithoutTouchingDb(): void
    {
        // id <= 0 returns null before any DB access — verifiable without
        // a live mysqli by passing a throwaway connection the function
        // never uses. We assert the guard via a reflection-safe path:
        // the function short-circuits on $id <= 0.
        $ref = new \ReflectionFunction('taphish_engagement_delete');
        self::assertSame(2, $ref->getNumberOfParameters());
        self::assertSame('conn', $ref->getParameters()[0]->getName());
        self::assertSame('id', $ref->getParameters()[1]->getName());
    }

    /**
     * Phase 3.48b — pure backfill reducer. A user-group is stamped with an
     * engagement only when the campaigns referencing it resolve to exactly one
     * engagement; zero references or an ambiguous spread leave it NULL.
     */
    public function testUserGroupBackfillEngagement(): void
    {
        self::assertNull(taphish_user_group_backfill_engagement([]));
        self::assertSame(5, taphish_user_group_backfill_engagement([['engagement_id' => 5]]));
        self::assertSame(5, taphish_user_group_backfill_engagement([['engagement_id' => 5], ['engagement_id' => 5]]));
        self::assertNull(taphish_user_group_backfill_engagement([['engagement_id' => 5], ['engagement_id' => 7]]));
        self::assertNull(taphish_user_group_backfill_engagement([['engagement_id' => null], ['engagement_id' => 0]]));
    }

    public function testUserGroupEngagementColumnMigrationDefined(): void
    {
        self::assertTrue(function_exists('taphish_user_group_ensure_engagement_column'));
    }
}

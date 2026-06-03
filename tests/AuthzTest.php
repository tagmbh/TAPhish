<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.48: pure tests for the RBAC policy decision logic + the authorize
 * entry point with injected role/membership resolvers (so no DB is needed).
 * The DB-backed taphish_user_role / taphish_engagement_role lookups are
 * exercised in the integration tier.
 */
final class AuthzTest extends TestCase
{
    public function testHelpersAreDefined(): void
    {
        foreach ([
            'taphish_policy_allows',
            'taphish_authorize',
            'taphish_user_role',
            'taphish_engagement_role',
            'taphish_require_authorize_or_die',
            'taphish_authz_ensure_role_column',
            'taphish_authz_ensure_engagement_member_table',
        ] as $fn) {
            self::assertTrue(function_exists($fn), "missing helper: {$fn}");
        }
    }

    public function testWildcardActionAllowsAnyAuthenticatedRole(): void
    {
        self::assertTrue(taphish_policy_allows('view_home', 'operator'));
        self::assertTrue(taphish_policy_allows('view_home', 'read-only'));
        self::assertTrue(taphish_policy_allows('view_home', 'super-admin'));
    }

    public function testDisabledRoleIsDeniedEverything(): void
    {
        self::assertFalse(taphish_policy_allows('view_home', 'disabled'));
        self::assertFalse(taphish_policy_allows('list_engagements', 'disabled'));
        self::assertFalse(taphish_policy_allows('view_audit_log', 'disabled'));
    }

    public function testUnknownActionIsDefaultDenied(): void
    {
        self::assertFalse(taphish_policy_allows('totally_unknown_action', 'super-admin'));
        self::assertFalse(taphish_policy_allows('totally_unknown_action', 'operator'));
    }

    public function testSuperAdminOnlyAction(): void
    {
        self::assertTrue(taphish_policy_allows('view_audit_log', 'super-admin'));
        self::assertFalse(taphish_policy_allows('view_audit_log', 'operator'));
        self::assertFalse(taphish_policy_allows('view_audit_log', 'read-only'));
    }

    public function testOperatorTierAction(): void
    {
        self::assertTrue(taphish_policy_allows('save_engagement', 'operator'));
        self::assertTrue(taphish_policy_allows('save_engagement', 'super-admin'));
        self::assertFalse(taphish_policy_allows('save_engagement', 'read-only'));
    }

    public function testEngagementMemberRequiresMembership(): void
    {
        self::assertTrue(taphish_policy_allows('view_engagement', 'operator', ['engagement_role' => 'member']));
        self::assertTrue(taphish_policy_allows('view_engagement', 'read-only', ['engagement_role' => 'read-only']));
        self::assertFalse(taphish_policy_allows('view_engagement', 'operator', ['engagement_role' => null]));
        self::assertFalse(taphish_policy_allows('view_engagement', 'operator', []));
        // super-admin implicitly satisfies engagement_member (open question #1).
        self::assertTrue(taphish_policy_allows('view_engagement', 'super-admin', []));
    }

    public function testEngagementOwnerRequiresOwnerRole(): void
    {
        self::assertTrue(taphish_policy_allows('transition_engagement_status', 'operator', ['engagement_role' => 'owner']));
        self::assertFalse(taphish_policy_allows('transition_engagement_status', 'operator', ['engagement_role' => 'member']));
        self::assertFalse(taphish_policy_allows('transition_engagement_status', 'operator', []));
        self::assertTrue(taphish_policy_allows('transition_engagement_status', 'super-admin', []));
    }

    public function testWildcardPolicyKeyMatchesByPrefix(): void
    {
        self::assertTrue(taphish_policy_allows('beef_settings_save', 'super-admin'));
        self::assertTrue(taphish_policy_allows('beef_settings_test', 'super-admin'));
        self::assertFalse(taphish_policy_allows('beef_settings_save', 'operator'));
    }

    public function testAuthorizeResolvesRoleAndMembershipViaInjectedSeam(): void
    {
        $asOperator = function (string $u): string { return 'operator'; };

        // Member of engagement 5 → allowed to view it.
        self::assertTrue(taphish_authorize(
            null, 'view_engagement', ['username' => 'bob', 'engagement_id' => 5],
            $asOperator, function (int $eid, string $u): ?string { return 'member'; }
        ));

        // Not a member → denied (NOT empty data — the caller turns this into 403).
        self::assertFalse(taphish_authorize(
            null, 'view_engagement', ['username' => 'bob', 'engagement_id' => 9],
            $asOperator, function (int $eid, string $u): ?string { return null; }
        ));

        // Operator hitting a super-admin-only action → denied.
        self::assertFalse(taphish_authorize(
            null, 'view_audit_log', ['username' => 'bob'], $asOperator
        ));

        // Super-admin → allowed for the super-admin-only action.
        self::assertTrue(taphish_authorize(
            null, 'view_audit_log', ['username' => 'alice'],
            function (string $u): string { return 'super-admin'; }
        ));
    }

    public function testAuthorizeDeniesWhenNoUserInContext(): void
    {
        self::assertFalse(taphish_authorize(
            null, 'view_home', [], function (string $u): string { return 'operator'; }
        ));
    }

    /**
     * Phase 3.48 task 4 — home_manager.php action coverage. Reads/AJAX-status
     * are open to any authenticated user; starting the cron worker is a
     * mutation (operator+); the audit-log query is super-admin only. disabled
     * gets nothing.
     */
    public function testHomeManagerActionPolicies(): void
    {
        foreach (['get_home_graphs_data', 'check_process', 'get_recent_log_entries', 'beef_list_hooks'] as $a) {
            self::assertTrue(taphish_policy_allows($a, 'read-only'), "$a should allow read-only");
            self::assertTrue(taphish_policy_allows($a, 'operator'), "$a should allow operator");
            self::assertFalse(taphish_policy_allows($a, 'disabled'), "$a should deny disabled");
        }

        self::assertTrue(taphish_policy_allows('start_process', 'operator'));
        self::assertTrue(taphish_policy_allows('start_process', 'super-admin'));
        self::assertFalse(taphish_policy_allows('start_process', 'read-only'));
        self::assertFalse(taphish_policy_allows('start_process', 'disabled'));

        self::assertTrue(taphish_policy_allows('audit_log_query', 'super-admin'));
        self::assertFalse(taphish_policy_allows('audit_log_query', 'operator'));
        self::assertFalse(taphish_policy_allows('audit_log_query', 'read-only'));
    }
}

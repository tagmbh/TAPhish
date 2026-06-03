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
            'taphish_authz_ensure_initial_super_admins',
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
        self::assertFalse(taphish_policy_allows('audit_log_query', 'disabled'));
    }

    public function testUnknownActionIsDefaultDenied(): void
    {
        self::assertFalse(taphish_policy_allows('totally_unknown_action', 'super-admin'));
        self::assertFalse(taphish_policy_allows('totally_unknown_action', 'operator'));
    }

    public function testSuperAdminOnlyAction(): void
    {
        self::assertTrue(taphish_policy_allows('audit_log_query', 'super-admin'));
        self::assertFalse(taphish_policy_allows('audit_log_query', 'operator'));
        self::assertFalse(taphish_policy_allows('audit_log_query', 'read-only'));
    }

    public function testOperatorTierAction(): void
    {
        self::assertTrue(taphish_policy_allows('save_engagement', 'operator'));
        self::assertTrue(taphish_policy_allows('save_engagement', 'super-admin'));
        self::assertFalse(taphish_policy_allows('save_engagement', 'read-only'));
    }

    public function testEngagementMemberRequiresMembership(): void
    {
        self::assertTrue(taphish_policy_allows('get_engagement_view', 'operator', ['engagement_role' => 'member']));
        self::assertTrue(taphish_policy_allows('get_engagement_view', 'read-only', ['engagement_role' => 'read-only']));
        self::assertFalse(taphish_policy_allows('get_engagement_view', 'operator', ['engagement_role' => null]));
        self::assertFalse(taphish_policy_allows('get_engagement_view', 'operator', []));
        // super-admin implicitly satisfies engagement_member (open question #1).
        self::assertTrue(taphish_policy_allows('get_engagement_view', 'super-admin', []));
    }

    public function testEngagementOwnerRequiresOwnerRole(): void
    {
        self::assertTrue(taphish_policy_allows('engagement_transition_status', 'operator', ['engagement_role' => 'owner']));
        self::assertFalse(taphish_policy_allows('engagement_transition_status', 'operator', ['engagement_role' => 'member']));
        self::assertFalse(taphish_policy_allows('engagement_transition_status', 'operator', []));
        self::assertTrue(taphish_policy_allows('engagement_transition_status', 'super-admin', []));
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
            null, 'get_engagement_view', ['username' => 'bob', 'engagement_id' => 5],
            $asOperator, function (int $eid, string $u): ?string { return 'member'; }
        ));

        // Not a member → denied (NOT empty data — the caller turns this into 403).
        self::assertFalse(taphish_authorize(
            null, 'get_engagement_view', ['username' => 'bob', 'engagement_id' => 9],
            $asOperator, function (int $eid, string $u): ?string { return null; }
        ));

        // Operator hitting a super-admin-only action → denied.
        self::assertFalse(taphish_authorize(
            null, 'audit_log_query', ['username' => 'bob'], $asOperator
        ));

        // Super-admin → allowed for the super-admin-only action.
        self::assertTrue(taphish_authorize(
            null, 'audit_log_query', ['username' => 'alice'],
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

    /**
     * Phase 3.48 task 4 — allow/deny matrix across the guarded dispatchers.
     * Reads/own-2FA → any authenticated; recon + mutations → operator+;
     * settings/users/global → super-admin; disabled → nothing.
     */
    public function testReadActionsAllowAnyAuthenticated(): void
    {
        foreach ([
            'get_campaign_list', 'get_quick_tracker_list', 'get_web_tracker_list',
            'get_mail_template_list', 'get_sender_list', 'list_engagements',
            'list_pretexts', 'download_report', 'totp_get_status', 'get_current_user',
            'get_campaign_list_web_mail', 'get_mcamp_config_details', 'get_web_tracker_from_id',
        ] as $a) {
            self::assertTrue(taphish_policy_allows($a, 'read-only'), "$a → read-only");
            self::assertTrue(taphish_policy_allows($a, 'operator'), "$a → operator");
            self::assertTrue(taphish_policy_allows($a, 'super-admin'), "$a → super-admin");
            self::assertFalse(taphish_policy_allows($a, 'disabled'), "$a → disabled denied");
        }
    }

    public function testMutationAndReconActionsRequireOperator(): void
    {
        foreach ([
            'save_quick_tracker', 'delete_web_tracker', 'make_copy_web_tracker',
            'save_campaign_list', 'start_stop_mailCampaign', 'save_mail_template',
            'save_sender_list', 'save_user_group', 'upload_user', 'download_user',
            'get_user_group_list', 'get_user_group_data', 'save_mcamp_config',
            'osint_hunter_search', 'mx_classify_domain', 'web_fingerprint',
            'clone_pretext_to_my_templates', 'library_clone_to_my_sites', 'site_clone',
            'save_engagement', 'wizard_generate_dkim', 'generate_customer_pdf_report',
        ] as $a) {
            self::assertTrue(taphish_policy_allows($a, 'operator'), "$a → operator");
            self::assertTrue(taphish_policy_allows($a, 'super-admin'), "$a → super-admin");
            self::assertFalse(taphish_policy_allows($a, 'read-only'), "$a → read-only denied");
            self::assertFalse(taphish_policy_allows($a, 'disabled'), "$a → disabled denied");
        }
    }

    public function testAdminActionsRequireSuperAdmin(): void
    {
        foreach ([
            'get_user_list', 'add_account', 'modify_account', 'delete_account',
            'modify_SP_base_URL', 'clear_log', 'clear_junk_SP_data', 'download_logs',
            'get_logs', 'get_store_list', 'set_capture_webhook_url',
            'beef_settings_save', 'beef_settings_load', 'beef_test_connection',
            'telegram_settings_save', 'telegram_test',
            'add_user_to_table', 'update_user', 'delete_user', 'audit_log_query',
        ] as $a) {
            self::assertTrue(taphish_policy_allows($a, 'super-admin'), "$a → super-admin");
            self::assertFalse(taphish_policy_allows($a, 'operator'), "$a → operator denied");
            self::assertFalse(taphish_policy_allows($a, 'read-only'), "$a → read-only denied");
        }
    }

    public function testEngagementScopedActions(): void
    {
        // view: any member; non-members denied; super-admin implicit.
        self::assertTrue(taphish_policy_allows('get_engagement_view', 'operator', ['engagement_role' => 'member']));
        self::assertFalse(taphish_policy_allows('get_engagement_view', 'operator', []));
        self::assertTrue(taphish_policy_allows('get_engagement_view', 'super-admin', []));

        // transition / delete: owner or super-admin only.
        self::assertTrue(taphish_policy_allows('engagement_transition_status', 'operator', ['engagement_role' => 'owner']));
        self::assertFalse(taphish_policy_allows('engagement_transition_status', 'operator', ['engagement_role' => 'member']));
        self::assertTrue(taphish_policy_allows('delete_engagement', 'super-admin', []));
        self::assertFalse(taphish_policy_allows('delete_engagement', 'operator', ['engagement_role' => 'member']));

        // launch: member-scoped.
        self::assertTrue(taphish_policy_allows('wizard_launch_campaign', 'operator', ['engagement_role' => 'owner']));
        self::assertFalse(taphish_policy_allows('wizard_launch_campaign', 'operator', []));
    }

    public function testMembershipHelperDefined(): void
    {
        self::assertTrue(function_exists('taphish_engagement_add_member'));
    }

    /**
     * Phase 3.48 task 8 — a denied request logs "Forbidden <action> attempted
     * by <user>", which must classify as AUTH/warn in the activity feed.
     */
    public function testForbiddenLogClassifiesAsAuthWarn(): void
    {
        $c = taphish_classify_log_entry('Forbidden save_user_group attempted by bob');
        self::assertSame('AUTH', $c['kind']);
        self::assertSame('warn', $c['severity']);
    }
}

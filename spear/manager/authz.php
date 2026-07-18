<?php
/**
 * Phase 3.48 — central authorization guard (RBAC).
 *
 * Default-deny: any action not present in TAPHISH_POLICY is forbidden, so a
 * new dispatcher action is unreachable until policy explicitly admits it.
 *
 * The pure decision lives in taphish_policy_allows(). taphish_authorize()
 * resolves the caller's global role + (when an engagement is in context) their
 * engagement membership — from the DB, or via injected resolvers under test —
 * then delegates to the pure decision. taphish_require_authorize_or_die()
 * wraps it for dispatcher branches: 403 + {result:'forbidden'} + audit log.
 *
 * Roles: super-admin, operator, read-only, disabled.
 * Policy tiers (any-of): '*' (any authenticated, non-disabled user), a global
 * role, 'engagement_member' (member of $context['engagement_id']), or
 * 'engagement_owner' (owner of it). super-admin implicitly satisfies any
 * action that is present in the policy table.
 */

if (!defined('TAPHISH_POLICY')) {
    /*
     * Complete map of every dispatcher action (Phase 3.48 task 4 inventory:
     * 134 actions across 11 dispatchers). Tiering principles:
     *   '*'                      reads / AJAX-status / own-2FA / session basics
     *   ['super-admin','operator'] recon + every mutation (operators run the work)
     *   ['super-admin']          settings / accounts / users / global config /
     *                            destructive maintenance / audit + system logs
     *   engagement_member/owner  engagement-level actions that carry engagement_id
     * Default-deny: anything not listed is forbidden.
     *
     * NOTE (requirement #4, DONE in Phase 3.48b): per-engagement PII isolation
     * now covers recipient lists too. tb_core_mailcamp_user_group carries an
     * engagement_id; these operator+ actions additionally enforce ROW-LEVEL
     * scoping inside their handlers (taphish_user_group_scope_where for lists,
     * taphish_user_group_guard_or_die for single-row ops, taphish_user_group_
     * can_stamp on create) since they don't carry an engagement_id in the
     * request. NULL engagement_id = legacy/unscoped = visible to all operators.
     */
    define('TAPHISH_POLICY', [
        // --- abstract actions used by the future super-admin pages (task 6) ---
        'view_home'                    => ['*'],
        'manage_users'                 => ['super-admin'],
        'manage_api_tokens'            => ['super-admin'],
        'manage_engagement_members'    => ['super-admin', 'engagement_owner'],

        // --- home_manager.php ---
        'get_home_graphs_data'         => ['*'],
        'check_process'                => ['*'],
        'start_process'                => ['super-admin', 'operator'],
        'get_recent_log_entries'       => ['*'],
        'beef_list_hooks'              => ['*'],
        'audit_log_query'              => ['super-admin'],

        // --- session_manager.php (post-auth dispatch) ---
        'get_access_info'              => ['*'],
        're_login'                     => ['*'],
        'terminate_session'            => ['*'],
        'manage_dashboard_access'      => ['super-admin'],

        // --- settings_manager.php ---
        'get_current_user'             => ['*'],
        'get_date_time_display'        => ['*'],
        'get_timestamp_settings'       => ['*'],
        'modify_timestamp_settings'    => ['super-admin'],
        'get_user_list'                => ['super-admin'],
        'add_account'                  => ['super-admin'],
        'modify_account'               => ['super-admin'],
        'delete_account'               => ['super-admin'],
        'modify_SP_base_URL'           => ['super-admin'],
        'clear_junk_SP_data'           => ['super-admin'],
        'clear_log'                    => ['super-admin'],
        'download_logs'                => ['super-admin'],
        'get_logs'                     => ['super-admin'],
        'get_store_list'               => ['super-admin'],
        'get_capture_webhook_url'      => ['super-admin'],
        'set_capture_webhook_url'      => ['super-admin'],
        'beef_settings_*'              => ['super-admin'],
        'beef_test_connection'         => ['super-admin'],
        'telegram_*'                   => ['super-admin'],
        'push_settings_*'              => ['super-admin'], // Phase 3.57: off-host backup push destination
        // Phase 3.60: managing landing-host profiles (creds) is super-admin; the
        // wizard's read + push are operator-level (exact keys beat the wildcard).
        'landing_host_list'            => ['super-admin', 'operator'],
        'landing_host_push'            => ['super-admin', 'operator'],
        'landing_host_*'               => ['super-admin'], // Phase 3.60: external self-hosted landing FTP hosts
        'anthropic_settings_*'         => ['super-admin'], // Anthropic API key card (AI landing generator)
        // own-account 2FA — every authenticated user manages their own.
        'totp_begin_enrollment'        => ['*'],
        'totp_confirm_enrollment'      => ['*'],
        'totp_disable'                 => ['*'],
        'totp_get_status'              => ['*'],
        'totp_recovery_status'         => ['*'],
        'totp_regenerate_recovery_codes' => ['*'],

        // --- tracker_report_manager.php ---
        'download_report'              => ['*'],
        'get_table_webpage_visit_form_submission' => ['*'],
        'get_web_tracker_from_id'      => ['*'],

        // --- mail_campaign_config_manager.php ---
        'get_mcamp_config_details'         => ['*'],
        'get_mcamp_config_details_from_id' => ['*'],
        'save_mcamp_config'                => ['super-admin', 'operator'],
        'delete_mcamp_config'              => ['super-admin', 'operator'],

        // --- web_mail_campaign_manager.php ---
        'get_campaign_list_web_mail'        => ['*'],
        'get_timeline_data_web'             => ['*'],
        'get_web_mail_tracker_from_id'      => ['*'],
        'get_webcamp_graph_data'            => ['*'],
        'multi_get_live_campaign_data_web_mail' => ['*'],

        // --- quick_tracker_manager.php ---
        'get_quick_tracker_data'            => ['*'],
        'get_quick_tracker_from_id'         => ['*'],
        'get_quick_tracker_list'            => ['*'],
        'save_quick_tracker'                => ['super-admin', 'operator'],
        'delete_quick_tracker'              => ['super-admin', 'operator'],
        'delete_quick_tracker_data'         => ['super-admin', 'operator'],
        'pause_stop_quick_tracker_tracking' => ['super-admin', 'operator'],

        // --- web_tracker_generator_list_manager.php ---
        'get_SP_base_URL'                   => ['*'],
        'get_html_content'                  => ['*'],
        'get_link_to_web_tracker'           => ['*'],
        'get_web_tracker_list'              => ['*'],
        'get_web_tracker_list_for_modal'    => ['*'],
        'save_web_tracker'                  => ['super-admin', 'operator'],
        'delete_web_tracker'                => ['super-admin', 'operator'],
        'delete_web_tracker_data'           => ['super-admin', 'operator'],
        'make_copy_web_tracker'             => ['super-admin', 'operator'],
        'pause_stop_web_tracker_tracking'   => ['super-admin', 'operator'],

        // --- mail_campaign_manager.php ---
        'get_campaign_from_campaign_list_id' => ['*'],
        'get_campaign_list'                  => ['*'],
        'get_mail_replied'                   => ['*'],
        'multi_get_mcampinfo_from_mcamp_list_id_get_live_mcamp_data' => ['*'],
        'pull_mail_campaign_field_data'      => ['*'],
        'get_user_group_data'                => ['super-admin', 'operator'], // recipient PII
        'save_campaign_list'                 => ['super-admin', 'operator'],
        'delete_campaign_from_campaign_id'   => ['super-admin', 'operator'],
        'make_copy_campaign_list'            => ['super-admin', 'operator'],
        'start_stop_mailCampaign'            => ['super-admin', 'operator'],
        'generate_customer_pdf_report'       => ['super-admin', 'operator'],
        'poll_bounces'                       => ['super-admin', 'operator'],

        // --- userlist_campaignlist_mailtemplate_manager.php ---
        'list_engagements'             => ['*'],
        'list_pretexts'                => ['*'],
        'list_pretexts_ranked'         => ['*'],
        'library_list'                 => ['*'],
        'get_mail_template_from_template_id' => ['*'],
        'get_mail_template_list'       => ['*'],
        'get_sender_from_sender_list_id' => ['*'],
        'get_sender_list'              => ['*'],
        'get_capture_summary_for_campaign' => ['*'],
        // recon (active lookups — operator work, not read-only)
        'email_posture_lookup'         => ['super-admin', 'operator'],
        'homoglyph_candidates'         => ['super-admin', 'operator'],
        'homoglyph_check_candidates'   => ['super-admin', 'operator'],
        'mx_classify_domain'           => ['super-admin', 'operator'],
        'osint_crt_sh_subdomains'      => ['super-admin', 'operator'],
        'osint_hunter_email_finder'    => ['super-admin', 'operator'],
        'osint_hunter_search'          => ['super-admin', 'operator'],
        'osint_shodan_host'            => ['super-admin', 'operator'],
        'web_fingerprint'              => ['super-admin', 'operator'],
        'run_toolset_checks'           => ['super-admin', 'operator'],
        // recipient / user-group (PII — operator+, additionally row-scoped per
        // engagement inside the handlers; see requirement #4 note above)
        'get_user_group_list'                => ['super-admin', 'operator'],
        'get_user_group_from_group_Id_table' => ['super-admin', 'operator'],
        'save_user_group'                    => ['super-admin', 'operator'],
        'delete_user_group_from_group_id'    => ['super-admin', 'operator'],
        'make_copy_user_group'               => ['super-admin', 'operator'],
        'upload_user'                        => ['super-admin', 'operator'],
        'download_user'                      => ['super-admin', 'operator'],
        // templates / senders (mutations)
        'save_mail_template'                 => ['super-admin', 'operator'],
        'delete_mail_template_from_template_id' => ['super-admin', 'operator'],
        'make_copy_mail_template'            => ['super-admin', 'operator'],
        'save_sender_list'                   => ['super-admin', 'operator'],
        'delete_mail_sender_list_from_list_id' => ['super-admin', 'operator'],
        'make_copy_sender_list'              => ['super-admin', 'operator'],
        'send_test_mail_sample'              => ['super-admin', 'operator'],
        'send_test_mail_verification'        => ['super-admin', 'operator'],
        'verify_mailbox_access'              => ['super-admin', 'operator'],
        'upload_attachments'                 => ['super-admin', 'operator'],
        'upload_mail_body_files'             => ['super-admin', 'operator'],
        'upload_tracker_image'               => ['super-admin', 'operator'],
        // pretext / landing library
        'clone_pretext_to_my_templates'      => ['super-admin', 'operator'],
        'library_clone_to_my_sites'          => ['super-admin', 'operator'],
        'site_clone'                         => ['super-admin', 'operator'],
        // Phase 3.55: look-alike domain deployment (DNS helper + bundle/publish).
        'lookalike_list_clones'              => ['super-admin', 'operator'],
        'lookalike_dns_records'              => ['super-admin', 'operator'],
        'lookalike_publish_hosted'           => ['super-admin', 'operator'],
        'lookalike_build_bundle'             => ['super-admin', 'operator'],
        // user administration (super-admin only)
        'add_user_to_table'                  => ['super-admin'],
        'update_user'                        => ['super-admin'],
        'delete_user'                        => ['super-admin'],
        // engagement lifecycle (engagement-scoped — these carry engagement_id)
        'save_engagement'                    => ['super-admin', 'operator'],
        'get_engagement_view'                => ['engagement_member'],
        // Consolidated funnel analytics — operator-tier (carries recipient emails).
        'engagement_analytics_summary'       => ['super-admin', 'operator'],
        'engagement_transition_status'       => ['super-admin', 'engagement_owner'],
        'delete_engagement'                  => ['super-admin', 'engagement_owner'],
        // P1.4b: Unscoped/Legacy bucket — list unscoped campaigns/trackers and
        // assign one to an engagement (operator work).
        'list_unscoped_campaigns'            => ['super-admin', 'operator'],
        'assign_engagement'                  => ['super-admin', 'operator'],
        // wizard steps (operator work; launch is engagement-scoped)
        'wizard_generate_dkim'               => ['super-admin', 'operator'],
        'wizard_list_landing_options'        => ['super-admin', 'operator'],
        'wizard_preflight'                   => ['super-admin', 'operator'],
        'wizard_recipient_preview'           => ['super-admin', 'operator'],
        'wizard_save_progress'               => ['super-admin', 'operator'],
        // Phase 3.57: full-funnel wizard helpers (auto-tracker, recipient commit).
        'wizard_create_web_tracker'          => ['super-admin', 'operator'],
        'wizard_list_web_trackers'           => ['super-admin', 'operator'],
        // P2.1: unified tracker list (web + quick) — read, operator+.
        'list_all_trackers'                  => ['super-admin', 'operator'],
        'wizard_commit_recipients'           => ['super-admin', 'operator'],
        'wizard_launch_campaign'             => ['super-admin', 'engagement_owner'],

        // --- Phase 3.48 admin UI (tasks 5/6): role assignment, API tokens, members ---
        // Assigning a global role is super-admin only (settings_manager.php).
        'set_user_role'                      => ['super-admin'],
        // Per-operator API tokens — self-service over the operator's OWN tokens
        // (the manager resolves user_id from the session; one user can never
        // touch another's). read-only is excluded: a token only ever does what
        // its owner can, so a read-only token is pointless credential sprawl.
        'list_api_tokens'                    => ['super-admin', 'operator'],
        'mint_api_token'                     => ['super-admin', 'operator'],
        'revoke_api_token'                   => ['super-admin', 'operator'],
        // Engagement membership management (engagement.php; carries engagement_id).
        // Mutations are owner-or-super-admin; viewing the roster needs membership.
        'list_engagement_members'            => ['engagement_member'],
        'add_engagement_member'              => ['super-admin', 'engagement_owner'],
        'remove_engagement_member'           => ['super-admin', 'engagement_owner'],
        'set_engagement_member_role'         => ['super-admin', 'engagement_owner'],
    ]);
}

if (!function_exists('taphish_authz_ensure_role_column')) {
    /**
     * Phase 3.48 task 2: add tb_main.role (idempotent boot migration, mirrors
     * the Phase 3.45a pattern). On first add, promote the bootstrap `admin`
     * to super-admin — one-shot, only when the column was just created, so a
     * later manual role change is never clobbered.
     */
    function taphish_authz_ensure_role_column(\mysqli $conn): void
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tb_main'
               AND COLUMN_NAME = 'role'"
        );
        if ($stmt === false) {
            return;
        }
        $stmt->execute();
        $present = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        if ($present > 0) {
            return;
        }
        @$conn->query("ALTER TABLE tb_main ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'operator'");
        // Every account that existed before RBAC had full admin access, so
        // promote ALL of them to super-admin — not just one named 'admin'.
        // (An install whose operator is named e.g. 'TADD' would otherwise be
        // silently demoted to operator and locked out of super-admin actions.)
        @$conn->query("UPDATE tb_main SET role = 'super-admin'");
    }
}

if (!function_exists('taphish_authz_ensure_initial_super_admins')) {
    /**
     * Phase 3.48 corrective (one-time, tb_store-gated): heals installs where an
     * earlier build added the role column with an admin-ONLY promote, leaving
     * pre-existing operators (not named 'admin') demoted to 'operator' and
     * locked out of super-admin functions. Promotes every CURRENT account to
     * super-admin once (they all had full access before RBAC); new accounts
     * created afterward keep the 'operator' default. Runs once, then the flag
     * row in tb_store stops it — so deliberately-demoted users stay demoted.
     */
    function taphish_authz_ensure_initial_super_admins(\mysqli $conn): void
    {
        if (!($conn instanceof \mysqli)) {
            return;
        }
        $chk = @$conn->query("SELECT content FROM tb_store WHERE type='rbac' AND name='initial_promote' LIMIT 1");
        if ($chk && $chk->fetch_row()) {
            return; // already run
        }
        @$conn->query("UPDATE tb_main SET role = 'super-admin'");
        $stmt = $conn->prepare(
            "INSERT INTO tb_store (type, name, info, content)
             VALUES ('rbac', 'initial_promote', 'Phase 3.48: one-time promote of pre-RBAC accounts to super-admin', '1')"
        );
        if ($stmt !== false) {
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('taphish_authz_ensure_engagement_member_table')) {
    /**
     * Phase 3.48 task 3: create tb_core_engagement_member (idempotent) and
     * backfill the bootstrap `admin` as owner of every existing engagement so
     * legacy data isn't orphaned. INSERT IGNORE keeps the backfill safe to run
     * on every boot — it only adds missing owner rows.
     */
    function taphish_authz_ensure_engagement_member_table(\mysqli $conn): void
    {
        @$conn->query(
            "CREATE TABLE IF NOT EXISTS tb_core_engagement_member (
               engagement_id INT         NOT NULL,
               user_id       INT         NOT NULL,
               role          VARCHAR(32) NOT NULL DEFAULT 'operator',
               created_at    BIGINT      NOT NULL,
               PRIMARY KEY (engagement_id, user_id),
               KEY (user_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $adminId = 0;
        $res = @$conn->query("SELECT id FROM tb_main WHERE username = 'admin' LIMIT 1");
        if ($res && ($r = $res->fetch_assoc())) {
            $adminId = (int) $r['id'];
        }
        if ($adminId > 0) {
            $ts = time();
            @$conn->query(
                "INSERT IGNORE INTO tb_core_engagement_member (engagement_id, user_id, role, created_at)
                 SELECT e.id, {$adminId}, 'owner', {$ts} FROM tb_core_engagement e"
            );
        }
    }
}

if (!function_exists('taphish_engagement_add_member')) {
    /**
     * Phase 3.48: register a user as a member of an engagement (default role
     * 'owner'). Called when an engagement is created so the creator can act on
     * their own engagement under engagement_member/owner checks. Idempotent
     * (INSERT IGNORE on the composite PK).
     */
    function taphish_engagement_add_member(\mysqli $conn, int $engagement_id, string $username, string $role = 'owner'): bool
    {
        if (!($conn instanceof \mysqli) || $engagement_id <= 0 || $username === '') {
            return false;
        }
        $stmt = $conn->prepare("SELECT id FROM tb_main WHERE username = ?");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        $uid = (int) $row['id'];
        $ts  = time();
        $ins = $conn->prepare(
            "INSERT IGNORE INTO tb_core_engagement_member (engagement_id, user_id, role, created_at)
             VALUES (?, ?, ?, ?)"
        );
        if ($ins === false) {
            return false;
        }
        $ins->bind_param('iisi', $engagement_id, $uid, $role, $ts);
        $ok = $ins->execute();
        $ins->close();
        return (bool) $ok;
    }
}

if (!function_exists('taphish_engagement_members')) {
    /**
     * Phase 3.48: the membership roster for an engagement — one row per member
     * with their account details + engagement role, newest membership first.
     * Never returns secrets; safe to render in the EngagementMembers grid.
     */
    function taphish_engagement_members(\mysqli $conn, int $engagement_id): array
    {
        if (!($conn instanceof \mysqli) || $engagement_id <= 0) {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT u.id AS user_id, u.username, u.name, u.role AS global_role,
                    m.role AS engagement_role, m.created_at
             FROM tb_core_engagement_member m
             JOIN tb_main u ON u.id = m.user_id
             WHERE m.engagement_id = ?
             ORDER BY m.created_at DESC, u.username ASC"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $engagement_id);
        $stmt->execute();
        $res  = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('taphish_engagement_count_owners')) {
    /** Phase 3.48: how many members hold role='owner' on an engagement. */
    function taphish_engagement_count_owners(\mysqli $conn, int $engagement_id): int
    {
        if (!($conn instanceof \mysqli) || $engagement_id <= 0) {
            return 0;
        }
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM tb_core_engagement_member
             WHERE engagement_id = ? AND role = 'owner'"
        );
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('i', $engagement_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['n'] ?? 0);
    }
}

if (!function_exists('taphish_engagement_remove_member')) {
    /**
     * Phase 3.48: drop a user from an engagement. Refuses to remove the LAST
     * owner — an engagement with no owner can never be administered again
     * (only the owner/super-admin tier can manage members). super-admin can
     * still reach it implicitly, but we don't want to strand the roster.
     */
    function taphish_engagement_remove_member(\mysqli $conn, int $engagement_id, string $username): bool
    {
        if (!($conn instanceof \mysqli) || $engagement_id <= 0 || $username === '') {
            return false;
        }
        $cur = taphish_engagement_role($conn, $engagement_id, $username);
        if ($cur === null) {
            return false; // not a member
        }
        if ($cur === 'owner' && taphish_engagement_count_owners($conn, $engagement_id) <= 1) {
            return false; // would strand the engagement with no owner
        }
        $stmt = $conn->prepare(
            "DELETE m FROM tb_core_engagement_member m
             JOIN tb_main u ON u.id = m.user_id
             WHERE m.engagement_id = ? AND u.username = ?"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('is', $engagement_id, $username);
        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('taphish_engagement_set_member_role')) {
    /**
     * Phase 3.48: change an existing member's engagement role
     * (owner | member | read-only). Refuses to demote the last owner away from
     * 'owner' for the same stranding reason as removal.
     */
    function taphish_engagement_set_member_role(\mysqli $conn, int $engagement_id, string $username, string $role): bool
    {
        $valid = ['owner', 'member', 'read-only'];
        if (!($conn instanceof \mysqli) || $engagement_id <= 0 || $username === '' || !in_array($role, $valid, true)) {
            return false;
        }
        $cur = taphish_engagement_role($conn, $engagement_id, $username);
        if ($cur === null) {
            return false; // not a member — use add instead
        }
        if ($cur === 'owner' && $role !== 'owner' && taphish_engagement_count_owners($conn, $engagement_id) <= 1) {
            return false; // last owner can't demote themselves out of ownership
        }
        $stmt = $conn->prepare(
            "UPDATE tb_core_engagement_member m
             JOIN tb_main u ON u.id = m.user_id
             SET m.role = ?
             WHERE m.engagement_id = ? AND u.username = ?"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sis', $role, $engagement_id, $username);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('taphish_policy_for')) {
    /**
     * Resolve the allowed-tier list for an action. Exact match first, then a
     * trailing-'*' wildcard key (e.g. 'beef_settings_*'). Null => default-deny.
     */
    function taphish_policy_for(string $action): ?array
    {
        $policy = TAPHISH_POLICY;
        if (isset($policy[$action])) {
            return $policy[$action];
        }
        foreach ($policy as $key => $tiers) {
            if (substr($key, -1) === '*' && str_starts_with($action, substr($key, 0, -1))) {
                return $tiers;
            }
        }
        return null;
    }
}

if (!function_exists('taphish_policy_allows')) {
    /**
     * Pure RBAC decision. $ctx may carry 'engagement_role' =>
     * 'owner'|'member'|'read-only'|null (the caller's role on the engagement
     * in context, already resolved).
     */
    function taphish_policy_allows(string $action, string $role, array $ctx = []): bool
    {
        if ($role === 'disabled') {
            return false;
        }
        $tiers = taphish_policy_for($action);
        if ($tiers === null) {
            return false; // default-deny
        }
        // super-admin implicitly satisfies any policied action.
        if ($role === 'super-admin') {
            return true;
        }
        $engRole = $ctx['engagement_role'] ?? null;
        foreach ($tiers as $tier) {
            if ($tier === '*') {
                return true;
            }
            if ($tier === $role) {
                return true;
            }
            if ($tier === 'engagement_member' && $engRole !== null) {
                return true;
            }
            if ($tier === 'engagement_owner' && $engRole === 'owner') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('taphish_user_role')) {
    /**
     * Global role for a username from tb_main. Safe default 'operator' (the
     * column default) on miss/hiccup so a DB blip never locks an authenticated
     * operator out; only an explicit role='disabled' disables.
     */
    function taphish_user_role($conn, string $username): string
    {
        $valid = ['super-admin', 'operator', 'read-only', 'disabled'];
        if (!($conn instanceof \mysqli) || $username === '') {
            return 'operator';
        }
        $stmt = $conn->prepare("SELECT role FROM tb_main WHERE username = ?");
        if ($stmt === false) {
            return 'operator';
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $role = (string) ($row['role'] ?? '');
        return in_array($role, $valid, true) ? $role : 'operator';
    }
}

if (!function_exists('taphish_engagement_role')) {
    /**
     * The caller's role on a specific engagement (via tb_core_engagement_member
     * joined to tb_main), or null if they are not a member.
     */
    function taphish_engagement_role($conn, int $engagement_id, string $username): ?string
    {
        if (!($conn instanceof \mysqli) || $engagement_id <= 0 || $username === '') {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT m.role FROM tb_core_engagement_member m
             JOIN tb_main u ON u.id = m.user_id
             WHERE m.engagement_id = ? AND u.username = ?"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('is', $engagement_id, $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return isset($row['role']) ? (string) $row['role'] : null;
    }
}

if (!function_exists('taphish_authorize')) {
    /**
     * Resolve the caller's role (+ engagement membership when
     * $context['engagement_id'] is set) and apply the policy. The two resolver
     * callables are an injection seam for unit tests; production callers omit
     * them and the DB-backed helpers are used.
     */
    function taphish_authorize(
        $conn,
        string $action,
        array $context = [],
        ?callable $roleResolver = null,
        ?callable $engRoleResolver = null
    ): bool {
        $username = (string) ($context['username'] ?? ($_SESSION['username'] ?? ''));
        if ($username === '') {
            return false;
        }
        $role = $roleResolver ? (string) $roleResolver($username) : taphish_user_role($conn, $username);
        $ctx = [];
        if (isset($context['engagement_id'])) {
            $eid = (int) $context['engagement_id'];
            $ctx['engagement_role'] = $engRoleResolver
                ? $engRoleResolver($eid, $username)
                : taphish_engagement_role($conn, $eid, $username);
        }
        return taphish_policy_allows($action, $role, $ctx);
    }
}

if (!function_exists('taphish_current_user_role')) {
    /** Global role of the logged-in operator ('disabled' if no session). */
    function taphish_current_user_role($conn): string
    {
        $u = (string) ($_SESSION['username'] ?? '');
        return $u === '' ? 'disabled' : taphish_user_role($conn, $u);
    }
}

if (!function_exists('taphish_can')) {
    /**
     * UI-side permission check: same decision as the guard but returns a bool
     * instead of dying, so pages can hide controls the operator can't use.
     * The guard remains the security boundary; this is UX only.
     */
    function taphish_can($conn, string $action, array $context = []): bool
    {
        if (!isset($context['username']) && isset($_SESSION['username'])) {
            $context['username'] = $_SESSION['username'];
        }
        return taphish_authorize($conn, $action, $context);
    }
}

if (!function_exists('taphish_require_authorize_or_die')) {
    /**
     * Dispatcher guard: allow → return; deny → audit-log + 403 +
     * {result:'forbidden'} JSON + exit. Never a soft 200 with empty data.
     */
    function taphish_require_authorize_or_die($conn, string $action, array $context = []): void
    {
        if (taphish_authorize($conn, $action, $context)) {
            return;
        }
        $username = (string) ($context['username'] ?? ($_SESSION['username'] ?? '(anonymous)'));
        if (function_exists('logIt')) {
            logIt('Forbidden ' . $action . ' attempted by ' . $username);
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'result' => 'forbidden',
            'error'  => 'You do not have permission to perform this action.',
        ]);
        exit;
    }
}

if (!function_exists('taphish_user_group_visible')) {
    /**
     * Phase 3.48b (per-engagement PII isolation). Pure: may a user with this
     * global role + engagement memberships see/act on a recipient user-group
     * carrying this engagement_id? super-admin sees all; disabled sees nothing;
     * a NULL engagement_id is a legacy/unscoped group visible to any operator
     * (decision #1 — no lockout on deploy); a scoped group is visible only to
     * members of that engagement.
     */
    function taphish_user_group_visible(?int $groupEngagementId, string $role, array $memberIds): bool
    {
        if ($role === 'disabled') {
            return false;
        }
        if ($role === 'super-admin') {
            return true;
        }
        if ($groupEngagementId === null) {
            return true;
        }
        return in_array((int) $groupEngagementId, array_map('intval', $memberIds), true);
    }
}

if (!function_exists('taphish_user_engagement_ids')) {
    /** Phase 3.48b: engagement ids the user belongs to (tb_core_engagement_member ⋈ tb_main). */
    function taphish_user_engagement_ids(\mysqli $conn, string $username): array
    {
        if (!($conn instanceof \mysqli) || $username === '') {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT m.engagement_id FROM tb_core_engagement_member m
             JOIN tb_main u ON u.id = m.user_id WHERE u.username = ?"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $ids = [];
        while ($r = $res->fetch_assoc()) {
            $ids[] = (int) $r['engagement_id'];
        }
        $stmt->close();
        return $ids;
    }
}

if (!function_exists('taphish_user_group_guard_or_die')) {
    /**
     * Phase 3.48b: dispatcher guard for single-row user-group ops. Resolves the
     * group's engagement_id, applies taphish_user_group_visible; on deny emits
     * 403 + {result:'forbidden'} + audit log + exit — the same no-soft-200 shape
     * as taphish_require_authorize_or_die. A missing group falls through to the
     * visibility check with engagement_id NULL (visible), so the handler's own
     * not-found path still runs.
     */
    function taphish_user_group_guard_or_die($conn, string $group_id): void
    {
        $username = (string) ($_SESSION['username'] ?? '');
        $role = taphish_user_role($conn, $username);
        $eid = null;
        if (($conn instanceof \mysqli) && $group_id !== '') {
            $stmt = $conn->prepare("SELECT engagement_id FROM tb_core_mailcamp_user_group WHERE user_group_id = ?");
            if ($stmt !== false) {
                $stmt->bind_param('s', $group_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $eid = (isset($row['engagement_id']) && $row['engagement_id'] !== null) ? (int) $row['engagement_id'] : null;
            }
        }
        if (taphish_user_group_visible($eid, $role, taphish_user_engagement_ids($conn, $username))) {
            return;
        }
        if (function_exists('logIt')) {
            logIt('Forbidden user-group ' . $group_id . ' attempted by ' . $username);
        }
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'result' => 'forbidden',
            'error'  => 'You do not have access to this recipient list.',
        ]);
        exit;
    }
}

if (!function_exists('taphish_user_group_scope_where')) {
    /**
     * Phase 3.48b: a SQL WHERE suffix that limits tb_core_mailcamp_user_group to
     * the rows the current operator may see — '' for super-admin (unfiltered),
     * otherwise " WHERE (engagement_id IS NULL OR engagement_id IN (...))". The
     * id list is int-cast so the inlined IN(...) is injection-safe. Intended for
     * the bare list SELECTs that have no other WHERE clause.
     */
    function taphish_user_group_scope_where($conn): string
    {
        $username = (string) ($_SESSION['username'] ?? '');
        $role = taphish_user_role($conn, $username);
        if ($role === 'super-admin') {
            return '';
        }
        $ids = taphish_user_engagement_ids($conn, $username);
        $inList = $ids ? implode(',', array_map('intval', $ids)) : '0';
        return " WHERE (engagement_id IS NULL OR engagement_id IN ($inList))";
    }
}

if (!function_exists('taphish_user_group_can_stamp')) {
    /**
     * Phase 3.48b: may the current operator stamp a recipient list onto this
     * engagement? engagement_id <= 0 means "leave unscoped" (always allowed);
     * otherwise super-admin always may, and an operator only if they are a
     * member of that engagement — so PII can't be filed into an engagement the
     * operator can't see.
     */
    function taphish_user_group_can_stamp($conn, int $engagement_id): bool
    {
        if ($engagement_id <= 0) {
            return true;
        }
        $username = (string) ($_SESSION['username'] ?? '');
        if (taphish_user_role($conn, $username) === 'super-admin') {
            return true;
        }
        return taphish_engagement_role($conn, $engagement_id, $username) !== null;
    }
}

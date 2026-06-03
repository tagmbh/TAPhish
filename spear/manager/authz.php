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
     * NOTE (requirement #4, partial): per-engagement PII isolation is enforced
     * for engagement-level actions that carry engagement_id; recipient/user-
     * group actions are limited to operator+ (not read-only) but are NOT yet
     * per-engagement scoped because tb_core_mailcamp_user_group has no
     * engagement_id column. Closing that fully needs a follow-up migration —
     * tracked as deferred PII-isolation hardening.
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
        // recipient / user-group (PII — operator+; see requirement #4 note)
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
        // user administration (super-admin only)
        'add_user_to_table'                  => ['super-admin'],
        'update_user'                        => ['super-admin'],
        'delete_user'                        => ['super-admin'],
        // engagement lifecycle (engagement-scoped — these carry engagement_id)
        'save_engagement'                    => ['super-admin', 'operator'],
        'get_engagement_view'                => ['engagement_member'],
        'engagement_transition_status'       => ['super-admin', 'engagement_owner'],
        'delete_engagement'                  => ['super-admin', 'engagement_owner'],
        // wizard steps (operator work; launch is engagement-scoped)
        'wizard_generate_dkim'               => ['super-admin', 'operator'],
        'wizard_list_landing_options'        => ['super-admin', 'operator'],
        'wizard_preflight'                   => ['super-admin', 'operator'],
        'wizard_recipient_preview'           => ['super-admin', 'operator'],
        'wizard_save_progress'               => ['super-admin', 'operator'],
        'wizard_launch_campaign'             => ['engagement_member'],
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
        @$conn->query("UPDATE tb_main SET role = 'super-admin' WHERE username = 'admin'");
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

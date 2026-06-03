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
    define('TAPHISH_POLICY', [
        'view_home'                    => ['*'],
        'list_engagements'             => ['*'],
        'beef_list_hooks'              => ['*'],
        'view_audit_log'               => ['super-admin'],
        'export_audit_log'             => ['super-admin'],
        'manage_users'                 => ['super-admin'],
        'manage_api_tokens'            => ['super-admin'],
        'beef_settings_*'              => ['super-admin'],
        'save_engagement'              => ['super-admin', 'operator'],
        'library_clone_to_my_sites'    => ['operator', 'super-admin'],
        'site_clone'                   => ['operator', 'super-admin'],
        'view_engagement'              => ['engagement_member'],
        'export_engagement_pdf'        => ['engagement_member'],
        'save_campaign'                => ['engagement_member'],
        'send_campaign'                => ['engagement_member'],
        'view_recipients'             => ['engagement_member'],
        'upload_recipients'           => ['engagement_member'],
        'transition_engagement_status' => ['super-admin', 'engagement_owner'],
        'manage_engagement_members'    => ['super-admin', 'engagement_owner'],
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

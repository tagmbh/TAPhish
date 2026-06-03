<?php
/**
 * Phase 3.48 — per-operator API tokens.
 *
 * Long-lived bearer tokens that authenticate AS an operator and then pass
 * through the same taphish_authorize() guard (no separate trust path). The
 * plaintext is shown ONCE at mint; storage is a bcrypt hash (GitHub-PAT model).
 *
 * Token shape: "tphtk_<id>_<secret>". The <id> segment is the public lookup
 * key (so we don't have to scan + bcrypt-verify every row); the <secret> is
 * verified against the row's bcrypt hash. Pure helpers (format/parse/verify
 * a row) are unit-tested; mint/authenticate/list/revoke are DB-backed.
 */

if (!function_exists('taphish_extract_bearer_token')) {
    /**
     * Pull the bearer token from the Authorization header, '' if absent.
     * Checks $_SERVER and getallheaders() (some SAPIs only expose one).
     */
    function taphish_extract_bearer_token(): string
    {
        $auth = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $auth = (string) $v; break; }
            }
        }
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
            return $m[1];
        }
        return '';
    }
}

if (!function_exists('taphish_api_token_ensure_table')) {
    function taphish_api_token_ensure_table(\mysqli $conn): void
    {
        @$conn->query(
            "CREATE TABLE IF NOT EXISTS tb_main_api_token (
               id           INT AUTO_INCREMENT PRIMARY KEY,
               user_id      INT          NOT NULL,
               token_hash   VARCHAR(255) NOT NULL,
               label        VARCHAR(128) NOT NULL,
               last_used_at BIGINT       NULL,
               created_at   BIGINT       NOT NULL,
               revoked_at   BIGINT       NULL,
               KEY (user_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('taphish_api_token_format')) {
    /** Pure: assemble the plaintext token from its id + secret. */
    function taphish_api_token_format(int $id, string $secret): string
    {
        return 'tphtk_' . $id . '_' . $secret;
    }
}

if (!function_exists('taphish_api_token_parse')) {
    /** Pure: split a token into ['id'=>int,'secret'=>string], or null if malformed. */
    function taphish_api_token_parse(string $token): ?array
    {
        if (!preg_match('/^tphtk_([0-9]+)_([A-Za-z0-9]{20,})$/', trim($token), $m)) {
            return null;
        }
        return ['id' => (int) $m[1], 'secret' => $m[2]];
    }
}

if (!function_exists('taphish_api_token_verify_secret')) {
    /**
     * Pure: does $secret match a stored token row? False for a missing row, a
     * revoked token, or a non-matching secret.
     */
    function taphish_api_token_verify_secret(string $secret, ?array $row): bool
    {
        if (!$row || !isset($row['token_hash'])) {
            return false;
        }
        if (!empty($row['revoked_at'])) {
            return false;
        }
        return verify_user_password($secret, (string) $row['token_hash']);
    }
}

if (!function_exists('taphish_api_token_mint')) {
    /**
     * Insert a token for $user_id and return ['id'=>int,'token'=>plaintext].
     * The plaintext is the only time the secret is recoverable. Null on error.
     */
    function taphish_api_token_mint(\mysqli $conn, int $user_id, string $label): ?array
    {
        if (!($conn instanceof \mysqli) || $user_id <= 0) {
            return null;
        }
        $secret = function_exists('make_secure_token') ? make_secure_token() : bin2hex(random_bytes(24));
        $secret = preg_replace('/[^A-Za-z0-9]/', '', (string) $secret);
        if (strlen($secret) < 24) {
            $secret .= bin2hex(random_bytes(16));
        }
        $hash  = hash_user_password($secret);
        $label = substr(trim($label) !== '' ? trim($label) : 'token', 0, 128);
        $ts    = time();
        $stmt  = $conn->prepare(
            "INSERT INTO tb_main_api_token (user_id, token_hash, label, created_at) VALUES (?, ?, ?, ?)"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('issi', $user_id, $hash, $label, $ts);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $id = $conn->insert_id;
        $stmt->close();
        return ['id' => (int) $id, 'token' => taphish_api_token_format((int) $id, $secret)];
    }
}

if (!function_exists('taphish_api_token_authenticate')) {
    /**
     * Resolve a bearer token to the username it authenticates as (or null).
     * Stamps last_used_at on success. The caller then runs the request through
     * taphish_authorize exactly as a session would.
     */
    function taphish_api_token_authenticate(\mysqli $conn, string $token): ?string
    {
        if (!($conn instanceof \mysqli)) {
            return null;
        }
        $p = taphish_api_token_parse($token);
        if ($p === null) {
            return null;
        }
        $stmt = $conn->prepare(
            "SELECT t.token_hash, t.revoked_at, u.username
             FROM tb_main_api_token t JOIN tb_main u ON u.id = t.user_id
             WHERE t.id = ?"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $p['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!taphish_api_token_verify_secret($p['secret'], $row)) {
            return null;
        }
        $ts = time();
        $u  = $conn->prepare("UPDATE tb_main_api_token SET last_used_at = ? WHERE id = ?");
        if ($u !== false) {
            $u->bind_param('ii', $ts, $p['id']);
            $u->execute();
            $u->close();
        }
        return (string) $row['username'];
    }
}

if (!function_exists('taphish_api_token_list')) {
    /** Active + revoked tokens for a user (never returns the hash). */
    function taphish_api_token_list(\mysqli $conn, int $user_id): array
    {
        if (!($conn instanceof \mysqli) || $user_id <= 0) {
            return [];
        }
        $stmt = $conn->prepare(
            "SELECT id, label, last_used_at, created_at, revoked_at
             FROM tb_main_api_token WHERE user_id = ? ORDER BY created_at DESC"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $user_id);
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

if (!function_exists('taphish_api_token_revoke')) {
    /** Revoke a token (scoped to its owner so one user can't revoke another's). */
    function taphish_api_token_revoke(\mysqli $conn, int $id, int $user_id): bool
    {
        if (!($conn instanceof \mysqli) || $id <= 0 || $user_id <= 0) {
            return false;
        }
        $ts   = time();
        $stmt = $conn->prepare(
            "UPDATE tb_main_api_token SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL"
        );
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('iii', $ts, $id, $user_id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }
}

<?php
/**
 * Symmetric at-rest encryption for sensitive operator-stored credentials
 * (initially SMTP/IMAP passwords on tb_core_mailcamp_sender_list).
 *
 * Scheme: AES-256-GCM via openssl. Output is base64'd `iv | tag |
 * ciphertext` prefixed with `enc1:` so callers can distinguish
 * already-encrypted strings from legacy plaintext on a transparent
 * upgrade path.
 *
 * Key storage: a 32-byte random key kept in /spear/config/secret.key.
 * Generated lazily on first use, gitignored alongside db.php.
 *
 * Design intent — what this defends:
 *   - DB dump leaks (mysqldump in a backup the operator misplaces,
 *     hosting provider migration, etc.)
 *   - SQL injection that reads tb_core_mailcamp_sender_list (the
 *     attacker still needs filesystem read on the panel host to
 *     decrypt)
 *
 * What this does NOT defend:
 *   - Full server compromise — the key sits next to the DB credentials
 *     and an attacker with code-exec can decrypt anything they read
 *   - Memory disclosure during a send — the cron worker has the
 *     plaintext SMTP password in process memory while it's logging in
 *
 * Pure helpers (encrypt/decrypt/is_encrypted) live at the top of this
 * file and are tested in tests/SecretAtRestTest.php. Filesystem-key
 * lookup lives at the bottom.
 */

if (!defined('SECRET_AT_REST_PREFIX')) {
    define('SECRET_AT_REST_PREFIX', 'enc1:');
}
if (!defined('SECRET_AT_REST_KEY_PATH')) {
    define('SECRET_AT_REST_KEY_PATH', dirname(__FILE__, 2) . '/config/secret.key');
}

if (!function_exists('secret_at_rest_is_encrypted')) {
    function secret_at_rest_is_encrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, SECRET_AT_REST_PREFIX);
    }
}

if (!function_exists('secret_at_rest_encrypt')) {
    /**
     * Encrypt $plaintext with $key (32 raw bytes). Returns a
     * SECRET_AT_REST_PREFIX-prefixed envelope or null on failure.
     */
    function secret_at_rest_encrypt(string $plaintext, string $key): ?string
    {
        if (strlen($key) !== 32) {
            return null;
        }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($ct === false || $tag === '') {
            return null;
        }
        return SECRET_AT_REST_PREFIX . base64_encode($iv . $tag . $ct);
    }
}

if (!function_exists('secret_at_rest_decrypt')) {
    /**
     * Decrypt an envelope previously produced by secret_at_rest_encrypt().
     * Returns the plaintext, or null on tampering / bad key / malformed
     * envelope.
     */
    function secret_at_rest_decrypt(string $envelope, string $key): ?string
    {
        if (strlen($key) !== 32) {
            return null;
        }
        if (!secret_at_rest_is_encrypted($envelope)) {
            return null;
        }
        $payload = base64_decode(substr($envelope, strlen(SECRET_AT_REST_PREFIX)), true);
        if ($payload === false || strlen($payload) < 12 + 16) {
            return null;
        }
        $iv  = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ct  = substr($payload, 28);
        $pt = openssl_decrypt(
            $ct,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );
        return $pt === false ? null : $pt;
    }
}

if (!function_exists('secret_at_rest_passthrough_decrypt')) {
    /**
     * Convenience wrapper for reading from columns that may hold either a
     * pre-3.27 plaintext value OR a post-3.27 encrypted envelope: returns
     * the plaintext in both cases, transparently. Empty/null input passes
     * through unchanged.
     */
    function secret_at_rest_passthrough_decrypt(?string $stored, string $key): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (!secret_at_rest_is_encrypted($stored)) {
            return $stored; // legacy plaintext
        }
        return secret_at_rest_decrypt($stored, $key);
    }
}

if (!function_exists('mail_sender_seal_pwd')) {
    /**
     * Convenience wrapper: encrypt an operator-supplied SMTP/IMAP password
     * for at-rest storage in tb_core_mailcamp_sender_list. Falls back to
     * plaintext storage (with a log entry) if the on-disk key can't be
     * created — that keeps a misconfigured filesystem from blocking
     * legitimate operator action, but it's noisy enough that the operator
     * notices and fixes it.
     */
    function mail_sender_seal_pwd(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = secret_at_rest_get_key();
        if ($key === null) {
            if (function_exists('logIt')) {
                logIt('mail_sender_seal_pwd: at-rest key unavailable, storing plaintext', 'system');
            }
            return $plaintext;
        }
        $enc = secret_at_rest_encrypt($plaintext, $key);
        return $enc ?? $plaintext;
    }
}

if (!function_exists('mail_sender_unseal_pwd')) {
    /**
     * Convenience wrapper: decrypt for use, passing legacy plaintext
     * rows through unchanged. Returns the plaintext (or null/empty
     * passthrough).
     */
    function mail_sender_unseal_pwd(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (!secret_at_rest_is_encrypted($stored)) {
            return $stored;
        }
        $key = secret_at_rest_get_key();
        if ($key === null) {
            // Can't decrypt without the key — return null rather than
            // silently sending an envelope to an SMTP server.
            return null;
        }
        return secret_at_rest_decrypt($stored, $key);
    }
}

// ---- Phase 3.38: recipient list PII at-rest ----------------------------
//
// The tb_core_mailcamp_user_group.user_data column holds a JSON-encoded
// array of recipient rows (fname, lname, email, notes) — the most
// sensitive single column in the panel. Same envelope pattern as the
// SMTP-password wrappers above so existing plaintext rows continue to
// work and new writes get encrypted transparently.

if (!function_exists('recipient_data_seal')) {
    /**
     * Encrypt a JSON-encoded recipient blob for at-rest storage. Empty
     * input passes through. Falls back to plaintext (with a log note)
     * when the on-disk key is missing, matching the mail-sender helper.
     */
    function recipient_data_seal(string $plaintext_json): string
    {
        if ($plaintext_json === '') {
            return '';
        }
        $key = secret_at_rest_get_key();
        if ($key === null) {
            if (function_exists('logIt')) {
                logIt('recipient_data_seal: at-rest key unavailable, storing plaintext', 'system');
            }
            return $plaintext_json;
        }
        $enc = secret_at_rest_encrypt($plaintext_json, $key);
        return $enc ?? $plaintext_json;
    }
}

if (!function_exists('recipient_data_unseal')) {
    /**
     * Decrypt for use. Plaintext (legacy or fallback) passes through.
     * Returns the JSON string (or null/empty passthrough). Callers
     * still need to json_decode() the result.
     */
    function recipient_data_unseal(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (!secret_at_rest_is_encrypted($stored)) {
            return $stored;
        }
        $key = secret_at_rest_get_key();
        if ($key === null) {
            // Can't decrypt — return null rather than feed a ciphertext
            // envelope into json_decode() (which would silently produce
            // an empty recipient list).
            return null;
        }
        return secret_at_rest_decrypt($stored, $key);
    }
}

// ---- Filesystem-backed key lookup -------------------------------------

if (!function_exists('secret_at_rest_get_key')) {
    /**
     * Read the at-rest key from /spear/config/secret.key, generating it
     * lazily on first use. The file is created with 0600 so only the
     * panel's web user can read it.
     *
     * Returns the 32 raw bytes, or null if the filesystem refuses to
     * give us a key (read-only filesystem, no write perms — at which
     * point the caller should fall back to plaintext storage, with a
     * note in the log).
     */
    function secret_at_rest_get_key(): ?string
    {
        $path = SECRET_AT_REST_KEY_PATH;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && strlen($raw) === 32) {
                return $raw;
            }
            // Length is wrong — probably manually fiddled with. Bail
            // rather than re-generate and silently break decryption of
            // every existing encrypted row.
            return null;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }
        $key = random_bytes(32);
        if (@file_put_contents($path, $key, LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0600);
        return $key;
    }
}

if (!function_exists('secret_at_rest_ensure_sender_pwd_width')) {
    /**
     * Idempotent boot migration. The original (2022) schema declared
     * tb_core_mailcamp_sender_list.sender_acc_pwd as VARCHAR(50). Phase 3.27
     * began sealing the SMTP password at rest, whose base64 envelope (enc1:
     * + iv + tag + ciphertext) overflows 50 chars even for a short password
     * — so EVERY sender save fatals with "Data too long" on an un-migrated
     * install. Widen once to comfortably hold a sealed long password.
     */
    function secret_at_rest_ensure_sender_pwd_width(\mysqli $conn): void
    {
        $res = @$conn->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tb_core_mailcamp_sender_list'
               AND COLUMN_NAME = 'sender_acc_pwd'"
        );
        $n = ($res && ($row = $res->fetch_assoc())) ? (int) $row['n'] : 0;
        if ($n > 0 && $n < 512) {
            @$conn->query("ALTER TABLE tb_core_mailcamp_sender_list MODIFY sender_acc_pwd VARCHAR(512) NOT NULL");
        }
    }
}

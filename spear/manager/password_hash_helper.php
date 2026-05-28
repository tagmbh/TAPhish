<?php
/**
 * Password hashing helpers.
 *
 * New passwords are stored as bcrypt via password_hash(PASSWORD_BCRYPT).
 * Legacy installs may still hold unsalted SHA-256 hex digests (the upstream
 * SniperPhish format); verify_user_password() accepts both so existing
 * operators can log in. The session_manager rehashes to bcrypt on a
 * successful legacy login.
 *
 * No DB, no session. Pure helpers; unit-tested in
 * tests/PasswordHashHelperTest.php.
 */

if (!function_exists('hash_user_password')) {
    /**
     * Hash a new password. Uses PASSWORD_BCRYPT (cost 12) for broad PHP
     * compatibility; switch to PASSWORD_ARGON2ID later by changing this
     * single function.
     */
    function hash_user_password(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('is_legacy_sha256_hash')) {
    /**
     * True if a stored hash looks like the legacy unsalted SHA-256 hex
     * (exactly 64 lowercase hex chars). False for bcrypt ($2y$...) and
     * argon2 ($argon2id$...).
     */
    function is_legacy_sha256_hash(?string $stored): bool
    {
        if (!is_string($stored) || strlen($stored) !== 64) {
            return false;
        }
        return (bool) preg_match('/^[0-9a-f]{64}$/', $stored);
    }
}

if (!function_exists('verify_user_password')) {
    /**
     * Constant-time verification accepting either a current bcrypt hash or
     * the legacy unsalted SHA-256 hex digest.
     */
    function verify_user_password(string $plain, ?string $stored): bool
    {
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        if (is_legacy_sha256_hash($stored)) {
            return hash_equals($stored, hash('sha256', $plain, false));
        }
        return password_verify($plain, $stored);
    }
}

if (!function_exists('password_should_rehash')) {
    /**
     * True if the stored hash is in a format we want to migrate away from
     * (legacy SHA-256, or bcrypt with a stale cost).
     */
    function password_should_rehash(?string $stored): bool
    {
        if (!is_string($stored) || $stored === '') {
            return true;
        }
        if (is_legacy_sha256_hash($stored)) {
            return true;
        }
        return password_needs_rehash($stored, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('make_secure_token')) {
    /**
     * Cryptographically random URL-safe token (64 hex chars, 256 bits).
     * Use this for password-reset links instead of md5(uniqid+rand).
     */
    function make_secure_token(): string
    {
        return bin2hex(random_bytes(32));
    }
}

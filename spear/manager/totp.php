<?php
/**
 * RFC 6238 Time-based One-Time Password (TOTP) implementation.
 *
 * Used to add a second factor on operator login. Compatible with Google
 * Authenticator, Authy, 1Password, Bitwarden, and any other RFC 6238
 * client.
 *
 * Defaults match the RFC and what authenticator apps assume:
 *  - SHA-1 HMAC
 *  - 30-second period
 *  - 6-digit code
 *  - ±1 step tolerance for clock drift (so a code valid 30s before or
 *    30s after also passes)
 *
 * All helpers in this file are pure (no DB, no session, no clock unless
 * the caller passes one) so they're unit-tested in isolation in
 * tests/TotpTest.php.
 */

if (!function_exists('totp_base32_encode')) {
    /**
     * RFC 4648 base32 (the variant authenticator apps expect — uppercase
     * A-Z + 2-7, padded with '=' to multiples of 8 chars).
     */
    function totp_base32_encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        if ($bytes === '') {
            return '';
        }
        $bits = '';
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0, $n = strlen($bits); $i < $n; $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }
        // Pad to a multiple of 8 characters.
        $pad = 8 - (strlen($out) % 8);
        if ($pad !== 8) {
            $out .= str_repeat('=', $pad);
        }
        return $out;
    }
}

if (!function_exists('totp_base32_decode')) {
    /**
     * Inverse of totp_base32_encode(). Tolerates lowercase + missing
     * padding (authenticator apps print user-visible secrets without
     * padding and a copy-paste from a user might be lowercase).
     * Returns null on malformed input.
     */
    function totp_base32_decode(string $encoded): ?string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        if ($encoded === '') {
            return '';
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0, $n = strlen($encoded); $i < $n; $i++) {
            $idx = strpos($alphabet, $encoded[$i]);
            if ($idx === false) {
                return null;
            }
            $bits .= str_pad(decbin($idx), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}

if (!function_exists('totp_generate_secret')) {
    /**
     * 20 random bytes → 32 base32 chars. The RFC suggests at least
     * 128 bits; we go 160 to match the SHA-1 block size.
     */
    function totp_generate_secret(): string
    {
        return totp_base32_encode(random_bytes(20));
    }
}

if (!function_exists('totp_code_for_time')) {
    /**
     * Compute the 6-digit code for a given base32 secret at a given UTC
     * unix timestamp. Returns null on bad secret.
     */
    function totp_code_for_time(string $secret_base32, int $unixTime, int $period = 30, int $digits = 6): ?string
    {
        $key = totp_base32_decode($secret_base32);
        if ($key === null || $key === '') {
            return null;
        }
        $counter = intdiv($unixTime, max(1, $period));
        // RFC 4226 wants the counter as an 8-byte big-endian integer.
        $packed = pack('N*', 0, $counter);
        $hmac = hash_hmac('sha1', $packed, $key, true);
        $offset = ord($hmac[19]) & 0x0F;
        $binCode = (
            ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF)
        );
        $modulus = (int) (10 ** $digits);
        return str_pad((string) ($binCode % $modulus), $digits, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('totp_verify_code')) {
    /**
     * Verify a user-supplied code at the given UTC unix timestamp with
     * ±$tolerance steps of clock drift. Constant-time comparison.
     */
    function totp_verify_code(
        string $secret_base32,
        string $code,
        int $unixTime,
        int $period = 30,
        int $digits = 6,
        int $tolerance = 1
    ): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . $digits . '}$/', (string) $code)) {
            return false;
        }
        for ($drift = -$tolerance; $drift <= $tolerance; $drift++) {
            $expected = totp_code_for_time(
                $secret_base32,
                $unixTime + ($drift * $period),
                $period,
                $digits
            );
            if ($expected !== null && hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('totp_ensure_schema')) {
    /**
     * Idempotently add the TOTP columns to tb_main on existing installs.
     * Cheap: information_schema lookup once per session_manager boot,
     * ALTER TABLE only on first run.
     */
    function totp_ensure_schema(\mysqli $conn): void
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_main' AND COLUMN_NAME = 'totp_secret'"
        );
        if ($stmt === false) {
            return;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row && (int) $row[0] > 0) {
            return;
        }
        // First-run migration. ALTER TABLE is DDL — auto-commits, can't be
        // rolled back, but for these two nullable columns the failure mode
        // is benign (the feature silently no-ops if columns aren't there).
        @$conn->query("ALTER TABLE tb_main ADD COLUMN totp_secret varchar(64) DEFAULT NULL");
        @$conn->query("ALTER TABLE tb_main ADD COLUMN totp_enabled tinyint(1) NOT NULL DEFAULT 0");
    }
}

if (!function_exists('totp_provisioning_uri')) {
    /**
     * Build the otpauth:// URI an authenticator app can consume. The
     * issuer + account label end up in the app's name field next to the
     * code so the operator can tell which account a TOTP entry belongs
     * to (the panel they're protecting, not just "TAPhish").
     */
    function totp_provisioning_uri(string $secret_base32, string $accountLabel, string $issuer): string
    {
        $label = trim($issuer . ':' . $accountLabel, ':');
        $params = http_build_query([
            'secret'    => $secret_base32,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => 6,
            'period'    => 30,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . rawurlencode($label) . '?' . $params;
    }
}

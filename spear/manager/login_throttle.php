<?php
/**
 * Per-IP login throttle.
 *
 * Defeats password-spraying / dictionary attacks on the operator panel
 * without needing a new database table — state is one small JSON file
 * per IP under spear/uploads/login_attempts/ (idempotent dir create,
 * gitignored).
 *
 * Defaults (chosen to be "annoying for an attacker, invisible for a
 * real operator who misspelled their password once"):
 *   - LOGIN_THROTTLE_WINDOW          300s   (5-minute rolling window)
 *   - LOGIN_THROTTLE_MAX_ATTEMPTS    5
 *   - LOGIN_THROTTLE_LOCKOUT         900s   (15-minute lockout)
 *
 * Pure helpers live at the top of this file (tested in isolation in
 * tests/LoginThrottleTest.php); the file-IO wrappers live below.
 */

if (!defined('LOGIN_THROTTLE_WINDOW')) {
    define('LOGIN_THROTTLE_WINDOW', 300);
}
if (!defined('LOGIN_THROTTLE_MAX_ATTEMPTS')) {
    define('LOGIN_THROTTLE_MAX_ATTEMPTS', 5);
}
if (!defined('LOGIN_THROTTLE_LOCKOUT')) {
    define('LOGIN_THROTTLE_LOCKOUT', 900);
}
if (!defined('LOGIN_THROTTLE_DIR')) {
    define('LOGIN_THROTTLE_DIR', dirname(__FILE__, 2) . '/uploads/login_attempts');
}

if (!function_exists('login_throttle_prune_attempts')) {
    /**
     * Pure: drop attempts older than $now - $windowSeconds. Returns the
     * surviving array, sorted oldest-first.
     *
     * @param int[] $attempts unix-second timestamps
     * @return int[]
     */
    function login_throttle_prune_attempts(array $attempts, int $now, int $windowSeconds): array
    {
        $cutoff = $now - $windowSeconds;
        $surviving = [];
        foreach ($attempts as $a) {
            if (!is_int($a) && !is_string($a)) {
                continue;
            }
            $ts = (int) $a;
            if ($ts >= $cutoff) {
                $surviving[] = $ts;
            }
        }
        sort($surviving, SORT_NUMERIC);
        return $surviving;
    }
}

if (!function_exists('login_throttle_should_block')) {
    /**
     * Pure: given the current state for an IP, decide whether the next
     * login attempt should be blocked.
     *
     * @param array{attempts?: int[], lockout_until?: int} $state
     * @return array{blocked: bool, reason?: string, retry_after?: int}
     */
    function login_throttle_should_block(
        array $state,
        int $now,
        int $maxAttempts = LOGIN_THROTTLE_MAX_ATTEMPTS,
        int $windowSeconds = LOGIN_THROTTLE_WINDOW
    ): array {
        $lockoutUntil = isset($state['lockout_until']) ? (int) $state['lockout_until'] : 0;
        if ($lockoutUntil > $now) {
            return [
                'blocked'     => true,
                'reason'      => 'locked_out',
                'retry_after' => $lockoutUntil - $now,
            ];
        }
        $recent = login_throttle_prune_attempts(
            $state['attempts'] ?? [],
            $now,
            $windowSeconds
        );
        if (count($recent) >= $maxAttempts) {
            return [
                'blocked'     => true,
                'reason'      => 'too_many_attempts',
                'retry_after' => $windowSeconds,
            ];
        }
        return ['blocked' => false];
    }
}

if (!function_exists('login_throttle_record_failure')) {
    /**
     * Pure: append a failure timestamp to the state, prune the window,
     * and flip lockout on if we just crossed the threshold.
     *
     * @param array{attempts?: int[], lockout_until?: int} $state
     * @return array{attempts: int[], lockout_until: int}
     */
    function login_throttle_record_failure(
        array $state,
        int $now,
        int $maxAttempts = LOGIN_THROTTLE_MAX_ATTEMPTS,
        int $windowSeconds = LOGIN_THROTTLE_WINDOW,
        int $lockoutSeconds = LOGIN_THROTTLE_LOCKOUT
    ): array {
        $attempts = login_throttle_prune_attempts($state['attempts'] ?? [], $now, $windowSeconds);
        $attempts[] = $now;
        sort($attempts, SORT_NUMERIC);
        $lockoutUntil = isset($state['lockout_until']) ? (int) $state['lockout_until'] : 0;
        if (count($attempts) >= $maxAttempts) {
            $lockoutUntil = max($lockoutUntil, $now + $lockoutSeconds);
        }
        return ['attempts' => $attempts, 'lockout_until' => $lockoutUntil];
    }
}

if (!function_exists('login_throttle_clear')) {
    /**
     * Pure: clear all state after a successful login.
     *
     * @return array{attempts: int[], lockout_until: int}
     */
    function login_throttle_clear(): array
    {
        return ['attempts' => [], 'lockout_until' => 0];
    }
}

// ---- File-IO wrappers ------------------------------------------------

if (!function_exists('login_throttle_client_ip')) {
    /**
     * Best-effort client IP. Prefers X-Forwarded-For (first hop) when the
     * panel sits behind a reverse proxy, falls back to REMOTE_ADDR.
     * Validates that the result actually parses as an IP — won't accept
     * an attacker-supplied "1.2.3.4 (rm -rf /)" string.
     */
    function login_throttle_client_ip(?array $server = null): string
    {
        if ($server === null) {
            $server = $_SERVER ?? [];
        }
        $candidates = [];
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $candidates[] = trim(explode(',', $server['HTTP_X_FORWARDED_FOR'])[0]);
        }
        if (!empty($server['REMOTE_ADDR'])) {
            $candidates[] = (string) $server['REMOTE_ADDR'];
        }
        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return 'unknown';
    }
}

if (!function_exists('login_throttle_state_path')) {
    function login_throttle_state_path(string $ip): string
    {
        $key = hash('sha256', $ip);
        return LOGIN_THROTTLE_DIR . '/' . $key . '.json';
    }
}

if (!function_exists('login_throttle_read_state')) {
    /**
     * @return array{attempts: int[], lockout_until: int}
     */
    function login_throttle_read_state(string $ip): array
    {
        $path = login_throttle_state_path($ip);
        if (!is_file($path)) {
            return ['attempts' => [], 'lockout_until' => 0];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['attempts' => [], 'lockout_until' => 0];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['attempts' => [], 'lockout_until' => 0];
        }
        return [
            'attempts'      => is_array($decoded['attempts'] ?? null) ? $decoded['attempts'] : [],
            'lockout_until' => (int) ($decoded['lockout_until'] ?? 0),
        ];
    }
}

if (!function_exists('login_throttle_write_state')) {
    function login_throttle_write_state(string $ip, array $state): void
    {
        if (!is_dir(LOGIN_THROTTLE_DIR)) {
            @mkdir(LOGIN_THROTTLE_DIR, 0775, true);
        }
        @file_put_contents(
            login_throttle_state_path($ip),
            json_encode($state),
            LOCK_EX
        );
    }
}

if (!function_exists('login_throttle_check')) {
    /**
     * High-level: should this incoming login request be blocked? Returns
     * the same shape as login_throttle_should_block().
     */
    function login_throttle_check(?string $ip = null, ?int $now = null): array
    {
        $ip = $ip ?? login_throttle_client_ip();
        $now = $now ?? time();
        return login_throttle_should_block(login_throttle_read_state($ip), $now);
    }
}

if (!function_exists('login_throttle_register_failure')) {
    function login_throttle_register_failure(?string $ip = null, ?int $now = null): void
    {
        $ip = $ip ?? login_throttle_client_ip();
        $now = $now ?? time();
        $state = login_throttle_read_state($ip);
        $next = login_throttle_record_failure($state, $now);
        login_throttle_write_state($ip, $next);
    }
}

if (!function_exists('login_throttle_register_success')) {
    function login_throttle_register_success(?string $ip = null): void
    {
        $ip = $ip ?? login_throttle_client_ip();
        login_throttle_write_state($ip, login_throttle_clear());
    }
}

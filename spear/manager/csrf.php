<?php
/**
 * CSRF token issuance and verification.
 *
 * Tokens are 64-character hex strings (32 random bytes). The current token
 * lives in $_SESSION['_csrf'] for the duration of the session and is rotated
 * on login by session_manager::createSession().
 *
 * Verification accepts the token from one of three places, in order:
 *   1. HTTP header `X-CSRF-Token`
 *   2. JSON body `_csrf` field (php://input)
 *   3. application/x-www-form-urlencoded `_csrf` field ($_POST)
 *
 * Public unauthenticated endpoints (track.php, qt.php, mod.php) must not
 * require CSRF — the target's browser has no session and no token.
 */

if (!function_exists('_csrf_make_token')) {
    /**
     * Generate a new 64-char hex token from 32 random bytes. Pure.
     */
    function _csrf_make_token(): string
    {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('_csrf_compare')) {
    /**
     * Constant-time comparison of two tokens, with length and shape guards.
     * Pure.
     */
    function _csrf_compare(?string $expected, ?string $provided): bool
    {
        if (!is_string($expected) || !is_string($provided)) {
            return false;
        }
        if (strlen($expected) !== 64 || strlen($provided) !== 64) {
            return false;
        }
        if (!ctype_xdigit($expected) || !ctype_xdigit($provided)) {
            return false;
        }
        return hash_equals($expected, $provided);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Return the current session's CSRF token, generating one if missing.
     * Mutates $_SESSION (or the optional $store override, used by tests).
     *
     * @param array<string,mixed>|null $store
     */
    function csrf_token(?array &$store = null): string
    {
        if ($store === null) {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            if (empty($_SESSION['_csrf'])) {
                $_SESSION['_csrf'] = _csrf_make_token();
            }
            return $_SESSION['_csrf'];
        }
        if (empty($store['_csrf'])) {
            $store['_csrf'] = _csrf_make_token();
        }
        return $store['_csrf'];
    }
}

if (!function_exists('csrf_rotate')) {
    /**
     * Replace the session token with a fresh one. Call on login.
     *
     * @param array<string,mixed>|null $store
     */
    function csrf_rotate(?array &$store = null): string
    {
        if ($store === null) {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            $_SESSION['_csrf'] = _csrf_make_token();
            return $_SESSION['_csrf'];
        }
        $store['_csrf'] = _csrf_make_token();
        return $store['_csrf'];
    }
}

if (!function_exists('csrf_extract_from_request')) {
    /**
     * Pull the request-supplied CSRF token out of the request.
     *
     * Test-callable: pass explicit arrays for headers/body/post; in production
     * defaults are pulled from $_SERVER, php://input, $_POST.
     */
    function csrf_extract_from_request(
        ?array $headers = null,
        ?string $rawBody = null,
        ?array $post = null
    ): ?string {
        if ($headers === null) {
            $headers = [];
            if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $headers['X-CSRF-Token'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
            }
        }
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-CSRF-Token') === 0 && is_string($v) && $v !== '') {
                return $v;
            }
        }
        if ($rawBody === null) {
            $rawBody = (string) file_get_contents('php://input');
        }
        if ($rawBody !== '') {
            $json = json_decode($rawBody, true);
            if (is_array($json) && isset($json['_csrf']) && is_string($json['_csrf']) && $json['_csrf'] !== '') {
                return $json['_csrf'];
            }
        }
        if ($post === null) {
            $post = $_POST ?? [];
        }
        if (isset($post['_csrf']) && is_string($post['_csrf']) && $post['_csrf'] !== '') {
            return $post['_csrf'];
        }
        return null;
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * Verify a supplied token against the session token. Pure-by-injection:
     * tests pass explicit $store and $supplied; production callers use
     * csrf_require() below.
     */
    function csrf_verify(?string $supplied, ?array $store = null): bool
    {
        $expected = null;
        if ($store === null) {
            $expected = $_SESSION['_csrf'] ?? null;
        } else {
            $expected = $store['_csrf'] ?? null;
        }
        return _csrf_compare($expected, $supplied);
    }
}

if (!function_exists('csrf_require')) {
    /**
     * Stop the request with HTTP 403 if the request lacks a valid token.
     * Call after the session check at the top of every state-changing
     * dispatcher.
     */
    function csrf_require(): void
    {
        $supplied = csrf_extract_from_request();
        if (!csrf_verify($supplied)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['result' => 'failed', 'error' => 'CSRF token missing or invalid']);
            exit;
        }
    }
}

if (!function_exists('csrf_emit_script_tag')) {
    /**
     * Emit a <script> tag that exposes the current CSRF token to client-side
     * JS as window.TAPHISH_CSRF. Include from z_menu.php so every
     * authenticated page sees it.
     */
    function csrf_emit_script_tag(): void
    {
        $token = csrf_token();
        $safe = htmlspecialchars($token, ENT_QUOTES | ENT_HTML5);
        echo '<script>window.TAPHISH_CSRF = "' . $safe . '";</script>';
    }
}

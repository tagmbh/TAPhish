<?php
/**
 * Lightweight health endpoint for uptime monitors.
 *
 * Returns 200 with a minimal JSON body if the app can talk to its
 * database, 503 otherwise. Intentionally does NOT report internal
 * state (cron PID, DB host, schema version, operator count, …) —
 * uptime monitors don't need it and exposing it would leak
 * fingerprintable internals to anyone who can reach this endpoint.
 *
 * Mount as /health (the .htaccess rewrite at repo root already maps
 * /<name> → /<name>.php).
 *
 * Pings:
 *   GET /health   →  200 application/json  {"status":"ok","time":"..."}
 *                or 503 application/json  {"status":"error","time":"..."}
 *
 * No session, no CSRF, no auth — by design.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

date_default_timezone_set('UTC');
$now = gmdate('c');

$db_ok = false;
if (file_exists(__DIR__ . '/spear/config/db.php')) {
    // db.php sets up $conn. Defensive: a fatal in there shouldn't 500 the
    // endpoint — the monitor would get a confusing HTML response. Suppress
    // errors at the include level; verify ping() below.
    @include __DIR__ . '/spear/config/db.php';
    if (isset($conn) && $conn instanceof mysqli) {
        $db_ok = @$conn->ping();
    }
}

if ($db_ok) {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'time' => $now]);
} else {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'time' => $now]);
}

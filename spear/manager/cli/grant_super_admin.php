<?php
/**
 * Phase 3.48 RBAC recovery hatch — CLI ONLY.
 *
 * Promotes a username to super-admin even if RBAC has locked every operator
 * out of the UI. This is the "fixable in 30 seconds" escape hatch the plan
 * requires for a bad rollout.
 *
 *   php spear/manager/cli/grant_super_admin.php <username>
 *
 * Refuses to run over the web (php_sapi_name() guard) so it can't be reached
 * as an unauthenticated privilege-escalation endpoint.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: this script is CLI-only.\n");
}

$username = isset($argv[1]) ? trim((string) $argv[1]) : '';
if ($username === '') {
    fwrite(STDERR, "Usage: php grant_super_admin.php <username>\n");
    exit(2);
}

$dbFile = dirname(__FILE__, 3) . '/config/db.php';   // spear/config/db.php
if (!is_file($dbFile)) {
    fwrite(STDERR, "Cannot find config/db.php — run from the TAPhish install.\n");
    exit(1);
}
require_once($dbFile);
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

// Ensure the role column exists, in case this runs before the first boot
// migration (e.g. on a fresh upgrade that hasn't served a page yet).
require_once(dirname(__FILE__, 2) . '/authz.php');   // spear/manager/authz.php
if (function_exists('taphish_authz_ensure_role_column')) {
    taphish_authz_ensure_role_column($conn);
}

$stmt = $conn->prepare("UPDATE tb_main SET role = 'super-admin' WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$changed = $stmt->affected_rows;
$stmt->close();

if ($changed > 0) {
    echo "OK: '{$username}' is now super-admin.\n";
    exit(0);
}

// affected_rows == 0 can mean "already super-admin" or "no such user".
$chk = $conn->prepare("SELECT role FROM tb_main WHERE username = ?");
$chk->bind_param('s', $username);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if ($row) {
    echo "OK: '{$username}' already has role '{$row['role']}'.\n";
    exit(0);
}

fwrite(STDERR, "No such user: '{$username}'.\n");
exit(1);

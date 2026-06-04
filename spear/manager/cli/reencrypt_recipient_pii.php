<?php
/**
 * Phase 3.49 — Recipient PII re-encrypt sweep (CLI driver).
 *
 * Forces every tb_core_mailcamp_user_group.user_data row to be sealed at rest.
 * Idempotent: already-sealed rows are skipped. Run over SSH from the install root:
 *
 *   php spear/manager/cli/reencrypt_recipient_pii.php [--dry-run] [--verbose]
 *
 * Exit codes: 0 ok · 1 key/DB error · 2 bad usage · 3 sweep had errors/write failures
 *
 * Logic lives in spear/manager/recipient_reencrypt.php (unit-tested); this file
 * only wires the real crypto envelope + a mysqli row stream into it.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: this script is CLI-only.\n");
}

// ---- argv ----
$dryRun  = false;
$verbose = false;
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--dry-run':
            $dryRun = true;
            break;
        case '--verbose':
            $verbose = true;
            break;
        default:
            fwrite(STDERR, "Usage: php reencrypt_recipient_pii.php [--dry-run] [--verbose]\n");
            exit(2);
    }
}

// ---- bootstrap ----
$dbFile = dirname(__FILE__, 3) . '/config/db.php';
if (!is_file($dbFile)) {
    fwrite(STDERR, "Cannot find config/db.php — run from the TAPhish install.\n");
    exit(1);
}
require_once($dbFile);
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}
require_once(dirname(__FILE__, 2) . '/secret_at_rest.php');
require_once(dirname(__FILE__, 2) . '/recipient_reencrypt.php');
@require_once(dirname(__FILE__, 2) . '/common_functions.php'); // best-effort, for logIt()

// ---- hard key gate ----
// recipient_data_seal() silently passes plaintext through when the key is
// unavailable, so a keyless run would "succeed" while sealing nothing.
if (secret_at_rest_get_key() === null) {
    fwrite(STDERR, "At-rest key unavailable at spear/config/secret.key — refusing to run so we never store plaintext.\n");
    exit(1);
}

// ---- crypto seams ----
$crypto = [
    'seal'   => 'recipient_data_seal',
    'unseal' => 'recipient_data_unseal',
    'isEnc'  => 'secret_at_rest_is_encrypted',
];

// ---- row stream (one row at a time; never loads all PII into memory) ----
$rows = (static function () use ($conn) {
    $res = $conn->query("SELECT user_group_id, user_data FROM tb_core_mailcamp_user_group");
    if (!($res instanceof mysqli_result)) {
        return;
    }
    while ($row = $res->fetch_assoc()) {
        yield $row;
    }
    $res->free();
})();

// ---- apply closure (reused prepared UPDATE; engagement_id untouched) ----
$upd = $conn->prepare("UPDATE tb_core_mailcamp_user_group SET user_data=? WHERE user_group_id=?");
if ($upd === false) {
    fwrite(STDERR, "Cannot prepare UPDATE: " . $conn->error . "\n");
    exit(1);
}
$applyUpdate = static function ($id, string $sealed) use ($upd): bool {
    $sid = (string) $id;
    $upd->bind_param('ss', $sealed, $sid);
    return $upd->execute();
};

// ---- run + report ----
$counts = taphish_reencrypt_run($rows, $applyUpdate, $crypto, $dryRun);
$upd->close();

echo taphish_reencrypt_format_summary($counts, $dryRun);

if ($verbose && !empty($counts['sealed_ids'])) {
    echo "sealed group_ids: " . implode(', ', array_map('strval', $counts['sealed_ids'])) . "\n";
}

// ---- audit (best-effort; 'cli' username avoids $_SESSION) ----
if (function_exists('logIt')) {
    $msg = sprintf(
        'recipient PII re-encrypt sweep: sealed %d of %d rows%s; suspect=%d errors=%d write_failures=%d',
        (int) $counts['sealed'],
        (int) $counts['scanned'],
        $dryRun ? ' (dry-run)' : '',
        (int) $counts['suspect'],
        (int) $counts['errors'],
        (int) $counts['write_failures']
    );
    logIt($msg, 'cli');
}

exit(((int) $counts['errors'] > 0 || (int) $counts['write_failures'] > 0) ? 3 : 0);

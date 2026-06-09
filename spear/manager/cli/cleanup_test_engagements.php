<?php
/**
 * CLI: wipe test engagement data (cascades to campaigns, recipient lists,
 * send log, captures, clone-meta, cloned/<slug>/ dirs).
 *
 * KEEPS: tb_main (users), tb_store (secrets), tb_core_pretext_library,
 * tb_core_mail_template, tb_core_mailcamp_sender_list, tb_log, trackers.
 *
 * Usage:
 *   php spear/manager/cli/cleanup_test_engagements.php --dry-run --ids=1,2,3
 *   php spear/manager/cli/cleanup_test_engagements.php --dry-run --status=draft
 *   php spear/manager/cli/cleanup_test_engagements.php --apply  --ids=1,2,3
 *   php spear/manager/cli/cleanup_test_engagements.php --apply  --all-engagements --i-mean-it
 *
 * Refuses to run over the web. Refuses --apply without an explicit selector.
 * Refuses --all-engagements unless --i-mean-it is also passed.
 *
 * Exit codes: 0 ok, 1 fatal (db/env), 2 usage, 3 db delete error.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: this script is CLI-only.\n");
}

$opts = getopt('', [
    'dry-run', 'apply', 'i-mean-it', 'all-engagements',
    'ids::', 'status::',
    'cloned-root::',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 1500));
    exit(0);
}

$dryRun = array_key_exists('dry-run', $opts);
$apply  = array_key_exists('apply', $opts);
if ($dryRun === $apply) {
    fwrite(STDERR, "Pick exactly one mode: --dry-run OR --apply\n");
    exit(2);
}

$selector = [];
if (!empty($opts['ids'])) {
    $selector['ids'] = array_filter(array_map('intval', explode(',', (string) $opts['ids'])));
}
if (!empty($opts['status'])) {
    $selector['status'] = (string) $opts['status'];
}
if (array_key_exists('all-engagements', $opts)) {
    $selector['all'] = true;
}
if (!$selector) {
    fwrite(STDERR, "Pick a selector: --ids=1,2,3 OR --status=draft OR --all-engagements\n");
    exit(2);
}
if (!empty($selector['all']) && $apply && !array_key_exists('i-mean-it', $opts)) {
    fwrite(STDERR, "--apply --all-engagements requires --i-mean-it (nuclear option).\n");
    exit(2);
}

// Bootstrap
$root = dirname(__FILE__, 3);          // spear/manager/cli -> repo
$dbFile = $root . '/spear/config/db.php';
if (!is_file($dbFile)) {
    fwrite(STDERR, "Cannot find spear/config/db.php — run from the TAPhish install.\n");
    exit(1);
}
require_once($dbFile);
if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}
require_once($root . '/spear/manager/engagement_cleanup.php');

$clonedRoot = !empty($opts['cloned-root'])
    ? (string) $opts['cloned-root']
    : $root . '/spear/sniperhost/cloned';

$ids = taphish_cleanup_resolve_engagement_ids($conn, $selector);
$plan = taphish_cleanup_plan($conn, $ids);

fwrite(STDOUT, "TAPhish cleanup — " . ($dryRun ? "DRY-RUN" : "APPLY") . "\n");
fwrite(STDOUT, str_repeat('-', 60) . "\n");
fwrite(STDOUT, "Engagements selected: " . count($ids) . " (" . implode(',', $ids) . ")\n");
fwrite(STDOUT, "Campaigns referenced: " . count($plan['campaign_ids']) . "\n");
fwrite(STDOUT, "Cloned site dirs:     " . count($plan['clone_slugs']) . "\n");
fwrite(STDOUT, "Row counts per table:\n");
foreach ($plan['counts'] as $tbl => $n) {
    $display = $n === -1 ? '(table absent)' : (string) $n;
    fwrite(STDOUT, sprintf("  %-32s %s\n", $tbl, $display));
}

if ($dryRun) {
    fwrite(STDOUT, "\nDRY-RUN — no DB writes, no rm. Re-run with --apply to commit.\n");
    exit(0);
}

if (!$ids) {
    fwrite(STDERR, "No engagements matched the selector — nothing to do.\n");
    exit(0);
}

$result = taphish_cleanup_execute($conn, $ids, $clonedRoot);
if (!$result['applied']) {
    fwrite(STDERR, "\nFAILED:\n");
    foreach (($result['errors'] ?? []) as $e) { fwrite(STDERR, "  - $e\n"); }
    exit(3);
}
fwrite(STDOUT, "\nAPPLIED.\n");
fwrite(STDOUT, "Removed dirs: " . count($result['removed_dirs'] ?? []) . "\n");
foreach (($result['removed_dirs'] ?? []) as $d) { fwrite(STDOUT, "  rm -rf $d\n"); }
if (!empty($result['skipped_dirs'])) {
    fwrite(STDOUT, "Skipped dirs:\n");
    foreach ($result['skipped_dirs'] as $d) { fwrite(STDOUT, "  $d\n"); }
}

// Best-effort audit line.
if (function_exists('logIt')) {
    @logIt('cli', 'ENGAGEMENT_CLEANUP', sprintf(
        'wiped engagement_ids=[%s] campaigns=%d dirs=%d',
        implode(',', $ids),
        count($result['campaign_ids']),
        count($result['removed_dirs'] ?? [])
    ));
}

exit(0);

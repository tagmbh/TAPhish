<?php
/**
 * Phase 3.50c — Configure the off-host backup push destination (CLI).
 *
 *   WebDAV:  php backup_push_config.php --type=webdav --url=URL --user=U --pass=P
 *   S3:      php backup_push_config.php --type=s3 --bucket=B --region=R \
 *                  --access-key=K --secret-key=S [--endpoint=E] [--path-style]
 *   Show:    php backup_push_config.php --show     (secrets masked)
 *   Clear:   php backup_push_config.php --clear
 *
 * Config is stored encrypted at rest in tb_store. Used by `backup_run.php --push`.
 * Exit: 0 ok · 1 key/DB error · 2 bad usage/validation
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: this script is CLI-only.\n");
}

$usage = "Usage:\n"
    . "  php backup_push_config.php --type=webdav --url=URL --user=U --pass=P\n"
    . "  php backup_push_config.php --type=s3 --bucket=B --region=R --access-key=K --secret-key=S [--endpoint=E] [--path-style]\n"
    . "  php backup_push_config.php --show | --clear\n";

// ---- argv ----
$opt = ['path_style' => false];
$mode = 'set';
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--show') {
        $mode = 'show';
    } elseif ($a === '--clear') {
        $mode = 'clear';
    } elseif ($a === '--path-style') {
        $opt['path_style'] = true;
    } elseif (str_starts_with($a, '--type=')) {
        $opt['type'] = substr($a, 7);
    } elseif (str_starts_with($a, '--url=')) {
        $opt['url'] = substr($a, 6);
    } elseif (str_starts_with($a, '--user=')) {
        $opt['user'] = substr($a, 7);
    } elseif (str_starts_with($a, '--pass=')) {
        $opt['pass'] = substr($a, 7);
    } elseif (str_starts_with($a, '--bucket=')) {
        $opt['bucket'] = substr($a, 9);
    } elseif (str_starts_with($a, '--region=')) {
        $opt['region'] = substr($a, 9);
    } elseif (str_starts_with($a, '--access-key=')) {
        $opt['access_key'] = substr($a, 13);
    } elseif (str_starts_with($a, '--secret-key=')) {
        $opt['secret_key'] = substr($a, 13);
    } elseif (str_starts_with($a, '--endpoint=')) {
        $opt['endpoint'] = substr($a, 11);
    } else {
        fwrite(STDERR, $usage);
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
require_once(dirname(__FILE__, 2) . '/backup_push.php');

if ($mode === 'show') {
    $cfg = taphish_push_get_config($conn);
    if ($cfg === null) {
        echo "No off-host push destination configured.\n";
        exit(0);
    }
    echo "Off-host push destination:\n";
    foreach (taphish_push_config_mask($cfg) as $k => $v) {
        echo "  {$k}: " . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
    }
    exit(0);
}

if ($mode === 'clear') {
    taphish_push_clear_config($conn);
    echo "Off-host push destination cleared.\n";
    exit(0);
}

// ---- set: validate + seal + store ----
$res = taphish_push_config_validate($opt);
if (!$res['ok']) {
    fwrite(STDERR, "Invalid config: " . implode('; ', $res['errors']) . "\n\n" . $usage);
    exit(2);
}
// normalize: drop path_style when false / irrelevant
$cfg = $res['cfg'];
if (empty($cfg['path_style'])) {
    unset($cfg['path_style']);
}

if (secret_at_rest_get_key() === null) {
    fwrite(STDERR, "At-rest key unavailable — refusing to store a destination secret in plaintext.\n");
    exit(1);
}
if (!taphish_push_set_config($conn, $cfg)) {
    fwrite(STDERR, "Failed to store config.\n");
    exit(1);
}
echo "Off-host push destination saved ({$cfg['type']}).\n";
@require_once(dirname(__FILE__, 2) . '/common_functions.php'); // best-effort, for logIt()
if (function_exists('logIt')) {
    logIt("off-host backup push destination configured ({$cfg['type']})", 'cli');
}
exit(0);

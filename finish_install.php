<?php
/**
 * One-shot install finisher — TEMPORARY. DELETE after a successful run.
 *
 * Use when the web installer's AJAX spinner won't complete (e.g. a fatal mid
 * do_install). It reuses the installer's own createTables() +
 * modifySniperPhishSettings() and reads the already-written
 * spear/config/db.php for the DB connection — so NO credentials live in this
 * file. Open it once in a browser with ?go=1, then delete it.
 *
 * It drops any partial tables first, so only run it on a fresh / half-installed
 * database (which is exactly the stuck-installer case).
 */

chdir(__DIR__);

// install_manager.php defines createTables()/modifySniperPhishSettings() and
// does nothing on its own without a JSON POST body.
require __DIR__ . '/install_manager.php';

// Re-assert plain-text output (install_manager.php set application/json).
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['go'] ?? '') !== '1') {
    echo "Install finisher ready.\n";
    echo "Open this URL with ?go=1 to create the tables, e.g.\n";
    echo "  https://" . ($_SERVER['HTTP_HOST'] ?? 'your-host') . "/finish_install.php?go=1\n";
    exit;
}

$dbphp = __DIR__ . '/spear/config/db.php';
if (!is_file($dbphp)) {
    http_response_code(500);
    exit("spear/config/db.php not found. Run /install once (it writes db.php), then retry this.\n");
}

require $dbphp; // provides $conn + $curr_db from the saved credentials
if (!isset($conn) || mysqli_connect_errno()) {
    http_response_code(500);
    exit("DB connection via db.php failed.\n");
}

// Refuse to wipe an already-populated install.
$check = @mysqli_query($conn, "SHOW TABLES LIKE 'tb_main'");
if ($check && $check->num_rows > 0) {
    $cnt = @mysqli_query($conn, "SELECT COUNT(*) c FROM tb_main");
    $rows = ($cnt && ($r = $cnt->fetch_assoc())) ? (int) $r['c'] : 0;
    if ($rows > 0) {
        http_response_code(409);
        exit("Looks already installed (tb_main has $rows row(s)). Aborting to avoid data loss.\n"
           . "Delete this file and just log in at /spear/.\n");
    }
}

// Clean slate: drop any partial tables from a half-run install.
if ($res = mysqli_query($conn, 'SHOW TABLES')) {
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
        mysqli_query($conn, 'DROP TABLE IF EXISTS `' . $row[0] . '`');
    }
}

$mail = 'i.stricker@t-alpha.ch';
$tz   = json_encode(['timezone' => 'UTC']); // {"timezone":"UTC"} — what the app expects

try {
    if (createTables($conn) && modifySniperPhishSettings($conn, $tz, $mail)) {
        echo "INSTALL OK — tables created.\n\n";
        echo "Next:\n";
        echo "  1. Open  https://" . ($_SERVER['HTTP_HOST'] ?? 'your-host') . "/spear/  and log in (admin / sniperphish), then change the password + enable 2FA.\n";
        echo "  2. DELETE these files from the host:  finish_install.php, dbtest.php, install.php, install_manager.php\n";
    } else {
        http_response_code(500);
        echo "createTables()/modifySniperPhishSettings() returned false.\n";
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}

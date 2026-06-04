<?php
/**
 * Phase 3.50 — Restore an encrypted DB backup (CLI driver).
 *
 *   php spear/manager/cli/backup_restore.php --in=FILE [--out=FILE] [--apply --yes]
 *
 * Default: decrypt + gunzip the .tapbak to a .sql file (inspect/apply manually).
 * --apply --yes: execute the SQL against the live DB (DESTRUCTIVE — DROP/CREATE/INSERT).
 *
 * Exit: 0 ok · 1 key/DB/decrypt error · 2 bad usage
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: this script is CLI-only.\n");
}

// ---- argv ----
$inFile  = '';
$outFile = '';
$apply   = false;
$yes     = false;
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (str_starts_with($a, '--in=')) {
        $inFile = substr($a, 5);
    } elseif (str_starts_with($a, '--out=')) {
        $outFile = substr($a, 6);
    } elseif ($a === '--apply') {
        $apply = true;
    } elseif ($a === '--yes') {
        $yes = true;
    } else {
        fwrite(STDERR, "Usage: php backup_restore.php --in=FILE [--out=FILE] [--apply --yes]\n");
        exit(2);
    }
}
if ($inFile === '' || !is_file($inFile)) {
    fwrite(STDERR, "Missing or unreadable --in=FILE\n");
    exit(2);
}
if ($outFile === '') {
    $outFile = preg_replace('/\.tapbak$/', '', $inFile) . '.sql';
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
require_once(dirname(__FILE__, 2) . '/backup_archive.php');
require_once(dirname(__FILE__, 2) . '/backup_state.php');
@require_once(dirname(__FILE__, 2) . '/common_functions.php');

$key = secret_at_rest_get_key();
if ($key === null) {
    fwrite(STDERR, "At-rest key unavailable — cannot decrypt.\n");
    exit(1);
}

// ---- decrypt .tapbak -> temp inner payload (gzip or zip) ----
$innerTmp = tempnam(sys_get_temp_dir(), 'tapres_in_');
$in    = fopen($inFile, 'rb');
$inner = fopen($innerTmp, 'wb');
if ($in === false || $inner === false) {
    @unlink($innerTmp);
    fwrite(STDERR, "Open failed.\n");
    exit(1);
}
$read  = static fn (int $n): string => (string) fread($in, $n);
$write = static function (string $b) use ($inner): void { fwrite($inner, $b); };
$decFn = static fn (string $e): ?string => secret_at_rest_decrypt($e, $key);
$ok = taphish_backup_decrypt_stream($read, $write, $decFn);
fclose($in);
fclose($inner);
if (!$ok) {
    @unlink($innerTmp);
    fwrite(STDERR, "Decryption failed (wrong key, tampered, or not a .tapbak).\n");
    exit(1);
}

// ---- sniff inner format ----
$head = (string) file_get_contents($innerTmp, false, null, 0, 4);
$fmt  = taphish_backup_sniff_format($head);

// stream one zip entry to a target path, creating parent dirs.
$extractEntry = static function (\ZipArchive $zip, string $name, string $target): bool {
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $s = $zip->getStream($name);
    if ($s === false) {
        return false;
    }
    $oh = fopen($target, 'wb');
    if ($oh === false) {
        fclose($s);
        return false;
    }
    while (!feof($s)) {
        $buf = fread($s, 262144);
        if ($buf === false) {
            break;
        }
        fwrite($oh, $buf);
    }
    fclose($s);
    fclose($oh);
    return true;
};

if ($fmt === 'gzip') {
    // legacy DB-only backup
    $gz   = gzopen($innerTmp, 'rb');
    $sqlH = fopen($outFile, 'wb');
    if ($gz === false || $sqlH === false) {
        @unlink($innerTmp);
        fwrite(STDERR, "Decompress open failed.\n");
        exit(1);
    }
    while (!gzeof($gz)) {
        $buf = gzread($gz, 262144);
        if ($buf === false || $buf === '') {
            break;
        }
        fwrite($sqlH, $buf);
    }
    gzclose($gz);
    fclose($sqlH);
    @unlink($innerTmp);
    echo "Decrypted SQL written: {$outFile}\n";
} elseif ($fmt === 'zip') {
    if (!class_exists('ZipArchive')) {
        @unlink($innerTmp);
        fwrite(STDERR, "Need the PHP zip extension to restore a --with-state backup.\n");
        exit(1);
    }
    $zip = new ZipArchive();
    if ($zip->open($innerTmp) !== true) {
        @unlink($innerTmp);
        fwrite(STDERR, "Cannot open inner archive.\n");
        exit(1);
    }

    $spearRoot  = dirname(__FILE__, 2);
    $applyState = ($apply && $yes);
    $stagingDir = $outFile . '.state';
    $liveRoots  = [
        'state/cloned'      => $spearRoot . '/sniperhost/cloned',
        'state/attachments' => $spearRoot . '/uploads/attachments',
        'state/timages'     => $spearRoot . '/uploads/timages',
    ];
    $stageRoots = [
        'state/cloned'      => $stagingDir . '/cloned',
        'state/attachments' => $stagingDir . '/attachments',
        'state/timages'     => $stagingDir . '/timages',
    ];
    $roots = $applyState ? $liveRoots : $stageRoots;

    $stateCount = 0;
    $skipped    = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || substr($name, -1) === '/') {
            continue; // directory entry
        }
        if ($name === 'db.sql') {
            if (!$extractEntry($zip, $name, $outFile)) {
                $zip->close();
                @unlink($innerTmp);
                fwrite(STDERR, "Failed to extract db.sql.\n");
                exit(1);
            }
            continue;
        }
        if (strncmp($name, 'state/', 6) === 0) {
            $target = taphish_backup_state_restore_target($name, $roots);
            if ($target === null) {       // zip-slip / unknown prefix
                $skipped++;
                continue;
            }
            if ($extractEntry($zip, $name, $target)) {
                $stateCount++;
            } else {
                $skipped++;
            }
        }
    }
    $zip->close();
    @unlink($innerTmp);

    echo "Decrypted SQL written: {$outFile}\n";
    echo ($applyState
        ? "Restored {$stateCount} state files to live dirs"
        : "Extracted {$stateCount} state files to {$stagingDir}/")
        . ($skipped ? " ({$skipped} skipped)" : "") . "\n";
} else {
    @unlink($innerTmp);
    fwrite(STDERR, "Unrecognized inner format (not gzip or zip) — not a TAPhish backup?\n");
    exit(1);
}

// ---- optional apply ----
if ($apply) {
    if (!$yes) {
        fwrite(STDERR, "Refusing to apply without --yes (this DROPs/recreates tables). SQL is at: {$outFile}\n");
        exit(2);
    }
    $sql = (string) file_get_contents($outFile);
    if ($conn->multi_query($sql)) {
        do {
            if ($r = $conn->store_result()) {
                $r->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
    if ($conn->errno) {
        fwrite(STDERR, "Apply finished with errors: " . $conn->error . "\n");
        if (function_exists('logIt')) {
            logIt("DB restore APPLIED from " . basename($inFile) . " with errors: " . $conn->error, 'cli');
        }
        exit(1);
    }
    echo "Applied to database.\n";
    if (function_exists('logIt')) {
        logIt("DB restore APPLIED from " . basename($inFile), 'cli');
    }
}

exit(0);

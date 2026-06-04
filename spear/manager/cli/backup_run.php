<?php
/**
 * Phase 3.50 — Encrypted DB backup (CLI driver).
 *
 *   php spear/manager/cli/backup_run.php [--keep=N] [--dry-run] [--out=DIR]
 *
 * Writes spear/uploads/backups/taphish-backup-<UTC>.tapbak — a pure-PHP logical dump
 * (all tb_* tables) gzipped and AES-256-GCM chunked-encrypted with the at-rest key.
 * Keeps the newest N (default 7). Schedule daily via the Hostpoint Tasks panel.
 *
 * Exit: 0 ok · 1 key/DB/setup error · 2 bad usage · 3 dump/encrypt failure
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: this script is CLI-only.\n");
}

// ---- argv ----
$keep      = 7;
$dryRun    = false;
$withState = false;
$outDir    = dirname(__FILE__, 3) . '/uploads/backups';
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--dry-run') {
        $dryRun = true;
    } elseif ($a === '--with-state') {
        $withState = true;
    } elseif (str_starts_with($a, '--keep=')) {
        $keep = max(0, (int) substr($a, 7));
    } elseif (str_starts_with($a, '--out=')) {
        $outDir = substr($a, 6);
    } else {
        fwrite(STDERR, "Usage: php backup_run.php [--keep=N] [--with-state] [--dry-run] [--out=DIR]\n");
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
require_once(dirname(__FILE__, 2) . '/backup_helper.php');
require_once(dirname(__FILE__, 2) . '/backup_archive.php');
require_once(dirname(__FILE__, 2) . '/backup_state.php');
@require_once(dirname(__FILE__, 2) . '/common_functions.php'); // best-effort, for logIt()

// state dirs swept when --with-state is set (config/ + backups/ deliberately excluded)
$spearRoot  = dirname(__FILE__, 2);
$stateRoots = [
    'state/cloned'      => $spearRoot . '/sniperhost/cloned',
    'state/attachments' => $spearRoot . '/uploads/attachments',
    'state/timages'     => $spearRoot . '/uploads/timages',
];
$stateLister = static function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $base  = rtrim($dir, '/');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $files[] = substr($f->getPathname(), strlen($base) + 1);
        }
    }
    return $files;
};

// ---- key gate ----
$key = secret_at_rest_get_key();
if ($key === null) {
    fwrite(STDERR, "At-rest key unavailable at spear/config/secret.key — refusing to write an unencrypted backup.\n");
    exit(1);
}

// ---- ensure dir + deny-all .htaccess ----
if (!is_dir($outDir) && !@mkdir($outDir, 0700, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create backups dir: {$outDir}\n");
    exit(1);
}
$ht = rtrim($outDir, '/') . '/.htaccess';
if (!is_file($ht)) {
    @file_put_contents($ht,
        "# TAPhish backups — never web-accessible\n" .
        "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
        "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
    );
}

$stamp = gmdate('Ymd-His');

// ---- table list ----
$tables = [];
$res = $conn->query("SHOW TABLES");
if ($res instanceof mysqli_result) {
    while ($r = $res->fetch_row()) {
        $tables[] = $r[0];
    }
    $res->free();
}
if (!$tables) {
    fwrite(STDERR, "No tables found in database.\n");
    exit(1);
}

// ---- dry-run: report only ----
if ($dryRun) {
    echo "Backup (dry-run)\n  database: " . ($curr_db ?? '?') . "\n  tables:   " . count($tables) . "\n";
    foreach ($tables as $t) {
        $cnt = 0;
        $cr  = $conn->query("SELECT COUNT(*) FROM `" . $t . "`");
        if ($cr instanceof mysqli_result) {
            $cnt = (int) ($cr->fetch_row()[0] ?? 0);
            $cr->free();
        }
        echo "    - {$t}: {$cnt} rows\n";
    }
    if ($withState) {
        $sf = count(taphish_backup_state_manifest($stateRoots, $stateLister));
        echo "  with-state: yes ({$sf} state files from cloned/attachments/timages)\n";
    }
    echo "  would write: " . rtrim($outDir, '/') . '/' . taphish_backup_filename($stamp) . "\n  keep: {$keep}\n";
    exit(0);
}

// ---- temp files + cleanup ----
$sqlTmp = tempnam(sys_get_temp_dir(), 'tapbak_sql_');
$gzTmp  = tempnam(sys_get_temp_dir(), 'tapbak_gz_');
$zipTmp = tempnam(sys_get_temp_dir(), 'tapbak_zip_');
$encTmp = tempnam(sys_get_temp_dir(), 'tapbak_enc_');
$cleanup = static function () use ($sqlTmp, $gzTmp, $zipTmp, $encTmp): void {
    foreach ([$sqlTmp, $gzTmp, $zipTmp, $encTmp] as $f) {
        if (is_string($f) && is_file($f)) {
            @unlink($f);
        }
    }
};

$fh = fopen($sqlTmp, 'wb');
if ($fh === false) {
    $cleanup();
    fwrite(STDERR, "Cannot open temp file.\n");
    exit(1);
}
$escape = static fn (string $s): string => $conn->real_escape_string($s);
$write  = static function (string $s) use ($fh): void { fwrite($fh, $s); };

fwrite($fh, "-- TAPhish backup of `" . ($curr_db ?? '') . "` at {$stamp} UTC\n");
fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

$totalRows = 0;
$failed    = false;
foreach ($tables as $t) {
    // schema (buffered, fully consumed)
    $createSql = '';
    $cr = $conn->query("SHOW CREATE TABLE `" . $t . "`");
    if ($cr instanceof mysqli_result) {
        $row = $cr->fetch_assoc();
        $createSql = (string) ($row['Create Table'] ?? ($row['Create View'] ?? ''));
        $cr->free();
    }
    if ($createSql === '') {
        $failed = true;
        break;
    }
    // data (unbuffered: rows fetched one at a time)
    $sel = $conn->query("SELECT * FROM `" . $t . "`", MYSQLI_USE_RESULT);
    if (!($sel instanceof mysqli_result)) {
        $failed = true;
        break;
    }
    $columns = array_map(static fn ($f) => $f->name, $sel->fetch_fields());
    $rowsGen = (static function () use ($sel) {
        while ($row = $sel->fetch_assoc()) {
            yield $row;
        }
    })();
    $totalRows += taphish_backup_dump_table($t, $createSql, $columns, $rowsGen, $escape, $write);
    $sel->free();
    fwrite($fh, "\n");
}
fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

if ($failed) {
    $cleanup();
    fwrite(STDERR, "Dump failed (a table could not be read). No backup written.\n");
    exit(3);
}

// ---- build the inner payload ----
// DB-only: gzip(sql). --with-state: zip(db.sql + state/<files>) — zip is already
// compressed, so it is not additionally gzipped. The .tapbak container is agnostic.
$innerTmp   = $gzTmp;
$stateFiles = 0;
if ($withState) {
    if (!class_exists('ZipArchive')) {
        $cleanup();
        fwrite(STDERR, "--with-state needs the PHP zip extension (ZipArchive).\n");
        exit(3);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipTmp, ZipArchive::OVERWRITE) !== true) {
        $cleanup();
        fwrite(STDERR, "Cannot create state archive.\n");
        exit(3);
    }
    $zip->addFile($sqlTmp, 'db.sql');
    foreach (taphish_backup_state_manifest($stateRoots, $stateLister) as $m) {
        if (is_file($m['src'])) {
            $zip->addFile($m['src'], $m['dest']);
            $stateFiles++;
        }
    }
    if (!$zip->close()) {
        $cleanup();
        fwrite(STDERR, "Failed to finalize state archive.\n");
        exit(3);
    }
    $innerTmp = $zipTmp;
} else {
    $src = fopen($sqlTmp, 'rb');
    $gz  = gzopen($gzTmp, 'wb9');
    if ($src === false || $gz === false) {
        $cleanup();
        fwrite(STDERR, "Compression open failed.\n");
        exit(3);
    }
    while (!feof($src)) {
        $buf = fread($src, 262144);
        if ($buf === false) {
            break;
        }
        gzwrite($gz, $buf);
    }
    fclose($src);
    gzclose($gz);
}

// ---- encrypt-stream the inner payload into .tapbak ----
$in  = fopen($innerTmp, 'rb');
$out = fopen($encTmp, 'wb');
if ($in === false || $out === false) {
    $cleanup();
    fwrite(STDERR, "Encrypt open failed.\n");
    exit(3);
}
$read     = static fn (int $n): string => (string) fread($in, $n);
$writeOut = static function (string $b) use ($out): void { fwrite($out, $b); };
$encFn    = static fn (string $p): ?string => secret_at_rest_encrypt($p, $key);
$ok = taphish_backup_encrypt_stream($read, $writeOut, $encFn);
fclose($in);
fclose($out);
if (!$ok) {
    $cleanup();
    fwrite(STDERR, "Encryption failed. No backup written.\n");
    exit(3);
}

// ---- atomic publish ----
$finalName = taphish_backup_filename($stamp);
$finalPath = rtrim($outDir, '/') . '/' . $finalName;
if (!@rename($encTmp, $finalPath)) {
    $cleanup();
    fwrite(STDERR, "Could not move backup into place.\n");
    exit(3);
}
@chmod($finalPath, 0600);
@unlink($sqlTmp);
@unlink($gzTmp);
@unlink($zipTmp);

// ---- rotate ----
$existing = [];
foreach ((array) glob(rtrim($outDir, '/') . '/taphish-backup-*.tapbak') as $p) {
    $existing[] = basename($p);
}
$deleted = 0;
foreach (taphish_backup_rotation_plan($existing, $keep) as $old) {
    if (@unlink(rtrim($outDir, '/') . '/' . $old)) {
        $deleted++;
    }
}

$size      = is_file($finalPath) ? filesize($finalPath) : 0;
$stateNote = $withState ? "  state files: {$stateFiles}" : '';
echo "Backup written: {$finalPath}\n  tables: " . count($tables) . "  rows: {$totalRows}{$stateNote}  size: {$size} bytes  pruned: {$deleted}\n";

if (function_exists('logIt')) {
    logIt(sprintf(
        'DB backup written: %s (%d tables, %d rows%s, %d bytes); pruned %d old',
        $finalName, count($tables), $totalRows,
        $withState ? sprintf(', %d state files', $stateFiles) : '',
        $size, $deleted
    ), 'cli');
}

exit(0);

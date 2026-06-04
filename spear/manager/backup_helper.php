<?php
/**
 * Phase 3.50 — Encrypted DB backup: SQL serialization + filename + rotation (pure).
 *
 * No mysqli, no crypto, no filesystem. The CLI (backup_run.php) injects a real
 * escaper + write sink and feeds streamed rows.
 *
 * Container/crypto: spear/manager/backup_archive.php
 * Design: docs/superpowers/specs/2026-06-04-phase-3.50-backup-snapshot-design.md
 */

if (!function_exists('taphish_backup_sql_table_header')) {
    function taphish_backup_sql_table_header(string $table, string $createSql): string
    {
        return "DROP TABLE IF EXISTS `" . $table . "`;\n" . rtrim($createSql, ";\n") . ";\n";
    }
}

if (!function_exists('taphish_backup_sql_insert')) {
    /**
     * @param string[]            $columns
     * @param array<string,mixed> $row     column => value (null allowed)
     * @param callable            $escape  fn(string): string
     */
    function taphish_backup_sql_insert(string $table, array $columns, array $row, callable $escape): string
    {
        $cols = [];
        $vals = [];
        foreach ($columns as $col) {
            $cols[] = '`' . $col . '`';
            $v = $row[$col] ?? null;
            $vals[] = $v === null ? 'NULL' : "'" . $escape((string) $v) . "'";
        }
        return 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
    }
}

if (!function_exists('taphish_backup_dump_table')) {
    /**
     * Stream one table: header once, then one INSERT per row, through $write.
     *
     * @param string[] $columns
     * @param iterable $rows    each an associative array
     * @param callable $escape  fn(string): string
     * @param callable $write   fn(string): void
     * @return int rows written
     */
    function taphish_backup_dump_table(string $table, string $createSql, array $columns,
                                       iterable $rows, callable $escape, callable $write): int
    {
        $write(taphish_backup_sql_table_header($table, $createSql));
        $n = 0;
        foreach ($rows as $row) {
            $write(taphish_backup_sql_insert($table, $columns, (array) $row, $escape));
            $n++;
        }
        return $n;
    }
}

if (!function_exists('taphish_backup_filename')) {
    function taphish_backup_filename(string $stamp): string
    {
        return 'taphish-backup-' . $stamp . '.tapbak';
    }
}

if (!function_exists('taphish_backup_rotation_plan')) {
    /**
     * Return the filenames to DELETE so only the newest $keep remain.
     * Lexical sort on the filename (the timestamp format sorts chronologically).
     *
     * @param string[] $filenames
     * @return string[] oldest-first
     */
    function taphish_backup_rotation_plan(array $filenames, int $keep): array
    {
        $files = array_values(array_filter($filenames, static fn ($f) => is_string($f) && $f !== ''));
        sort($files, SORT_STRING);
        if ($keep < 0) {
            $keep = 0;
        }
        $deleteCount = count($files) - $keep;
        return $deleteCount > 0 ? array_slice($files, 0, $deleteCount) : [];
    }
}

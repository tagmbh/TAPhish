<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class BackupHelperTest extends TestCase
{
    public function testTableHeaderDropsAndCreates(): void
    {
        $h = taphish_backup_sql_table_header('tb_main', 'CREATE TABLE `tb_main` (`id` int)');
        self::assertSame("DROP TABLE IF EXISTS `tb_main`;\nCREATE TABLE `tb_main` (`id` int);\n", $h);
    }

    public function testInsertQuotesEscapesAndNulls(): void
    {
        $esc = static fn (string $s): string => addslashes($s);
        $sql = taphish_backup_sql_insert(
            'tb_x',
            ['a', 'b', 'c'],
            ['a' => "o'brien", 'b' => null, 'c' => 'plain'],
            $esc
        );
        self::assertSame("INSERT INTO `tb_x` (`a`,`b`,`c`) VALUES ('o\\'brien',NULL,'plain');\n", $sql);
    }

    public function testDumpTableStreamsHeaderThenOneInsertPerRow(): void
    {
        $esc   = static fn (string $s): string => addslashes($s);
        $out   = '';
        $write = static function (string $s) use (&$out): void { $out .= $s; };
        $rows  = [
            ['id' => '1', 'name' => 'a'],
            ['id' => '2', 'name' => 'b'],
        ];
        $n = taphish_backup_dump_table('tb_x', 'CREATE TABLE `tb_x` (`id` int,`name` text)', ['id', 'name'], $rows, $esc, $write);
        self::assertSame(2, $n);
        self::assertStringContainsString('DROP TABLE IF EXISTS `tb_x`;', $out);
        self::assertSame(2, substr_count($out, 'INSERT INTO `tb_x`'));
    }

    public function testDumpTableEmptyRowsWritesHeaderOnly(): void
    {
        $esc   = static fn (string $s): string => $s;
        $out   = '';
        $write = static function (string $s) use (&$out): void { $out .= $s; };
        $n = taphish_backup_dump_table('tb_x', 'CREATE TABLE `tb_x` (`id` int)', ['id'], [], $esc, $write);
        self::assertSame(0, $n);
        self::assertStringContainsString('DROP TABLE IF EXISTS `tb_x`;', $out);
        self::assertStringNotContainsString('INSERT INTO', $out);
    }

    public function testFilename(): void
    {
        self::assertSame('taphish-backup-20260604-131500.tapbak', taphish_backup_filename('20260604-131500'));
    }

    public function testRotationKeepsNewestN(): void
    {
        $files = [
            'taphish-backup-20260601-000000.tapbak',
            'taphish-backup-20260603-000000.tapbak',
            'taphish-backup-20260602-000000.tapbak',
            'taphish-backup-20260604-000000.tapbak',
        ];
        $del = taphish_backup_rotation_plan($files, 2);
        self::assertSame(
            ['taphish-backup-20260601-000000.tapbak', 'taphish-backup-20260602-000000.tapbak'],
            $del
        );
    }

    public function testRotationKeepGreaterThanCountDeletesNothing(): void
    {
        self::assertSame([], taphish_backup_rotation_plan(['a.tapbak', 'b.tapbak'], 5));
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class BackupStateTest extends TestCase
{
    public function testManifestMapsRootsToPrefixedDests(): void
    {
        $roots = [
            'state/cloned'      => '/abs/sniperhost/cloned',
            'state/attachments' => '/abs/uploads/attachments',
        ];
        $lister = static function (string $dir): array {
            return $dir === '/abs/sniperhost/cloned'
                ? ['site/index.html', 'site/css/a.css']
                : ['report.pdf'];
        };
        $m = taphish_backup_state_manifest($roots, $lister);
        self::assertSame([
            ['src' => '/abs/sniperhost/cloned/site/index.html', 'dest' => 'state/cloned/site/index.html'],
            ['src' => '/abs/sniperhost/cloned/site/css/a.css',  'dest' => 'state/cloned/site/css/a.css'],
            ['src' => '/abs/uploads/attachments/report.pdf',    'dest' => 'state/attachments/report.pdf'],
        ], $m);
    }

    public function testManifestSkipsEmptyAndMissing(): void
    {
        $roots  = ['state/x' => '/abs/x'];
        $lister = static fn (string $dir): array => [];
        self::assertSame([], taphish_backup_state_manifest($roots, $lister));
    }

    public function testSniffFormat(): void
    {
        self::assertSame('gzip', taphish_backup_sniff_format("\x1f\x8b\x08\x00rest"));
        self::assertSame('zip', taphish_backup_sniff_format("PK\x03\x04rest"));
        self::assertSame('zip', taphish_backup_sniff_format("PK\x05\x06"));     // empty archive
        self::assertSame('unknown', taphish_backup_sniff_format('-- plain sql'));
        self::assertSame('unknown', taphish_backup_sniff_format(''));
    }

    public function testRestoreTargetMapsUnderRoot(): void
    {
        $roots = ['state/cloned' => '/srv/spear/sniperhost/cloned'];
        self::assertSame(
            '/srv/spear/sniperhost/cloned/site/index.html',
            taphish_backup_state_restore_target('state/cloned/site/index.html', $roots)
        );
    }

    public function testRestoreTargetRejectsTraversal(): void
    {
        $roots = ['state/cloned' => '/srv/spear/sniperhost/cloned'];
        self::assertNull(taphish_backup_state_restore_target('state/cloned/../../etc/passwd', $roots));
        self::assertNull(taphish_backup_state_restore_target('state/cloned/../escape', $roots));
    }

    public function testRestoreTargetRejectsNulAndUnknownPrefix(): void
    {
        $roots = ['state/cloned' => '/srv/spear/sniperhost/cloned'];
        self::assertNull(taphish_backup_state_restore_target("state/cloned/a\0b", $roots));
        self::assertNull(taphish_backup_state_restore_target('other/x', $roots));
        self::assertNull(taphish_backup_state_restore_target('state/cloned', $roots)); // prefix only, no file
    }
}

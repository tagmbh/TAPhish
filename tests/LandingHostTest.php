<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.60 (P1) — pure-side tests for the external landing-host helpers.
 * No DB / network: the FTP push uses an injected uploader stub.
 */
final class LandingHostTest extends TestCase
{
    private function goodCfg(array $over = []): array
    {
        return $over + [
            'id'               => 'lh_test',
            'label'            => 'OWA look-alike',
            'type'             => 'ftps',
            'host'             => 'sl2084.web.hostpoint.ch',
            'port'             => 21,
            'username'         => 'ftp@owa.textilcolor.ch',
            'password'         => 'sekret',
            'remote_base_path' => '',
            'public_url_base'  => 'https://owa.textilcolor.ch/',
        ];
    }

    public function testValidateAcceptsGoodFtps(): void
    {
        self::assertTrue(landing_host_validate($this->goodCfg())['ok']);
    }

    public function testValidateRejectsBadType(): void
    {
        $r = landing_host_validate($this->goodCfg(['type' => 'sftp']));
        self::assertFalse($r['ok']);
        self::assertContains('type must be ftp or ftps', $r['errors']);
    }

    public function testValidateRejectsMissingFieldsAndBadPort(): void
    {
        $r = landing_host_validate(['type' => 'ftps', 'port' => 0]);
        self::assertFalse($r['ok']);
        self::assertContains('missing host', $r['errors']);
        self::assertContains('missing password', $r['errors']);
        self::assertContains('port must be 1..65535', $r['errors']);
    }

    public function testValidateRejectsNonHttpPublicBase(): void
    {
        $r = landing_host_validate($this->goodCfg(['public_url_base' => 'owa.textilcolor.ch']));
        self::assertFalse($r['ok']);
        self::assertContains('public_url_base must be http(s)://', $r['errors']);
    }

    public function testMaskRedactsPassword(): void
    {
        $m = landing_host_mask($this->goodCfg(['password' => 'supersecret']));
        self::assertStringStartsWith('su', $m['password']);
        self::assertStringNotContainsString('persecret', $m['password']);
    }

    public function testFromRequestDefaultsAndId(): void
    {
        $c = landing_host_from_request([
            'label' => ' Acme ', 'host' => ' h ', 'username' => ' u ',
            'public_url_base' => 'https://owa.x.ch', 'remote_base_path' => '/www/owa/',
        ]);
        self::assertSame('Acme', $c['label']);
        self::assertSame('h', $c['host']);
        self::assertSame('ftps', $c['type']);          // default
        self::assertSame(21, $c['port']);              // default
        self::assertSame('www/owa', $c['remote_base_path']);  // slashes stripped
        self::assertSame('https://owa.x.ch/', $c['public_url_base']); // trailing slash added
        self::assertStringStartsWith('lh_', $c['id']); // generated
    }

    public function testFromRequestNormalizesDotBasePath(): void
    {
        self::assertSame('', landing_host_from_request(['remote_base_path' => '.'])['remote_base_path']);
        self::assertSame('', landing_host_from_request(['remote_base_path' => '/'])['remote_base_path']);
    }

    public function testMergeSecretKeepsExistingWhenBlank(): void
    {
        $cfg = $this->goodCfg(['password' => '']);
        $merged = landing_host_merge_secret($cfg, ['password' => 'stored-pw']);
        self::assertSame('stored-pw', $merged['password']);
    }

    public function testMergeSecretDoesNotOverrideProvided(): void
    {
        $merged = landing_host_merge_secret($this->goodCfg(['password' => 'new-pw']), ['password' => 'old']);
        self::assertSame('new-pw', $merged['password']);
    }

    public function testPublicUrlAndRemoteRoot(): void
    {
        $cfg = $this->goodCfg(['public_url_base' => 'https://owa.textilcolor.ch/', 'remote_base_path' => 'www/owa']);
        self::assertSame('https://owa.textilcolor.ch/m365-login/', landing_host_public_url($cfg, 'm365-login'));
        self::assertSame('www/owa/m365-login', landing_host_remote_root($cfg, 'm365-login'));
        // empty base => slug at the FTP root
        self::assertSame('m365-login', landing_host_remote_root($this->goodCfg(), 'm365-login'));
    }

    public function testFtpUrlBuildsAndEncodes(): void
    {
        $cfg = $this->goodCfg(['host' => 'h.example', 'port' => 2121]);
        self::assertSame('ftp://h.example:2121/a/b%20c/index.html',
            landing_host_ftp_url($cfg, 'a/b c/index.html'));
    }

    public function testMapRemoteStripsLocalDirPrefix(): void
    {
        $files = ['/tmp/clone/index.html', '/tmp/clone/assets/app.css'];
        $plan  = landing_host_map_remote($files, '/tmp/clone', 'www/owa/m365');
        self::assertSame('www/owa/m365/index.html', $plan[0]['remote']);
        self::assertSame('www/owa/m365/assets/app.css', $plan[1]['remote']);
        self::assertSame('/tmp/clone/index.html', $plan[0]['local']);
    }

    public function testMapRemoteEmptyRoot(): void
    {
        $plan = landing_host_map_remote(['/d/x/y.txt'], '/d', '');
        self::assertSame('x/y.txt', $plan[0]['remote']);
    }

    public function testPushDirUploadsEveryFileViaInjectedUploader(): void
    {
        // Build a tiny real local tree so list_files has something to walk.
        $dir = sys_get_temp_dir() . '/lh_' . bin2hex(random_bytes(4));
        mkdir($dir . '/assets', 0777, true);
        file_put_contents($dir . '/index.html', '<form></form>');
        file_put_contents($dir . '/assets/app.css', 'body{}');

        $seen = [];
        $uploader = function (array $cfg, string $local, string $remote) use (&$seen): array {
            $seen[] = $remote;
            return ['ok' => true, 'error' => ''];
        };
        $r = landing_host_push_dir($this->goodCfg(['remote_base_path' => 'www/owa']), 'm365', $dir, $uploader);

        self::assertTrue($r['ok']);
        self::assertSame(2, $r['uploaded']);
        self::assertSame(2, $r['total']);
        self::assertSame('https://owa.textilcolor.ch/m365/', $r['public_url']);
        self::assertContains('www/owa/m365/index.html', $seen);
        self::assertContains('www/owa/m365/assets/app.css', $seen);

        @unlink($dir . '/assets/app.css');
        @unlink($dir . '/index.html');
        @rmdir($dir . '/assets');
        @rmdir($dir);
    }

    public function testPushDirStopsOnFirstFailure(): void
    {
        $dir = sys_get_temp_dir() . '/lh_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/a.txt', 'x');
        file_put_contents($dir . '/b.txt', 'y');

        $uploader = fn(array $c, string $l, string $rp): array => ['ok' => false, 'error' => 'boom'];
        $r = landing_host_push_dir($this->goodCfg(), 'slug', $dir, $uploader);

        self::assertFalse($r['ok']);
        self::assertSame(0, $r['uploaded']);
        self::assertStringContainsString('boom', $r['error']);

        @unlink($dir . '/a.txt');
        @unlink($dir . '/b.txt');
        @rmdir($dir);
    }

    // --- P3: default host + push status ---------------------------------

    public function testMarkDefaultSetsOneAndClearsOthers(): void
    {
        $profiles = [
            ['id' => 'a', 'is_default' => true],
            ['id' => 'b'],
            ['id' => 'c', 'is_default' => true],
        ];
        $out = landing_host_mark_default($profiles, 'b');
        self::assertFalse($out[0]['is_default']);
        self::assertTrue($out[1]['is_default']);
        self::assertFalse($out[2]['is_default']);
    }

    public function testMarkDefaultBlankIdClearsAll(): void
    {
        $out = landing_host_mark_default([['id' => 'a', 'is_default' => true]], '');
        self::assertFalse($out[0]['is_default']);
    }

    public function testPickDefaultPrefersFlaggedElseFirst(): void
    {
        self::assertSame('b', landing_host_pick_default([['id' => 'a'], ['id' => 'b', 'is_default' => true]])['id']);
        self::assertSame('a', landing_host_pick_default([['id' => 'a'], ['id' => 'b']])['id']);
        self::assertNull(landing_host_pick_default([]));
    }

    public function testFromRequestParsesIsDefault(): void
    {
        self::assertTrue(landing_host_from_request(['is_default' => 'on'])['is_default']);
        self::assertTrue(landing_host_from_request(['is_default' => true])['is_default']);
        self::assertFalse(landing_host_from_request([])['is_default']);
    }

    public function testStampPushRecordsMeta(): void
    {
        $c = landing_host_stamp_push($this->goodCfg(), 'm365', 'https://owa.x.ch/m365/', 7, '2026-06-10T10:00:00Z');
        self::assertSame('m365', $c['last_push']['slug']);
        self::assertSame(7, $c['last_push']['uploaded']);
        self::assertSame('https://owa.x.ch/m365/', $c['last_push']['public_url']);
        self::assertSame('2026-06-10T10:00:00Z', $c['last_push']['at']);
    }

    public function testPushDirEmptyDirIsAnError(): void
    {
        $dir = sys_get_temp_dir() . '/lh_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        $r = landing_host_push_dir($this->goodCfg(), 'slug', $dir, fn() => ['ok' => true]);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('no files', $r['error']);
        @rmdir($dir);
    }

    // --- Merge (FEATURE-R2.4): render {{POST_URL}} at push time -------------

    public function testRenderHtmlSubstitutesPostUrlAndDropsBeacon(): void
    {
        $html = "<form action=\"{{POST_URL}}\">\n<script data-tracker src=\"{{TRACKER_URL_ATTR}}\"></script>\n<end>";
        $out  = landing_host_render_html($html, 'https://deepaudit.ch/track.php');
        self::assertStringContainsString('action="https://deepaudit.ch/track.php"', $out);
        self::assertStringNotContainsString('{{POST_URL}}', $out);
        self::assertStringNotContainsString('{{TRACKER_URL_ATTR}}', $out);
        self::assertStringContainsString('<end>', $out);
    }

    public function testRenderHtmlIsIdempotentOnBakedContent(): void
    {
        // A clone already baked with an absolute POST_URL is a no-op.
        $baked = '<form action="https://deepaudit.ch/track.php"><input name="p"></form>';
        self::assertSame($baked, landing_host_render_html($baked, 'https://deepaudit.ch/track.php'));
    }

    public function testPushDirRendersHtmlButLeavesOtherFilesByteForByte(): void
    {
        $dir = sys_get_temp_dir() . '/lh_' . bin2hex(random_bytes(4));
        mkdir($dir . '/assets', 0777, true);
        file_put_contents($dir . '/index.html', '<form action="{{POST_URL}}">');
        file_put_contents($dir . '/assets/app.css', 'body{background:url({{POST_URL}})}'); // non-HTML: untouched

        $content = [];
        $uploader = function (array $cfg, string $local, string $remote) use (&$content): array {
            $content[$remote] = file_get_contents($local);
            return ['ok' => true, 'error' => ''];
        };
        $r = landing_host_push_dir($this->goodCfg(), 'm365', $dir, $uploader, 'https://deepaudit.ch/track.php');

        self::assertTrue($r['ok']);
        self::assertStringContainsString('action="https://deepaudit.ch/track.php"', $content['m365/index.html']);
        self::assertStringNotContainsString('{{POST_URL}}', $content['m365/index.html']);
        self::assertStringContainsString('{{POST_URL}}', $content['m365/assets/app.css'], 'only HTML is rendered');

        @unlink($dir . '/assets/app.css'); @unlink($dir . '/index.html');
        @rmdir($dir . '/assets'); @rmdir($dir);
    }

    public function testPushDirWithoutPostUrlUploadsHtmlUnchanged(): void
    {
        $dir = sys_get_temp_dir() . '/lh_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/index.html', '<form action="{{POST_URL}}">');

        $seen = null;
        $uploader = function ($c, $l, $rp) use (&$seen): array { $seen = file_get_contents($l); return ['ok' => true, 'error' => '']; };
        landing_host_push_dir($this->goodCfg(), 's', $dir, $uploader); // no postUrl -> byte-for-byte
        self::assertStringContainsString('{{POST_URL}}', $seen);

        @unlink($dir . '/index.html'); @rmdir($dir);
    }
}

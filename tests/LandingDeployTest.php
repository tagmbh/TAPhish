<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * FEATURE-R2.4 Phase 1: in-app landing deploy engine.
 * Pure functions (render / resolve_target / list_targets) + the local write
 * driver, exercised against temp fixtures — never the real ~/www/.
 * Design: docs/superpowers/specs/2026-07-18-in-app-landing-deploy-design.md
 */
final class LandingDeployTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ld_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) { return; }
        foreach (scandir($d) as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $d . '/' . $e;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }

    // ---- render -----------------------------------------------------------

    public function testRenderReplacesPostUrl(): void
    {
        $out = taphish_landing_deploy_render('<form action="{{POST_URL}}">', 'https://deepaudit.ch/track.php');
        self::assertStringContainsString('action="https://deepaudit.ch/track.php"', $out);
        self::assertStringNotContainsString('{{POST_URL}}', $out);
    }

    public function testRenderDropsTrackerBeaconLine(): void
    {
        $html = "top\n<script data-tracker src=\"{{TRACKER_URL_ATTR}}\"></script>\nbottom";
        $out = taphish_landing_deploy_render($html, 'https://deepaudit.ch/track.php');
        self::assertStringNotContainsString('{{TRACKER_URL_ATTR}}', $out);
        self::assertStringContainsString('top', $out);
        self::assertStringContainsString('bottom', $out);
    }

    public function testRenderKeepsInlineScreenResBeacon(): void
    {
        $html = "<script>function sres(){return screen.width+'x'+screen.height;}</script>\n<form action=\"{{POST_URL}}\">";
        $out = taphish_landing_deploy_render($html, 'https://deepaudit.ch/track.php');
        self::assertStringContainsString('sres', $out, 'the unrelated screen_res beacon must survive');
    }

    // ---- resolve_target ---------------------------------------------------

    public function testResolveTargetAcceptsExistingHostUnderBase(): void
    {
        mkdir($this->tmp . '/owa.example.com');
        $r = taphish_landing_deploy_resolve_target('owa.example.com', $this->tmp);
        self::assertTrue($r['ok']);
        self::assertSame(realpath($this->tmp . '/owa.example.com'), $r['docroot']);
    }

    public function testResolveTargetRejectsTraversal(): void
    {
        $r = taphish_landing_deploy_resolve_target('../etc', $this->tmp);
        self::assertFalse($r['ok']);
    }

    public function testResolveTargetRejectsProtectedAppHost(): void
    {
        mkdir($this->tmp . '/deepaudit.ch');
        $r = taphish_landing_deploy_resolve_target('deepaudit.ch', $this->tmp);
        self::assertFalse($r['ok'], 'never overwrite the app itself');
    }

    public function testResolveTargetRejectsConfig(): void
    {
        mkdir($this->tmp . '/config');
        $r = taphish_landing_deploy_resolve_target('config', $this->tmp);
        self::assertFalse($r['ok']);
    }

    public function testResolveTargetRejectsMissingDir(): void
    {
        $r = taphish_landing_deploy_resolve_target('nope.example.ch', $this->tmp);
        self::assertFalse($r['ok'], 'targets must be pre-provisioned vhosts');
    }

    public function testResolveTargetRejectsLeadingDot(): void
    {
        $r = taphish_landing_deploy_resolve_target('.hidden', $this->tmp);
        self::assertFalse($r['ok']);
    }

    // ---- list_targets -----------------------------------------------------

    public function testListTargetsEnumeratesDirsMinusProtected(): void
    {
        mkdir($this->tmp . '/owa.example.com');
        mkdir($this->tmp . '/abacus.example.com');
        mkdir($this->tmp . '/deepaudit.ch');   // app — excluded
        mkdir($this->tmp . '/config');          // protected — excluded
        file_put_contents($this->tmp . '/loose.txt', 'x'); // non-dir — excluded
        $t = taphish_landing_deploy_list_targets($this->tmp);
        self::assertSame(['abacus.example.com', 'owa.example.com'], $t);
    }

    // ---- write_local (integration) ---------------------------------------

    public function testWriteLocalWritesRenderedFilesAndBacksUpExisting(): void
    {
        $src = $this->tmp . '/src';
        mkdir($src . '/assets', 0777, true);
        file_put_contents($src . '/index.html', '<form action="{{POST_URL}}">');
        file_put_contents($src . '/learn.html', 'learn page');
        file_put_contents($src . '/assets/style.css', 'body{}');

        $docroot = $this->tmp . '/host';
        mkdir($docroot);
        file_put_contents($docroot . '/index.html', 'OLD CONTENT'); // must be backed up

        $rendered = taphish_landing_deploy_render(file_get_contents($src . '/index.html'), 'https://deepaudit.ch/track.php');
        $res = taphish_landing_deploy_write_local([
            'index_html'     => $rendered,
            'learn_html_src' => $src . '/learn.html',
            'assets_src'     => $src . '/assets',
        ], $docroot, '20260718');

        self::assertTrue($res['ok'], $res['error'] ?? '');
        self::assertStringContainsString('https://deepaudit.ch/track.php', file_get_contents($docroot . '/index.html'));
        self::assertFileExists($docroot . '/learn.html');
        self::assertFileExists($docroot . '/assets/style.css');
        self::assertFileExists($docroot . '/index.html.bak-20260718');
        self::assertSame('OLD CONTENT', file_get_contents($docroot . '/index.html.bak-20260718'));
    }

    public function testWriteLocalCreatesMissingDocroot(): void
    {
        $docroot = $this->tmp . '/newhost'; // does not exist yet
        $res = taphish_landing_deploy_write_local(['index_html' => 'hello'], $docroot, '20260718');
        self::assertTrue($res['ok']);
        self::assertFileExists($docroot . '/index.html');
        self::assertSame('hello', file_get_contents($docroot . '/index.html'));
    }

    // ---- list_sources -----------------------------------------------------

    public function testListSourcesFindsLibraryAndClonedWithIndex(): void
    {
        mkdir($this->tmp . '/library/owa-exchange-capture', 0777, true);
        file_put_contents($this->tmp . '/library/owa-exchange-capture/index.html', 'x');
        mkdir($this->tmp . '/library/no-index', 0777, true); // no index.html -> excluded
        mkdir($this->tmp . '/cloned/acme-login', 0777, true);
        file_put_contents($this->tmp . '/cloned/acme-login/index.html', 'x');

        $pairs = array_map(
            static fn($r) => $r['kind'] . '/' . $r['name'],
            taphish_landing_deploy_list_sources($this->tmp)
        );
        sort($pairs);
        self::assertSame(['cloned/acme-login', 'library/owa-exchange-capture'], $pairs);
    }

    // ---- run (orchestrator) ----------------------------------------------

    public function testRunResolvesRendersAndWrites(): void
    {
        $src = $this->tmp . '/src';
        mkdir($src . '/assets', 0777, true);
        file_put_contents($src . '/index.html', '<form action="{{POST_URL}}">');
        file_put_contents($src . '/learn.html', 'learn');
        file_put_contents($src . '/assets/a.js', '1');

        $www = $this->tmp . '/www';
        mkdir($www . '/owa.example.com', 0777, true);

        $res = taphish_landing_deploy_run($src, 'owa.example.com', $www, 'https://deepaudit.ch/track.php', '20260718');
        self::assertTrue($res['ok'], $res['error'] ?? '');
        self::assertStringContainsString('https://deepaudit.ch/track.php', file_get_contents($www . '/owa.example.com/index.html'));
        self::assertFileExists($www . '/owa.example.com/learn.html');
        self::assertFileExists($www . '/owa.example.com/assets/a.js');
    }

    public function testRunRejectsProtectedTarget(): void
    {
        $src = $this->tmp . '/src';
        mkdir($src, 0777, true);
        file_put_contents($src . '/index.html', 'x');
        $www = $this->tmp . '/www';
        mkdir($www, 0777, true);

        $res = taphish_landing_deploy_run($src, 'deepaudit.ch', $www, 'https://x/track.php', '20260718');
        self::assertFalse($res['ok']);
        self::assertStringContainsString('protected', (string) $res['error']);
    }

    public function testRunRejectsMissingSourceIndex(): void
    {
        $src = $this->tmp . '/src';
        mkdir($src, 0777, true); // no index.html
        $www = $this->tmp . '/www';
        mkdir($www . '/owa.example.com', 0777, true);

        $res = taphish_landing_deploy_run($src, 'owa.example.com', $www, 'https://x/track.php', '20260718');
        self::assertFalse($res['ok']);
    }

    // ---- verify (IO) ------------------------------------------------------

    public function testVerifyUnreachableReturnsWellFormedZeroCode(): void
    {
        // Connection-refused localhost port: a well-formed result, code 0, no throw.
        $r = taphish_landing_deploy_verify('https://127.0.0.1:1/');
        self::assertArrayHasKey('http_code', $r);
        self::assertArrayHasKey('ssl_ok', $r);
        self::assertIsInt($r['http_code']);
        self::assertFalse($r['ssl_ok']);
    }

    // ---- resolve_source (source-path safety, symmetric with resolve_target) --

    public function testResolveSourceAcceptsLibraryWithIndex(): void
    {
        mkdir($this->tmp . '/library/owa-exchange-capture', 0777, true);
        file_put_contents($this->tmp . '/library/owa-exchange-capture/index.html', 'x');
        $r = taphish_landing_deploy_resolve_source($this->tmp, 'library', 'owa-exchange-capture');
        self::assertTrue($r['ok']);
        self::assertSame(realpath($this->tmp . '/library/owa-exchange-capture'), $r['dir']);
    }

    public function testResolveSourceRejectsUnknownKind(): void
    {
        $r = taphish_landing_deploy_resolve_source($this->tmp, 'evil', 'x');
        self::assertFalse($r['ok']);
    }

    public function testResolveSourceRejectsTraversalName(): void
    {
        mkdir($this->tmp . '/library', 0777, true);
        $r = taphish_landing_deploy_resolve_source($this->tmp, 'library', '../../etc');
        self::assertFalse($r['ok']);
    }

    public function testResolveSourceRejectsMissingIndex(): void
    {
        mkdir($this->tmp . '/cloned/no-index', 0777, true);
        $r = taphish_landing_deploy_resolve_source($this->tmp, 'cloned', 'no-index');
        self::assertFalse($r['ok']);
    }
}

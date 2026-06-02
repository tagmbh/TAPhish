<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class ToolsetChecksTest extends TestCase
{
    public function testCheckPhpExtensionOkWhenLoaded(): void
    {
        $r = taphish_toolset_check_php_extension('curl', fn ($n) => true);
        self::assertSame('ok', $r['status']);
        self::assertSame('ext-curl', $r['key']);
    }

    public function testCheckPhpExtensionErrorWhenMissing(): void
    {
        $r = taphish_toolset_check_php_extension('imagick', fn () => false);
        self::assertSame('error', $r['status']);
        self::assertStringContainsString('not loaded', $r['detail']);
    }

    public function testCheckPhpVersionAcceptsModern(): void
    {
        $r = taphish_toolset_check_php_version('8.3.10');
        self::assertSame('ok', $r['status']);
    }

    public function testCheckPhpVersionWarnsOnOld(): void
    {
        $r = taphish_toolset_check_php_version('7.4.33');
        self::assertSame('warn', $r['status']);
    }

    public function testWritableDirsHonoursInjection(): void
    {
        $rs = taphish_toolset_check_writable_dirs(['/var/x', '/var/y'], function ($d) {
            return $d === '/var/x';
        });
        self::assertCount(2, $rs);
        self::assertSame('ok',    $rs[0]['status']);
        self::assertSame('error', $rs[1]['status']);
    }

    public function testCheckDnsOkWhenRecordsPresent(): void
    {
        $r = taphish_toolset_check_dns('dns-mx', 'MX', 'acme.test', DNS_MX, fn () => [['target' => 'mx.acme.test']]);
        self::assertSame('ok', $r['status']);
        self::assertStringContainsString('1', $r['detail']);
    }

    public function testCheckDnsWarnsWhenEmpty(): void
    {
        $r = taphish_toolset_check_dns('dns-mx', 'MX', 'acme.test', DNS_MX, fn () => []);
        self::assertSame('warn', $r['status']);
    }

    public function testCheckUrlReachableOkOn200(): void
    {
        $r = taphish_toolset_check_url_reachable('webhook', 'wh', 'https://hooks.test/x', fn () => ['ok' => true, 'status' => 200]);
        self::assertSame('ok', $r['status']);
    }

    public function testCheckUrlReachableWarnsWhenEmpty(): void
    {
        $r = taphish_toolset_check_url_reachable('webhook', 'wh', '', fn () => ['ok' => true, 'status' => 200]);
        self::assertSame('warn', $r['status']);
        self::assertSame('not configured', $r['detail']);
    }

    public function testCheckUrlReachableErrorOn5xx(): void
    {
        $r = taphish_toolset_check_url_reachable('webhook', 'wh', 'https://x.test', fn () => ['ok' => false, 'status' => 502]);
        self::assertSame('error', $r['status']);
    }

    public function testSummariseVerdictReady(): void
    {
        $s = taphish_toolset_summarise([
            ['status' => 'ok'],
            ['status' => 'ok'],
        ]);
        self::assertSame('ready', $s['verdict']);
        self::assertSame(2, $s['counts']['ok']);
    }

    public function testSummariseVerdictCaution(): void
    {
        $s = taphish_toolset_summarise([
            ['status' => 'ok'],
            ['status' => 'warn'],
        ]);
        self::assertSame('caution', $s['verdict']);
    }

    public function testSummariseVerdictBlocked(): void
    {
        $s = taphish_toolset_summarise([
            ['status' => 'ok'],
            ['status' => 'error'],
            ['status' => 'warn'],
        ]);
        self::assertSame('blocked', $s['verdict']);
    }

    public function testRunEndToEndAllGreen(): void
    {
        $r = taphish_toolset_run([
            'sender_domain' => 'acme.test',
            'webhook_url'   => 'https://hooks.test/x',
            'status_url'    => 'https://acme.test/status',
            'writable_dirs' => ['/var/uploads'],
            'php_version'   => '8.3.0',
            'is_loaded'     => fn () => true,
            'is_writable'   => fn () => true,
            'resolver'      => fn () => [['target' => 'mx.acme.test']],
            'fetcher'       => fn () => ['ok' => true, 'status' => 200],
        ]);
        self::assertSame('ready', $r['summary']['verdict']);
        self::assertSame(0, $r['summary']['counts']['error']);
    }

    public function testRunEndToEndFlagsMissingExtension(): void
    {
        $r = taphish_toolset_run([
            'sender_domain' => 'acme.test',
            'webhook_url'   => 'https://hooks.test/x',
            'status_url'    => 'https://acme.test/status',
            'php_version'   => '8.3.0',
            'is_loaded'     => fn ($name) => $name !== 'gd',
            'is_writable'   => fn () => true,
            'resolver'      => fn () => [['target' => 'mx.acme.test']],
            'fetcher'       => fn () => ['ok' => true, 'status' => 200],
        ]);
        self::assertSame('blocked', $r['summary']['verdict']);
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P2.0 — regression guards for the tracker bug batch. Behaviour is verified by
 * the live demo; these lock the fixes so the copy-pasted defects can't return.
 */
final class TrackerBugBatchTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testListJsUseCorrectDataTablesEventNamespace(): void
    {
        foreach (['js/web_tracker_list.js', 'js/quick_tracker.js', 'js/quick_tracker_report.js'] as $f) {
            $src = $this->f($f);
            self::assertStringContainsString('order.dt search.dt', $src, "$f must bind the real .dt events");
            self::assertDoesNotMatchRegularExpression('/order\.dt_[a-z_]+/', $src, "$f must not use a custom event namespace");
        }
    }

    public function testListJsUseWellFormedOrderNotMalformedAaSorting(): void
    {
        foreach (['js/web_tracker_list.js', 'js/quick_tracker.js', 'js/quick_tracker_report.js'] as $f) {
            self::assertStringNotContainsString('aaSorting', $this->f($f), "$f must not use the malformed aaSorting");
        }
        // web list sorts Date Created (col 4), quick sorts Date Created (col 3).
        self::assertStringContainsString('[[4, \'desc\']]', $this->f('js/web_tracker_list.js'));
        self::assertStringContainsString('[[3, \'desc\']]', $this->f('js/quick_tracker.js'));
    }

    public function testQuickStopButtonSendsRealBoolean(): void
    {
        $src = $this->f('js/quick_tracker.js');
        self::assertStringNotContainsString('data-status_value=fale', $src, 'the Stop-button typo must be gone');
        self::assertStringContainsString('data-status_value=false', $src, 'Stop must send a real false');
    }

    public function testDeadPauseHandlerRemoved(): void
    {
        self::assertStringNotContainsString(
            'pause_stop_tracker_tracking',
            $this->f('js/web_tracker_generator_function.js'),
            'the dead pause_stop_tracker_tracking handler must be removed'
        );
    }

    public function testImportHtmlFetchIsSsrfGuardedAndNotFatal(): void
    {
        $src = $this->f('manager/web_tracker_generator_list_manager.php');
        self::assertStringContainsString('taphish_fetch_url_precheck', $src, 'Import-HTML fetch must run the SSRF precheck');
        self::assertStringContainsString('taphish_ip_is_public', $src, 'Import-HTML fetch must verify resolved IPs are public');
        self::assertStringNotContainsString('$stmt->error()', $src, 'the fatal $stmt->error() call must be gone');
        self::assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER, false', $src, 'TLS verification must not be disabled');
    }
}

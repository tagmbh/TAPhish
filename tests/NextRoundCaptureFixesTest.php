<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Next-round capture fixes (prepared 2026-07-17). These are capture/reporting
 * paths that are awkward to exercise in a unit test (IMAP, a recipient-facing
 * landing), so these structural guards lock the fixes; behaviour is verified
 * live (#4 now; #1/#2/#3 at the next campaign wave when the landing/cron redeploy).
 */
final class NextRoundCaptureFixesTest extends TestCase
{
    private function read(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/' . $rel);
    }

    /**
     * All per-host capture-landing variants must carry the same beacon/screen_res
     * enhancement (the engagement uses branded per-host pretexts, not just m365).
     */
    private const CAPTURE_LANDINGS = [
        'spear/sniperhost/library/m365-login-capture/index.html',
        'spear/sniperhost/library/owa-exchange-capture/index.html',
        'spear/sniperhost/library/myabacus-login-capture/index.html',
        'spear/sniperhost/library/fortigate-vpn-capture/index.html',
    ];

    public function testLandingSendsPageZeroVisitBeacon(): void
    {
        // #2: every capture landing must POST a page-0 visit on load (→ tb_data_webpage_visit).
        foreach (self::CAPTURE_LANDINGS as $f) {
            self::assertMatchesRegularExpression('/post\(\{\s*page:\s*0/', $this->read($f), "$f must fire a page-0 visit beacon on load");
        }
    }

    public function testLandingSendsScreenRes(): void
    {
        // #3: every capture POST carries screen_res (sink track.php already stores it).
        foreach (self::CAPTURE_LANDINGS as $f) {
            $html = $this->read($f);
            self::assertStringContainsString('function sres()', $html, "$f: screen-res helper present");
            self::assertSame(4, substr_count($html, 'screen_res: sres()'), "$f: screen_res on page 0/1/2/3 posts");
        }
    }

    public function testGetMailRepliedFailsSoft(): void
    {
        // #4: never return an 'error' key (which turns the dashboard panel into
        // "Loading error!"); guard imap_open behind function_exists.
        $src = $this->read('spear/manager/common_functions.php');
        // isolate getMailReplied
        $start = strpos($src, 'function getMailReplied');
        $body = substr($src, $start, 3000);
        self::assertStringContainsString("function_exists('imap_open')", $body, 'imap_open must be guarded');
        self::assertStringNotContainsString("'error'=>\$arr_err", $body, 'reply path must not return an error key');
    }

    public function testCronGuaranteesOpenPixelWhenTrackingOn(): void
    {
        // #1: append {{TRACKER}} when timage_type==1 and the body lacks it.
        self::assertMatchesRegularExpression(
            '/timage_type[\s\S]{0,40}===\s*\'1\'[\s\S]{0,80}\{\{TRACKER\}\}/',
            $this->read('spear/core/mail_campaign_cron.php'),
            'cron must guarantee the open-pixel for tracking templates'
        );
    }
}

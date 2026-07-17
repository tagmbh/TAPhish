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

    public function testLandingSendsPageZeroVisitBeacon(): void
    {
        // #2: the m365 landing must POST a page-0 visit on load (→ tb_data_webpage_visit).
        self::assertMatchesRegularExpression(
            '/post\(\{\s*page:\s*0/',
            $this->read('spear/sniperhost/library/m365-login-capture/index.html'),
            'landing must fire a page-0 visit beacon on load'
        );
    }

    public function testLandingSendsScreenRes(): void
    {
        // #3: every capture POST carries screen_res (sink track.php already stores it).
        $html = $this->read('spear/sniperhost/library/m365-login-capture/index.html');
        self::assertStringContainsString('function sres()', $html, 'screen-res helper present');
        self::assertSame(4, substr_count($html, 'screen_res: sres()'), 'screen_res on page 0/1/2/3 posts');
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

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 1 — dashboard single-page fold. The web dashboard becomes the one
 * "Campaign Dashboard": email metrics always render; the web-tracker sections
 * render only when a tracker is selected AND the "Show web tracker" toggle is on.
 * taphish_dashboard_sections is the pure decision the client mirrors.
 */
final class DashboardViewTest extends TestCase
{
    public function testEmailAlwaysRenders(): void
    {
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$hasTracker, $showWeb]) {
            self::assertTrue(taphish_dashboard_sections($hasTracker, $showWeb)['email']);
        }
    }

    public function testWebNeedsBothTrackerAndToggle(): void
    {
        self::assertTrue(taphish_dashboard_sections(true, true)['web']);
    }

    public function testWebHiddenWhenNoTracker(): void
    {
        self::assertFalse(taphish_dashboard_sections(false, true)['web']);
        self::assertFalse(taphish_dashboard_sections(false, false)['web']);
    }

    public function testWebHiddenWhenToggleOff(): void
    {
        self::assertFalse(taphish_dashboard_sections(true, false)['web']);
    }

    public function testCoercesTruthyArgs(): void
    {
        // The client passes a possibly-empty tracker_id string and a checkbox
        // state; the helper must treat '' / 0 as false and a non-empty id as true.
        self::assertTrue(taphish_dashboard_sections('bsqk7v', 1)['web']);
        self::assertFalse(taphish_dashboard_sections('', 1)['web']);
        self::assertFalse(taphish_dashboard_sections('bsqk7v', 0)['web']);
    }
}

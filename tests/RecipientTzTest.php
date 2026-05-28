<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class RecipientTzTest extends TestCase
{
    // --- recipient_tz_from_email -----------------------------------------

    public function testTzFromCommonCountryTlds(): void
    {
        self::assertSame('Europe/Zurich', recipient_tz_from_email('alice@example.ch', 'UTC'));
        self::assertSame('Europe/Berlin', recipient_tz_from_email('bob@firma.de', 'UTC'));
        self::assertSame('America/New_York', recipient_tz_from_email('carol@corp.us', 'UTC'));
        self::assertSame('Asia/Tokyo', recipient_tz_from_email('dave@kaisha.jp', 'UTC'));
        self::assertSame('Australia/Sydney', recipient_tz_from_email('eve@co.au', 'UTC'));
    }

    public function testTzFallsBackToDefaultForGenericTld(): void
    {
        self::assertSame('Europe/Zurich', recipient_tz_from_email('x@example.com', 'Europe/Zurich'));
        self::assertSame('Europe/Zurich', recipient_tz_from_email('x@example.org', 'Europe/Zurich'));
        self::assertSame('Europe/Zurich', recipient_tz_from_email('x@example.net', 'Europe/Zurich'));
    }

    public function testTzFallsBackOnMalformedEmail(): void
    {
        self::assertSame('Europe/Zurich', recipient_tz_from_email('not-an-email', 'Europe/Zurich'));
        self::assertSame('Europe/Zurich', recipient_tz_from_email('', 'Europe/Zurich'));
        self::assertSame('Europe/Zurich', recipient_tz_from_email('user@', 'Europe/Zurich'));
    }

    public function testTzEmptyDefaultBecomesUtc(): void
    {
        self::assertSame('UTC', recipient_tz_from_email('x@example.com', ''));
    }

    public function testTzCaseInsensitive(): void
    {
        self::assertSame('Europe/Berlin', recipient_tz_from_email('BOB@FIRMA.DE', 'UTC'));
    }

    public function testTzMapHasExpectedSwissAndEuropeanCoverage(): void
    {
        $map = recipient_tz_tld_map();
        foreach (['de', 'at', 'ch', 'fr', 'it', 'uk', 'gb', 'ie'] as $tld) {
            self::assertArrayHasKey($tld, $map, "expected TLD $tld in map");
        }
    }

    // --- recipient_tz_clamp_hour -----------------------------------------

    public function testClampHourAcceptsValid(): void
    {
        self::assertSame(0, recipient_tz_clamp_hour(0));
        self::assertSame(9, recipient_tz_clamp_hour(9));
        self::assertSame(23, recipient_tz_clamp_hour(23));
    }

    public function testClampHourClampsOutOfRange(): void
    {
        self::assertSame(0, recipient_tz_clamp_hour(-5));
        self::assertSame(23, recipient_tz_clamp_hour(99));
    }

    public function testClampHourFallsBackOnNonNumeric(): void
    {
        self::assertSame(9, recipient_tz_clamp_hour(null));
        self::assertSame(9, recipient_tz_clamp_hour(''));
        self::assertSame(9, recipient_tz_clamp_hour('abc'));
    }

    // --- recipient_tz_clamp_window ---------------------------------------

    public function testClampWindowAcceptsValid(): void
    {
        self::assertSame(1, recipient_tz_clamp_window(1));
        self::assertSame(4, recipient_tz_clamp_window(4));
        self::assertSame(12, recipient_tz_clamp_window(12));
    }

    public function testClampWindowClampsOutOfRange(): void
    {
        self::assertSame(1, recipient_tz_clamp_window(0));
        self::assertSame(12, recipient_tz_clamp_window(99));
    }

    // --- recipient_local_hour_at -----------------------------------------

    public function testLocalHourAtZurichOffsetFromUtc(): void
    {
        // 2026-01-15 10:00 UTC → 11:00 in Europe/Zurich (CET, UTC+1)
        $utc = (int) strtotime('2026-01-15T10:00:00Z');
        self::assertSame(11, recipient_local_hour_at('x@example.ch', 'UTC', $utc));
    }

    public function testLocalHourAtTokyoOffsetFromUtc(): void
    {
        // 2026-01-15 00:00 UTC → 09:00 in Asia/Tokyo (JST, UTC+9)
        $utc = (int) strtotime('2026-01-15T00:00:00Z');
        self::assertSame(9, recipient_local_hour_at('x@example.jp', 'UTC', $utc));
    }

    public function testLocalHourFallsBackToUtcOnBadTzString(): void
    {
        // Map points to a valid zone, but the default is whatever the
        // operator typed. For a generic TLD with a bogus default we just
        // shouldn't crash.
        $utc = (int) strtotime('2026-01-15T07:00:00Z');
        self::assertSame(7, recipient_local_hour_at('x@example.com', 'Not/AZone', $utc));
    }

    // --- recipient_in_send_window ----------------------------------------

    public function testInWindowHappyPath(): void
    {
        // 2026-01-15 08:00 UTC → 09:00 in Zurich.  Target 9, window 4h → in.
        $utc = (int) strtotime('2026-01-15T08:00:00Z');
        self::assertTrue(recipient_in_send_window('x@example.ch', 'UTC', 9, 4, $utc));
    }

    public function testOutsideWindow(): void
    {
        // 06:00 Zurich → outside target 9, window 4 (covers 9..12).
        $utc = (int) strtotime('2026-01-15T05:00:00Z');
        self::assertFalse(recipient_in_send_window('x@example.ch', 'UTC', 9, 4, $utc));
    }

    public function testWindowWrapsOverMidnight(): void
    {
        // Target 22, window 4 → matches 22, 23, 0, 1.
        // 2026-01-15 22:00 UTC → 23:00 Berlin.
        $utc = (int) strtotime('2026-01-15T22:00:00Z');
        self::assertTrue(recipient_in_send_window('x@example.de', 'UTC', 22, 4, $utc));

        // 2026-01-15 00:00 UTC → 01:00 Berlin → still in [22..1] wrapped window.
        $utc = (int) strtotime('2026-01-16T00:00:00Z');
        self::assertTrue(recipient_in_send_window('x@example.de', 'UTC', 22, 4, $utc));

        // 03:00 Berlin → outside.
        $utc = (int) strtotime('2026-01-16T02:00:00Z');
        self::assertFalse(recipient_in_send_window('x@example.de', 'UTC', 22, 4, $utc));
    }

    public function testTargetHourClampedToValidRange(): void
    {
        $utc = (int) strtotime('2026-01-15T08:00:00Z');
        // Target -1 → clamped to 0, window 4 → 0..3 local. Zurich is 09 → out.
        self::assertFalse(recipient_in_send_window('x@example.ch', 'UTC', -1, 4, $utc));
        // Target 99 → clamped to 23, window 1 → 23 local. Zurich is 09 → out.
        self::assertFalse(recipient_in_send_window('x@example.ch', 'UTC', 99, 1, $utc));
    }
}

<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for spear/manager/ip_info_projection.php.
 *
 * Pins the upstream-API → row-shape projection used on every recipient open
 * and click. If the upstream IP-info provider ever renames a field (e.g.
 * country_name → country), the dashboard / customer report fields would
 * silently turn null — the tests below catch that.
 */
final class IpInfoProjectionTest extends TestCase
{
    public function testCompletePayloadProjectsAllSixFields(): void
    {
        $row = taphish_ip_info_projection([
            'country_name' => 'Switzerland',
            'city'         => 'Zurich',
            'postal'       => '8001',
            'org'          => 'Hostpoint AG',
            'timezone'     => 'Europe/Zurich',
            'utc_offset'   => '+0200',
            'latitude'     => 47.3769,
            'longitude'    => 8.5417,
        ]);
        self::assertSame('Switzerland',                 $row['country']);
        self::assertSame('Zurich',                      $row['city']);
        self::assertSame('8001',                        $row['zip']);
        self::assertSame('Hostpoint AG',                $row['isp']);
        self::assertSame('Europe/Zurich (+0200)',       $row['timezone']);
        self::assertSame('47.3769(lat)/8.5417(long)',   $row['coordinates']);
    }

    public function testMissingFieldsBecomeNull(): void
    {
        // Per-slot null is the downstream contract — the dashboard treats
        // null as "unknown" / blank. The projection must NEVER emit the
        // upstream key's literal text as a value.
        $row = taphish_ip_info_projection([]);
        self::assertNull($row['country']);
        self::assertNull($row['city']);
        self::assertNull($row['zip']);
        self::assertNull($row['isp']);
        self::assertNull($row['timezone']);
        self::assertNull($row['coordinates']);
    }

    public function testTimezoneIsAllOrNothing(): void
    {
        // A timezone without an offset (or vice versa) yields null — the
        // combined "Europe/Zurich (+0200)" string is the dashboard's
        // contract, and a half-populated value would render as
        // "Europe/Zurich ()" — looks like a bug to the operator.
        self::assertNull(taphish_ip_info_projection(['timezone' => 'Europe/Zurich'])['timezone']);
        self::assertNull(taphish_ip_info_projection(['utc_offset' => '+0200'])['timezone']);
        self::assertNull(taphish_ip_info_projection(['timezone' => 'Europe/Zurich', 'utc_offset' => ''])['timezone']);
    }

    public function testCoordinatesAreAllOrNothing(): void
    {
        self::assertNull(taphish_ip_info_projection(['latitude' => 47.0])['coordinates']);
        self::assertNull(taphish_ip_info_projection(['longitude' => 8.5])['coordinates']);
        // empty() treats 0 as empty — so a 0/0 coordinate is rejected. That's
        // an existing behavioural quirk worth pinning: a recipient genuinely
        // at the equator/prime-meridian would have no coordinates recorded.
        self::assertNull(taphish_ip_info_projection(['latitude' => 0, 'longitude' => 0])['coordinates']);
    }

    public function testZeroAndEmptyStringTreatedAsMissing(): void
    {
        // Matches empty() semantics — pinned so a switch to strict
        // null-coalescing would be a deliberate decision.
        $row = taphish_ip_info_projection([
            'country_name' => '',
            'city'         => '0',  // empty('0') is TRUE — quirk pinned
            'postal'       => null,
        ]);
        self::assertNull($row['country']);
        self::assertNull($row['city']);
        self::assertNull($row['zip']);
    }

    public function testRowShapeHasExactlySixKeys(): void
    {
        $row = taphish_ip_info_projection(['country_name' => 'X']);
        self::assertSame(
            ['country', 'city', 'zip', 'isp', 'timezone', 'coordinates'],
            array_keys($row),
            'projection key order/set is downstream contract — dashboard iterates these keys'
        );
    }

    public function testValuesAreCoercedToStringWhenNonEmpty(): void
    {
        // Upstream sometimes returns ints / floats for postal codes and ISP
        // ids. Downstream JSON-encodes the row — we keep types stable.
        $row = taphish_ip_info_projection([
            'country_name' => 'X',
            'postal'       => 8001,
            'org'          => 'AS12345',
        ]);
        self::assertIsString($row['country']);
        self::assertIsString($row['zip']);
        self::assertIsString($row['isp']);
        self::assertSame('8001', $row['zip']);
    }
}

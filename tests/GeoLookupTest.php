<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P3 geo — local MaxMind/DB-IP mmdb lookup, replacing the rate-limited ipapi.co
 * call. taphish_geo_from_mmdb_record is the pure projection: a raw mmdb record
 * (nested arrays like DB-IP / GeoLite2 emit) → the app's fixed 6-field ip_info
 * shape {country,city,zip,isp,timezone,coordinates}. Country Lite carries only
 * country; City DBs add city/coords/timezone — the projection handles both.
 */
final class GeoLookupTest extends TestCase
{
    public function testCountryRecordProjectsCountryOnly(): void
    {
        $rec = ['country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']]];
        $out = taphish_geo_from_mmdb_record($rec);
        self::assertSame('United States', $out['country']);
        self::assertNull($out['city']);
        self::assertNull($out['zip']);
        self::assertNull($out['isp']);
        self::assertNull($out['timezone']);
        self::assertNull($out['coordinates']);
    }

    public function testFallsBackToIsoCodeWhenNoEnglishName(): void
    {
        $out = taphish_geo_from_mmdb_record(['country' => ['iso_code' => 'CH']]);
        self::assertSame('CH', $out['country']);
    }

    public function testCityRecordProjectsCityCoordsTimezone(): void
    {
        $rec = [
            'country'  => ['names' => ['en' => 'Switzerland']],
            'city'     => ['names' => ['en' => 'Zurich']],
            'postal'   => ['code' => '8001'],
            'location' => ['latitude' => 47.37, 'longitude' => 8.54, 'time_zone' => 'Europe/Zurich'],
        ];
        $out = taphish_geo_from_mmdb_record($rec);
        self::assertSame('Switzerland', $out['country']);
        self::assertSame('Zurich', $out['city']);
        self::assertSame('8001', $out['zip']);
        self::assertSame('Europe/Zurich', $out['timezone']);
        self::assertSame('47.37(lat)/8.54(long)', $out['coordinates']);
    }

    public function testTraitsIspOrOrganization(): void
    {
        self::assertSame('Acme ISP', taphish_geo_from_mmdb_record(['traits' => ['isp' => 'Acme ISP']])['isp']);
        self::assertSame('Acme Org', taphish_geo_from_mmdb_record(['traits' => ['organization' => 'Acme Org']])['isp']);
    }

    public function testEmptyOrNullRecordYieldsAllNull(): void
    {
        foreach ([[], null] as $rec) {
            $out = taphish_geo_from_mmdb_record($rec);
            foreach (['country', 'city', 'zip', 'isp', 'timezone', 'coordinates'] as $k) {
                self::assertNull($out[$k], "$k must be null for an empty record");
            }
        }
    }

    public function testHelpersAreDefined(): void
    {
        self::assertTrue(function_exists('taphish_geo_lookup'), 'the mmdb lookup wrapper must exist');
    }
}

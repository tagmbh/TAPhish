<?php
// P3 geo — local IP-geo via a bundled MaxMind-format mmdb (DB-IP Country Lite),
// replacing the rate-limited ipapi.co web call. No external network, no limits.
// Projection is pure + unit-tested; the reader degrades to all-null if the DB or
// the reader library isn't present, so the caller can fall back gracefully.

if (!function_exists('taphish_geo_from_mmdb_record')) {
    /**
     * Pure: a raw mmdb record (nested arrays, DB-IP / GeoLite2 shape) → the app's
     * fixed 6-field ip_info projection. Country DBs carry only country; City DBs
     * add city / postal / location.
     *
     * @param array|null $record
     * @return array{country:?string,city:?string,zip:?string,isp:?string,timezone:?string,coordinates:?string}
     */
    function taphish_geo_from_mmdb_record($record): array
    {
        $r = is_array($record) ? $record : [];
        $country = $r['country']['names']['en'] ?? ($r['country']['iso_code'] ?? null);
        $city    = $r['city']['names']['en'] ?? null;
        $zip     = $r['postal']['code'] ?? null;
        $isp     = $r['traits']['isp'] ?? ($r['traits']['organization'] ?? null);
        $tz      = $r['location']['time_zone'] ?? null;
        $lat     = $r['location']['latitude'] ?? null;
        $lon     = $r['location']['longitude'] ?? null;
        $coords  = ($lat !== null && $lon !== null) ? ($lat . '(lat)/' . $lon . '(long)') : null;

        $s = static fn ($v) => $v === null ? null : (string) $v;
        return [
            'country'     => $s($country),
            'city'        => $s($city),
            'zip'         => $s($zip),
            'isp'         => $s($isp),
            'timezone'    => $s($tz),
            'coordinates' => $coords,
        ];
    }
}

if (!function_exists('taphish_geo_mmdb_path')) {
    function taphish_geo_mmdb_path(): ?string
    {
        $spear = dirname(__FILE__, 2); // .../spear
        foreach (['dbip-country-lite.mmdb', 'dbip-city-lite.mmdb', 'GeoLite2-City.mmdb', 'GeoLite2-Country.mmdb'] as $name) {
            $p = $spear . '/config/geo/' . $name;
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }
}

if (!function_exists('taphish_geo_reader')) {
    /** Lazily open the mmdb Reader once (or null if unavailable). */
    function taphish_geo_reader()
    {
        static $reader = false;                 // false = not yet attempted
        if ($reader !== false) {
            return $reader;
        }
        $path = taphish_geo_mmdb_path();
        if ($path === null) {
            return $reader = null;
        }
        $spear = dirname(__FILE__, 2);
        $root  = dirname($spear);
        foreach ([$spear . '/libs/maxmind-db-reader/autoload.php', $root . '/vendor/maxmind-db/reader/autoload.php'] as $al) {
            if (is_file($al)) {
                require_once $al;
                break;
            }
        }
        if (!class_exists('MaxMind\\Db\\Reader')) {
            return $reader = null;
        }
        try {
            return $reader = new \MaxMind\Db\Reader($path);
        } catch (\Throwable $e) {
            return $reader = null;
        }
    }
}

if (!function_exists('taphish_geo_lookup')) {
    /** Look up an IP in the local mmdb → the 6-field ip_info shape (all-null on miss). */
    function taphish_geo_lookup(string $ip): array
    {
        $miss = taphish_geo_from_mmdb_record([]);
        $reader = taphish_geo_reader();
        if ($reader === null || $ip === '') {
            return $miss;
        }
        try {
            return taphish_geo_from_mmdb_record($reader->get($ip));
        } catch (\Throwable $e) {
            return $miss;
        }
    }
}

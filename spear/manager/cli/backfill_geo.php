<?php
/**
 * P3 — one-time geo backfill for captured hits whose ip_info has no country
 * (the ipapi.co lookup didn't populate at capture time). Re-resolves each
 * distinct public_ip via the SAME ipapi.co endpoint + the SAME pure projection
 * (taphish_ip_info_projection) the app uses, and updates ip_info in place.
 *
 * Enrichment only — writes the 6-field geo (country/city/zip/isp/timezone/
 * coordinates); never touches form_field_data / credentials. Idempotent: rows
 * that already have a country are skipped. Prints COUNTS only (no IPs, no geo
 * values). Rate-limited (1 lookup/sec).
 *
 * Usage:  php backfill_geo.php --dry      (count only, no writes)
 *         php backfill_geo.php --commit   (perform the updates)
 */

$spear = dirname(__FILE__, 3);           // .../deepaudit.ch/spear
require_once($spear . '/config/db.php');
require_once($spear . '/manager/geo_lookup.php');   // local mmdb — no rate limit

$mode = $argv[1] ?? '--dry';
$commit = ($mode === '--commit');

$tables = ['tb_data_webform_submit', 'tb_data_webpage_visit'];
$geoCache = [];                          // public_ip => projected geo (or null)
$scanned = 0; $needGeo = 0; $updated = 0; $resolved = 0; $lookupFail = 0;

function geo_of(string $ip): ?array
{
    $g = taphish_geo_lookup($ip);          // local mmdb → 6-field ip_info shape
    return empty($g['country']) ? null : $g;
}

foreach ($tables as $t) {
    $res = @$conn->query("SELECT id, public_ip, ip_info FROM $t");
    if (!($res instanceof mysqli_result)) {
        continue;
    }
    while ($row = $res->fetch_assoc()) {
        $scanned++;
        $geo = json_decode((string) ($row['ip_info'] ?? ''), true);
        $geo = is_array($geo) ? $geo : [];
        if (!empty($geo['country'])) {
            continue;                    // already has geo → idempotent skip
        }
        $ip = (string) ($row['public_ip'] ?? '');
        if ($ip === '') {
            continue;
        }
        $needGeo++;

        if (!array_key_exists($ip, $geoCache)) {
            $geoCache[$ip] = geo_of($ip);   // local mmdb — no rate limit, no sleep
            if ($geoCache[$ip] === null) { $lookupFail++; } else { $resolved++; }
        }
        $fresh = $geoCache[$ip];
        if ($fresh === null || empty($fresh['country'])) {
            continue;
        }
        if ($commit) {
            $newInfo = json_encode(array_merge($geo, $fresh));
            $stmt = $conn->prepare("UPDATE $t SET ip_info = ? WHERE id = ?");
            $stmt->bind_param('si', $newInfo, $row['id']);
            $stmt->execute();
            $stmt->close();
        }
        $updated++;
    }
}

echo ($commit ? '[COMMIT] ' : '[DRY-RUN] ')
    . "scanned=$scanned need_geo=$needGeo distinct_ips_resolved=$resolved lookup_failures=$lookupFail rows_"
    . ($commit ? 'updated' : 'to_update') . "=$updated\n";

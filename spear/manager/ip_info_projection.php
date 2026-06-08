<?php
/**
 * IP-info API output → standard row shape — pure helper.
 *
 * Extracted from common_functions.php::craftIPInfoArr() (which now delegates
 * here) so the upstream-API contract can be unit-tested. craftIPInfoArr() is
 * called once per tracker hit (open/click) on a recipient's mail — the
 * external API output goes through this projection before being JSON-encoded
 * into tb_data_mailcamp_live.ip_info / tb_data_webform_submit.ip_info.
 *
 * The fixed 6-field shape (country / city / zip / isp / timezone /
 * coordinates) is downstream contract — the dashboard / customer report
 * iterate exactly these keys. Adding a field is fine; renaming or removing
 * one breaks the report renderer.
 *
 * Upstream fields the projection reads (ipapi.co-compatible payload):
 *   country_name, city, postal, org, timezone, utc_offset, latitude, longitude
 *
 * Any field can be missing / empty — the projection returns null for that
 * slot, never the upstream key's literal name.
 */

if (!function_exists('taphish_ip_info_projection')) {
    /**
     * @param array<string,mixed> $output upstream API JSON-decoded payload
     * @return array{country:?string,city:?string,zip:?string,isp:?string,timezone:?string,coordinates:?string}
     */
    function taphish_ip_info_projection(array $output): array
    {
        $tz = (!empty($output['timezone']) && !empty($output['utc_offset']))
            ? ($output['timezone'] . ' (' . $output['utc_offset'] . ')')
            : null;
        $coords = (!empty($output['latitude']) && !empty($output['longitude']))
            ? ($output['latitude'] . '(lat)/' . $output['longitude'] . '(long)')
            : null;

        return [
            'country'     => empty($output['country_name']) ? null : (string) $output['country_name'],
            'city'        => empty($output['city'])         ? null : (string) $output['city'],
            'zip'         => empty($output['postal'])       ? null : (string) $output['postal'],
            'isp'         => empty($output['org'])          ? null : (string) $output['org'],
            'timezone'    => $tz,
            'coordinates' => $coords,
        ];
    }
}

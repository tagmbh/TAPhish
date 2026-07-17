<?php

/**
 * Phase 2 — unified Reports generator: the per-type report contract.
 *
 * One report page picks any tracker (web or quick) via the shared
 * list_all_trackers feed, then branches on the tracker's type. This pure helper
 * returns everything the unified client needs to drive the correct server feed
 * and render the correct columns:
 *   - manager/action : the DataTables serverSide endpoint for that type
 *   - hasPageSelector: web trackers have a per-page (#reportTypeSelector) view
 *   - dict           : fixed column id → label map (copied verbatim from
 *                      quick_tracker_report.js:4 / web_tracker_report_functions.js:4)
 *
 * Web reports additionally build dynamic Field-<name> columns per page at
 * runtime (from get_web_tracker_from_id); those are not part of this fixed dict.
 */

if (!function_exists('taphish_report_config')) {
    function taphish_report_config(string $type): ?array
    {
        $map = [
            'quick' => [
                'manager'         => 'manager/quick_tracker_manager',
                'action'          => 'get_quick_tracker_data',
                'hasPageSelector' => false,
                'dict'            => [
                    'rid' => 'RID', 'public_ip' => 'Public IP', 'mail_client' => 'Mail Client/Browser',
                    'platform' => 'Platform', 'device_type' => 'Device Type', 'all_headers' => 'HTTP Headers',
                    'user_agent' => 'User Agent', 'time' => 'Hit Time', 'country' => 'Country', 'city' => 'City',
                    'zip' => 'Zip', 'isp' => 'ISP', 'timezone' => 'Timezone', 'coordinates' => 'Coordinates',
                ],
            ],
            'web' => [
                'manager'         => 'manager/tracker_report_manager',
                'action'          => 'get_table_webpage_visit_form_submission',
                'hasPageSelector' => true,
                'dict'            => [
                    'rid' => 'RID', 'session_id' => 'Session ID', 'public_ip' => 'Public IP',
                    'user_agent' => 'User Agent', 'time' => 'Hit Time', 'browser' => 'Browser',
                    'platform' => 'Platform', 'screen_res' => 'Screen Res', 'device_type' => 'Device Type',
                    'country' => 'Country', 'city' => 'City', 'zip' => 'Zip', 'isp' => 'ISP',
                    'timezone' => 'Timezone', 'coordinates' => 'Coordinates',
                ],
            ],
        ];
        return $map[$type] ?? null;
    }
}

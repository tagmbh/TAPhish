<?php
// P2.1 — unified tracker list. One view over both tracker types (web = multi-page
// visit/form capture; quick = open-pixel beacon), type-tagged so a single page can
// list them with a Type column + filter. Read-only aggregation; the per-type CRUD
// still lives in the existing managers.

if (!function_exists('taphish_tracker_list_normalize')) {
    /**
     * Pure merge: tag each tracker row with its type and normalise to a common
     * shape, web rows first then quick (each already date-desc from SQL; the
     * client DataTable does cross-type sorting).
     *
     * @return list<array{type:string,tracker_id:string,tracker_name:string,active:int,date:mixed,start_time:mixed,stop_time:mixed,engagement_id:?int}>
     */
    function taphish_tracker_list_normalize(array $web, array $quick): array
    {
        $out = [];
        foreach (['web' => $web, 'quick' => $quick] as $type => $rows) {
            foreach ($rows as $r) {
                $eid = $r['engagement_id'] ?? null;
                $out[] = [
                    'type'          => $type,
                    'tracker_id'    => (string) ($r['tracker_id'] ?? ''),
                    'tracker_name'  => (string) ($r['tracker_name'] ?? ''),
                    'active'        => isset($r['active']) ? (int) $r['active'] : 0,
                    'date'          => $r['date'] ?? null,
                    'start_time'    => $r['start_time'] ?? null,
                    'stop_time'     => $r['stop_time'] ?? null,
                    'engagement_id' => ($eid === null || $eid === '') ? null : (int) $eid,
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('taphish_all_trackers')) {
    /**
     * Fetch ALL web + quick trackers (any engagement), type-tagged + localized.
     * $localize maps a stored timestamp string to the client tz (pass the app's
     * getInClientTime_FD-style closure); null leaves the raw value.
     */
    function taphish_all_trackers(\mysqli $conn, ?callable $localize = null): array
    {
        $fetch = static function (string $sql) use ($conn): array {
            $rows = [];
            $res = @$conn->query($sql);
            if ($res instanceof \mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
            return $rows;
        };
        $web   = $fetch("SELECT tracker_id, tracker_name, active, date, start_time, stop_time, engagement_id FROM tb_core_web_tracker_list ORDER BY date DESC");
        $quick = $fetch("SELECT tracker_id, tracker_name, active, date, start_time, stop_time, engagement_id FROM tb_core_quick_tracker_list ORDER BY date DESC");
        $out = taphish_tracker_list_normalize($web, $quick);
        if ($localize !== null) {
            foreach ($out as &$row) {
                foreach (['date', 'start_time', 'stop_time'] as $c) {
                    if (!empty($row[$c])) {
                        $row[$c] = $localize($row[$c]);
                    }
                }
            }
            unset($row);
        }
        return $out;
    }
}

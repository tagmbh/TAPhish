<?php
/**
 * R2.2 — Campaign-list engagement filtering + annotation. Pure helpers so the
 * flat Email-Campaign list can scope to one engagement (and show which
 * engagement each campaign belongs to). DB-free; loaded by helpers_shim.
 */

if (!function_exists('taphish_campaigns_filter_by_engagement')) {
    /**
     * Keep only campaigns for engagement $engId. A 0/null id means "no filter"
     * (all campaigns). Result is re-indexed so json_encode emits an array.
     *
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    function taphish_campaigns_filter_by_engagement(array $rows, ?int $engId): array
    {
        if (!$engId) {
            return $rows;
        }
        return array_values(array_filter($rows, static function ($r) use ($engId) {
            return (int) ($r['engagement_id'] ?? 0) === $engId;
        }));
    }
}

if (!function_exists('taphish_campaigns_annotate_engagement')) {
    /**
     * Add an `engagement_name` to each row from a {id => name} map. Unscoped rows
     * (no engagement_id) get ''; a scoped id missing from the map falls back to
     * '#<id>' so the row is never mislabelled as unscoped.
     *
     * @param array<int,array> $rows
     * @param array<int,string> $engMap
     * @return array<int,array>
     */
    function taphish_campaigns_annotate_engagement(array $rows, array $engMap): array
    {
        foreach ($rows as &$r) {
            $eid = (int) ($r['engagement_id'] ?? 0);
            $r['engagement_name'] = $eid > 0
                ? (string) ($engMap[$eid] ?? ('#' . $eid))
                : '';
        }
        unset($r);
        return $rows;
    }
}

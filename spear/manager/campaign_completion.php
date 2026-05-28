<?php
/**
 * Pure helpers for the campaign auto-complete check.
 *
 * The cron worker polls campaigns in the "mail-done, tracking-phase" state
 * (camp_status = 4) and transitions them to "completed" (camp_status = 3)
 * once the share of recipients that engaged with the email crosses the
 * operator-configured threshold.
 *
 * Engagement is currently defined as "the recipient opened the email at
 * least once" (mail_open_times non-empty). Future Phase 3 work can extend
 * this to also count web-tracker form submissions or clicks.
 *
 * No DB, no session. Tested in tests/CampaignCompletionTest.php.
 */

if (!function_exists('auto_complete_should_trigger')) {
    /**
     * Decide whether an in-tracking campaign should be auto-completed.
     *
     * Returns true when:
     *   - threshold > 0 (a 0 threshold disables the check entirely)
     *   - total > 0 (don't complete an empty campaign — that's a config bug)
     *   - opened / total * 100 ≥ threshold
     */
    function auto_complete_should_trigger(int $opened, int $total, int $thresholdPercent): bool
    {
        if ($thresholdPercent <= 0 || $total <= 0) {
            return false;
        }
        if ($opened < 0 || $opened > $total) {
            return false;
        }
        return ($opened * 100) >= ($thresholdPercent * $total);
    }
}

if (!function_exists('auto_complete_clamp_threshold')) {
    /**
     * Normalize an operator-supplied threshold value: integer between 0 and
     * 100, defaulting to 100 when the input is missing or malformed.
     *
     * @param mixed $raw
     */
    function auto_complete_clamp_threshold($raw): int
    {
        if (!is_numeric($raw)) {
            return 100;
        }
        $v = (int) $raw;
        if ($v < 0) {
            return 0;
        }
        if ($v > 100) {
            return 100;
        }
        return $v;
    }
}

if (!function_exists('auto_complete_canonical_metric')) {
    /**
     * Phase 3.15: which engagement signals count toward the auto-complete
     * threshold. Accepts the operator-supplied raw value (anything else
     * normalizes to 'opens', the Phase 3.3 behavior).
     *
     * @param mixed $raw
     * @return string  one of 'opens' | 'opens_clicks' | 'opens_clicks_submits'
     */
    function auto_complete_canonical_metric($raw): string
    {
        $allowed = ['opens', 'opens_clicks', 'opens_clicks_submits'];
        if (is_string($raw) && in_array($raw, $allowed, true)) {
            return $raw;
        }
        return 'opens';
    }
}

if (!function_exists('auto_complete_signals_for_metric')) {
    /**
     * Decompose a metric into the constituent boolean signals so the SQL
     * builder knows which tables to consult.
     *
     * @return array{opens: bool, clicks: bool, submits: bool}
     */
    function auto_complete_signals_for_metric(string $metric): array
    {
        $m = auto_complete_canonical_metric($metric);
        return [
            'opens'   => true, // always counted; it's the baseline signal
            'clicks'  => in_array($m, ['opens_clicks', 'opens_clicks_submits'], true),
            'submits' => $m === 'opens_clicks_submits',
        ];
    }
}

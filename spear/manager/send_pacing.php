<?php
/**
 * Pure send-pacing helpers for the mail campaign cron.
 *
 * Extracted so the send loop can't be killed mid-campaign by a malformed
 * cadence config. Both helpers are total functions: any garbage in yields a
 * safe, well-ordered result rather than a PHP 8 DivisionByZeroError /
 * ValueError that would abort the run with some recipients sent and some not.
 */

if (!function_exists('taphish_msg_interval_bounds_ms')) {
    /**
     * Parse a "min-max" seconds interval (as entered in the campaign UI) into
     * a clamped [minMs, maxMs] millisecond pair suitable for rand().
     *
     * Robust against the shapes that used to crash `rand(a,b)`:
     *   ""          → [0, 0]           (no delay)
     *   "5"         → [5000, 5000]     (single value = fixed delay)
     *   "3-7"       → [3000, 7000]
     *   "7-3"       → [3000, 7000]     (reversed → reordered, never min>max)
     *   "x-y"       → [0, 0]           (non-numeric → no delay)
     *
     * @return array{0:int,1:int}  [minMs, maxMs], always 0 <= min <= max
     */
    function taphish_msg_interval_bounds_ms(?string $interval): array
    {
        $interval = trim((string) $interval);
        if ($interval === '') {
            return [0, 0];
        }
        $parts = explode('-', $interval, 2);
        $min = is_numeric(trim($parts[0])) ? (float) trim($parts[0]) : 0.0;
        $max = isset($parts[1]) && is_numeric(trim($parts[1])) ? (float) trim($parts[1]) : $min;
        $minMs = (int) round(max(0.0, $min) * 1000);
        $maxMs = (int) round(max(0.0, $max) * 1000);
        if ($minMs > $maxMs) {
            [$minMs, $maxMs] = [$maxMs, $minMs];
        }
        return [$minMs, $maxMs];
    }
}

if (!function_exists('taphish_antiflood_limit_sane')) {
    /**
     * Sanitise the anti-flood "send N then pause" limit. A missing / zero /
     * negative / non-numeric limit means "no anti-flood batching" — the
     * caller must treat <= 0 as disabled and NEVER use it as a modulo divisor
     * (`$i % 0` is a fatal DivisionByZeroError on PHP 8).
     *
     * @return int  >= 0; 0 means "anti-flood disabled".
     */
    function taphish_antiflood_limit_sane($limit): int
    {
        if (!is_numeric($limit)) {
            return 0;
        }
        $n = (int) $limit;
        return $n > 0 ? $n : 0;
    }
}

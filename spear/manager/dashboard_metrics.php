<?php
/**
 * Phase 3.58 — Home dashboard headline metrics.
 *
 * The dashboard metric strip (dashboard.js renderMetrics) already renders
 * `data.metrics.open_rate` / `data.metrics.click_rate` and falls back to the
 * em-dash placeholder when they're absent. Until now nothing computed them, so
 * the rate tiles always showed "—". This file computes the open rate from
 * tb_data_mailcamp_live (scanner traffic excluded, matching the Phase 3.45a
 * customer-report KPI), and is forward-compatible with a click/capture rate
 * (pass $captured once its definition is pinned down).
 *
 * The pure reducer (taphish_home_metrics_rates) is side-effect-free and
 * unit-tested. The DB facade (taphish_home_metrics) throws on query failure so
 * the caller can omit the `metrics` key entirely rather than surface a false 0 %.
 */

if (!function_exists('taphish_home_metrics_rates')) {
    /**
     * Turn aggregate counts into dashboard rates. A null denominator-result
     * (nothing sent yet) yields a null rate, which the JS renders as "—".
     * `click_rate` is only included when $captured is provided (forward-compat).
     *
     * @return array{sent:int,opened:int,open_rate:?float,captured?:int,click_rate?:?float}
     */
    function taphish_home_metrics_rates(int $sent, int $opened, ?int $captured = null): array
    {
        $rate = static fn (int $n, int $d): ?float => $d > 0 ? round(($n / $d) * 100, 1) : null;
        $out = [
            'sent'      => $sent,
            'opened'    => $opened,
            'open_rate' => $rate($opened, $sent),
        ];
        if ($captured !== null) {
            $out['captured']   = $captured;
            $out['click_rate'] = $rate($captured, $sent);
        }
        return $out;
    }
}

if (!function_exists('taphish_home_metrics')) {
    /**
     * Aggregate the open rate across every mail-campaign recipient. Counts only
     * successfully-sent rows (sending_status=2); an "open" is a non-scanner row
     * with a non-empty mail_open_times. Throws on query failure (e.g. a missing
     * column) so the caller falls back to no-metrics rather than a false 0 %.
     */
    function taphish_home_metrics(\mysqli $conn): array
    {
        $count = static function (\mysqli $c, string $sql): int {
            $r = $c->query($sql);
            if ($r === false) {
                throw new \RuntimeException('dashboard metrics query failed: ' . $c->error);
            }
            $row = $r->fetch_assoc();
            return (int) ($row['c'] ?? 0);
        };

        $sent = $count($conn, "SELECT COUNT(*) AS c FROM tb_data_mailcamp_live WHERE sending_status = 2");
        $opened = $count(
            $conn,
            "SELECT COUNT(*) AS c FROM tb_data_mailcamp_live
             WHERE sending_status = 2
               AND COALESCE(is_scanner, 0) = 0
               AND mail_open_times IS NOT NULL
               AND mail_open_times <> ''
               AND mail_open_times <> '[]'"
        );

        return taphish_home_metrics_rates($sent, $opened);
    }
}

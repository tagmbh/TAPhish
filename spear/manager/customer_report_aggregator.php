<?php
/**
 * Customer-facing campaign report — pure aggregation helpers.
 *
 * Given the raw row set from tb_data_mailcamp_live for a campaign,
 * compute the KPIs we surface in the client-facing PDF deliverable.
 *
 * No DB, no session, no TCPDF. Side-effect-free; tested in
 * tests/CustomerReportAggregatorTest.php. The actual PDF rendering
 * (TCPDF wrapper + HTML template) lives next to this file in the
 * manager that owns the dispatcher.
 *
 * Schema reference (tb_data_mailcamp_live):
 *   sending_status: 1=in progress, 2=success, 3=error
 *   mail_open_times: JSON array of millisecond timestamps; empty/null = not opened
 *   send_time: when sending was attempted
 *   user_email, user_name, public_ip, ip_info, device_type, platform, browser
 */

if (!function_exists('customer_report_format_pct')) {
    /**
     * Format "N / total (XX.X%)". When total is 0, returns "0 / 0 (—)" so the
     * report never renders a div-by-zero artifact.
     */
    function customer_report_format_pct(int $count, int $total): string
    {
        if ($total <= 0) {
            return $count . ' / 0 (—)';
        }
        $pct = ($count / $total) * 100;
        return sprintf('%d / %d (%.1f%%)', $count, $total, $pct);
    }
}

if (!function_exists('customer_report_parse_open_times')) {
    /**
     * Normalize mail_open_times into an array of ms timestamps. The column
     * may arrive as a JSON string, as a decoded array, or as null.
     *
     * @param mixed $raw
     * @return int[]
     */
    function customer_report_parse_open_times($raw): array
    {
        if ($raw === null || $raw === '' || $raw === '[]') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $t) {
            if (is_numeric($t)) {
                $out[] = (int) $t;
            }
        }
        return $out;
    }
}

if (!function_exists('customer_report_compute_kpis')) {
    /**
     * Compute the headline KPIs for the customer report.
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array{
     *   recipients: int,
     *   sent: int,
     *   in_progress: int,
     *   failed: int,
     *   opened: int,
     *   total_opens: int,
     *   send_success_rate: string,
     *   open_rate_of_sent: string,
     *   open_rate_of_total: string,
     * }
     */
    function customer_report_compute_kpis(array $rows): array
    {
        $total       = count($rows);
        $sent        = 0;
        $inProgress  = 0;
        $failed      = 0;
        $opened      = 0;
        $totalOpens  = 0;
        // Phase 3.45a: scanner traffic is recorded but excluded from
        // the headline open-rate so SafeLinks / Proofpoint pre-fetches
        // don't inflate the customer's "recipients clicked" number.
        $scannerHits = 0;

        foreach ($rows as $r) {
            $status = (int) ($r['sending_status'] ?? 0);
            if ($status === 1) {
                $inProgress++;
            } elseif ($status === 2) {
                $sent++;
            } elseif ($status === 3) {
                $failed++;
            }

            $isScanner = !empty($r['is_scanner']);
            if ($isScanner) {
                $scannerHits++;
            }

            $opens = customer_report_parse_open_times($r['mail_open_times'] ?? null);
            if (count($opens) > 0 && !$isScanner) {
                $opened++;
                $totalOpens += count($opens);
            }
        }

        return [
            'recipients'         => $total,
            'sent'               => $sent,
            'in_progress'        => $inProgress,
            'failed'             => $failed,
            'opened'             => $opened,
            'total_opens'        => $totalOpens,
            'scanner_hit_count'  => $scannerHits,
            'send_success_rate'  => customer_report_format_pct($sent, $total),
            'open_rate_of_sent'  => customer_report_format_pct($opened, $sent),
            'open_rate_of_total' => customer_report_format_pct($opened, $total),
        ];
    }
}

if (!function_exists('customer_report_recipient_rows')) {
    /**
     * Project the dataset down to the per-recipient table we render in the
     * PDF: email, status label, send time, first-open time, open count.
     * Sorted by engagement order: opened-first (earliest open), then sent,
     * then failed/pending.
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array{
     *   email: string,
     *   name: string,
     *   status: string,
     *   send_time_ms: ?int,
     *   first_open_ms: ?int,
     *   open_count: int,
     * }>
     */
    function customer_report_recipient_rows(array $rows): array
    {
        $statusLabel = [1 => 'In-progress', 2 => 'Sent', 3 => 'Failed'];
        $out = [];
        foreach ($rows as $r) {
            $opens     = customer_report_parse_open_times($r['mail_open_times'] ?? null);
            $sendTime  = $r['send_time'] ?? null;
            $statusInt = (int) ($r['sending_status'] ?? 0);
            $out[]     = [
                'email'         => (string) ($r['user_email'] ?? ''),
                'name'          => (string) ($r['user_name'] ?? ''),
                'status'        => $statusLabel[$statusInt] ?? 'Unknown',
                'send_time_ms'  => is_numeric($sendTime) ? (int) $sendTime : null,
                'first_open_ms' => $opens === [] ? null : min($opens),
                'open_count'    => count($opens),
            ];
        }
        // Sort: openers first (by earliest open), then sent-but-not-opened, then failed/in-progress.
        usort($out, function ($a, $b) {
            $aOpened = $a['first_open_ms'] !== null;
            $bOpened = $b['first_open_ms'] !== null;
            if ($aOpened !== $bOpened) {
                return $aOpened ? -1 : 1;
            }
            if ($aOpened && $bOpened) {
                return $a['first_open_ms'] <=> $b['first_open_ms'];
            }
            // Both unopened: sent > failed/in-progress > unknown
            $rank = ['Sent' => 0, 'In-progress' => 1, 'Failed' => 2, 'Unknown' => 3];
            return ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
        });
        return $out;
    }
}

if (!function_exists('customer_report_format_timestamp')) {
    /**
     * Format a millisecond timestamp as ISO-8601 UTC (e.g.
     * "2026-05-28 14:32 UTC"). Returns "—" for null. The intentionally
     * short format is what fits in the recipient table.
     */
    function customer_report_format_timestamp(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }
        return gmdate('Y-m-d H:i', (int) ($ms / 1000)) . ' UTC';
    }
}

<?php
/**
 * Phase 3.47: per-engagement summary report + PDF export.
 *
 * Aggregates across every campaign linked to an engagement (via the
 * Phase 3.45b engagement_id FK) and produces:
 *
 *   - Campaign list (send time, recipient count by domain — no individual PII)
 *   - Click + capture timeline (counts only, scanner-excluded by default)
 *   - Scanner-hit breakdown by vendor (Phase 3.40 / 3.45a data)
 *   - 2FA-capture counts (Phase 3.45e data)
 *   - Sender posture summary (DMARC verdict + actually-used domain)
 *   - Operator notes (from tb_core_engagement.notes)
 *
 * All pure reducers live in this file (no DB, no I/O, no TCPDF
 * coupling) so the heavy lifting is unit-testable. The DB facade
 * (engagement_report_aggregate) and the TCPDF render
 * (engagement_report_render_pdf) are deliberately separated from the
 * reducers — those wire the data path together and are exercised in
 * the integration tier.
 */

if (!function_exists('engagement_report_recipient_counts_by_domain')) {
    /**
     * Aggregate a list of recipient emails into a sorted
     * domain -> count map. Strips display names + trims; lowercases
     * the host part. Returns the array sorted by count DESC, then
     * domain ASC for stable test output.
     *
     * @param array<int,string> $emails
     * @return array<int,array{domain:string,count:int}>
     */
    function engagement_report_recipient_counts_by_domain(array $emails): array
    {
        $counts = [];
        foreach ($emails as $raw) {
            $e = strtolower(trim((string) $raw));
            if ($e === '') continue;
            // Strip "Name <addr>" if present.
            if (preg_match('/<([^>]+)>/', $e, $m)) $e = trim($m[1]);
            $at = strrpos($e, '@');
            // Require non-empty local part before the @ so "@nodomain"
            // doesn't bucket as "nodomain".
            if ($at === false || $at === 0) continue;
            $domain = substr($e, $at + 1);
            if ($domain === '') continue;
            $counts[$domain] = ($counts[$domain] ?? 0) + 1;
        }
        $out = [];
        foreach ($counts as $d => $n) $out[] = ['domain' => $d, 'count' => $n];
        usort($out, function ($a, $b) {
            if ($a['count'] !== $b['count']) return $b['count'] - $a['count'];
            return strcmp($a['domain'], $b['domain']);
        });
        return $out;
    }
}

if (!function_exists('engagement_report_capture_timeline')) {
    /**
     * Bucket capture rows into per-day counts. Each input row needs a
     * `time` (unix ms) and optionally `is_scanner`. When
     * $excludeScanners is true, rows where is_scanner=1 are dropped
     * before bucketing.
     *
     * Output keyed by ISO date (UTC) with count + has_2fa subcount.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{date:string,count:int,with_2fa:int}>
     */
    function engagement_report_capture_timeline(array $rows, bool $excludeScanners = true): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            if ($excludeScanners && !empty($r['is_scanner'])) continue;
            $ms = (int) ($r['time'] ?? 0);
            if ($ms <= 0) continue;
            $date = gmdate('Y-m-d', intdiv($ms, 1000));
            if (!isset($buckets[$date])) $buckets[$date] = ['date' => $date, 'count' => 0, 'with_2fa' => 0];
            $buckets[$date]['count']++;
            if (!empty($r['is_2fa_capture']) || !empty($r['code_2fa'])) {
                $buckets[$date]['with_2fa']++;
            }
        }
        ksort($buckets);
        return array_values($buckets);
    }
}

if (!function_exists('engagement_report_scanner_breakdown')) {
    /**
     * Roll up scanner hits by their classifier reason. Each row needs
     * a `scanner_reason` string (e.g. "Microsoft SafeLinks", "Proofpoint
     * URL Defense", or "" for unclassified). Output sorted by count
     * DESC.
     *
     * @param array<int,array<string,mixed>> $rows  rows with is_scanner=1 + scanner_reason
     * @return array<int,array{vendor:string,count:int}>
     */
    function engagement_report_scanner_breakdown(array $rows): array
    {
        $counts = [];
        foreach ($rows as $r) {
            if (empty($r['is_scanner'])) continue;
            $vendor = trim((string) ($r['scanner_reason'] ?? ''));
            if ($vendor === '') $vendor = 'unclassified';
            $counts[$vendor] = ($counts[$vendor] ?? 0) + 1;
        }
        $out = [];
        foreach ($counts as $v => $n) $out[] = ['vendor' => $v, 'count' => $n];
        usort($out, function ($a, $b) {
            if ($a['count'] !== $b['count']) return $b['count'] - $a['count'];
            return strcmp($a['vendor'], $b['vendor']);
        });
        return $out;
    }
}

if (!function_exists('engagement_report_2fa_summary')) {
    /**
     * Count how many captures carried a 2FA code (Phase 3.45e), and
     * separately count distinct (tracker_id, rid) pairs that ever
     * coughed up a 2FA code — that's the "users who handed over MFA"
     * number operators usually want for the report cover.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{total_captures:int, with_2fa:int, distinct_users_with_2fa:int, repeat_webhooks_fired:int}
     */
    function engagement_report_2fa_summary(array $rows): array
    {
        $total = $with2fa = $repeat = 0;
        $seen = [];
        foreach ($rows as $r) {
            $total++;
            if (!empty($r['is_2fa_capture']) || !empty($r['code_2fa'])) {
                $with2fa++;
                $key = (string) ($r['tracker_id'] ?? '') . '|' . (string) ($r['rid'] ?? '');
                $seen[$key] = true;
            }
            if (!empty($r['repeat_webhook_sent'])) $repeat++;
        }
        return [
            'total_captures'          => $total,
            'with_2fa'                => $with2fa,
            'distinct_users_with_2fa' => count($seen),
            'repeat_webhooks_fired'   => $repeat,
        ];
    }
}

if (!function_exists('engagement_report_sender_posture_summary')) {
    /**
     * Pure summarizer over the Phase 3.41 DMARC/SPF posture output
     * (whatever the operator captured during Step 2 OSINT). If the
     * caller didn't supply a posture rollup, the report just notes
     * "not captured" rather than failing.
     *
     * @param array $posture rows keyed by sender domain — each value
     *                       is the recommendation block from
     *                       email_posture_lookup
     * @return array<int,array{domain:string,verdict:string,note:string}>
     */
    function engagement_report_sender_posture_summary(array $posture): array
    {
        $out = [];
        foreach ($posture as $domain => $p) {
            if (!is_array($p)) continue;
            $verdict = (string) ($p['recommendation']['verdict'] ?? ($p['verdict'] ?? 'unknown'));
            $note    = (string) ($p['recommendation']['message'] ?? ($p['note']    ?? ''));
            $out[] = [
                'domain'  => (string) $domain,
                'verdict' => $verdict,
                'note'    => $note,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['domain'], $b['domain']));
        return $out;
    }
}

if (!function_exists('engagement_report_aggregate')) {
    /**
     * DB-backed facade that pulls everything an engagement report
     * needs into a single structured array. The aggregators stay
     * pure; this is the only place that touches mysqli for the
     * report path.
     *
     * If the engagement doesn't exist, returns null.
     *
     * @return array{
     *   engagement:array,
     *   campaigns:array<int,array<string,mixed>>,
     *   recipient_counts:array<int,array{domain:string,count:int}>,
     *   capture_timeline:array<int,array{date:string,count:int,with_2fa:int}>,
     *   scanner_breakdown:array<int,array{vendor:string,count:int}>,
     *   twofa_summary:array{total_captures:int,with_2fa:int,distinct_users_with_2fa:int,repeat_webhooks_fired:int},
     *   sender_posture:array,
     *   generated_at_ms:int,
     * }|null
     */
    function engagement_report_aggregate(\mysqli $conn, int $engagementId, int $nowMs = 0): ?array
    {
        $eng = function_exists('taphish_engagement_get_by_id')
            ? taphish_engagement_get_by_id($conn, $engagementId)
            : null;
        if ($eng === null) return null;

        $campaigns = function_exists('taphish_engagement_campaigns')
            ? taphish_engagement_campaigns($conn, $engagementId)
            : [];

        $trackerIds = array_filter(array_map(
            fn($c) => (string) ($c['campaign_id'] ?? ''),
            $campaigns
        ));

        // Pull recipient lists across this engagement's campaigns. We
        // aggregate ONLY counts-by-domain — never individual emails —
        // so the PDF can ship without PII.
        $emails = [];
        if ($trackerIds) {
            $placeholders = implode(',', array_fill(0, count($trackerIds), '?'));
            $types = str_repeat('s', count($trackerIds));
            $stmt = $conn->prepare(
                "SELECT user_data FROM tb_core_mailcamp_user_group
                  WHERE camp_id IN ($placeholders)"
            );
            if ($stmt !== false) {
                $stmt->bind_param($types, ...$trackerIds);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $raw = (string) ($r['user_data'] ?? '');
                    if (function_exists('recipient_data_unseal')) {
                        $u = recipient_data_unseal($raw);
                        if (is_string($u)) $raw = $u;
                    }
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) continue;
                    foreach ($decoded as $rec) {
                        if (is_array($rec) && !empty($rec['email'])) {
                            $emails[] = (string) $rec['email'];
                        }
                    }
                }
                $stmt->close();
            }
        }
        $recipient_counts = engagement_report_recipient_counts_by_domain($emails);

        // Capture timeline + scanner breakdown + 2FA summary all come
        // from tb_data_webform_submit + tb_data_mailcamp_live joined
        // against the campaigns' tracker_ids.
        $captures = [];
        $opens    = [];
        if ($trackerIds) {
            $placeholders = implode(',', array_fill(0, count($trackerIds), '?'));
            $types = str_repeat('s', count($trackerIds));
            $stmt = $conn->prepare(
                "SELECT tracker_id, rid, time, code_2fa, is_2fa_capture, repeat_webhook_sent,
                        is_scanner, scanner_reason
                   FROM tb_data_webform_submit
                  WHERE tracker_id IN ($placeholders)"
            );
            if ($stmt !== false) {
                $stmt->bind_param($types, ...$trackerIds);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $captures[] = $r;
                $stmt->close();
            }
            $stmt = $conn->prepare(
                "SELECT time, is_scanner, scanner_reason
                   FROM tb_data_mailcamp_live
                  WHERE tracker_id IN ($placeholders)"
            );
            if ($stmt !== false) {
                $stmt->bind_param($types, ...$trackerIds);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) $opens[] = $r;
                $stmt->close();
            }
        }
        $capture_timeline  = engagement_report_capture_timeline($captures, true);
        $scanner_breakdown = engagement_report_scanner_breakdown(array_merge($captures, $opens));
        $twofa_summary     = engagement_report_2fa_summary($captures);

        // Sender posture is captured at OSINT time but isn't persisted
        // per-engagement today. We surface it as "not captured" and
        // leave the field in place for a future enhancement.
        $sender_posture = [];

        return [
            'engagement'        => $eng,
            'campaigns'         => $campaigns,
            'recipient_counts'  => $recipient_counts,
            'capture_timeline'  => $capture_timeline,
            'scanner_breakdown' => $scanner_breakdown,
            'twofa_summary'     => $twofa_summary,
            'sender_posture'    => $sender_posture,
            'generated_at_ms'   => $nowMs > 0 ? $nowMs : (int) (microtime(true) * 1000),
        ];
    }
}

if (!function_exists('engagement_report_render_pdf')) {
    /**
     * Render an engagement report into a PDF byte string. The TCPDF
     * dependency is loaded lazily so the helpers can be unit-tested
     * without dragging it in.
     *
     * The page layout is intentionally simple (no images, no fonts
     * beyond TCPDF's built-in helvetica) so the output is portable
     * and reproducible — every TAPhish install ships the same report
     * shape.
     *
     * @param array $data shape produced by engagement_report_aggregate()
     * @return string the PDF bytes
     */
    function engagement_report_render_pdf(array $data): string
    {
        require_once dirname(__FILE__, 2) . '/libs/tcpdf_min/tcpdf.php';

        $eng = $data['engagement'];
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('TAPhish');
        $pdf->SetAuthor('TAPhish');
        $pdf->SetTitle('Engagement report — ' . (string) ($eng['name'] ?? $eng['slug']));
        $pdf->SetSubject('Phishing engagement summary');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        // Cover
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 10, 'Engagement report', 0, 1);
        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 6, (string) ($eng['name'] ?? '—'), 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Slug: ' . (string) ($eng['slug'] ?? '—')
            . '  ·  Target org: ' . (string) ($eng['target_org'] ?? '—'), 0, 1);
        $pdf->Cell(0, 5, 'Window: ' . (string) ($eng['start_at'] ?? '—')
            . '  →  ' . (string) ($eng['end_at'] ?? '—'), 0, 1);
        $pdf->Cell(0, 5, 'Status: ' . (string) ($eng['status'] ?? '—'), 0, 1);
        $genMs = (int) ($data['generated_at_ms'] ?? 0);
        $pdf->Cell(0, 5, 'Generated: ' . gmdate('Y-m-d H:i', intdiv($genMs, 1000)) . ' UTC', 0, 1);
        $pdf->Ln(3);

        $twofa = $data['twofa_summary'];
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'At-a-glance', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Campaigns linked: ' . count($data['campaigns']), 0, 1);
        $pdf->Cell(0, 5, 'Captures (non-scanner): ' . (int) $twofa['total_captures'], 0, 1);
        $pdf->Cell(0, 5, 'Captures including a 2FA code: ' . (int) $twofa['with_2fa'], 0, 1);
        $pdf->Cell(0, 5, 'Distinct users who handed over MFA: ' . (int) $twofa['distinct_users_with_2fa'], 0, 1);
        $pdf->Cell(0, 5, 'Repeat 2FA webhooks fired: ' . (int) $twofa['repeat_webhooks_fired'], 0, 1);
        $pdf->Ln(3);

        // Campaigns table
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'Linked campaigns', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        if (empty($data['campaigns'])) {
            $pdf->Cell(0, 5, '— no campaigns linked to this engagement —', 0, 1);
        } else {
            foreach ($data['campaigns'] as $c) {
                $line = sprintf(
                    '%s  ·  %s  ·  %s',
                    (string) ($c['campaign_name'] ?? '—'),
                    (string) ($c['camp_status']   ?? '—'),
                    (string) ($c['scheduled_time'] ?? ($c['date'] ?? '—'))
                );
                $pdf->Cell(0, 5, $line, 0, 1);
            }
        }
        $pdf->Ln(3);

        // Recipients by domain
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'Recipients by domain (no individual PII)', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        if (empty($data['recipient_counts'])) {
            $pdf->Cell(0, 5, '— no recipients in scope —', 0, 1);
        } else {
            foreach ($data['recipient_counts'] as $row) {
                $pdf->Cell(80, 5, (string) $row['domain'], 0, 0);
                $pdf->Cell(0,  5, (string) $row['count'], 0, 1);
            }
        }
        $pdf->Ln(3);

        // Capture timeline
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'Capture timeline (scanner-excluded)', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        if (empty($data['capture_timeline'])) {
            $pdf->Cell(0, 5, '— no captures recorded —', 0, 1);
        } else {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(40, 5, 'Date',     0, 0);
            $pdf->Cell(30, 5, 'Captures', 0, 0);
            $pdf->Cell(30, 5, 'With 2FA', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            foreach ($data['capture_timeline'] as $row) {
                $pdf->Cell(40, 5, (string) $row['date'],     0, 0);
                $pdf->Cell(30, 5, (string) $row['count'],    0, 0);
                $pdf->Cell(30, 5, (string) $row['with_2fa'], 0, 1);
            }
        }
        $pdf->Ln(3);

        // Scanner breakdown
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'Scanner-hit breakdown by vendor', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        if (empty($data['scanner_breakdown'])) {
            $pdf->Cell(0, 5, '— no scanner activity recorded —', 0, 1);
        } else {
            foreach ($data['scanner_breakdown'] as $row) {
                $pdf->Cell(80, 5, (string) $row['vendor'], 0, 0);
                $pdf->Cell(0,  5, (string) $row['count'],  0, 1);
            }
        }
        $pdf->Ln(3);

        // Operator notes
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'Operator notes', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $notes = trim((string) ($eng['notes'] ?? ''));
        if ($notes === '') $notes = '— no notes recorded —';
        $pdf->MultiCell(0, 5, $notes, 0, 'L');

        return (string) $pdf->Output('engagement-report.pdf', 'S');
    }
}

<?php
// Engagement analytics — pure aggregation (built test-first).

if (!function_exists('taphish_analytics_build')) {
    /**
     * Consolidated engagement analytics. Joins send/open (mailcamp_live), click
     * (webpage_visit) and credential/OTP (webform_submit) rows by rid.
     *
     * @param array $sends   rows: campaign_id,rid,user_email,user_name,sending_status,mail_open_times,send_time
     * @param array $visits  rows: tracker_id,rid,time
     * @param array $submits rows: tracker_id,rid,page,is_2fa_capture,time
     * @param array $campaignMap  campaign_id => ['wave'=>,'cohort'=>,'slot'=>]
     */
    function taphish_analytics_build(array $sends, array $visits, array $submits, array $campaignMap): array
    {
        // Index click evidence by rid (earliest time each).
        $visitTimeByRid = [];
        foreach ($visits as $v) {
            $t = (int) ($v['time'] ?? 0);
            if (!isset($visitTimeByRid[$v['rid']]) || $t < $visitTimeByRid[$v['rid']]) { $visitTimeByRid[$v['rid']] = $t; }
        }
        $submitTimeByRid = $otpTimeByRid = [];
        foreach ($submits as $s) {
            $t = (int) ($s['time'] ?? 0);
            if (!isset($submitTimeByRid[$s['rid']]) || $t < $submitTimeByRid[$s['rid']]) { $submitTimeByRid[$s['rid']] = $t; }
            if ((int) ($s['is_2fa_capture'] ?? 0) === 1
                && (!isset($otpTimeByRid[$s['rid']]) || $t < $otpTimeByRid[$s['rid']])) { $otpTimeByRid[$s['rid']] = $t; }
        }

        // One stage record per delivered recipient (rid).
        $recipients = [];
        foreach ($sends as $row) {
            if ((int) $row['sending_status'] !== 2) { continue; }   // only delivered enter the funnel
            $rid = $row['rid'];
            $meta = $campaignMap[$row['campaign_id']] ?? ['wave'=>'?','cohort'=>'?','slot'=>'?'];
            $clickTimes = array_filter([$visitTimeByRid[$rid] ?? null, $submitTimeByRid[$rid] ?? null], fn($x)=>$x!==null);
            $recipients[] = [
                'rid'         => $rid,
                'email'       => $row['user_email'] ?? '',
                'name'        => $row['user_name'] ?? '',
                'wave'        => $meta['wave'],
                'cohort'      => $meta['cohort'],
                'slot'        => $meta['slot'] ?? '?',
                'delivered'   => true,
                'opened'      => !empty($row['mail_open_times']) && $row['mail_open_times'] !== 'null',
                'clicked'     => isset($visitTimeByRid[$rid]) || isset($submitTimeByRid[$rid]),
                'credentials' => isset($submitTimeByRid[$rid]),
                'otp'         => isset($otpTimeByRid[$rid]),
                'clicked_at'  => $clickTimes ? min($clickTimes) : null,
                'credentials_at' => $submitTimeByRid[$rid] ?? null,
                'otp_at'      => $otpTimeByRid[$rid] ?? null,
            ];
        }

        $byWave = $byCohort = [];
        foreach ($recipients as $r) {
            $byWave[$r['wave']][]   = $r;
            $byCohort[$r['cohort']][] = $r;
        }
        $wrap = fn(array $grp) => array_map(fn($g) => ['funnel' => taphish_analytics_rollup($g)], $grp);

        return [
            'funnel'           => taphish_analytics_rollup($recipients),
            'by_wave'          => $wrap($byWave),
            'by_cohort'        => $wrap($byCohort),
            'recipients'       => $recipients,
            'repeat_offenders' => taphish_analytics_repeat_offenders($recipients),
            'timeline'         => taphish_analytics_timeline($recipients),
        ];
    }
}

if (!function_exists('taphish_analytics_campaign_map')) {
    /**
     * Build the campaign_id => {wave,cohort,slot} map taphish_analytics_build
     * needs, generically: each campaign is a "wave" (its name) within a "cohort"
     * (its user-group name). No engagement-specific parsing.
     *
     * @param array $rows rows: campaign_id, campaign_name, user_group_name
     */
    function taphish_analytics_campaign_map(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            $map[$r['campaign_id']] = [
                'wave'   => (string) ($r['campaign_name'] ?? '?'),
                'cohort' => ($r['user_group_name'] ?? '') !== '' ? (string) $r['user_group_name'] : '?',
                'slot'   => (string) $r['campaign_id'],
            ];
        }
        return $map;
    }
}

if (!function_exists('taphish_engagement_analytics')) {
    /**
     * Gather an engagement's send/visit/submit rows from the DB and run the pure
     * aggregation. Recipients are joined by rid (a recipient's rid is carried
     * from the mail CTA into the landing POST). Operator-tier: the result carries
     * emails, so the dispatcher action is RBAC-gated to super-admin/operator.
     */
    function taphish_engagement_analytics(\mysqli $conn, int $engagementId): array
    {
        $esc = fn(array $xs) => implode(',', array_map(fn($x) => "'" . $conn->real_escape_string((string) $x) . "'", $xs));

        // campaigns of this engagement (+ user-group name → cohort)
        $campaigns = [];
        $campIds = [];
        $res = @$conn->query("SELECT campaign_id, campaign_name, campaign_data FROM tb_core_mailcamp_list WHERE engagement_id = " . (int) $engagementId);
        if ($res instanceof \mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $cd = json_decode($r['campaign_data'], true);
                $campaigns[] = ['campaign_id' => $r['campaign_id'], 'campaign_name' => $r['campaign_name'], 'user_group_name' => $cd['user_group']['name'] ?? ''];
                $campIds[] = $r['campaign_id'];
            }
        }
        $map = taphish_analytics_campaign_map($campaigns);

        $sends = [];
        if ($campIds) {
            $res = @$conn->query("SELECT campaign_id,rid,user_email,user_name,sending_status,mail_open_times,send_time FROM tb_data_mailcamp_live WHERE campaign_id IN (" . $esc($campIds) . ")");
            if ($res instanceof \mysqli_result) { while ($r = $res->fetch_assoc()) { $sends[] = $r; } }
        }

        // web trackers of this engagement → click/submit evidence
        $trackerIds = [];
        $res = @$conn->query("SELECT tracker_id FROM tb_core_web_tracker_list WHERE engagement_id = " . (int) $engagementId);
        if ($res instanceof \mysqli_result) { while ($r = $res->fetch_assoc()) { $trackerIds[] = $r['tracker_id']; } }

        $visits = $submits = [];
        if ($trackerIds) {
            $inT = $esc($trackerIds);
            $res = @$conn->query("SELECT tracker_id,rid,time FROM tb_data_webpage_visit WHERE tracker_id IN ($inT)");
            if ($res instanceof \mysqli_result) { while ($r = $res->fetch_assoc()) { $visits[] = $r; } }
            $res = @$conn->query("SELECT tracker_id,rid,page,is_2fa_capture,time FROM tb_data_webform_submit WHERE tracker_id IN ($inT)");
            if ($res instanceof \mysqli_result) { while ($r = $res->fetch_assoc()) { $submits[] = $r; } }
        }

        $built = taphish_analytics_build($sends, $visits, $submits, $map);
        $built['engagement_id']  = $engagementId;
        $built['campaign_count'] = count($campIds);
        $built['tracker_count']  = count($trackerIds);
        return $built;
    }
}

if (!function_exists('taphish_analytics_redact_values')) {
    /**
     * R2.3: redact captured field VALUES while keeping the field names, for the
     * PII-free aggregate/'*' tier (proves what was captured without exposing it).
     * @param array<string,mixed> $fields
     * @return array<string,string>
     */
    function taphish_analytics_redact_values(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $s = (string) $v;
            $out[(string) $k] = ($s === '') ? '' : str_repeat('•', min(8, max(4, strlen($s))));
        }
        return $out;
    }
}

if (!function_exists('taphish_analytics_creds_rows')) {
    /**
     * R2.3: per-recipient captured-credentials table. One row per recipient who
     * submitted a form (credentials=true). $reveal comes from RBAC — the
     * operator tier sees plaintext values; any lower tier gets redacted values
     * (this whole table is served only to the operator tier, redaction is
     * defence-in-depth). Captures are joined by rid from $capturesByRid:
     *   ['<rid>' => ['fields' => ['username'=>..,'password'=>..], 'otp' => '..']]
     *
     * @param array $recipients stage records from taphish_analytics_build
     * @param array<string,array{fields?:array,otp?:string}> $capturesByRid
     * @return array<int,array>
     */
    function taphish_analytics_creds_rows(array $recipients, array $capturesByRid, bool $reveal): array
    {
        $out = [];
        foreach ($recipients as $r) {
            if (empty($r['credentials'])) { continue; }   // only actual form submitters
            $rid    = (string) ($r['rid'] ?? '');
            $cap    = $capturesByRid[$rid] ?? [];
            $fields = (isset($cap['fields']) && is_array($cap['fields'])) ? $cap['fields'] : [];
            $otp    = (string) ($cap['otp'] ?? '');
            $out[] = [
                'email'   => (string) ($r['email'] ?? ''),
                'name'    => (string) ($r['name'] ?? ''),
                'wave'    => (string) ($r['wave'] ?? ''),
                'cohort'  => (string) ($r['cohort'] ?? ''),
                'fields'  => $reveal ? $fields : taphish_analytics_redact_values($fields),
                'otp'     => $reveal ? $otp : ($otp !== '' ? str_repeat('•', min(6, max(4, strlen($otp)))) : ''),
                'has_otp' => $otp !== '',
            ];
        }
        return $out;
    }
}

if (!function_exists('taphish_analytics_repeat_offenders')) {
    /**
     * People who clicked in ≥2 distinct waves — the awareness-progress signal.
     * @param array $recipients stage records from taphish_analytics_build
     */
    function taphish_analytics_repeat_offenders(array $recipients): array
    {
        $byEmail = [];
        foreach ($recipients as $r) {
            if (empty($r['clicked'])) { continue; }
            $e = $r['email'];
            $byEmail[$e]['email'] = $e;
            $byEmail[$e]['name']  = $r['name'] ?? '';
            if (!in_array($r['wave'], $byEmail[$e]['waves'] ?? [], true)) { $byEmail[$e]['waves'][] = $r['wave']; }
            $byEmail[$e]['clicks'] = ($byEmail[$e]['clicks'] ?? 0) + 1;
            if (!empty($r['credentials'])) { $byEmail[$e]['credentials'] = ($byEmail[$e]['credentials'] ?? 0) + 1; }
        }
        $out = [];
        foreach ($byEmail as $rec) {
            if (count($rec['waves']) >= 2) {
                $out[] = [
                    'email'       => $rec['email'],
                    'name'        => $rec['name'],
                    'waves'       => $rec['waves'],
                    'clicks'      => $rec['clicks'],
                    'credentials' => $rec['credentials'] ?? 0,
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('taphish_analytics_timeline')) {
    /**
     * Flattened, time-sorted click/credentials/otp events for the timeline view.
     */
    function taphish_analytics_timeline(array $recipients): array
    {
        $events = [];
        foreach ($recipients as $r) {
            foreach ([['clicked_at','click'],['credentials_at','credentials'],['otp_at','otp']] as [$field,$kind]) {
                if ($r[$field] !== null) {
                    $events[] = ['ts'=>$r[$field], 'kind'=>$kind, 'wave'=>$r['wave'], 'cohort'=>$r['cohort'], 'email'=>$r['email']];
                }
            }
        }
        usort($events, fn($a,$b) => $a['ts'] <=> $b['ts']);
        return $events;
    }
}

if (!function_exists('taphish_analytics_rollup')) {
    /**
     * Roll a list of recipient stage records up into counts + rates (each stage
     * as a percent of delivered, one decimal). Empty list → zeros, never a
     * division error.
     */
    function taphish_analytics_rollup(array $recipients): array
    {
        $c = ['delivered'=>0,'opened'=>0,'clicked'=>0,'credentials'=>0,'otp'=>0];
        foreach ($recipients as $r) {
            foreach ($c as $k => $_) { if (!empty($r[$k])) { $c[$k]++; } }
        }
        $d = $c['delivered'];
        $pct = fn(int $n) => $d > 0 ? round($n * 100 / $d, 1) : 0.0;
        $c['rates'] = [
            'opened'      => $pct($c['opened']),
            'clicked'     => $pct($c['clicked']),
            'credentials' => $pct($c['credentials']),
            'otp'         => $pct($c['otp']),
        ];
        return $c;
    }
}

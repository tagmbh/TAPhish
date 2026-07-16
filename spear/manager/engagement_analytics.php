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

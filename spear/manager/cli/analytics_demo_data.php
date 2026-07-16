<?php
/**
 * Dev-only: synthesize a realistic rotation dataset (104 people × 4 waves) and
 * run it through the REAL analytics core, so the cockpit beta demo shows the
 * actual aggregation logic on representative data. Deterministic (seeded).
 *
 *   php analytics_demo_data.php > demo.json
 */
require_once(dirname(__FILE__, 2) . '/engagement_analytics.php');
mt_srand(42);

$waves   = ['K1','K2','K3','K4'];
$cohorts = ['A','B','C','D'];
$slots   = ['Do16','Mo20','Mi22','Fr24'];
// per-wave susceptibility (open, then click|open, creds|click, otp|creds)
$prob = [
    'K1' => [0.52, 0.34, 0.55, 0.35],  // M365 mailbox
    'K2' => [0.60, 0.42, 0.62, 0.40],  // HR payroll — most effective
    'K3' => [0.48, 0.30, 0.50, 0.30],  // OneDrive share
    'K4' => [0.40, 0.22, 0.45, 0.25],  // REACH compliance — least
];

$sends = $visits = $submits = [];
$map = [];
$t0 = 1784169900000;  // base ms
$rid = 0;
foreach ($slots as $si => $slot) {
    $slotBase = $t0 + $si * 4 * 24 * 3600 * 1000;
    foreach ($cohorts as $ci => $cohort) {
        $wave = $waves[($si + $ci) % 4];
        $cid = "s{$si}c{$cohort}";
        $map[$cid] = ['wave' => $wave, 'cohort' => $cohort, 'slot' => $slot];
        for ($i = 0; $i < 26; $i++) {
            $rid++;
            $r = 'r' . $rid;
            $email = strtolower($cohort) . $i . '@textilcolor.test';
            $name  = "Mitarbeiter {$cohort}{$i}";
            $bounce = mt_rand(0, 99) < 3;   // ~3% bounce
            $sending_status = $bounce ? 3 : 2;
            $opened = !$bounce && (mt_rand() / mt_getrandmax()) < $prob[$wave][0];
            $sendT = $slotBase + mt_rand(0, 55 * 60 * 1000);
            $sends[] = [
                'campaign_id' => $cid, 'rid' => $r, 'user_email' => $email, 'user_name' => $name,
                'sending_status' => $sending_status,
                'mail_open_times' => $opened ? json_encode([(string)($sendT + mt_rand(60000, 7200000))]) : null,
                'send_time' => (string) $sendT,
            ];
            if (!$opened) { continue; }
            $clicked = (mt_rand() / mt_getrandmax()) < $prob[$wave][1];
            if (!$clicked) { continue; }
            $clickT = $sendT + mt_rand(120000, 10800000);
            $visits[] = ['tracker_id' => 't' . $wave, 'rid' => $r, 'time' => (string) $clickT];
            $creds = (mt_rand() / mt_getrandmax()) < $prob[$wave][2];
            if (!$creds) { continue; }
            $otp = (mt_rand() / mt_getrandmax()) < $prob[$wave][3];
            $submits[] = ['tracker_id' => 't' . $wave, 'rid' => $r, 'page' => '2',
                'is_2fa_capture' => $otp ? 1 : 0, 'time' => (string) ($clickT + mt_rand(15000, 300000))];
        }
    }
}

$result = taphish_analytics_build($sends, $visits, $submits, $map);
$result['meta'] = [
    'engagement' => 'DEMO — Textilcolor AG (synthetische Daten)',
    'generated_at' => '2026-07-16',
    'waves' => $waves, 'cohorts' => $cohorts, 'slots' => $slots,
    'is_demo' => true,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
/**
 * CLI (read-only): consolidated engagement analytics as JSON, for the cockpit.
 * Reads engagement N's TC campaigns + their send/open/click/credential rows,
 * joins them via the analytics core, prints JSON. Never selects the captured
 * credential VALUES (form_field_data) — only stage flags + timestamps.
 *
 *   php engagement_report.php [engagement_id=3]
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); die("CLI only\n"); }
date_default_timezone_set('UTC');

$eng = isset($argv[1]) && ctype_digit($argv[1]) ? (int) $argv[1] : 3;
$root = dirname(__FILE__, 3);
require_once($root . '/config/db.php');
require_once($root . '/manager/engagement_analytics.php');
if (!isset($conn) || !($conn instanceof mysqli)) { fwrite(STDERR, "no db\n"); exit(1); }

function q_list(mysqli $c, array $vals): string {
    $vals = array_values(array_unique(array_filter($vals, fn($v) => $v !== null && $v !== '')));
    if (!$vals) { return "''"; }
    return implode(',', array_map(fn($v) => "'" . $c->real_escape_string((string) $v) . "'", $vals));
}

// 1) campaigns for the engagement → campaign_id => {wave,cohort,slot}
$map = [];
$res = $conn->query("SELECT campaign_id, campaign_name FROM tb_core_mailcamp_list
                     WHERE engagement_id = " . (int) $eng . " AND campaign_name LIKE 'TC %'");
while ($res && ($r = $res->fetch_assoc())) {
    // "TC · <slot> · Kohorte <cohort> · <wave>"
    $parts = preg_split('/\s*·\s*/u', (string) $r['campaign_name']);
    $wave   = $parts[3] ?? '?';
    $cohort = isset($parts[2]) ? trim(str_ireplace('Kohorte', '', $parts[2])) : '?';
    $slot   = $parts[1] ?? '?';
    $map[$r['campaign_id']] = ['wave' => $wave, 'cohort' => $cohort, 'slot' => $slot];
}
$cidList = q_list($conn, array_keys($map));

// 2) sends
$sends = [];
$res = $conn->query("SELECT campaign_id,rid,user_email,user_name,sending_status,mail_open_times,send_time
                     FROM tb_data_mailcamp_live WHERE campaign_id IN ($cidList)");
$rids = [];
while ($res && ($r = $res->fetch_assoc())) { $sends[] = $r; $rids[] = $r['rid']; }
$ridList = q_list($conn, $rids);

// 3) click + credential/OTP evidence for those rids (NO form_field_data)
$visits = $submits = [];
$res = $conn->query("SELECT tracker_id,rid,time FROM tb_data_webpage_visit WHERE rid IN ($ridList)");
while ($res && ($r = $res->fetch_assoc())) { $visits[] = $r; }
$res = $conn->query("SELECT tracker_id,rid,page,is_2fa_capture,time FROM tb_data_webform_submit WHERE rid IN ($ridList)");
while ($res && ($r = $res->fetch_assoc())) { $submits[] = $r; }

$out = taphish_analytics_build($sends, $visits, $submits, $map);
$out['meta'] = [
    'engagement'   => 'Textilcolor AG — Awareness 2026 (Live)',
    'engagement_id'=> $eng,
    'generated_at' => date('Y-m-d H:i') . ' UTC',
    'campaigns'    => count($map),
    'is_demo'      => false,
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

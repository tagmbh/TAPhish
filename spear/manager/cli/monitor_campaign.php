<?php
/**
 * CLI-only slot monitor for the Textilcolor rotation (engagement 3).
 *
 * Runs from cron a few times after each send slot. For every slot whose time
 * has passed it checks the slot's 4 campaigns and fires a Telegram alert via
 * the app's own (decrypted) bot config when something looks wrong, plus a
 * one-time "sent" summary per slot. Deduped via a small state file so it does
 * not spam. Sends NOTHING for slots that are not due yet.
 *
 *   php monitor_campaign.php              # normal run (cron)
 *   php monitor_campaign.php --test-telegram   # send one "monitor armed" ping
 *   php monitor_campaign.php --dry         # evaluate + print, never send
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); die("CLI only\n"); }
date_default_timezone_set('UTC');                       // scheduled_time is stored UTC

$DRY  = in_array('--dry', $argv, true);
$TEST = in_array('--test-telegram', $argv, true);

$root = dirname(__FILE__, 3);                            // .../spear
require_once($root . '/config/db.php');
require_once($root . '/manager/secret_at_rest.php');
require_once($root . '/manager/telegram_alerting.php');
if (!isset($conn) || !($conn instanceof mysqli)) { fwrite(STDERR, "no db\n"); exit(1); }

// scope + safety guard: only the 4 known July-2026 slots
$SLOTS = [
  'Do16' => strtotime('16-07-2026 07:15 AM'),
  'Mo20' => strtotime('20-07-2026 07:15 AM'),
  'Mi22' => strtotime('22-07-2026 07:15 AM'),
  'Fr24' => strtotime('24-07-2026 07:15 AM'),
];
$now = time();

$STATE_FILE = getenv('HOME') . '/taphish_monitor_state.json';
$state = @json_decode((string)@file_get_contents($STATE_FILE), true) ?: ['summarized'=>[], 'alerted'=>[]];

function tg(string $msg, bool $dry): bool {
  global $conn;
  if ($dry) { fwrite(STDOUT, "[dry] would telegram: " . str_replace("\n"," ", $msg) . "\n"); return true; }
  $cfg = function_exists('taphish_get_telegram_config') ? taphish_get_telegram_config($conn) : null;
  if (!$cfg) { fwrite(STDERR, "telegram not configured\n"); return false; }
  return @taphish_telegram_send($cfg['token'], $cfg['chat_id'], $msg);
}

if ($TEST) {
  tg("\xF0\x9F\x9B\xA1 TAPhish-Monitor aktiv — Textilcolor-Rotation wird überwacht (Slots 16./20./22./24.07., je 09:15). Alarm nur bei Problemen.", $DRY);
  echo "test ping sent\n"; exit(0);
}

// pull TC campaigns for engagement 3
$rows = [];
$res = mysqli_query($conn, "SELECT campaign_id,campaign_name,scheduled_time,camp_status
                            FROM tb_core_mailcamp_list WHERE engagement_id=3 AND campaign_name LIKE 'TC · %'");
while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }

// live send stats per campaign
function stats(mysqli $conn, string $cid): array {
  $out = ['total'=>0,'ok'=>0,'err'=>0];
  $s = $conn->prepare("SELECT sending_status, COUNT(*) c FROM tb_data_mailcamp_live WHERE campaign_id=? GROUP BY sending_status");
  if ($s) { $s->bind_param('s',$cid); $s->execute(); $q=$s->get_result();
    while ($q && ($x=$q->fetch_assoc())) { $out['total']+=(int)$x['c'];
      if ((int)$x['sending_status']===2) $out['ok']=(int)$x['c'];
      if ((int)$x['sending_status']===3) $out['err']=(int)$x['c']; }
    $s->close(); }
  return $out;
}

$problems = 0; $summary_lines = [];
foreach ($SLOTS as $slot => $t) {
  if ($now < $t + 120) continue;                        // not due yet (2 min grace)
  $camps = array_values(array_filter($rows, fn($r)=> strpos($r['campaign_name'], "· $slot ·") !== false));
  if (!$camps) continue;

  $stuck = array_filter($camps, fn($c)=> (int)$c['camp_status'] === 1);
  $tot=0;$ok=0;$err=0;
  foreach ($camps as $c) { $st=stats($conn,$c['campaign_id']); $tot+=$st['total']; $ok+=$st['ok']; $err+=$st['err']; }

  // 1) stuck (daemon didn't fire) — alert once, after 10 min grace
  if ($stuck && $now > $t + 600 && !in_array("$slot-stuck", $state['alerted'], true)) {
    tg("\xF0\x9F\x9A\xA8 TAPhish ALARM — Slot $slot: " . count($stuck) . " Kampagne(n) noch nicht gestartet (camp_status=1). Scheduler-Daemon prüfen (SniperPhish_Manager).", $DRY);
    $state['alerted'][] = "$slot-stuck"; $problems++;
  }
  // 2) high error rate — alert once
  if ($tot > 0 && $err >= max(5, (int)ceil($tot*0.3)) && !in_array("$slot-err", $state['alerted'], true)) {
    tg("\xE2\x9A\xA0\xEF\xB8\x8F TAPhish — Slot $slot: erhöhte Sendefehler ($err von $tot). Sender/SMTP + Deliverability prüfen.", $DRY);
    $state['alerted'][] = "$slot-err"; $problems++;
  }
  // 3) one-time "sent" summary once the slot has fully started (no stuck)
  if (!$stuck && $tot > 0 && !in_array($slot, $state['summarized'], true)) {
    $icon = $err===0 ? "\xE2\x9C\x85" : "\xE2\x9A\xA0\xEF\xB8\x8F";
    tg("$icon TAPhish — Slot $slot versendet: $ok ok" . ($err?", $err Fehler":"") . " (von $tot).", $DRY);
    $state['summarized'][] = $slot;
  }
  $summary_lines[] = "$slot due=1 stuck=".count($stuck)." total=$tot ok=$ok err=$err";
}

if (!$DRY) @file_put_contents($STATE_FILE, json_encode($state));
echo ($summary_lines ? implode("\n", $summary_lines) : "no slots due") . "\n";
echo "problems=$problems\n";
exit(0);

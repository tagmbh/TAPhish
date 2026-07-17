<?php
/**
 * DEMO seeder — CLI ONLY. Creates DRAFT campaign building-blocks for the
 * Textilcolor awareness demo so they show up in the UI ready to review and
 * launch. Idempotent (skips entities whose name already exists), reversible
 * (everything is tagged "DEMO –"; remove via the normal UI or the engagement
 * cleanup CLI), and SAFE BY DEFAULT: prints a plan and writes NOTHING unless
 * you pass --commit.
 *
 *   # 1) Dry run — shows exactly what it would create, touches nothing:
 *   php spear/manager/cli/seed_demo_campaigns.php --base=https://deepaudit.ch
 *
 *   # 2) Real run — creates the drafts:
 *   php spear/manager/cli/seed_demo_campaigns.php --base=https://deepaudit.ch --commit
 *
 * Optional: --force re-clones landings if the slug dir already exists.
 *
 * What it creates:
 *   - 1 draft engagement "DEMO – Textilcolor"
 *   - 1 recipient group "DEMO – Empfänger" (test users + demo users)
 *   - per wave: a web tracker, a cloned awareness-safe landing, a mail template
 *
 * It does NOT launch/send anything. Wiring a recipient group + template +
 * sender + tracker into a live send still happens in the wizard (Step 7),
 * which is the deliberate human go/no-go gate.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: this script is CLI-only.\n");
}

// ---- args ----------------------------------------------------------------
$COMMIT = in_array('--commit', $argv, true);
$FORCE  = in_array('--force', $argv, true);
$BASE   = 'https://deepaudit.ch';
foreach ($argv as $a) {
    if (strpos($a, '--base=') === 0) { $BASE = rtrim(substr($a, 7), '/'); }
}
$DRY = !$COMMIT;

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function step(string $s): void { out(($GLOBALS['DRY'] ? '[dry] ' : '[do]  ') . $s); }

out("== TAPhish DEMO seeder ==");
out("Mode : " . ($DRY ? "DRY-RUN (no writes — pass --commit to apply)" : "COMMIT (writing)"));
out("Base : $BASE");
out("");

// ---- bootstrap -----------------------------------------------------------
$root = dirname(__FILE__, 2);                 // spear/manager
$dbFile = dirname(__FILE__, 3) . '/config/db.php';
if (!is_file($dbFile)) { fwrite(STDERR, "Cannot find config/db.php — run from the TAPhish install.\n"); exit(1); }
require_once($dbFile);
if (!isset($conn) || !($conn instanceof mysqli)) { fwrite(STDERR, "No database connection.\n"); exit(1); }

require_once($root . '/common_functions.php');     // getRandomStr, $entry_time
require_once($root . '/secret_at_rest.php');        // recipient_data_seal
require_once($root . '/engagement.php');            // engagement schema/normalize/insert
require_once($root . '/landing_library.php');       // clone_to_path
require_once($root . '/wizard_tracker_builder.php');// build_minimal_tracker

if (function_exists('taphish_engagement_ensure_schema')) {
    if (!$DRY) { taphish_engagement_ensure_schema($conn); }
    else { out("[dry] would ensure engagement schema"); }
}

$entry = $GLOBALS['entry_time'] ?? (new DateTime())->format('d-m-Y h:i A');
$report = [];

// ---- helpers -------------------------------------------------------------
function existing_id(mysqli $conn, string $table, string $idCol, string $nameCol, string $name): ?string {
    $stmt = $conn->prepare("SELECT $idCol FROM $table WHERE $nameCol = ? LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res[$idCol] ?? null;
}

// ---- 1) engagement (draft) ----------------------------------------------
$engName = 'DEMO – Textilcolor';
$engId = null;
$exStmt = $conn->prepare("SELECT id FROM tb_core_engagement WHERE name = ? LIMIT 1");
if ($exStmt) { $exStmt->bind_param('s', $engName); $exStmt->execute(); $r = $exStmt->get_result()->fetch_assoc(); $exStmt->close(); $engId = $r['id'] ?? null; }
if ($engId) {
    out("engagement: '$engName' exists (id=$engId) — reuse");
} else {
    step("create draft engagement '$engName'");
    if (!$DRY) {
        $v = taphish_engagement_validate_input([
            'name' => $engName, 'target_org' => 'Textilcolor AG',
            'start_at' => date('Y-m-d'), 'end_at' => '',
            'scope_allowlist' => 'textilcolor.ch, example.ch', 'notes' => 'Demo-Drafts (seed).',
        ]);
        if (empty($v['ok'])) {
            out("   !! engagement validation failed: " . json_encode($v['errors'] ?? []));
        } else {
            $engId = taphish_engagement_insert($conn, $v['normalized'], 'seed-cli');
            out("   -> engagement id = " . var_export($engId, true));
        }
    }
}
$report['engagement'] = $engId ?: '(dry)';

// ---- 2) recipient group --------------------------------------------------
$grpName = 'DEMO – Empfänger';
$grpId = existing_id($conn, 'tb_core_mailcamp_user_group', 'user_group_id', 'user_group_name', $grpName);
if ($grpId) {
    out("recipients: '$grpName' exists (id=$grpId) — reuse");
} else {
    $users = [
        ['fname'=>'Admin','lname'=>'Textilcolor','email'=>'admin@textilcolor.ch'],
        ['fname'=>'Ivan','lname'=>'Stricker','email'=>'i.stricker@textilcolor.ch'],
        ['fname'=>'Anna','lname'=>'Bühler','email'=>'anna.buehler@example.ch'],
        ['fname'=>'Marco','lname'=>'Steiner','email'=>'marco.steiner@example.ch'],
        ['fname'=>'Sophie','lname'=>'Meier','email'=>'sophie.meier@example.ch'],
        ['fname'=>'Luca','lname'=>'Weber','email'=>'luca.weber@example.ch'],
    ];
    $rows = array_map(fn($u) => [
        'uid'=>getRandomStr(10),'fname'=>$u['fname'],'lname'=>$u['lname'],'email'=>$u['email'],'notes'=>''
    ], $users);
    step("create recipient group '$grpName' with " . count($rows) . " users (incl. admin@/ i.stricker@textilcolor.ch)");
    if (!$DRY) {
        $grpId = getRandomStr(10);
        $sealed = recipient_data_seal(json_encode($rows));
        $eidParam = $engId ? (int)$engId : null;
        $stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_user_group(user_group_id,user_group_name,user_data,date,engagement_id) VALUES(?,?,?,?,?)");
        $stmt->bind_param('ssssi', $grpId, $grpName, $sealed, $entry, $eidParam);
        $stmt->execute(); $stmt->close();
        out("   -> user_group_id = $grpId");
    }
}
$report['recipients'] = $grpId ?: '(dry)';

// ---- wave definitions ----------------------------------------------------
// landing = library source slug to clone (null = no landing, e.g. BEC)
$waves = [
    ['key'=>'k1','tracker'=>'TC-K1-M365','landing'=>'k1-m365','src'=>'m365-login-safe',
     'tpl'=>'DEMO – K1 M365 Postfach voll',
     'subj'=>'Ihr Postfach erreicht das Speicherlimit — Aktion erforderlich',
     'body'=>"Sehr geehrte/r [Vorname] [Nachname],\n\nIhr Microsoft 365 Postfach hat 95 % des verfügbaren Speicherplatzes erreicht (14,3 GB von 15 GB). Sobald die Grenze überschritten ist, können keine neuen E-Mails mehr empfangen werden.\n\nUm Ihre Mailbox zu erweitern und Datenverlust zu vermeiden, bestätigen Sie bitte Ihr Konto über den nachstehenden Link:\n\n[Mailbox jetzt erweitern]\n\nDiese Aktion ist innerhalb von 24 Stunden erforderlich.\n\nMit freundlichen Grüssen\nMicrosoft 365 Mailbox Support Team"],
    ['key'=>'k2','tracker'=>'TC-K2-HR','landing'=>'k2-hr','src'=>'m365-login-safe',
     'tpl'=>'DEMO – K2 HR Lohnabrechnung',
     'subj'=>'Ihre Lohnabrechnung Juni 2026 steht bereit',
     'body'=>"Sehr geehrte/r [Vorname] [Nachname],\n\nIhre Lohnabrechnung für Juni 2026 wurde im Mitarbeiterportal bereitgestellt. Aufgrund einer Aktualisierung der Lohndaten ist eine einmalige Bestätigung Ihrer Anmeldung nötig.\n\n[Lohnabrechnung ansehen]\n\nBei Fragen wenden Sie sich an die Personalabteilung.\n\nFreundliche Grüsse\nPersonalabteilung / Payroll"],
    ['key'=>'quish','tracker'=>'TC-QUISH-APP','landing'=>'quish-app','src'=>'m365-login-safe',
     'tpl'=>'DEMO – Quishing Mitarbeiter-App',
     'subj'=>'Neue Mitarbeiter-App — jetzt mit QR-Code aktivieren',
     'body'=>"Hallo [Vorname] [Nachname],\n\nab sofort steht die neue Textilcolor-Mitarbeiter-App zur Verfügung (Lohnausweise, Schichtpläne, interne News). Scannen Sie den QR-Code mit Ihrem Smartphone, um den Zugang zu aktivieren:\n\n[ QR-CODE ]\n\nDie Aktivierung ist bis Ende der Woche erforderlich.\n\nIhr IT-Services-Team"],
    ['key'=>'k3','tracker'=>'TC-K3-ONEDRIVE','landing'=>'k3-onedrive','src'=>'m365-login-safe',
     'tpl'=>'DEMO – K3 OneDrive Sharing',
     'subj'=>'Detlef Fischer hat eine Datei mit Ihnen geteilt: Strategie_2026_Schoeller_Integration.xlsx',
     'body'=>"Detlef Fischer hat eine Datei mit Ihnen geteilt\n\nStrategie_2026_Schoeller_Integration.xlsx\n\nDiese Datei wurde mit Ihnen über OneDrive geteilt. Bitte beachten Sie die Vertraulichkeit der enthaltenen Informationen zur laufenden Integration von Schoeller Technologies AG.\n\n[Öffnen]\n\nDiese Freigabe läuft am 14.09.2026 ab.\n\nMit freundlichen Grüssen\nMicrosoft OneDrive"],
    ['key'=>'k4','tracker'=>'TC-K4-REACH','landing'=>'k4-reach','src'=>'m365-login-safe',
     'tpl'=>'DEMO – K4 REACH-Konformität',
     'subj'=>'REACH-Konformitätsnachweis — Aktualisierung bis 09.10. erforderlich',
     'body'=>"Guten Tag [Vorname] [Nachname],\n\nim Rahmen der jährlichen REACH-Prüfung benötigen wir Ihre Bestätigung der aktualisierten Sicherheitsdatenblätter. Bitte öffnen Sie das Dokument und bestätigen Sie die Einsicht:\n\n[Dokument öffnen]\n\nFrist: 09.10.2026.\n\nFreundliche Grüsse\nQHSE / Compliance"],
    ['key'=>'k4sub','tracker'=>'TC-K4SUB-BEC','landing'=>null,'src'=>null,
     'tpl'=>'DEMO – K4-Sub CEO-Fraud (BEC)',
     'subj'=>'Vertraulich — dringende Überweisung Schoeller-Integration',
     'body'=>"Hans-Peter,\n\nich bin den ganzen Tag in Meetings zur Schoeller-Integration und kann nicht ans Telefon. Wir brauchen heute noch eine Überweisung an einen neuen Beratungspartner — die Details schickt dir gleich unser Anwalt.\n\nEs ist absolut vertraulich, bitte nichts ans Team weitergeben, bis ich zurück bin.\n\nKönnen wir das bis 16:00 abwickeln? Danke für dein Vertrauen.\n\nDetlef\n— gesendet vom mobilen Gerät"],
];

out("");
foreach ($waves as $w) {
    out("── Welle " . strtoupper($w['key']) . " ─────────────────────────────");
    $waveRep = ['tracker'=>null,'landing'=>null,'template'=>null];

    // tracker
    $trkId = existing_id($conn, 'tb_core_web_tracker_list', 'tracker_id', 'tracker_name', $w['tracker']);
    if ($trkId) { out("  tracker '{$w['tracker']}' exists ($trkId) — reuse"); }
    else {
        step("  create web tracker '{$w['tracker']}'");
        if (!$DRY) {
            $trkId = getRandomStr(6);
            while (existing_id($conn,'tb_core_web_tracker_list','tracker_id','tracker_id',$trkId)) { $trkId = getRandomStr(6); }
            $built = taphish_wizard_build_minimal_tracker($trkId, $w['tracker'], $BASE . '/track.php');
            $active = 1;
            $stmt = $conn->prepare("INSERT INTO tb_core_web_tracker_list(tracker_id,tracker_name,content_html,content_js,tracker_step_data,active,date) VALUES(?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssis', $trkId, $w['tracker'], $built['content_html'], $built['content_js'], $built['tracker_step_data'], $active, $entry);
            $stmt->execute(); $stmt->close();
            out("     -> tracker_id = $trkId");
        }
    }
    $waveRep['tracker'] = $trkId ?: '(dry)';

    // landing clone
    if ($w['landing']) {
        $clonesRoot = landing_library_clones_root();
        $exists = is_dir($clonesRoot . '/' . $w['landing']);
        if ($exists && !$FORCE) { out("  landing '{$w['landing']}' already cloned — keep"); $waveRep['landing'] = $w['landing']; }
        else {
            step("  clone landing '{$w['src']}' -> '{$w['landing']}'");
            if (!$DRY) {
                $postUrl = $BASE . '/track.php';
                $res = landing_library_clone_to_path($w['src'], $w['landing'], $postUrl, '', $FORCE);
                if (!empty($res['ok'])) { out("     -> cloned (" . ($res['files'] ?? '?') . " files) at " . ($res['path'] ?? ('sniperhost/cloned/'.$w['landing'].'/'))); $waveRep['landing'] = $w['landing']; }
                else { out("     !! clone failed: " . ($res['err'] ?? 'unknown')); $waveRep['landing'] = 'FAILED: ' . ($res['err'] ?? '?'); }
            }
        }
    } else { out("  landing: none (by design — BEC/Vishing)"); $waveRep['landing'] = '(none)'; }

    // mail template
    $tplId = existing_id($conn, 'tb_core_mailcamp_template_list', 'mail_template_id', 'mail_template_name', $w['tpl']);
    if ($tplId) { out("  template '{$w['tpl']}' exists ($tplId) — reuse"); }
    else {
        step("  create mail template '{$w['tpl']}'");
        if (!$DRY) {
            $tplId = getRandomStr(10);
            $html = nl2br(htmlspecialchars($w['body'], ENT_QUOTES, 'UTF-8'));
            // shootMail()/mail_campaign_cron only emit an HTML part when the
            // content-type is exactly 'text/html'; the short-form 'html' (the
            // old buggy clone value pretext_library's heal-migration exists to
            // fix) would ship the body as plaintext. Write the correct MIME
            // type up front so seeded demo mails render even before any web
            // login triggers session_manager's heal pass.
            $timage_type = ''; $contentType = 'text/html'; $attachments = '';
            $stmt = $conn->prepare("INSERT INTO tb_core_mailcamp_template_list(mail_template_id, mail_template_name, mail_template_subject, mail_template_content, timage_type, mail_content_type, attachment, date) VALUES(?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssssss', $tplId, $w['tpl'], $w['subj'], $html, $timage_type, $contentType, $attachments, $entry);
            $stmt->execute(); $stmt->close();
            out("     -> mail_template_id = $tplId");
        }
    }
    $waveRep['template'] = $tplId ?: '(dry)';
    $report['waves'][$w['key']] = $waveRep;
}

// ---- verification report -------------------------------------------------
out("");
out("== Verifikations-Report ==");
out("Engagement : " . ($report['engagement']));
out("Empfänger  : " . ($report['recipients']));
foreach ($report['waves'] ?? [] as $k => $v) {
    out(sprintf("  %-6s tracker=%-8s landing=%-22s template=%s",
        strtoupper($k), (string)$v['tracker'], (string)$v['landing'], (string)$v['template']));
}
out("");
if ($DRY) {
    out(">> DRY-RUN: es wurde NICHTS geschrieben. Wenn der Plan stimmt, erneut mit --commit ausführen.");
} else {
    out(">> COMMIT fertig. Drafts erscheinen im UI unter Engagements / Mail-Templates / Web-Tracker.");
    out(">> Zum Zeigen: im Quick-Start-Wizard die Welle wählen, Empfänger + Template + Landing + Tracker sind angelegt.");
}
exit(0);

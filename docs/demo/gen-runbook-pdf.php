<?php
define('K_TCPDF_EXTERNAL_CONFIG', 1);
require '/home/user/TAPhish/spear/libs/tcpdf_min/tcpdf.php';

$pdf = new TCPDF('P','mm','A4',true,'UTF-8');
$pdf->SetCreator('TAPhish'); $pdf->SetTitle('Runbook — K1 auf deepaudit.ch startklar');
$pdf->SetMargins(15,15,15); $pdf->SetAutoPageBreak(true,15);
$pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
$pdf->AddPage();

$css='<style>
 h1{font-size:17pt;color:#0067b8;font-weight:bold}
 h2{font-size:11pt;color:#004e8c;font-weight:bold}
 .sub{font-size:9pt;color:#605e5c}
 p,td,li{font-size:9.3pt;color:#201f1e;line-height:1.45}
 .muted{color:#605e5c}
 td{padding:3px 5px;vertical-align:top}
 .n{color:#fff;background:#0067b8;font-weight:bold}
 .mono{font-family:courier;color:#004e8c}
</style>';

$html=$css.'
<h1>Runbook — K1 in ~10 Minuten startklar</h1>
<p class="sub">Host: <b>deepaudit.ch</b> &middot; Ziel: lauff&auml;hige Demo-Welle „M365 Postfach voll" mit awareness-sicherer Landing &middot; alles im <b>Quick-Start-Wizard</b></p>
<br>
<p><b>Wichtig:</b> Wellen, Landings und Empf&auml;ngerlisten sind Laufzeit-Daten in der App &mdash; sie werden hier <b>einmal im Wizard</b> angelegt (nicht per Code-Deploy). Danach sind sie dauerhaft vorhanden.</p>
<br>

<h2>Vorab (1 Min)</h2>
<table>
 <tr><td width="5%">&bull;</td><td>Einloggen auf <span class="mono">deepaudit.ch</span> &rarr; Men&uuml; <b>Quick-Start</b> &ouml;ffnen.</td></tr>
 <tr><td>&bull;</td><td>Datei <span class="mono">demo-recipients.csv</span> bereitlegen (8 Demo-User).</td></tr>
</table>
<br>

<h2>Wizard Schritt f&uuml;r Schritt</h2>
<table>
 <tr><td width="7%"><span class="n">&nbsp;1&nbsp;</span></td><td><b>Engagement metadata</b> &mdash; Name z.&nbsp;B. „Demo Textilcolor K1", Scope-Domain leer lassen (oder <span class="mono">example.ch</span>) &rarr; Weiter.</td></tr>
 <tr><td><span class="n">&nbsp;2&nbsp;</span></td><td><b>OSINT pre-check</b> &mdash; f&uuml;r die Demo &uuml;berspringen &rarr; Weiter.</td></tr>
 <tr><td><span class="n">&nbsp;3&nbsp;</span></td><td><b>Recipients</b> &mdash; Listenname „Demo MA" &rarr; <b>Import</b> &rarr; <span class="mono">demo-recipients.csv</span> w&auml;hlen. 8 Empf&auml;nger erscheinen &rarr; Weiter.</td></tr>
 <tr><td><span class="n">4a</span></td><td><b>Web tracker</b> &mdash; vorhandenen Tracker w&auml;hlen oder neu anlegen (Standard reicht) &rarr; Weiter.</td></tr>
 <tr><td><span class="n">4b</span></td><td><b>Landing page</b> &mdash; unter „Or clone a library template" <b>m365-login-safe</b> w&auml;hlen &rarr; <b>Clone library template</b>. <br>Optional: bei konfiguriertem Host <b>Auto-Push</b> ankreuzen. &rarr; „Landing ready", URL erscheint &rarr; Weiter.</td></tr>
 <tr><td><span class="n">&nbsp;5&nbsp;</span></td><td><b>Mail template</b> &mdash; Pretext „M365 / Postfach voll" w&auml;hlen oder kurz inline schreiben (Betreff: „Ihr Postfach erreicht das Speicherlimit"). <b>Save &amp; wire</b> verlinkt automatisch die Landing-URL &rarr; Weiter.</td></tr>
 <tr><td><span class="n">&nbsp;6&nbsp;</span></td><td><b>Sender</b> &mdash; bestehenden Absender w&auml;hlen oder inline anlegen; <b>SMTP-Probe</b> ausl&ouml;sen (gr&uuml;n) &rarr; Weiter.</td></tr>
 <tr><td><span class="n">&nbsp;7&nbsp;</span></td><td><b>Pre-flight + Launch</b> &mdash; Gates pr&uuml;fen lassen. Alle gr&uuml;n &rarr; Kampagne ist startklar. F&uuml;r die Demo: <b>Schedule</b> statt sofort senden (oder an eine eigene Test-Adresse senden).</td></tr>
</table>
<br>

<h2>F&uuml;r die Live-Demo morgen</h2>
<table>
 <tr><td width="5%">&bull;</td><td>Den <b>Empf&auml;nger-Flow</b> zeigen: Landing-URL aus Schritt 4b im Browser &ouml;ffnen &rarr; M365-Login &rarr; absenden &rarr; <b>Microlearning</b>. Im Tracker erscheint „geklickt + abgeschickt" &mdash; <b>kein Passwort</b>.</td></tr>
 <tr><td>&bull;</td><td>Den <b>Report</b> zeigen: Klick-/Submit-Quoten je Empf&auml;nger (pseudonym).</td></tr>
 <tr><td>&bull;</td><td>Tipp: vorab einmal selbst durchklicken, damit im Tracker bereits ein Datenpunkt sichtbar ist.</td></tr>
</table>
<br>

<h2>Wenn etwas h&auml;ngt</h2>
<table>
 <tr><td width="5%">&bull;</td><td>Landing 404 / nicht erreichbar &rarr; in Schritt 4b TAPhish-Hosting (<span class="mono">/p/&lt;slug&gt;/</span>) statt externem Host nutzen; das braucht keine Domain/Zertifikat.</td></tr>
 <tr><td>&bull;</td><td>Import 0 Zeilen &rarr; Scope-Domain in Schritt 1 leer lassen (sonst werden fremde Domains gefiltert).</td></tr>
 <tr><td>&bull;</td><td>Pre-flight rot bei „Landing" &rarr; pr&uuml;fen, ob die geklonte Landing wirklich &ouml;ffnet (Schritt 4b URL anklicken).</td></tr>
</table>
<br>
<p class="sub">Diese Plattform dient ausschliesslich autorisierten Security-Awareness-&Uuml;bungen. Die awareness-sichere Landing erfasst nur die Tatsache des Absendens &mdash; nie den Passwortwert.</p>
';

$pdf->writeHTML($html,true,false,true,false,'');
$pdf->Output('/home/user/TAPhish/docs/demo/TAPhish-Runbook-K1.pdf','F');
echo "written\n";

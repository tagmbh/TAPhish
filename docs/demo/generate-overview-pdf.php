<?php
define('K_TCPDF_EXTERNAL_CONFIG', 1);
require dirname(__DIR__, 2) . '/spear/libs/tcpdf_min/tcpdf.php';

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('TAPhish');
$pdf->SetTitle('TAPhish — Demo Overview (Textilcolor / deepaudit.ch)');
$pdf->SetMargins(16, 16, 16);
$pdf->SetAutoPageBreak(true, 16);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

$css = '<style>
  h1 { font-size:17pt; color:#0067b8; font-weight:bold; }
  h2 { font-size:11.5pt; color:#0067b8; font-weight:bold; }
  .sub { font-size:9pt; color:#605e5c; }
  p, td, li { font-size:9.2pt; color:#201f1e; line-height:1.45; }
  a { color:#0067b8; text-decoration:none; }
  .muted { color:#605e5c; }
  table { border-collapse:collapse; }
  td { padding:3px 5px; vertical-align:top; }
  .hr { border-bottom:1px solid #edebe9; }
</style>';

$html = $css . '
<h1>TAPhish — Demo Overview</h1>
<p class="sub">Security-Awareness-Plattform &middot; Kunde: <b>Textilcolor AG</b> &middot; Demo-Host: <b>deepaudit.ch</b> &middot; Stand: 10.06.2026</p>
<br>

<h2>1 &middot; Demo-Umgebung</h2>
<table>
  <tr><td width="38%"><b>Demo-Instanz (live)</b></td><td><a href="https://deepaudit.ch">https://deepaudit.ch</a></td></tr>
  <tr><td><b>Zweite Instanz (Failover)</b></td><td class="muted">interne Adresse (gleicher Stand, beide deployed)</td></tr>
  <tr><td><b>Code-Repository</b></td><td><a href="https://github.com/tagmbh/TAPhish">github.com/tagmbh/TAPhish</a></td></tr>
</table>
<br>

<h2>2 &middot; Heute ausgeliefert (alle live auf beiden Instanzen)</h2>
<table>
  <tr><td width="22%"><a href="https://github.com/tagmbh/TAPhish/pull/160">PR #160</a></td><td>Pre-Go-Live-Polish: AJAX-Fehlerbehandlung, cURL-Hygiene, Installer-Hinweis</td></tr>
  <tr><td><a href="https://github.com/tagmbh/TAPhish/pull/161">PR #161</a></td><td>Ein-Klick Auto-Push einer geklonten Landing auf den Standard-Host + Template-Primer</td></tr>
  <tr><td><a href="https://github.com/tagmbh/TAPhish/pull/162">PR #162</a></td><td>Quick-Add Look-alike-Domain &rarr; Sub-Domain-Hosts in einem Schritt</td></tr>
  <tr><td><a href="https://github.com/tagmbh/TAPhish/pull/163">PR #163</a></td><td>Awareness-sichere M365-Landing (verwirft Passwort, leitet auf Microlearning)</td></tr>
</table>
<br>

<h2>3 &middot; Demo-Ablauf (5 Schritte)</h2>
<table>
  <tr><td width="6%"><b>1.</b></td><td><b>Quick-Add</b> der Look-alike-Domain (Settings &rarr; External landing hosts): FTP-Login einmal eingeben, Sub-Domains <span class="muted">owa, abacus, remote</span> ankreuzen &rarr; alle Host-Profile generiert, erstes = Standard.</td></tr>
  <tr><td><b>2.</b></td><td>Quick-Start-Wizard &rarr; Landing <b>m365-login-safe</b> klonen &rarr; <b>Auto-Push</b> auf den Standard-Host.</td></tr>
  <tr><td><b>3.</b></td><td>Empf&auml;nger-Flow zeigen: realistischer M365-Login &rarr; Absenden &rarr; landet auf <b>Microlearning</b>. Tracker zeigt &bdquo;geklickt + abgeschickt&ldquo; &mdash; <b>aber kein Passwort</b>.</td></tr>
  <tr><td><b>4.</b></td><td><b>Preflight</b>-Gate zeigen: f&auml;ngt eine nicht erreichbare Landing (DNS/Zertifikat) vor dem Launch ab.</td></tr>
  <tr><td><b>5.</b></td><td>Tracker-Report: Klick-/Submit-Raten je Empf&auml;nger (rid-Token), ohne Klartext-Credentials.</td></tr>
</table>
<br>

<h2>4 &middot; Szenario-Referenzen (K1 / K3 OSINT)</h2>
<table>
  <tr><td width="38%"><b>Echte M365-Login (URL-Check)</b></td><td><a href="https://login.microsoftonline.com/">login.microsoftonline.com</a></td></tr>
  <tr><td><b>Textilcolor — Kontakte (CEO)</b></td><td><a href="https://www.textilcolor.ch/en/contact/contacts">textilcolor.ch/en/contact/contacts</a></td></tr>
  <tr><td><b>Schoeller-&Uuml;bernahme (Kontext K3)</b></td><td><a href="https://www.textileworld.com/textile-world/2025/07/textilcolor-ag-acquires-schoeller-technologies-ag-strengthening-innovative-strength-in-the-textile-sector-and-intensifying-brand-partnerships/">textileworld.com &mdash; Textilcolor acquires Schoeller (07/2025)</a></td></tr>
  <tr><td><b>Look-alike-Beispiele</b></td><td class="muted">m365-mailbox-ch.com &middot; textilcolor-share.com &middot; textilcolor-ag.ch</td></tr>
</table>
<br>

<h2>5 &middot; Wellen-&Uuml;bersicht</h2>
<table>
  <tr class="hr"><td width="16%"><b>Welle</b></td><td width="20%"><b>Datum</b></td><td><b>Pretext</b></td></tr>
  <tr><td>K1</td><td>15.06.2026</td><td>M365 &bdquo;Postfach voll&ldquo; (Baseline) &mdash; m365-login-safe</td></tr>
  <tr><td>K2</td><td>06.07.2026</td><td>HR-Lohn / Lieferantenrechnung (Split nach Abteilung)</td></tr>
  <tr><td>Quishing</td><td>17.08.2026</td><td>QR-Code in E-Mail &bdquo;Neue MA-App&ldquo;</td></tr>
  <tr><td>K3</td><td>07.09.2026</td><td>OneDrive-Sharing &bdquo;Detlef Fischer hat eine Datei geteilt&ldquo;</td></tr>
  <tr><td>K4</td><td>05.10.2026</td><td>Abteilungs-Mix (REACH / Frachtbrief / Schoeller-Memo)</td></tr>
  <tr><td>K4-Sub</td><td>19.&ndash;23.10.2026</td><td>CEO-zu-CFO BEC + Multi-Channel Vishing</td></tr>
</table>
<br>

<h2>6 &middot; Vor den echten Wellen (kundenseitig)</h2>
<table>
  <tr><td width="6%">&bull;</td><td>Schriftliches CEO-Einverst&auml;ndnis (Anhang A.4) vor Versand der CEO-imitierenden Szenarien.</td></tr>
  <tr><td>&bull;</td><td>M365 Defender Advanced Delivery: Sende-IP + Look-alike-Domain allowlisten.</td></tr>
  <tr><td>&bull;</td><td>Hostpoint: FTP-Account + Sub-Domains + Let&rsquo;s-Encrypt-Zertifikate f&uuml;r die Look-alike-Domain anlegen.</td></tr>
</table>
<br>
<p class="sub">Hinweis: Diese Plattform dient ausschliesslich autorisierten Security-Awareness-&Uuml;bungen. Die awareness-sichere Landing erfasst nur die Tatsache des Absendens &mdash; nie den Passwortwert.</p>
';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output(__DIR__ . '/TAPhish-Demo-Overview.pdf', 'F');
echo "written\n";

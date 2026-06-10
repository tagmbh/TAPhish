<?php
define('K_TCPDF_EXTERNAL_CONFIG', 1);
require '/home/user/TAPhish/spear/libs/tcpdf_min/tcpdf.php';

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('TAPhish');
$pdf->SetTitle('Kickoff — Awareness-Programm Textilcolor AG');
$pdf->SetMargins(16, 16, 16);
$pdf->SetAutoPageBreak(true, 16);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

$css = '<style>
  h1{font-size:18pt;color:#0067b8;font-weight:bold}
  h2{font-size:11.5pt;color:#0067b8;font-weight:bold}
  .sub{font-size:9pt;color:#605e5c}
  p,td,li{font-size:9.3pt;color:#201f1e;line-height:1.45}
  .muted{color:#605e5c}
  td{padding:3px 5px;vertical-align:top}
  .mono{font-family:courier;font-size:9pt;color:#004e8c}
  .box{background:#eaf3fb;}
</style>';

$html = $css . '
<h1>Kickoff — Security-Awareness-Programm</h1>
<p class="sub">Kunde: <b>Textilcolor AG</b> &middot; Vorbereitung &amp; Onboarding &middot; Stand: 10.06.2026</p>
<br>

<h2>1 &middot; Was Sie erwartet</h2>
<table>
  <tr><td width="4%">&bull;</td><td>Über mehrere Monate verschickte, realistische Phishing-Simulationen in eskalierenden Stufen (2&nbsp;&rarr;&nbsp;4).</td></tr>
  <tr><td>&bull;</td><td>Jeder Klick führt sofort zu einer kurzen <b>Microlearning-Seite</b> &mdash; Lernen im Moment statt Blossstellung.</td></tr>
  <tr><td>&bull;</td><td>Wir messen <b>Verhalten</b> (Klick, Absenden, Meldung) &mdash; <b>niemals echte Passwörter</b>. Ergebnisse pseudonymisiert und aggregiert.</td></tr>
  <tr><td>&bull;</td><td>Ziel: messbar bessere Reflexe &mdash; URL prüfen, melden, im Zweifel über einen Zweitkanal verifizieren.</td></tr>
</table>
<br>

<h2>2 &middot; Was wir von Ihnen brauchen — die Empfänger-Liste (CSV)</h2>
<p>Eine einfache CSV-Datei mit den Teilnehmenden. Spalten (Reihenfolge flexibel, Kopfzeile wird automatisch erkannt):</p>
<table>
  <tr><td width="30%"><b>Vorname</b></td><td>für die persönliche Anrede</td></tr>
  <tr><td><b>Nachname</b></td><td>optional</td></tr>
  <tr><td><b>E-Mail</b></td><td>zwingend &mdash; die einzige Pflichtangabe</td></tr>
</table>
<p class="muted" style="margin-top:6px">Format: UTF-8 &middot; Komma oder Semikolon als Trennzeichen &middot; eine Person pro Zeile. Mehrere Listen möglich (z.&nbsp;B. je Abteilung S1&ndash;S8 für den Pretext-Split).</p>
<table class="box"><tr><td>
<span class="mono">Vorname,Nachname,E-Mail<br>
Anna,Bühler,anna.buehler@tcag.ch<br>
Marco,Steiner,marco.steiner@tcag.ch<br>
Sophie,Meier,sophie.meier@tcag.ch</span>
</td></tr></table>
<p class="muted" style="margin-top:4px">Wir benötigen <b>nur</b> Name und E-Mail &mdash; keine Passwörter, keine HR-Daten.</p>
<br>

<h2>3 &middot; Technische Freigaben (vor Welle 1)</h2>
<table>
  <tr><td width="4%">&bull;</td><td><b>Allowlisting</b> in M365 Defender (Advanced Delivery): unsere Sende-IP + Look-alike-Domain, damit Übungs-Mails nicht im Filter hängen.</td></tr>
  <tr><td>&bull;</td><td><b>CEO-Einverständnis</b> (Anhang A.4), schriftlich &mdash; für Szenarien, die Führungspersonen imitieren (K3, K4-Sub).</td></tr>
  <tr><td>&bull;</td><td><b>Domains/DNS</b>: Look-alike-Domain + Sub-Domains mit eigenem Zertifikat (richten wir ein; ggf. Ihre Freigabe nötig).</td></tr>
</table>
<br>

<h2>4 &middot; Wie wir kommunizieren</h2>
<table>
  <tr><td width="4%">&bull;</td><td><b>Eingeweihter Kreis klein halten:</b> nur die für Freigaben/Eskalation nötigen Personen kennen den Zeitplan &mdash; sonst verfälscht es die Ergebnisse.</td></tr>
  <tr><td>&bull;</td><td><b>Awareness-Mailbox / „Phish-Button“:</b> Mitarbeitende melden verdächtige Mails dorthin; Meldungen zählen als <b>positives</b> Verhalten.</td></tr>
  <tr><td>&bull;</td><td><b>Eskalation:</b> je ein definierter Kontakt bei Ihnen und bei uns, erreichbar während aktiver Wellen.</td></tr>
  <tr><td>&bull;</td><td><b>Reporting:</b> Kurz-Auswertung nach jeder Welle, Gesamt-Report am Programmende (pseudonymisiert).</td></tr>
  <tr><td>&bull;</td><td><b>Vertraulichkeit:</b> Vishing-Mitschnitte nur mit Zustimmung (Art.&nbsp;179bis StGB); keine echten Überweisungen (Honeypot).</td></tr>
</table>
<br>

<h2>5 &middot; Zeitplan &amp; nächste Schritte</h2>
<table>
  <tr><td width="16%"><b>K1</b> 15.06.</td><td>M365 „Postfach voll“ (Baseline) &mdash; <b>Ihre To-dos:</b> CSV-Liste, Allowlisting, CEO-Einverständnis, Awareness-Mailbox + Eskalationskontakt.</td></tr>
  <tr><td><b>K2</b> 06.07.</td><td>HR-Lohn / Lieferantenrechnung (Split nach Abteilung)</td></tr>
  <tr><td><b>Quishing</b> 17.08.</td><td>QR-Code „Neue MA-App“</td></tr>
  <tr><td><b>K3</b> 07.09.</td><td>OneDrive-Sharing (CEO, OSINT)</td></tr>
  <tr><td><b>K4</b> 05.10.</td><td>Abteilungs-Mix (REACH / Frachtbrief / Schoeller)</td></tr>
  <tr><td><b>K4-Sub</b> KW43</td><td>CEO-BEC + Multi-Channel Vishing</td></tr>
</table>
<br>
<p class="sub">Sobald die vier kundenseitigen Punkte erledigt sind, startet K1 am 15.06.2026. Diese Plattform dient ausschliesslich autorisierten Security-Awareness-Übungen.</p>
';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Output('/home/user/TAPhish/docs/demo/TAPhish-Kickoff-Intro.pdf', 'F');
echo "written\n";

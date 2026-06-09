# Quick-Start-Wizard — Rebuild zum „0→100%"-Funnel

## Ziel
Der Quick-Start-Wizard soll eine komplette, sofort lauffähige **Mail + Landing**-Kampagne
end-to-end erstellen — ohne den Operator in Untermenüs (`SiteCloner`, `MailUserGroup`,
`MailTemplate`) zu schicken. Alles wird inline angelegt und verdrahtet:
Engagement → Empfänger → Landing-Page **inkl. automatisch erstelltem Tracker** →
Mail-Template (mit Auto-Verdrahtung von CTA-Link + Tracking-Pixel) → Sender (auswählen
oder inline anlegen) → Pre-flight → Launch einer **vollständig verknüpften** Kampagne.

## Heutiger Ist-Zustand (das Problem)
- Step 3 (Pretext): klont, leitet dann zum `MailTemplate`-Editor weiter.
- Step 4 (Sender): generiert nur DKIM-DNS-Records, **legt kein Sender-Profil an**.
- Step 5 (Recipients): nur Preview, **persistiert nichts** → Link zu `MailUserGroup`.
- Step 6 (Landing): nur Deeplinks, **klont nichts inline**, kein Tracker.
- Step 7 (Launch): legt eine Kampagne mit **leerem `campaign_data`** an → funktionslos.

## Neue Schrittstruktur (bleibt 7 Steps → Stepper/Resume/Persistenz-Keys 1..7 unverändert)
1. **Engagement** (Metadaten + Scope) — *commit* (unverändert, funktioniert bereits)
2. **OSINT pre-check** — optionale Beratung (unverändert; prefillt Ziel-Domain)
3. **Recipients** — *commit*: Empfängergruppe + Empfänger werden wirklich angelegt
4. **Landing + Tracker** — *commit*: Site inline klonen (URL + Slug) mit
   **automatisch erstelltem oder gewähltem Web-Tracker** → ergibt `landing_url`
5. **Mail template** — *commit*: Pretext wählen → Inline-Rich-Editor (Summernote) →
   Auto-Verdrahtung: CTA-Link auf `landing_url?rid={{RID}}` + `{{TRACKER}}`-Pixel
6. **Sender** — *commit*: bestehenden SMTP-Sender wählen ODER inline anlegen
   (Host:Port, From, User, Passwort; DKIM-Gen als optionaler Advanced-Block)
7. **Pre-flight + Launch** — Kontext auto-befüllt aus den Steps 3–6; baut **vollständiges**
   `campaign_data` und startet die verknüpfte Kampagne (CAS draft→live)

Begründung Reihenfolge: *wer* (3) → *wohin sie landen* (4) → *was sie bekommen* (5,
braucht `landing_url` für Auto-Verdrahtung) → *wer sendet* (6) → *prüfen & starten* (7).

## Backend-Änderungen

### Neue/erweiterte AJAX-Actions (Haupt-Dispatcher `userlist_campaignlist_mailtemplate_manager.php`)
- `wizard_list_web_trackers` — Liste vorhandener Web-Tracker (Dropdown Step 4).
- `wizard_create_web_tracker` — **NEU**: legt minimalen, funktionsfähigen Web-Tracker
  serverseitig an (Name + Webhook-URL, Default = `<base>/track.php`). Baut minimales
  `tracker_step_data` + `content_js` (Visit + Form-Submit → Webhook, RID aus URL) +
  `content_html`, `active=1`. Gibt `tracker_id` + `mod_url` (`<base>/mod?tlink=ID`) zurück.
  → erfüllt „Tracker automatisch erstellen, falls keiner konfiguriert".
- `wizard_commit_recipients` — **NEU**: parst CSV (reuse `taphish_recipient_csv_parse` +
  Scope-Filter), legt Gruppe an (reuse `saveUserGroup` mit `engagement_id`), fügt
  Empfänger ein (reuse vorhandene Add/Upload-Logik). Gibt `user_group_id` + Counts zurück.
- `wizard_launch_campaign` — **REWRITE**: nimmt jetzt die gespeicherten IDs
  (`user_group_id`, `mail_template_id`, `sender_list_id`, `tracker_id`, `landing_url`).
  Validiert Existenz + Engagement-Zugehörigkeit, re-runt Pre-flight, baut korrektes
  `campaign_data` `{user_group, mail_template, mail_sender, mail_config:'default',
  msg_interval, msg_fail_retry, notes}`, CAS draft→live, INSERT Kampagne.

### Reuse (keine neuen Endpunkte nötig)
- Sender-Liste: vorhandene `get_sender_list`; Sender anlegen: `save_sender_list`.
- Mail-Template speichern: `save_mail_template` (Wizard-JS baut den Content mit CTA+Pixel).
- Site klonen: vorhandener Endpoint `sniperhost/manager/site_cloner_manager.php`
  (`clone_site`, akzeptiert `url`,`slug`,`tracker_url`); Library-Klon: `library_clone_to_my_sites`.

### `taphish_wizard_state_normalize` (engagement.php) — Whitelist erweitern
Zusätzlich persistieren (alles nicht-geheim, nur IDs/Strings):
`sender_list_id`, `user_group_id`, `mail_template_id`, `tracker_id`, `clone_slug`,
`landing_url`, `campaign_type`. → ermöglicht vollständiges Resume.

### `authz.php` — Policy-Map ergänzen
`wizard_list_web_trackers`, `wizard_create_web_tracker`, `wizard_commit_recipients`
→ `['super-admin','operator']`. `wizard_launch_campaign` bleibt `engagement_member`.

## Frontend-Änderungen
- `spear/QuickStart.php`: Step-Wraps 3–7 neu (Recipients-Commit, Landing+Tracker-Inline-Form,
  Mail-Inline-Editor, Sender-Select/Create, auto-befülltes Launch). Summernote-Assets laden
  (wie in `MailTemplate.php`).
- `spear/js/quick_start.js`: Step-Logik 3–7 neu (echte Commits, State-Persistenz der IDs,
  Auto-Verdrahtung). `window.TAPhishWizard`-API entsprechend erweitern.
- `spear/js/wizard_stepflow.js`: Step-Labels/Reihenfolge-Hooks anpassen, Lazy-Loads neu
  zuordnen (Tracker-/Sender-Listen beim Anzeigen des jeweiligen Steps laden).

## Tests (subagent-getrieben, pure-unit nach Repo-Konvention)
PHPUnit in `tests/`:
- Minimaler Tracker-Builder: `tracker_step_data`-Form + `content_js` enthält `tracker_id`/Webhook.
- `campaign_data`-Builder: enthält user_group/template/sender/config korrekt.
- `wizard_state_normalize`: neue Keys werden sauber normalisiert/whitelisted.
- Recipient-Commit-Parsing: Scope-Filter + Fehlerzeilen.
Ausführen: `./vendor/bin/phpunit`.

## Deploy
Feature-Branch `feat/quick-start-full-funnel`, Commits, dann PR gegen `main`
(entspricht dem bestehenden PR-Workflow des Repos).

## Nicht im Scope (bewusst)
- Quick-Tracker-only / Web-Tracker-only Engagement-Typen (Nutzer wählte „Mail+Landing voll").
  Die Typ-Auswahl-Struktur wird erweiterbar vorbereitet, aber nur der volle Funnel implementiert.
- Automatischer DNS-Eintrag für DKIM (bleibt manuell/`LookalikeDeploy`).

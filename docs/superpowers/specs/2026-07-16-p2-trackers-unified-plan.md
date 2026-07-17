# P2 · Trackers unified — planning (in progress)

Goal (locked decision): collapse the two tracker groups (**Quick Tracker**, **Web Tracker**) plus the
stray **Web Tracker Report** leaf into ONE **Trackers** section — List / New / Reports — with a `type`
attribute distinguishing **open-pixel (quick)** vs **web/form (web)** trackers. Reuse the P1.3 union
infrastructure (`taphish_tracker_rows_by_engagement` + `taphish_engagement_campaigns_normalize`
already fetch + type-tag web *and* quick trackers).

## Current nav (from z_menu.php map, verified)
- Quick Tracker group (z_menu.php 148–152): Tracker List → `/spear/QuickTracker`; Reports → `/spear/QuickTrackerReport`.
- Web Tracker group (155–159): Tracker List → `/spear/TrackerList`; New Tracker → `/spear/TrackerGenerator`.
- Stray leaf (161): Web Tracker Report → `/spear/TrackerReport`.
- Topbar "Create New" dupes: Quick Tracker → `/spear/QuickTracker` (82), Web Tracker → `/spear/TrackerGenerator` (83).
- 5 pages behind it: QuickTracker.php, TrackerList.php (lists); TrackerGenerator.php (create);
  QuickTrackerReport.php, TrackerReport.php (reports).

## ⚠️ Load-bearing constraint (nav highlighting)
`js/libs/sidebarmenu.js` highlights by **exact href match** and **strips `?query` before matching**.
→ The type/view distinction MUST live in the **path**, not the query string, or every variant collapses
to one anchor and List/New/Reports (and web/quick) can't be highlighted distinctly. Options:
  (a) real distinct routes/pages (`Trackers`, `TrackerNew`, `TrackerReport`), OR
  (b) one page + custom active-class logic that inspects the query and sets `.active`/`.selected`/`.in`.
Decision leaning: keep DISTINCT top-level pages for List / New / Reports (path-based highlight works
out of the box), each internally handling both `type`s (web/quick) via an in-page toggle. Minimizes
new nav-highlight code and matches how the existing highlighter already works.

## Target nav (proposed — confirm in plan)
Single collapsible **"Trackers"** group:
- **List** → one page listing ALL trackers (web + quick) with a Type column + a type filter.
- **New** → one create page with a Type chooser (open-pixel vs web/form).
- **Reports** → one report page with a tracker picker spanning both types (absorbs both *Report pages).
Removes: the duplicate "Tracker List" label collision, the stray floating "Web Tracker Report" leaf,
and the topbar create-dupes (or keep topbar as a shortcut to New).

## Backend / JS (from explorer maps)

**Web Tracker subsystem** (mapped): TrackerList.php (list, client-side DataTable), TrackerGenerator.php
(3-step wizard builder + Import-HTML modal), TrackerReport.php (serverSide report + column picker).
Managers: `web_tracker_generator_list_manager.php` (CRUD: save/get_list[raw array]/get_from_id/delete/
copy/pause_stop/get_html_content/delete_data), `tracker_report_manager.php` (serverSide feed via the
P0 dt helper). Data: `tb_core_web_tracker_list` (def + `tracker_step_data` JSON) → capture splits into
`tb_data_webpage_visit` (views) + `tb_data_webform_submit` (submissions w/ `form_field_data`, `page`).

**Quick Tracker subsystem** (mapped): QuickTracker.php (list + inline builder modal `#modal_new_quick_tracker`),
QuickTrackerReport.php (serverSide report + picker modal). Beacon endpoint `qt.php` (`/qt?tid=&rid=` →
insert `tb_data_quick_tracker_live` row → serve default.jpg). Manager `quick_tracker_manager.php`:
save/get_list[array]/delete/delete_data/pause_stop/get_from_id/`get_quick_tracker_data`[serverSide via
dt helper]/download_report — line-for-line analogous to the web manager. Single capture table
`tb_data_quick_tracker_live` (+ `is_scanner` runtime migration). Quick = open-pixel `<img>` beacon
(did-they-open); Web = `<script>`/multi-page visit+form capture.

**Type discriminator:** `quick` = open-pixel, `web` = web/form. The P1.3 normalizer already type-tags
both (`taphish_engagement_campaigns_normalize` emits `web`/`quick`); a unified list feed can build on
`taphish_tracker_rows_by_engagement` (drop the engagement filter → all trackers).

**Duplication to collapse:** parallel CRUD managers (prefix swap), duplicated report backend
(same no-LIMIT+`taphish_dt_slice` pattern + identical `downloadReport` CSV/PDF/HTML), duplicated list JS
(with duplicated bugs), duplicated report client, 3 capture tables vs 1.

## Also-fix bugs to fold in (CONFIRMED, exact locations)
- **`#`-col blank** — `web_tracker_list.js:200` binds `order.dt_web_tracker_list`/`search.dt_web_tracker_list`
  (wrong namespace; DataTables fires `.dt`). Same in **`quick_tracker.js:183`**. Correct reference:
  `web_tracker_report_functions.js:106` (`order.dt search.dt`).
- **Malformed default sort** — `web_tracker_list.js:179` `aaSorting:[3,'desc']` (needs `[[4,'desc']]`);
  col 3 (Total Pages) carries the date `data-order`, col 4 (Date Created) has none. Same in
  `quick_tracker.js:162`.
- **Dead pause handler** — `web_tracker_generator_function.js:328-347`: posts `pause_stop_tracker_tracking`
  to `web_tracker_generator_list_manager` (missing `manager/` prefix), wrong param (`action_value` vs
  `active`), wrong response check. 4 reasons it 404/403s. Orphaned legacy → delete.
- **Import-HTML fatal + SSRF** — `web_tracker_generator_list_manager.php:170-183` `getHTMLContent`:
  line 182 `$stmt->error()` on a null `$stmt` (fatal on any fetch failure); cURL on arbitrary URL with
  `FOLLOWLOCATION` + `VERIFYPEER=false`, no allow-list → SSRF. Fix: clean error JSON + host/scheme
  allow-list + block internal ranges (reuse the landing-probe SSRF guard pattern from P1b/#155).
- **Quick "Stop" never records stop_time** — `quick_tracker.js:144` `data-status_value=fale` (typo,
  unquoted) → posts `active:"fale"`; `"fale" == false` is false in PHP so it takes the start/resume
  branch and never sets `stop_time`. HIGH-value fix (operator would hit it).
- **Ignored 2nd `.DataTable()` arg** `{order:[[1,'asc']]}` (quick_tracker_report.js:68-70) — dead config.
- **Scanner hits not filtered** in quick reports/exports (`get_quick_tracker_data` + `download_report`
  `SELECT *` no `is_scanner` filter) — unlike the mailcamp dashboards' hide-scanner toggle.
- **Cosmetic**: QuickTracker.php:76 undefined `nextRandomId`; :126 hardcoded "Create New Email Tracker"
  (JS overrides); QuickTrackerReport reuses table id `table_quick_tracker_list` (collides w/ list page).
- **Schema drift (flag):** `track.php` writes `code_2fa`/`is_2fa_capture`/`repeat_webhook_sent` not in the
  install schema → confirm the migration exists before touching capture code.

## ⛔ Safety rule for P2 (live campaign running)
The tracker CAPTURE path is LIVE (the Textilcolor campaign's web trackers write via `track.php`/`mod`;
quick beacons via `qt.php`). **P2 touches only the VIEW / LIST / REPORT / NAV layer — never the capture
ingestion or the tracker save/definition write-path while a campaign is active.** Consolidation is
read-side + nav.

## Increment breakdown (proposed sequence — each: TDD where pure, deploy w/ explicit go + .bak + base-check, demo live)
- **P2.0 · Bug batch (safe, high-value, standalone).** Fix the confirmed defects independent of any
  consolidation: `#`-col namespace typo (both list JS), malformed `aaSorting`+data-order, Quick Stop
  `fale` typo, dead pause handler (delete), Import-HTML fatal+SSRF (allow-list). Structural-guard tests
  + live demo. Also fold in the 4 audit files (esp. `wizard_tracker_builder.php`) to realign the server.
- **P2.1 · Unified Tracker List** — one "All Trackers" list (web+quick) with a Type column + type filter,
  built on the P1.3 normalizer (drop engagement filter) + one `list_all_trackers` action + the dt helper.
- **P2.2 · Unified Reports** — one report page, tracker picker spanning both types (report backends already
  share the dt-helper pattern). Add the missing `is_scanner` hide toggle.
- **P2.3 · Unified New Tracker** — one create entry with a type chooser (open-pixel vs web/form). Save-path
  change → extra care (still not the capture path).
- **P2.4 · Nav swap** — one "Trackers" group (List/New/Reports) replacing the 2 groups + stray leaf +
  topbar dupes. Path-based routes so the existing highlighter works (query-string is stripped).

## Increment breakdown (TO FINALIZE after maps)
Sequenced small + demo-verifiable, like P1. Likely: (P2.1) unified List page + feed; (P2.2) unified
Reports; (P2.3) unified New + type chooser; (P2.4) nav swap to the single Trackers group + retire dupes;
fold the also-fix bugs into the relevant increment. Each: TDD where pure, deploy with explicit go +
.bak + base-check, demo live.

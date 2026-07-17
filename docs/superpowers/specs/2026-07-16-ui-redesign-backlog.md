# TAPhish UI / IA redesign — operator-reported backlog

Living list of bugs + requirements gathered from the Textilcolor live run (2026-07-16), feeding the
`feature/ui-ia-redesign` analysis + plan.

## ✅ SHIPPED — P0 foundation (deployed + live-verified 2026-07-16)

All three P0 structural fixes are committed on `feature/ui-ia-redesign`, deployed to the live server
(15 files, sha256-verified == HEAD, `php -l` clean), and demo-verified against the running engagement.
Full suite **989 green**.

- **P0a — DataTables "Next" (all 4 tables).** `datatables_helper.php` (`taphish_dt_envelope` /
  `_search_clause` / `_order_clause` / `_limit` / **`_slice`**, TDD). The 4 managers now drop SQL
  `LIMIT/OFFSET`, build the FULL PHP-filtered set, count it, and slice the page via `taphish_dt_slice`,
  returning through `taphish_dt_envelope`. **Live proof** (campaign 1784124533380, 27 recipients):
  page-1 `recordsFiltered=27` (was 20 → Next dead), page-2 returns the remaining 7 rows fully disjoint,
  search "a" → `recordsFiltered=16` (full filtered count, not per-page). Commits b524beb, 38c9431.
- **P0b — nav dead-click.** Shared `z_navboot.php` partial (loads `sidebarmenu.js`, guarded) included
  before `</body>` on the 5 pages that were missing it. `NavBootstrapTest` locks the invariant (every
  page including `z_menu.php` must load the nav bootstrap). **Live proof**: `sidebarmenu.js` now present
  on all 5 pages. Commit 73a6910.
- **P0c — status "undefined".** `js/camp_status.js` = single canonical decoder (codes read from the
  scheduler's own setters); `mail_campaign.js` + `engagement_view.js` delegate. `CampStatusDecoderTest`
  locks map completeness + delegation. **Live proof**: `campStatus.label(5)==='Deferred'`, no label
  renders "undefined", unknown code → safe "Status N" fallback. Commit f2754d7.

## ✅ SHIPPED — P1 Engagements = hub (deployed + live-verified 2026-07-16)

All five P1 increments are committed, deployed, and demo-verified against the live engagement.
Suite **1006 green**. This makes "Campaigns under Engagements" real and fixes the
"can't delete engagements / no all-campaigns view" complaints.

- **P1.1 — engagement selector in the builder** (commit c85ec29, cf79d0a). The classic builder now
  sends `engagement_id` (server already persisted it). Live: save engagement_id=3 → read back 3 →
  deleted. (Live demo caught a `{engagements:[…]}` parse bug, fixed + guarded.)
- **P1.2 — engagement_id on the tracker tables** (ea181fd). Idempotent, whitelisted migration on both
  tracker tables via the lazy session-init call site. Live: both columns present after init.
- **P1.3 — unified hub list** (dbc8c7b). `taphish_engagement_campaigns_normalize` (pure, TDD) merges
  mail + web + quick, type-tagged; `get_engagement_view` returns the union; the client renders a Type
  badge + per-type link + status. Live: engagement 3 shows 16 Mail-tagged rows with canonical status.
- **P1.4a — picker Open + Delete** (bdd5aa4). Every engagement row (drafts included) gets Open + Delete
  → fixes undeletable abandoned drafts. Live: a draft renders Continue+Open+Delete; delete works.
- **P1.4b — Unscoped/Legacy bucket + Zuordnen** (3f6c704). Lists every campaign/tracker with no
  engagement + a per-row assign. Live: bucket lists 9 web + 3 quick real trackers; a throwaway assigns
  to engagement 3, appears in its hub, leaves the bucket.

~~Deferred: per-row **Löschen** in the Unscoped bucket~~ ✅ **DONE (polish, 2026-07-17):** per-row delete
dispatched by type to the existing delete actions (which clean up the item's data); the bucket only lists
unscoped items so live campaigns never appear. Live-verified (create throwaway → delete from bucket → gone).
Still deferred (low value, single-operator): **membership-filtered** `list_engagements` (single-
operator today); server-side paging for the hub list (client-side is fine at ~20 campaigns).

## ✅ SHIPPED — P2.0 tracker bug batch (deployed + live-verified 2026-07-16)

First P2 increment (operator chose bug-batch-first). 10 files deployed (6 fixes + 4 server-realign to
HEAD incl. `wizard_tracker_builder.php` for #170 consistency). Suite 1021 green. Capture path untouched.
- **Import-HTML SSRF + fatal** (`web_tracker_generator_list_manager.php` getHTMLContent) — new
  `url_fetch_guard.php` (`taphish_fetch_url_precheck` + `taphish_ip_is_public`, TDD 10 tests): http(s)
  only, reject private/reserved literal IPs + localhost, DNS-resolve every A record + require public;
  curl hardened (no FOLLOWLOCATION, http/https only, VERIFYPEER on, timeouts); fatal replaced with clean
  JSON. Live: `169.254.169.254`/`127.0.0.1`/`192.168.x`/`localhost`/`file://`/garbage all refused.
- **`#`-col namespace** (`web_tracker_list.js`, `quick_tracker.js`, `quick_tracker_report.js`) → `order.dt`.
  Live: web (9 rows) + quick (3 rows) renumber 1,2,3….
- **Malformed sort** → `order:[[N,'desc']]`; web list Date `data-order` moved to Date Created (col 4).
- **Quick "Stop" `fale` typo** → `false` (Stop now records stop_time). Live: no `fale`, buttons emit false.
- **Dead pause handler** removed; **ignored 2nd `.DataTable()` arg** dropped.
Guarded by `TrackerBugBatchTest` + `UrlFetchGuardTest`.

**P2.1 · Unified Tracker List** (deployed + live-verified 2026-07-16): new `/spear/Trackers` lists web +
quick in one table with a Type badge + Web/Quick/All filter. `tracker_unified.php`
(`taphish_tracker_list_normalize` pure/TDD + `taphish_all_trackers`), `list_all_trackers` action
(authz operator+), `Trackers.php` + `trackers_unified.js`. Live: 12 trackers (9 web + 3 quick),
type-badged, filter works, # renumbers, Report/Edit deep-link to existing pages. Read-only aggregation;
mutations still on the old pages. NOT yet in nav (that's P2.4). Guard: `TrackerListUnifyTest`.

**P2.2 · Reports scanner-hide** (deployed + live-verified 2026-07-16): `taphish_hit_is_visible` (pure/TDD)
+ opt-in `hide_scanner` on both report feeds (default off → unchanged), surfaced as a "Hide scanners"
toggle on QuickTrackerReport + TrackerReport. Live: default==off; tracker x7xow0 54→36, xs7n6u 316→280
scanner hits filtered; toggle present on both pages. **Scope note:** did NOT do a single-page
render-merge of the two report clients — they have hard global collisions (g_tracker_id/dic_all_col/
getAllReportColListSelected/exportReportAction), so merging = a large risky rewrite of the dynamic
web-column logic (deferred, do attended). The cross-type report ENTRY is already served by P2.1's
Trackers.php per-row Report links.

**P2.3 + P2.4 · Nav swap** (deployed + live-verified 2026-07-16): one **Trackers** sidebar group
(`z_menu.php`) replaces the two old groups (Quick Tracker, Web Tracker) + the stray "Web Tracker Report"
leaf → **All Trackers** (/spear/Trackers, the unified list), **New Web Tracker** (/spear/TrackerGenerator),
**New Quick Tracker** (/spear/QuickTracker). P2.3 delivered as the two explicit New items (cleaner than a
chooser page). Path-based so the exact-href highlighter works. Live: one group present, old groups +
stray leaf gone, /spear/Trackers highlights + expands the Trackers group. Old report/list/builder pages
stay reachable via the list's per-row links. Guard: `testNavUnifiesTrackersGroup`.

~~**Pre-existing bug: Home `moment is not defined`**~~ ✅ **FIXED (polish, 2026-07-17):** the culprit was
`common_scripts.js`'s Home-guarded login-bar `moment.tz()` call; Home.php now loads moment.min.js +
moment-timezone before common_scripts.js. Only Home was affected (the moment.tz call is guarded to Home;
other pages don't hit a moment path). Live: Home loads with zero JS errors.

## ✅ SHIPPED — P3 core: capture-field de-duplication (deployed + live-verified 2026-07-16)
The operator-reported field-mapping mess (email ×24, password ×8 in one cell) is fixed. New
`capture_fields.php`: `taphish_decode_capture_fields` (pure, TDD 8 tests incl. the exact 24×→1 case) =
the canonical decoder (DISTINCT non-empty values per field, first-seen order) + `taphish_capture_field_display`.
The web-mail dashboard's two Field- projection sites (feed + download_report) now push DISTINCT values —
strictly no data loss (every unique value kept, only exact repeats dropped). Deployed (sha256==HEAD, lint
clean); WebMailCmp dashboard loads clean. Verified behaviourally via TDD (NOT by pulling live credentials —
sensitive plaintext protected). Suite 1037 green.

**P3 still open (larger consolidation, needs operator go):** unified Reports/Analytics generator (default
ALL, one column-picker, one download_report on the tested engagement_analytics core); per-victim row dedup
on the WEB TRACKER report (currently one row per submission → victim repeats); Screen Res "Failed" + Country
empty (likely capture-side — needs its own investigation, may not be display-fixable).

### ✅ P2 (Trackers unified) COMPLETE — all increments deployed + live-verified 2026-07-16. Suite 1029 green.
Deferred (noted): single-page report render-merge (hard client global collisions → risky); topbar
"Create New" tracker shortcuts left as-is; the two campaign dashboards (P4) untouched.

### ⚠️ Deploy-discipline note (2026-07-16, during P1.3)
A tar-deploy of `userlist_campaignlist_mailtemplate_manager.php` overwrote a server copy whose
sha256 (`d5dc0bf…`) matched no commit → looked like a clobbered hotfix. **Investigated & resolved
BENIGN**: the identical hash was found on the sister host `ptbe.autodiscover.li`; diffing it showed
the server was simply running the **pre-#170** version (3-arg `taphish_wizard_build_minimal_tracker`),
i.e. deepaudit was *behind* the branch. My deploy was a clean forward-upgrade (deepaudit now also has
the #170 capture-field-schema fix). Nothing lost. Lessons locked in: (1) **always `cp .bak` before
overwriting** (the tar path skipped it) and **stop on a base-check DRIFT instead of deploying through
it**; (2) **the live server is likely behind the branch on other un-redeployed files** — a full
`sha256` audit of server-vs-HEAD is worth doing before the P2+ consolidations. Backup of the deployed
file: `…manager.php.bak-p13-deployed`.

**Server-vs-HEAD audit (done before P2, 2026-07-16):** of 182 code files (manager/core/js/top-level
views), only **4 differ**, and all 4 are cleanly *behind* the branch (each matches an older commit —
no untracked hotfixes): `cli/seed_demo_campaigns.php`, `landing_host.php`, `landing_library.php`,
`wizard_tracker_builder.php`. None are breaking. Note: the P1.3 deploy of `userlist_…manager.php`
(post-#170, 4-arg `taphish_wizard_build_minimal_tracker`) now sits over the server's pre-#170 builder
(3-arg) — PHP ignores the extra arg, so nothing breaks; #170's *optional* named-capture-field columns
just stay inactive on deepaudit. **PENDING (needs explicit operator go — out of P0/P1 scope):**
forward-upgrade those 4 files to HEAD to fully align the server + enable #170. Fold into the P2
tracker deploy or authorize separately. Backups staged: `*.bak-audit`.

## Information architecture (menu reorg)

- **Engagements** only lists engagements created from scratch, NOT the campaigns; can't delete them;
  need a useful "all running campaigns" view. → clarify what an Engagement is vs a Campaign.
- **Campaigns should live under Engagements?** (open decision)
- **One "Tracker" menu** that administers BOTH Quick Tracker + Web Tracker (create / list / configure).
- **Web Tracker is inconsistent**: "Web Tracker" group (web tracker / tracker list / new tracker) AND a
  SEPARATE "Web Tracker Report" menu → merge under the Tracker menu.
- **One "Reports/Analytics" entry** that generates reports across all campaigns / quick-trackers /
  web-trackers — not three separate dashboards + a tracker report.
- **Dashboards**: three today (Email Campaign Dashboard, Web-MailCamp Dashboard, + Web Tracker Report).
  Decide: submenu of Engagements, or a separate Dashboards/Analytics menu.

## Cross-cutting bugs

- **Nav click-twice**: clicking a next menu item sometimes does nothing — you must click the
  "TA-PHISH" logo/Home first, then the next click works. (z_menu.php + custom.min.js)
- **DataTables "Next" pagination dead**: default "Show 20", but Next does not advance when >20 rows —
  only changing the page-size shows more. Appears on many pages (Quick Tracker etc.).

## Reporting / capture display (Web Tracker Report + WebMailCamp)

- **Default view = "Page Visit" = empty** → operator must manually switch to the login/submit page
  every time. Directive: **default to ALL (all captures), page/tracker/wave as OPTIONAL filters.**
- **Captures are page-SEGMENTED** across Page-1 (email) / Page-2 (password) / Page-3 (2FA) tabs.
  Directive: **combine into ONE row per victim** — email + password + OTP together. ("why not combined?")
- **Field-<name> mapping broken**: duplicate columns (one `Field-email` per page that has the field →
  3× email, 2× password) AND each value repeated N times in a cell (email ×24, password ×8) because the
  report concatenates `form_field_data` across every submission row. → the core `decode_fields` must
  emit **one clean column per logical field with the distinct/latest value**.
- **Repeated rows per rid**: the multi-step landing re-posts, so each victim appears many times.
  → dedup to one per recipient (keep per-hit detail available on drill-down only).
- ~~**Screen Res = "Failed"**~~ / ~~**Country / geo empty**~~ — **INVESTIGATED (P3, 2026-07-17): both CAPTURE/INFRA-side, not display bugs.**
  - Screen Res: captured client-side by the injected tracker JS (`screen.width+"x"+screen.height`), but the custom m365 landing never POSTs it → stored empty for all 64 hits. **Not backfillable** (the client value is gone). Fix = have the landing send screen_res next round.
  - Country: the projection (`taphish_ip_info_projection`) is CORRECT for ipapi.co; the geo is empty because **ipapi.co's free/tokenless tier rate-limits under load** (a single lookup works, a batch gets throttled — confirmed: 8.8.8.8 resolves, but 18/18 victim IPs failed). Stored IPs are clean (48 IPv4 + 16 IPv6). **Backfill CLI built + ready** (`spear/manager/cli/backfill_geo.php`, idempotent, dry-run-first) — it works once a **geo API token** (ipapi.co paid / ipinfo) or a **local MaxMind GeoLite2 DB** is configured. Recommend the token/local-DB switch; then run `--commit` to enrich existing hits.

**✅ GEO FIXED (2026-07-17, local DB):** switched from rate-limited ipapi.co to a local DB-IP Country Lite mmdb (bundled maxmind-db reader, no composer at runtime, no rate limit). getIPInfo uses local-first + ipapi.co fallback. Backfilled all **64/64** existing hits with country (CH 22 real targets; the rest datacenter scanner-prefetch hits). Future captures enriched automatically. City-level is a drop-in upgrade (swap the mmdb). Screen Res still needs the landing to send it (next round).

## Tracking beacons (landing/template — fix NEXT round, not mid-campaign)

- **Open pixel dead**: 0% opens everywhere (Mail Open blank) even for people who clicked+submitted.
  `{{TRACKER}}` open-pixel was neutralized to a non-tracking 1×1. → restore a real pixel to tmail.php.
- **Visit/click beacon dead**: `tb_data_webpage_visit` empty (Page-Visit view blank); "clicked but
  didn't submit" unmeasurable. `deploy_hostpoint.sh` strips the `{{TRACKER_URL_ATTR}}` beacon. →
  re-enable a page-0 POST to track.php on landing load.
- **"Email Replied: Loading error!"** on the campaign dashboard (IMAP reply check hard-errors). → fail soft.

## Privacy — DECIDED

- **Captured passwords / OTP: shown in PLAINTEXT** (operator decision, 2026-07-16). Rationale:
  authorized awareness engagement — the operator needs the actual captured value for the follow-up
  ("this is the real password you gave away"). Guardrail: plaintext lives only in the **operator-tier**
  views (`analytics_recipients` / `analytics_hits`, RBAC `['super-admin','operator']`); the aggregate /
  `'*'` tier (`analytics_summary`) stays PII-free (funnel counts only, no values, email scrubbed).
  The pure core still reads only stage booleans; `decode_fields` (the report projection) surfaces the
  plaintext email/password/OTP for the operator report.

## Root causes (from deep analysis, 7-agent workflow 2026-07-16)

- ~~**Nav dead-click**: `js/libs/sidebarmenu.js` MISSING from 5 pages.~~ ✅ **DONE (P0b).** Fixed via
  shared `z_navboot.php` partial (NOT `z_footer` — that partial is emitted inside the content area
  before jQuery loads, so it can't carry JS). Locked by `NavBootstrapTest`.
- ~~**DataTables "Next" dead**: `recordsFiltered = sizeof($arr_filtered)` on LIMIT-sliced rows in 4
  managers.~~ ✅ **DONE (P0a).** Root cause confirmed exactly as analysed (Next gated by
  `recordsFiltered`; my earlier "fix #6" on `recordsTotal` was the wrong gate — corrected). Fix chosen:
  drop SQL LIMIT/OFFSET, filter the full set in PHP (search spans JSON/computed cols SQL LIKE can't
  reach — that's WHY the count was wrong), count it, slice via `taphish_dt_slice`. Shared helper is
  `datatables_helper.php`. Live-verified (see SHIPPED above).
- **Engagements listing/delete**: (1) area is a view over `tb_core_engagement` only; the classic
  Email Campaign builder saves `engagement_id=NULL` (mail_campaign.js never sends it), and Quick/Web
  trackers live in separate tables with no engagement_id column → non-wizard campaigns invisible.
  (2) Delete button only on the detail page; draft (wizard_step<7) picker shows only "Continue setup",
  never "Open" → abandoned drafts unreachable+undeletable. (3) list_engagements is `'*'` unfiltered but
  open/delete need member/owner → advertises rows the caller can't act on.

## Additional bugs found by the analysis (not previously reported)

- **Web Tracker list "#" column blank**: `web_tracker_list.js:200` binds renumber to
  `order.dt_web_tracker_list` — wrong namespace; DataTables fires `order.dt`. (report modal uses the
  correct one, proving the typo.)
- **Default sort broken**: `aaSorting:[3,'desc']` should be `order:[[4,'desc']]` (2-D array);
  and the `data-order` timestamp is on col 3 (Total Pages) instead of col 4 (Date Created) → Date
  Created sorts lexicographically. Same in quick_tracker.js / quick_tracker_report.js.
- **Engagement→dashboard deep-link opens empty**: engagement_view.js builds `?campaign_id=` but
  MailCmpDashboard reads only `?mcamp=` → picker always pops.
- ~~**Status "undefined"**: mail_campaign.js switch only handles 0–4; 5 falls through.~~ ✅ **DONE (P0c).**
  Note from the fix: code **6 is never set by any code path** (engagement_view.js had invented it);
  real codes are 0–5. NEW backlog item surfaced ↓.
- **NEW — camp_status 3 is overloaded** (found during P0c): status 3 means manual-stop AND
  failure-auto-pause AND auto-complete-terminal (4→3) — three different situations share one code, so
  the decoder can't tell "Completed" from "Error-paused". Kept the dominant "Completed" label (the
  failure case is already alerted via the send watchdog/Telegram). Proper fix (later phase): add a
  distinct error/stopped status so the list can show it honestly.
- **Import-HTML modal fatal + SSRF**: web_tracker_generator_list_manager.php:182 error branch calls
  `$stmt->error()` with no `$stmt` (fatal); the fetch is an unauth SSRF (VERIFYPEER off, follows redirects).
- **Dead handler**: web_tracker_generator_function.js posts `pause_stop_tracker_tracking` (manager
  handles `pause_stop_web_tracker_tracking`) to a URL missing the `manager/` prefix — leftover.

## Consolidation target (candidate IA — pending operator decisions)

1. **Trackers** — one section (List / New / Reports) with a type attribute (open-pixel vs web/form).
   Absorbs Quick Tracker group + Web Tracker group + the stray Web Tracker Report leaf + Create-New dupes.
2. **Reports / Analytics** — one report generator with a scope selector {Quick|Web|Campaign|Engagement},
   one shared column-picker + one `dt_server_response` + one `download_report`. Driven by the tested
   `engagement_analytics.php` core (give it a dispatcher action). Removes 4 copy-pasted export modals.
3. **Dashboards → ONE live dashboard** (fold Web-MailCamp into Email Campaign Dashboard as a "show web
   tracker" toggle); place under Engagements.
4. **Campaigns under Engagements** — Engagements = the all-campaigns hub (email+web+quick, scoped +
   an explicit "Unscoped/Legacy" bucket). Schema already has the nullable FK.
5. **Assets / Library** — Templates / Senders / Config / Pretexts as global reusable building blocks
   (User Group stays engagement-scoped, moves WITH Engagements).
6. **Shared platform layer** — nav bootstrap in z_footer; one `dt_server_response`; one camp_status
   decoder. Fixes both cross-cutting bugs structurally and lets the consolidations share one backend.

## What the interim dynamic cockpit already solves

One row per recipient (by email), captures merged via rid, per wave/cohort funnel — no page-segment
trap, no duplicate columns. Missing until the beacon fix: opens + pure-clicks. Missing until
decode_fields: the actual captured values (currently boolean "credentials ✓").

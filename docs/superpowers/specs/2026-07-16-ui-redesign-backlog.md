# TAPhish UI / IA redesign — operator-reported backlog

Living list of bugs + requirements gathered from the Textilcolor live run (2026-07-16), feeding the
`feature/ui-ia-redesign` analysis + plan. Nothing here is fixed yet — captured so it isn't lost.

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
- **Screen Res = "Failed"** for every hit (screen-resolution capture broken).
- **Country / geo empty** for every hit (ip_info geo lookup not populating).

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

- **Nav dead-click**: `js/libs/sidebarmenu.js` (binds the collapsible-group expand/collapse) is MISSING
  from EngagementView.php, PretextLibrary.php, SenderToolkit.php, ToolsetChecker.php, QuickStart.php →
  groups inert there; clicking Home re-binds it. Fix: add the include; better, move nav bootstrap
  (sidebarmenu.js + custom.min.js + common_scripts.js) into the shared `z_footer` partial.
- **DataTables "Next" dead**: Next is gated by `recordsFiltered`, and 4 managers set
  `recordsFiltered = sizeof($arr_filtered)` where the rows were ALREADY `LIMIT`-sliced to ~20 →
  ceil(20/20)=1 page. Managers: quick_tracker_manager:208, tracker_report_manager:205,
  web_mail_campaign_manager:436, mail_campaign_manager:455. ⚠️ **My earlier "fix #6" corrected
  `recordsTotal` only — WRONG gate; Next is still broken.** Also: search filtered in PHP AFTER the
  LIMIT (matches only current page); sort whitelists exclude JSON-derived cols. Fix: one shared
  `dt_server_response()` helper (correct pattern already in settings_manager:867 + userlist
  getUserGroupFromGroupIdTable:1221) doing real filtered COUNT + SQL search/sort/LIMIT.
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
- **Status "undefined"**: mail_campaign.js label switch only handles camp_status 0–4; 5 (tz-deferred)
  and 6 fall through → literal "undefined". camp_status has THREE divergent decoders (list/engagement/home).
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

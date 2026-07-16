# Native "Engagement Analytics" page — parallel integration plan

**Date:** 2026-07-16 · **Status:** plan (implementation deferred — parallel to the live campaign)
**Branch:** feature/engagement-analytics-dashboard · **Builds on:** the tested `engagement_analytics.php` core

## Goal

Add ONE native TAPhish page that renders the tested analytics core and, over time, **replaces the
three legacy report/dashboard menus** — non-breaking, in parallel: they stay live and unchanged
until the new page reaches parity, then get phased out.

## What the 3 legacy pages do (investigation)

| Page | File | Load-bearing features to preserve |
|---|---|---|
| **Web Tracker Report** | `spear/TrackerReport.php` (+ `js/web_tracker_report_functions.js`, `manager/tracker_report_manager.php`) | Page-SEGMENTED raw hits (Page-Visit=`tb_data_webpage_visit`, Page-N=`tb_data_webform_submit` by page); dynamic **`Field-<name>` captured VALUES** (the only view of *what* was typed); full per-hit forensics (public_ip, ip_info geo/isp/tz/coords, UA/browser/platform/screen/device/session); client-tz rendering; **CSV/PDF/HTML export** of selected+reordered cols; server-side DataTables; `?tracker=` deep-link + status badge. Tracker-scoped (shows anonymous hits with no rid). |
| **Email Campaign Dashboard** | `spear/MailCmpDashboard.php` (+ `manager/mail_campaign_manager.php` `multi_get_*`) | Per-campaign live **send lifecycle** (in-progress/success/error + `send_error` + IMAP bounce), sending **progress bar** vs full recipient denominator, **open telemetry** (count/first/last), replied donut (`getMailReplied`), radial/donut charts, per-recipient forensic table + 26-col picker + scanner-hide + single-vs-all, branded customer PDF, public `tk_id` share link. |
| **Web-MailCamp Dashboard** | `spear/WebMailCmpDashboard.php` (+ `manager/web_mail_campaign_manager.php`) | Closest analogue: campaign×tracker rid-join = mail send→open→web visit→submit on one **combined scatter timeline**; **Suspect Entry** (non-recipient traffic); per-page `SPPage-n` + per-field values + per-page %; reply/bounce; forensics both sides; configurable columns; export; public share. |

## Target architecture (5 files: 4 new, 2 edit — reuses the core)

**New**
- `spear/EngagementAnalytics.php` — page shell (copy `EngagementView.php` skeleton: session guard, `?engagement_id/?campaign_id/?tracker` deep-link parity, `z_menu` include). Cards: selector (engagement picker + campaign/tracker filters + Manual-Refresh + "Auto-refresh while sending" toggle); KPI/funnel + send-progress + in-progress/error/bounced/suspect badges; combined scatter timeline + donuts; by-wave/by-cohort + repeat-offender tables; server-side `#tbl_recipients` (union of all 3 pages' columns incl. `Field-<name>`, forensics, open/visit/submit counts) with select2 sortable column picker + scanner-hide + single/all; page-segmented raw-hit table (tracker parity); export modal.
- `spear/js/engagement_analytics.js` — controller IIFE (model on `engagement_view.js`); dispatcher `manager/engagement_analytics_manager`; DataTables serverSide; auto-refresh interval calls **only** `analytics_summary` and **only** while `camp_status==2`.
- `spear/manager/engagement_analytics_manager.php` — read-only JSON dispatcher (named `_manager` to not collide with the pure lib). Reuses `cli/engagement_report.php` gather logic (engagement_id scope + rid IN-lists, prepared statements). Actions: `analytics_list_scopes`, `analytics_summary`, `analytics_recipients`, `analytics_hits`, `analytics_export`, `analytics_reply_scan`, `analytics_poll_bounces`.
- `tests/EngagementAnalyticsCoreExtTest.php` — RED-first tests for the 7 core extensions + a regression lock that the existing funnel/wave/cohort/repeat/timeline outputs stay byte-identical.

**Edit**
- `spear/manager/authz.php` — register the new actions (default-deny). PII-free aggregate reads (`analytics_list_scopes`, `analytics_summary`) at `['*']`; recipient-PII / raw-hit / captured-value / export / reply / bounce at `['super-admin','operator']`.
- `spear/z_menu.php` — ONE flag-gated nav item near the 3 legacy links (`/spear/EngagementAnalytics`, auto-rewritten by `.htaccess` — no routing edit). Legacy items stay.

## Core extensions (TDD-first, in the pure `engagement_analytics.php`)

Locked by `EngagementAnalyticsCoreExtTest.php` red→green; existing tests must stay green (regression lock):
1. **Open telemetry** — `taphish_analytics_open_telemetry()` decodes `mail_open_times` → count/first/last/all (keep the `opened` boolean).
2. **Send lifecycle** — `taphish_analytics_send_status()` tallies delivered(2)/in-progress(1)/error(3)/waiting(4) + `send_error` + bounced (funnel still counts only status 2 → rates unchanged).
3. **Per-recipient counts/times** — keep visit_count/submit_count + first/last/all times (additive; don't touch clicked_at/credentials_at/otp_at).
4. **Suspect traffic** — `taphish_analytics_suspects()` → rids in visits/submits absent from sends.
5. **Per-page + captured VALUES** — `taphish_analytics_page_breakdown()` + `taphish_analytics_decode_fields($row,$formFieldMap)` resolving `Field-<idname>`→value (endpoint supplies the map from `tracker_step_data`; core stays pure).
6. Live send-status/refresh — no new core fn (endpoint composes summary+send_status; JS polls only while sending).
7. Forensics + IMAP reply — endpoint-level projections (ip_info expansion, `getMailReplied`), NOT core (keep the lib DB-free).

Plus a **feature flag** (constant or `tb_main_variables` row) gating the nav item + page for dark-launch; the authz guard remains the real security boundary.

## Selector, refresh, auth

- **Selector**: `?engagement_id=` drives the page (else an engagement picker); optional campaign/tracker filters; `?campaign_id=`/`?tracker=` honored for legacy deep-link parity.
- **Refresh**: Manual-Refresh re-runs active loaders; auto-refresh toggle `setInterval(~15s)` calls **only** `analytics_summary` and **only** while `camp_status==2`. Heavy recipient/hits/export + IMAP reply/bounce are explicit-click only.
- **Auth**: page `isSessionValid(true)`; endpoint `isSessionValid()` + `csrf_require()` + `taphish_require_authorize_or_die` before dispatch (default-deny). `analytics_summary` stays `['*']` ONLY because the handler scrubs email/name from timeline+repeat for `'*'` callers; PII only via `analytics_recipients` (operator+). Engagement row-scoping via the existing `taphish_user_group_scope_where`/`_guard_or_die` helpers.

## Parallel rollout → phase-out

- **A · Dark launch** — merge all 5 files, flag OFF, nav hidden; authz registered; core extensions landed test-first. Reachable by direct URL for dogfooding. Legacy untouched.
- **B · Funnel parity** — verify summary/recipients reproduce funnel + send lifecycle + open telemetry + suspects + forensics against Textilcolor eng 3, cross-checked vs the CLI.
- **C · Raw + export parity** — verify hits page-segmentation + `Field-<name>` + CSV/PDF/HTML match TrackerReport/WebMailCmp on the same tracker; verify reply/bounce (+ public share if kept).
- **D · Flag on** — enable flag, show the nav item; keep the 3 legacy items; accept `?tracker=`/`?campaign_id=` deep links.
- **E · Deprecate** — move the 3 legacy links under a collapsed "Legacy dashboards" group with a deprecation tooltip; keep the PHP files/endpoints live (public share links).
- **F · Retire** — after a release with no legacy usage in audit logs, remove the nav items; keep endpoints one release as redirects before deleting. Never delete an endpoint a live public `tk_id` link still needs.

## Risks

- **PII via `'*'` summary** — timeline/repeat carry email/name; the handler MUST scrub them for `'*'` callers, else downgrade `analytics_summary` to operator.
- **Brittle campaignMap parse** — depends on `TC · slot · Kohorte <c> · <wave>` naming; non-TC/renamed campaigns → wave/cohort `?`. Mitigate: key on `engagement_id`; ideally add explicit wave/cohort columns later.
- **Performance/memory** — keep recipients/hits strictly server-side paged SQL, scope every query engagement→campaign→rid; never run heavy/IMAP paths from auto-refresh; cap/stream TCPDF export.
- **Public share link** (`tk_id`/`amIPublic`) is load-bearing — replicate the gate or keep old pages alive for existing links before retiring endpoints.
- **Side-effecting parity** (bounce poll mutates rows; IMAP is slow) → explicit operator action only, never polled.
- **Anonymous tracker hits** — the rid-keyed core drops rid-less visits/submits; `analytics_hits` must offer a raw `tracker_id` filter (rid-join bypass) for TrackerReport parity.
- **Timestamp tz** — all times via `getInClientTime`/`getTimeInfo` or parity review fails.

## Build order (highest-value / lowest-risk first)

1. **TDD the 7 core extensions** (`EngagementAnalyticsCoreExtTest.php` red → green; keep existing tests green). Reusable, fully unit-testable, no UI.
2. **Dispatcher with `analytics_list_scopes` + `analytics_summary` only**, reusing the CLI gather logic; register in authz; verify JSON vs the CLI for eng 3.
3. **Page shell + JS** rendering selector + funnel/KPIs/charts from `analytics_summary` (flag OFF); confirm `/spear/EngagementAnalytics` loads and matches the old donuts.
4. Add `analytics_recipients` (+ column picker/forensics/`Field-<name>`/open telemetry), `analytics_hits` (page-segmented raw), then `analytics_export`, then reply/bounce.
5. **Last**: flag-gated nav entry in `z_menu.php`, flag on for operators; keep all 3 legacy menus intact.

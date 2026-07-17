# Cleanup + Dashboard Fold + Unified Reports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or
> superpowers:executing-plans. Steps use `- [ ]`. Branch: `feature/dashboard-reports-fold` (off merged main).

**Goal:** Assign the running campaign's trackers to their engagement + clean up old test trackers; then
fold the two campaign dashboards into one page; then unify the two tracker reports into one generator.

**Architecture:** Reuse the P1 assign/delete endpoints for cleanup. For the folds, the WEB client is the
superset in each pair — make it tracker/type-aware and retire the email-only / quick-only twin, rather than
co-loading two conflicting clients.

**Tech Stack:** PHP 8 + MySQL, vanilla JS + jQuery + DataTables, PHPUnit (tests/), deploy = scp + sha256 +
`php -l` to Hostpoint (`azitufem@sl2084…:/home/azitufem/www/deepaudit.ch`), drive/verify via the
authenticated debug-Chrome CSRF bridge (`~/.taphish-tools/*.mjs`).

**Standing rules:** TDD (RED→GREEN, keep the ~1049 suite green). Deploy discipline: `.bak` + base-check +
sha256 + `php -l`, STOP on drift. Live campaign is ARMED (st=1 slots pending) → touch only view/report/nav,
NEVER the capture/send path. Every deploy is a checkpoint; demo PII-safe (counts/booleans, never
credentials). Each live-verify uses `page.setCacheEnabled(false)` (stale JS bit me before).

---

## Phase 0 · Zusatztask — engagement cleanup (do FIRST; data admin, no new build)

**State (verified 2026-07-17):** 1 engagement `#3 textilcolor-ag-awareness-2026` (draft). 16 mail campaigns
already scoped to eng 3. **9 web trackers all `TC-*` = Textilcolor, UNSCOPED**; **3 quick trackers old/
non-TC** (`tst` 5 hits, `MUSIC.NULLTAG.CH` 320, `vampire` 54).

### Task 0.1: Assign the 9 TC web trackers to engagement 3
Reuse `assign_engagement` (manager `userlist_campaignlist_mailtemplate_manager`, built P1.4b; type=`web`).
Web tracker ids: `8mpkgv tkwf5d kc9etb 5kogr0 5zs3k9 7vk54l bsqk7v h8macz ymj27p`.
- [ ] Driver (extend `~/.taphish-tools/`): for each id → `post(UM,{action_type:'assign_engagement',type:'web',id,engagement_id:3})`.
- [ ] Verify: `SELECT COUNT(*) FROM tb_core_web_tracker_list WHERE engagement_id=3` == 9; unscoped web == 0.
- [ ] Verify in UI: `/spear/EngagementView?engagement_id=3` hub now lists the 9 web trackers (type-tagged);
      Unscoped bucket web count == 0.

### Task 0.2: Back up then clean up the 3 old quick trackers
"bereinigen" = remove the old non-engagement trackers, but back up first (destructive: 320+54+5 hits).
- [ ] Back up (server): dump `tb_core_quick_tracker_list` + `tb_data_quick_tracker_live` rows for
      `3vb1d5 xs7n6u x7xow0` → `~/backups/quick_trackers_20260717.json` (metadata + hit rows, PII-safe file).
- [ ] Delete each via the existing action: `post('manager/quick_tracker_manager',{action_type:'delete_quick_tracker',tracker_id:id})` (cascades to `deleteQuickTrackerData`).
- [ ] Verify: `SELECT COUNT(*) FROM tb_core_quick_tracker_list` == 0; unscoped quick == 0; the unified
      `/spear/Trackers` list now shows only the 9 TC web trackers.
- [ ] NOTE for operator review: `MUSIC.NULLTAG.CH` had 320 hits — deleted per "bereinigen"; restorable from the backup.

### Task 0.3 (optional): transition engagement 3 draft→live
The engagement is `draft` but its campaigns are running. `engagement_transition_status` (owner/super-admin).
- [ ] Decide with operator on review; leave draft for now (no functional impact). Note only.

**Commit:** none (data ops); record results in the progress log + memory.

---

## Phase 1 · Dashboard single-page fold (Web-MailCamp → the one "Campaign Dashboard")

**Design (from `2026-07-17-attended-tasks-plan.md`):** the web dashboard (`WebMailCmpDashboard.php` +
`web_mail_campaign_dashboard.js`) is the superset. Make the tracker OPTIONAL + add a "Show web tracker"
toggle; when no tracker / toggle off → email-only (== the old Email dashboard). Retire the email-only leaf.
Do NOT co-load `mail_campaign_dashboard.js` (hard global conflicts).

**Files:**
- Modify: `spear/js/web_mail_campaign_dashboard.js` (campaignSelectedValidation:191, campaignSelected:215,
  the web-feed/columns/graphs branches, the picker), `spear/WebMailCmpDashboard.php` (tracker selector
  optional + a "Show web tracker" toggle), `spear/z_menu.php` (point the group's single item at it).
- Create: `spear/manager/dashboard_view.php` (pure helpers) + `tests/DashboardViewTest.php`.

### Task 1.1: Pure — decide which sections render for a given (tracker?, toggle) state
- [ ] RED `tests/DashboardViewTest.php`: `taphish_dashboard_sections(hasTracker, showWeb)` →
      `{email:true, web: hasTracker && showWeb}`. Cases: (no tracker, on)→web:false; (tracker, off)→web:false;
      (tracker, on)→web:true; (no tracker, off)→web:false.
- [ ] Run → FAIL (undefined). GREEN: implement in `spear/manager/dashboard_view.php` (require in helpers_shim).
- [ ] Commit.

### Task 1.2: Make the tracker optional in the client
- [ ] `campaignSelectedValidation` (web_mail_campaign_dashboard.js:191): drop the mandatory
      `#modal_web_tracker_selector` empty→invalid check; allow campaign-only.
- [ ] `campaignSelected(campaign_id, tracker_id='')`: guard every web branch (`updateWebCampGraphs`,
      the `web_mail_campaign_manager` feed, the `wcm_/wpv_/wfs_/Field-` columns, the web pies/graph) behind
      `tracker_id !== ''` — when empty, render email-only (mirror the old Email dashboard).
- [ ] Deep-link handling: `?mcamp=` alone (no `&tracker=`) → email-only; `&tracker=` present → full.
- [ ] `node --check`; guard test asserts the web branches are tracker-gated (grep for the guard).

### Task 1.3: Toggle UI + retire the email-only page
- [ ] `WebMailCmpDashboard.php`: the web-tracker selector becomes optional; add a "Show web tracker" switch
      (`#cb_show_web`) that reveals the tracker selector + web sections; hidden = email-only.
- [ ] `web_mail_campaign_dashboard.js`: on `#cb_show_web` change → re-run `campaignSelected` with/without tracker.
- [ ] `z_menu.php`: collapse the P4 group to a single "Campaign Dashboard" → `/spear/WebMailCmpDashboard`
      (keep `MailCmpDashboard` reachable/redirect for one release).
- [ ] Guard test updated; suite green.

### Task 1.4: Deploy + live-verify (explicit deploy discipline)
- [ ] Deploy the changed files (`.bak` + base-check + sha256 + `php -l`).
- [ ] Demo (`~/.taphish-tools/demo_dashfold.mjs`, cache disabled): (a) a campaign WITHOUT a tracker → email
      metrics render, no web section, no JS error; (b) a campaign WITH a tracker + toggle on → web columns +
      web graph appear. Both against eng-3 campaigns. PII-safe (counts/booleans).
- [ ] Commit + update backlog/plan/memory.

---

## Phase 2 · Unified Reports generator (one type-aware report)

**Design:** one `TrackerReports.php` + `tracker_reports_unified.js`. Cross-type picker via the built
`list_all_trackers`. Branch on tracker `type`: quick → `get_quick_tracker_data` + fixed dict; web →
`get_web_tracker_from_id`(step_data)→dynamic `Field-*` dict + page selector →
`get_table_webpage_visit_form_submission`. Shared: the P2.2 scanner-hide toggle, the P3
`taphish_decode_capture_fields` for per-victim dedup, one column-picker, one export (`download_report`).

**Files:**
- Create: `spear/TrackerReports.php`, `spear/js/tracker_reports_unified.js`,
  `spear/manager/report_config.php` (pure type→config) + `tests/ReportConfigTest.php`.
- Modify: `spear/z_menu.php` (Trackers group → add "Reports" → `/spear/TrackerReports`).
- Reuse (no change): the two report feeds (already dt-helper + scanner-hide) + `list_all_trackers`.

### Task 2.1: Pure — type→report config
- [ ] RED `tests/ReportConfigTest.php`: `taphish_report_config('quick')` →
      `{manager:'manager/quick_tracker_manager', action:'get_quick_tracker_data', hasPageSelector:false, dict:{…quick fixed cols…}}`;
      `('web')` → `{manager:'manager/tracker_report_manager', action:'get_table_webpage_visit_form_submission', hasPageSelector:true, dict:{…web fixed cols…}}`;
      unknown → null. (Dicts copied verbatim from quick_tracker_report.js:4 / web_tracker_report_functions.js:4.)
- [ ] Run → FAIL. GREEN: implement `spear/manager/report_config.php` (+ helpers_shim). Commit.

### Task 2.2: The unified client (type-aware)
- [ ] `tracker_reports_unified.js`: picker (list_all_trackers modal or select) → on pick store `type`,`id`.
      Build `dic_all_col` from the type config; for web also fetch `get_web_tracker_from_id` → append dynamic
      `Field-<name>` per page + populate the `#reportTypeSelector` (page selector, web only).
- [ ] serverSide DataTable → the type's feed+action, injecting `tracker_id`, `selected_col`, `page` (web),
      `hide_scanner` (the P2.2 toggle). Reuse the P2.0-correct `order.dt` renumber pattern.
- [ ] Export: `download_report` to the type's manager.
- [ ] `TrackerReports.php`: the shell (picker + `#reportTypeSelector` + column-picker + `#cb_hide_scanner`
      + results table + export modal); loads `js/camp_status.js`? (no) + the unified client; `z_navboot`.
- [ ] `node --check` + structural guard test (page has the table + client wired + list_all_trackers).

### Task 2.3: Deploy + live-verify
- [ ] Register no new authz (reuses existing feed actions). Deploy (discipline).
- [ ] Demo (`demo_unified_reports.mjs`, cache disabled): pick a QUICK tracker → report table populates
      (counts); pick a WEB tracker (e.g. TC-K3-ONEDRIVE, 40 submits) → page selector appears, Field- cols
      build, scanner toggle drops rows. PII-safe.
- [ ] `z_menu.php`: add "Reports" to the Trackers group. Retire the stray report pages from nav (kept
      reachable). Commit + docs.

---

## Phase 3 · Next-round capture-fix DEPLOY (operational; per checklist)

Prepared + committed in #173; deploy at the next campaign wave per
`docs/superpowers/specs/2026-07-17-next-round-deploy-checklist.md`:
- [ ] `mail_campaign_cron.php` (pixel guarantee) — deploy with discipline.
- [ ] m365 landing (`deploy_hostpoint.sh`) — page-0 visit beacon + screen_res to the look-alike host(s).
- [ ] Post-wave verify: `tb_data_webpage_visit` gains page-0 rows; `screen_res` real not "Failed".
(Do NOT deploy mid-wave if recipients are actively in-flight.)

---

## Self-review
- Phase 0 covers the zusatztask (assign TC trackers + cleanup old, with backup). ✓
- Phase 1 = dashboard fold; Phase 2 = unified reports (both from the attended design). ✓
- Phase 3 = the prepared next-round deploy. ✓
- No new authz needed (Phase 1/2 reuse existing actions; assign/delete already registered). ✓
- Risk gates: tracker-optional guard (1.2), type config (2.1) are pure+TDD; both folds demo BOTH branches
  live before retiring twins. Capture/send path untouched.
</content>

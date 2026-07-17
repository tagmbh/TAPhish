# Attended tasks — two big rewrites (do WITH the operator)

Both are genuine single-page "merges" the operator wants, but both are large rewrites of **live** clients
that share hard global conflicts + dynamic columns/charts. Delivered so far: the safe consolidations
(P4 = one "Campaign Dashboard" nav group with Email + Web-tracker views; Reports entry = the P2.1 unified
`/spear/Trackers` list with per-row Report links). The full folds below break the live campaign's
dashboards/reports if done half-right — hence attended.

## A · Dashboard single-page fold (Web-MailCamp → Email, "show web tracker" toggle)

**Why it's big.** `mail_campaign_dashboard.js` and `web_mail_campaign_dashboard.js` are near-clones that
BOTH define `g_tb_data_single`, `dic_all_col`, `camp_status_def`, `camp_table_status_def`,
`allReportColListSelected`, and functions `campaignSelected`, `getAllReportColListSelected`,
`updateProgressbar`, `startLoaders`, `updatePie*`, `viewReplyMails`, … → can't co-load. Different feeds
(`mail_campaign_manager` vs `web_mail_campaign_manager`), different column sets (web adds
`wcm_*/wpv_*/wfs_*/Field-*`), and an extra web graph. The web dashboard also HARD-REQUIRES a
campaign+tracker pairing (`campaignSelectedValidation`, web_mail_campaign_dashboard.js:191).

**Recommended approach (lowest-risk real fold).** Make the **web** dashboard (the superset) the single
"Campaign Dashboard", with the tracker OPTIONAL + a "Show web tracker" toggle:
1. `campaignSelectedValidation`: drop the mandatory tracker; allow campaign-only.
2. `campaignSelected(campaign_id, tracker_id='')`: when `tracker_id===''` → skip the web feed, the
   `wcm_/wpv_/wfs_/Field-` columns, `updateWebCampGraphs`, and the web pies; render email-only (== the
   old Email dashboard).
3. A "Show web tracker" toggle: hidden→email-only; on→reveal the tracker selector + web columns/graph.
4. Point the single nav item at it; retire `MailCmpDashboard` (or keep as a redirect).
5. Heavy TDD on the pure column/graph selection; live demo BOTH a mail-only campaign and a
   campaign+tracker pair before retiring the email page.
**Risk:** it's the live campaign's dashboard — validate against the running engagement before switching nav.

## B · Unified Reports generator (one report across all trackers + campaigns)

**Why it's big.** Same wall: `web_tracker_report_functions.js` + `quick_tracker_report.js` both define
`g_tracker_id`, `dic_all_col`, `getAllReportColListSelected`, `exportReportAction` (P2.2 note). The WEB
report also builds DYNAMIC `Field-<name>` columns from the tracker's `tracker_step_data` per page, plus a
page/report-type selector; QUICK is fixed-column. Merging = one type-aware client re-implementing both
column strategies + both feeds + export.

**Recommended approach.** One `TrackerReports.php` + `tracker_reports_unified.js`:
1. Cross-type picker via `list_all_trackers` (already built, P2.1).
2. On pick, branch on `type`: quick → `get_quick_tracker_data` feed + fixed dict; web →
   `get_web_tracker_from_id` (step_data) → dynamic `Field-*` dict + page selector →
   `get_table_webpage_visit_form_submission` feed.
3. Shared: the P2.2 scanner-hide toggle, the P3 `taphish_decode_capture_fields` for one-row-per-victim
   (currently the tracker report is per-submission — the drill-down), one column-picker, one export.
4. TDD the pure type→config mapping + the per-victim dedup; demo both types live.
Absorbs the deferred backlog item "dedup to one row per victim (per-hit on drill-down)".

## Also fold in when doing A/B
- Screen Res / City geo upgrade (City Lite mmdb is a drop-in — swap the file, `taphish_geo_from_mmdb_record`
  already projects city/coords/timezone).
- `decode_fields` as the report's per-victim projection (its real second consumer).

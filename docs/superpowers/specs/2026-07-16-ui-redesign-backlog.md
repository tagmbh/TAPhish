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

## What the interim dynamic cockpit already solves

One row per recipient (by email), captures merged via rid, per wave/cohort funnel — no page-segment
trap, no duplicate columns. Missing until the beacon fix: opens + pure-clicks. Missing until
decode_fields: the actual captured values (currently boolean "credentials ✓").

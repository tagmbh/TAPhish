# Engagement Analytics — consolidated dashboard & reporting

**Date:** 2026-07-16 · **Status:** approved (design) · **Branch:** feature/engagement-analytics-dashboard

## Problem

Campaign results are spread across separate menus and page-segmented tables:
send/open in `tb_data_mailcamp_live` (Email Campaign Dashboard), click/credential in
`tb_data_webpage_visit` + `tb_data_webform_submit` (Web Tracker Report, one page per step).
For an engagement with 16 campaigns × 4 waves × 4 cohorts, an operator must click through
campaigns × trackers × pages to see who fell for what. There is no single funnel view.

All the data is linked by **`rid`** (one per recipient per campaign): the send row carries the
rid; the mail CTA carries the rid into the landing; `track.php` writes visit/submit rows with the
same rid + `tracker_id`. So a single join by rid yields the full per-recipient funnel.

## Goal

One consolidated system serving BOTH operator live-monitoring AND the client final report, with:
1. **Funnel** — Delivered → Opened → Clicked → Credentials → OTP, counts + rates, overall / per
   wave (K1–K4) / per cohort (A–D).
2. **Recipient detail** — per person × wave: stage timestamps, searchable/filterable.
3. **Timeline + risk** — clicks/submits over time, time-to-click, OTP-submitters (highest risk).
4. **Repeat offenders / awareness progress** — who fell across multiple waves; per-cohort trend
   across the 4 rotation slots.

## Architecture — Hybrid (C)

Three layers; the core is built once and reused.

- **Core (`spear/manager/engagement_analytics.php`)** — pure, unit-tested aggregation functions.
  Input: raw rows (sends, visits, submits) + a `campaign_id → {wave,cohort,slot}` map. Output: the
  four view models above. No DB/HTTP — fully testable with fixtures, loaded by the test shim like
  the other pure helpers.
- **Phase 1 (during campaign, safe): CLI + standalone cockpit.**
  - `spear/manager/cli/engagement_report.php` — CLI wrapper: reads engagement 3's campaigns +
    capture rows, calls the core module, prints JSON. Read-only; touches nothing on the send path.
  - `~/.taphish-tools/cockpit.mjs` — fetches the JSON (via SSH), renders ONE self-contained,
    auto-refreshing HTML page (all four views) + a T-ALPHA-branded PDF export.
  - **No web-app/UI changes** → safe to build, run, and test in parallel with the live campaign.
- **Phase 2 (after the 4 slots): native page.** A TAPhish "Engagement Report" page + a read-only
  manager endpoint that calls the SAME core module. Integrated into nav/auth. (Deferred.)

## Funnel stage definitions (by rid)

- **Delivered** — `tb_data_mailcamp_live.sending_status == 2`.
- **Opened** — `mail_open_times` non-empty.
- **Clicked** — rid present in `tb_data_webpage_visit` OR `tb_data_webform_submit`.
- **Credentials** — rid has a `tb_data_webform_submit` row (submitted the fake login form).
- **OTP** — a submit row with `is_2fa_capture == 1`.

Stages are monotonic for reporting (OTP implies Credentials implies Clicked …); the builder
derives booleans per rid and rolls them up. Each rid counted once (dedup on repeated submits).

## Data model (core output)

```
{ funnel:{delivered,opened,clicked,credentials,otp, rates:{...}},
  by_wave:{ K1:{funnel...}, K2:{...}, K3:{...}, K4:{...} },
  by_cohort:{ A:{funnel...}, B:{...}, C:{...}, D:{...} },
  recipients:[ {email,name,wave,cohort,delivered,opened,clicked,credentials,otp,clicked_at} ],
  timeline:[ {ts, kind:'click'|'credentials'|'otp', wave, cohort} ],
  repeat_offenders:[ {email, waves:['K1','K3'], clicks:n, credentials:n} ] }
```

## Privacy / security

- **Captured passwords are NEVER surfaced.** The core module reads only stage booleans +
  `is_2fa_capture` + timestamps — never `form_field_data` values. Views show "credentials
  captured ✓", not the plaintext. Client report shows no raw credentials.
- Data is DSG/GDPR-sensitive; reporting supports purge after the programme
  (`clear_captures` already exists).

## Testing (TDD)

Core module built test-first (PHPUnit, joins the existing 965-test suite):
- funnel counts + rates from fixtures; per-wave / per-cohort rollups;
- recipient stage derivation (delivered/opened/clicked/credentials/otp);
- repeat-offender detection across waves (same email, ≥2 waves);
- edge cases: unknown/mismatched tracker rid, opened-but-not-clicked, multiple submits per rid
  (dedup), tester present in all cohorts, empty dataset → zeroed funnel (no divide-by-zero).

CLI + cockpit are thin I/O/presentation layers over the tested core; validated by rendering the
demo against a representative dataset and (post-09:15) live data.

## Non-goals (YAGNI)

Department breakdowns (no dept data in the CSV), real-time websockets (periodic refresh is
enough), and the native page (Phase 2, deferred) are out of scope for the Phase-1 beta.

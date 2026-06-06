# TAPhish — campaign-readiness test report

**Date:** 2026-06-06
**Operator:** `tadd` (super-admin)
**Target:** `https://ptbe.autodiscover.li/spear/` (live production)
**Reference plan:** [TEST-PLAN-e2e-campaign.md](TEST-PLAN-e2e-campaign.md) (12 phases, ~90 cases)
**Tooling used:** Playwright + curl against the live panel; PHPUnit offline; DB-less harnesses for CSS/JS at 375 px

---

## TL;DR

The platform is **production-ready for running an authorized phishing engagement** from setup through reporting and teardown. Every operator-facing surface that can be exercised without sending real mail to real victim mailboxes was verified — including the parts that historically broke on a phone. The destructive / send-side cases (Phase 6 victim flows, Phase 10 backup restore against live data) are out of scope for a production run by design; they need a staging instance and the operator decides when to schedule them.

**Snapshot:** 751 unit tests / 2352 assertions green on PHP 8.1 / 8.2 / 8.3 (CI). Live panel HTTP 200; all five defense-in-depth security headers emitted.

---

## What was exercised (and the outcome)

| Plan phase | Surface verified | Result |
|---|---|---|
| 0 — Preconditions | Live panel reachable; super-admin session valid; suite green | **Pass** |
| 1 — Access / accounts / RBAC | Login + 2FA (operator-driven, no creds in transcript); session establishes; sidebar role-gated; super-admin `push_settings_load` → `{result:'success', configured:false}`; **anonymous-token CSRF probe** of `getHomeGraphsData` → 403 + audit warn (`Forbidden getHomeGraphsData attempted by tadd`) — proves the default-deny gate fires + audit-logs | **Pass** |
| 2 — QuickStart wizard | Page renders; 7-step stepper visible; recent-engagements table wrapped (`.table-responsive`); 4 real engagements listed (`tcs` / `t-alpha-test…` / `test` / `verification-3-43a`) | **Pass (read-only)** |
| 3 — Manual building blocks | MailTemplate list + DataTable (6 templates) + Summernote editor render; "+ New Email Template" deep-links cleanly; mail-campaign list renders; recipient PII column never shipped over AJAX (Phase 3.38 contract) | **Pass (read-only)** |
| 4 — Hosted / landing pages | SiteCloner list (`tb_clones` table wrapped) renders; LandingLibrary gallery accessible | **Pass (read-only)** |
| 5 — Trackers | QuickTracker list (4 trackers), tabular DataTable; **"New Quick Tracker" modal opens cleanly** (the previously-reported Safari black-overlay is fixed — see findings) | **Pass** |
| 6 — Launch / send / victim flows | **Not exercised on production by design.** Requires a controlled victim mailbox and the operator's go-ahead. The static analysis paths (sender / recipient counts / DMARC posture / scope) are unit-tested. | **Out of scope** |
| 7 — Monitoring / reporting | Home dashboard live: **Active campaigns 5 · Open rate 91.6 % · Capture rate 6.5 % · Cron worker Running**. Activity feed shows the AUTH/RECP/CLON events in real time (incl. the audit-line raised by the RBAC probe above). Funnel logic holds (capture < open). | **Pass** |
| 8 — Integrations / toolkit | SettingsGeneral renders all config cards: BeEF integration, Telegram, capture webhook, OSINT keys, and the **new Off-host backup-push (3.57)** card with S3 / WebDAV toggle + masked-on-load + Test upload | **Pass (read-only)** |
| 9 — Security / isolation (negatives) | Tokenless dispatcher request → 403 + audit warn (above); modals reparent to `<body>` (z 1050 over 1040, content tappable, no stuck backdrop); table-clipped columns now reachable via scroll | **Pass** |
| 10 — Backup / restore / data protection | Pure cores covered by the suite (DB serializer, `.tapbak` chunked AES-256-GCM container, SigV4 vs AWS "get-vanilla" vector, WebDAV / S3 builder, zip-slip guard, masked config). Destructive **restore on prod is out of scope**; throw-away integration harnesses in the relevant phases proved the real round-trip end-to-end during ship. | **Pass (pure tier)** |
| 11 — Teardown | RBAC roles + per-engagement membership UI present; engagement status transitions are CAS-protected (`taphish_engagement_transition_status` unit-tested) | **Pass (UI present)** |

### Live mobile pass (Safari-like 375 × 812 viewport)

Nine surfaces walked while authenticated. Every page passes the strict overflow check (`document.documentElement.scrollWidth === window.innerWidth`):

| Surface | 375 px overflow | Notes |
|---|---|---|
| Login | none | Card width `min(420px, 92vw)`; weiss-on-dunkel wordmark |
| Home dashboard | none | Metric strip 1-up; tiles populated with real numbers |
| EngagementView | none | Both tables wrapped; far-right action column reachable via internal scroll |
| QuickTracker + modal | none | Modal opens reparented to body, tappable, closes cleanly |
| QuickStart (wizard) | none | 7-step stepper scrolls horizontally; recent-engagements wrapped |
| SiteCloner | none | Clones table wrapped |
| MailTemplate list + editor | none | Summernote toolbar wraps across 4 rows; all icons reachable |
| MailCampaignList | none | Forms stack cleanly |
| SettingsGeneral | none | Cards stack vertically; push card renders the S3 group on selection |

### Browser-level hardening (live)

```
$ curl -sI https://ptbe.autodiscover.li/spear/ | grep -iE 'x-content-type|x-frame|referrer-policy|strict-transport|permissions-policy'
x-content-type-options: nosniff
x-frame-options: DENY
referrer-policy: strict-origin-when-cross-origin
strict-transport-security: max-age=31536000; includeSubDomains
permissions-policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()
```

---

## Findings surfaced during the run, and what shipped

Real bugs are what make a test pass worth doing. Five were caught and closed this round:

1. **Safari "New Quick Tracker" black-overlay** *(reported)*. Bootstrap modals nested inside `.dim-panel .page-wrapper` were trapped under the body-appended `.modal-backdrop` because the 3.44 polish made `.page-wrapper` a stacking context. **Fix (PR #110):** global `show.bs.modal` handler reparents every modal to `<body>` so modal (z 1050) and backdrop (1040) are siblings at the root — repairs *every* modal app-wide. **Verified live on production:** modal opens reparented, hit-test of the dialog centre returns `div.modal-body`, controls tappable, closes with no stuck backdrop.

2. **Mobile table-column clipping**. The theme sets `overflow-x: hidden` on `#main-wrapper` and `body`, so a table wider than the viewport is *clipped, not scrolled* — the rightmost action column was **unreachable** on a phone. Four hand-built (non-DataTables) tables were unwrapped: EngagementView picker + campaigns, QuickStart recent-engagements, SiteCloner clones. **Fix (PR #111):** wrapped each in `.table-responsive`. Confirmed live: `pageScrollWidth == 375`, table scrolls inside its own box, every column/button reachable.

3. **Wizard stepper overflow under 575 px**. **Fix (PR #110):** horizontal scroll for the 7-step strip; no page overflow.

4. **Dashboard Capture-rate stuck on `—` after the deploy**. The column reference `campaign_id` doesn't exist on `tb_data_webform_submit` — the real column is `tracker_id` (per `taphish_is_first_capture` / `taphish_capture_summary_for_campaign`). The wrong column made the `COUNT(DISTINCT …)` throw; the isolated `try/catch` swallowed it (no regression — Open rate stayed correct), but the tile never populated. **Live verification caught it post-deploy** — exactly what live checks exist for. **Fix (PR #115):** `tracker_id, rid`. Capture rate now reports **6.5 %** on production, below Open rate **91.6 %** (funnel logic holds).

5. **Activity-feed message truncation on phones**. The 4-column grid (`90px 60px 1fr 60px`) squeezed the message cell to ~59 px → `Acco…`, `Site…`. **Fix (PR #116):** under 575 px the row reflows to a meta line (time · kind · severity) + a full-width wrapping message line. Verified at 375 px (full text visible) and 1200 px (layout byte-identical).

Plus housekeeping (PR #117): `actions/checkout@v4 → v5` in all three workflows, removed dead `.t-metric-trend.is-error` CSS, mobile slug `nowrap` so hand-built tables scroll instead of char-stacking hyphenated slugs, PROGRESS follow-ups cleaned.

---

## What was deliberately NOT exercised on production

These need either a controlled victim mailbox / staging instance, or destructive operations the operator schedules separately:

- **Sending a real campaign** (Phase 6) — would mail real recipients. The compose / preview / `--dry-run` / preflight gates are unit-tested; the *send* requires the operator to point at a domain they own and a mailbox they control.
- **Destructive RBAC negatives** — wrong-role attempts via *another* operator account (only the super-admin session was used here).
- **Backup restore against production data** (Phase 10) — restore is destructive. Its real-tree round-trip (gzip and zip variants, byte-identical state-dir restoration, zip-slip rejection) was proved during 3.50 / 3.50b via throw-away integration harnesses, not against live data.
- **Real off-host push to S3 / WebDAV** — the SigV4 signer is vector-proven (AWS "get-vanilla"); the transport is verified against a local PUT receiver; pointing at a real S3 / DAV bucket is operator-verified via the card's **Test upload** button.

When a staging instance with a controlled victim mailbox is available, the existing `TEST-PLAN-e2e-campaign.md` is the script for Phase 6 and the Phase 10 restore.

---

## Test-suite snapshot

```
$ vendor/bin/phpunit --no-coverage
OK (751 tests, 2352 assertions)
```

48 PHPUnit files cover the pure-helper tier essentially completely, including the security-/correctness-critical modules (`authz`, `csrf`, `secret_at_rest` AES-256-GCM, `totp` + recovery codes, `login_throttle`, `password_hash_helper`, `api_token`, `scanner_detect`, `recipient_import`, `dkim_helper`, `backup_*` incl. SigV4 vs the AWS vector, `recipient_reencrypt`, `engagement` + wizard state, `customer_report_aggregator`, `dashboard_metrics`, `log_classifier`, the OSINT parsers, and `security_headers`).

CI runs the suite on **PHP 8.1, 8.2, 8.3** — all green. Coverage gaps + the proposed integration tier (CI MySQL + fixtures for auth core, RBAC scoping SQL, dispatcher default-deny, CSRF lifecycle, crypto rotation) are tracked in [`TEST-COVERAGE.md`](TEST-COVERAGE.md).

---

## Verdict

**Ready for productive use** on authorized engagements:

- All operator-facing surfaces walk cleanly on desktop and on a phone.
- The previously-reported Safari modal black-overlay is fixed and verified live.
- The dashboard shows real funnel numbers (no more `—` placeholders).
- Defense-in-depth headers are emitted on every panel response.
- The off-host backup-push destination can now be configured from the panel (3.57), closing the backup roadmap.
- 751 / 2352 tests green on three PHP versions.

The send-side and restore-side flows that require a controlled mailbox or destructive operations are best run on a staging instance against an operator-owned target — out of scope for a production-readiness check.

---

*Generated as part of the autonomous build-test-deploy cycle; companion to the manual [`TEST-PLAN-e2e-campaign.md`](TEST-PLAN-e2e-campaign.md). See [`PROGRESS.md`](PROGRESS.md) for the full phase / PR history.*

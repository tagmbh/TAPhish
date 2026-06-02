---
layout: page
title: Progress + roadmap
permalink: /progress.html
---

# TAPhish — progress + roadmap

Living document. Updated when phases ship.

## Where we are

- Production fork live at <https://ptbe.autodiscover.li/spear/> (operator panel).
- **Test suite**: 415 tests / 1243 assertions, all green.
- **Last verified end-to-end**: Phase 3.42 + 2 hotfixes (jQuery paths, CSRF rotation) — Playwright-walked every page, every AJAX endpoint hits 200, zero console errors across the panel. Phase 3.43a + alert-contrast + SiteCloner-UX deployed 2026-06-02; live verification pending FTPS deploy completion.
- **CI/CD**: GitHub Actions → FTPS deploy to Hostpoint Shared.

## Phases completed

| Phase | What landed |
|---|---|
| 3.32 | Visual polish — operator-console aesthetic: design tokens, distinctive type stack, layered atmospheric backdrops, refined inputs/modals/badges |
| 3.33 | Dim mode for sidebar + Home dashboard. New metric strip + activity feed. Pure log-entry classifier with audit-log routing. |
| 3.33b | `t-alpha` brand identity (logo, color rebased on #0071BB sampled from the wordmark) |
| 3.33c | Sidebar `<ul>` background gap + drop dead `home_functions.js` + tighter cron class hygiene |
| 3.34 | Dim-panel rename + Bootstrap surface rules (.card/.form-control/.modal/.table/.dropdown/.alert). Settings pages opted in. |
| 3.35 | Audit-log coverage for campaign / recipient list / mail template lifecycle (9 new `logIt()` callsites + classifier rules for created/updated/deleted/copied) |
| 3.36 | Dim-panel on every remaining internal page (16 pages). Third-party widget themes for DataTables, Select2, Summernote, nav-tabs, list-group, custom-control. |
| 3.37 | Self-host the three brand fonts (Bricolage Grotesque + IBM Plex Sans + JetBrains Mono). Latin-subset variable woff2 under `spear/css/fonts/`. Drops Google Fonts CDN. |
| 3.38 | AES-256-GCM at-rest encryption for `tb_core_mailcamp_user_group.user_data` (recipient PII). `recipient_data_seal/unseal` wrappers. Transparent migration via passthrough; `getUserGroupList` no longer ships PII over AJAX. |
| 3.39 | Pretext + landing-page template library (12 starter pretexts across Authentication / Finance / HR / IT / Shipping). New `tb_core_pretext_library` + idempotent seed. Gallery page + clone-to-my-templates action. |
| 3.40 | URL-scanner detection on `qt.php` and `tmail.php`. Pure `taphish_classify_visitor()` covers ~30 known vendors (SafeLinks, Proofpoint, Mimecast, Cisco, etc.) by UA + datacenter PTR + sub-5s timing. Scanner hits skip the tracker INSERT and surface as `SCAN/warn` in the activity feed. |
| 3.41 | Pre-engagement sender toolkit. Homoglyph + typo + TLD-swap domain generator. SPF / DMARC posture analyzer with operator-facing verdict (hardened / partially-hardened / monitoring / spf-only-strict / wide-open / unknown). |
| 3.42 | First-capture webhook alerting (Slack / Teams / Discord compatible JSON shape). `code_2fa` column on `tb_data_webform_submit`. Webhook URL stored encrypted at-rest in `tb_store`. New SettingsGeneral card to configure. |
| 3.39+ hotfix | jQuery / waves.js / sidebarmenu.js path corrections in PretextLibrary + SenderToolkit |
| 3.34+ hotfix | `createSession()` was rotating `_csrf` on every page refresh — every authenticated AJAX 403'd. Preserve token across refresh, mint only on actual login. (Bug existed since CSRF gate was introduced; surfaced during Phase 3.39 QA.) |
| 3.34+ hotfix 2 | `.alert-success` / `.alert-info` / `.alert-primary` / `.alert-secondary` were light-text on Bootstrap-default pale-tinted bg on dim panels (most visible: SiteCloner result box). Added semantic-color tinted bg + matching fg, mirroring the alert-warning / alert-danger pattern. |
| 3.43a | Quick-Start Wizard — Step 1: engagement metadata. New `tb_core_engagement` (slug + window + scope_allowlist + notes + status). `spear/QuickStart.php` page with form + recent-engagements list + "use in campaign" links. Boot-time idempotent schema + `save_engagement` / `list_engagements` dispatcher + log_classifier `ENGM` / `CLON` rules. 22 new engagement helper tests. |
| 3.43b | Quick-Start Wizard — Step 2: OSINT pre-check fan-out. New `mx_classify.php` (24 known providers across cloud-mailbox / security-gateway / shared-host buckets + pretext-category recommendations). New `web_fingerprint.php` (title + generator + robots.txt + .well-known/security.txt). New dispatcher actions `mx_classify_domain` / `web_fingerprint`. QuickStart page grows a six-card OSINT panel that fans out SPF/DMARC + MX + homoglyph + crt.sh + Hunter + web-fingerprint in parallel. 26 new helper tests. |
| SiteCloner UX | After-clone box now shows publicly-accessible landing-page URL (built from request scheme/host honoring `X-Forwarded-Proto`) with Copy + Open buttons, plus a three-step "use this clone in a campaign" hint deep-linking to MailCampaign / WebTracker. Existing-clones table grows per-row open / copy URL links. |
| Branding | Footer was `t-alpha GmbH` (lowercase) — official brand is `T-Alpha GmbH`. Fixed in `brand.php` + tests. New `brand_copyright()` shape links to `www.t-alpha.ch` + appends product name + version. New SVG logos (`logo.svg` / `logo-text.svg` / `logo-icon.svg`) replace the old PNGs — crisp at any zoom, lead with `TAPhish` wordmark + small "BY T-ALPHA GMBH" caption. Old PNGs removed. |

## What you can already do today

- Phishing campaigns end-to-end: recipient lists (PII encrypted) → mail templates (with merge tokens) → mail senders (SMTP password encrypted) → schedule + send via cron worker
- Pre-engagement intel: homoglyph candidates + SPF/DMARC posture (SenderToolkit page); subdomain enum via `osint_crt_sh`; email-format guessing via `osint_hunter`
- Pretext library with 12 curated starters; one-click clone to operator's own template list
- Scanner hits filtered from engagement metrics; webhook fires on first capture per recipient
- Operator audit log surfaces every campaign / recipient / template / login / 2FA event in the Home dashboard's activity feed
- 2FA with recovery codes; per-IP login throttle; secrets-at-rest envelope for SMTP/IMAP passwords AND recipient PII

## Known follow-ups (not blockers)

- `actions/checkout@v4` Node-20 deprecation (cosmetic CI warning; fix by bumping to v5 when convenient)
- Spec drift: the Phase 3.33 design doc describes `check_process` response shape that was adjusted at implementation time — JS is canonical
- `.is-error` Trend-Klasse in CSS definiert, JS schreibt sie nicht
- Open / Click rate tiles render `—` because the metrics blob isn't yet wired server-side (the JS already handles real numbers as soon as `home_manager.getHomeGraphsData` exposes them)
- Phase 3.38 integration tests (full encrypt round-trip with on-disk key) — pure-helper passthrough tier ships; integration tier deferred

## Roadmap — next phases

### Phase 3.43 — Quick-Start Wizard *(highest impact, build next)*

A guided multi-step flow that takes an operator from "I have an engagement to set up" to "campaign ready to send" in ~5 minutes. Hooks together every pre-engagement helper TAPhish already has.

**Step 1 — Engagement metadata**
Name, target organisation, engagement window (start/end dates), authorised scope (allowlist of email domains the operator is permitted to phish). Saved to a new `tb_core_engagement` table so subsequent reports can scope by engagement.

**Step 2 — OSINT pre-check (one-click panel)**
Operator types the target's primary domain. The wizard fans out:
- DMARC/SPF/MX lookup → reuses Phase 3.41 `taphish_lookup_email_posture()`
- Look-alike candidates → reuses Phase 3.41 `taphish_homoglyph_candidates()`
- Subdomain enumeration → reuses existing `spear/manager/osint_crt_sh.php`
- Email format guess → reuses existing `spear/manager/osint_hunter.php`
- Tech-stack detection via MX records → new `spear/manager/mx_classify.php` (M365 / Google Workspace / Hostpoint / OnPrem / etc.)
- Public web fingerprint → new lightweight helper that fetches `/robots.txt`, `/.well-known/*`, page `<title>`, `<meta name="generator">` for quick OSINT colour

**Step 3 — Pretext selection**
Filter the Phase 3.39 library by the detected tech stack — M365 detected ⇒ surface M365 pretexts first. One-click clone into the operator's templates with merge tokens already pre-filled from Step 1.

**Step 4 — Sender setup**
- Suggest a look-alike domain from Step 2's homoglyph results, ranked by confusability score
- Generate DKIM key pair + recommended SPF / DMARC TXT records for the look-alike domain
- Verify SMTP credentials against the configured Mail Sender (Phase 2.4 presets) with a live test send to a seed mailbox the operator owns
- Verify IMAP login (already used by bounce-poll worker)

**Step 5 — Recipient list**
CSV upload → existing `uploadUserCVS` path → recipient PII encrypted at rest (Phase 3.38). Preview shows count + per-domain breakdown so the operator can sanity-check against the Step 1 allowlist before continuing.

**Step 6 — Landing page**
Three options:
1. **Clone real page** via existing `spear/sniperhost` cloner
2. **Generate via AI** via existing `spear/manager/ai_landing_page.php`
3. **Library template** — new asset: hand-curated M365 / Okta / Google Workspace / Outlook Web Access / generic VPN clones with the form-submit endpoint pre-wired

**Step 7 — Anti-scanner + alerting confirm**
Webhook URL already-configured indicator (Phase 3.42); scanner detection on by default (Phase 3.40); confirm-or-edit panel.

**Step 8 — Pre-flight check**
Hard gates before "Launch":
- Step 1 scope allowlist must cover every recipient domain (block otherwise)
- DMARC posture from Step 2 vs. sender choice from Step 4 — if `p=reject` AND operator picked the real domain, refuse to send
- Recipient count > 0
- Mail Sender connectivity green
- DKIM published on the look-alike (if applicable)
- Webhook URL reachable (optional but recommended)

**Step 9 — Send or schedule**
Fire campaign or schedule for the engagement window. Returns a campaign-ID for the dashboard.

**Toolset checker** *(usable standalone too, accessed from the same wizard at any step)*
- SMTP / IMAP live probe
- Outbound IP reputation (Spamhaus, SpamAssassin)
- DKIM TXT presence + key sanity
- SPF / DMARC TXT presence on the operator's sender domain
- Webhook reachability ping
- Cron worker liveness
- /status endpoint check

### Phase 3.44 — Landing-page clone library

Hand-curated, tested clones for the high-frequency targets — M365 login, Okta login, Google Workspace, Outlook Web Access, generic SAML SSO, VPN portal (Fortinet / Cisco). Each clone includes the form-submit endpoint wired to `track.php` and an optional 2FA second-step page that captures `code_2fa` (Phase 3.42 column).

### Phase 3.45 — Engagement reports + PDF export

Per-engagement PDF report:
- Campaign summary (send time, recipient count by domain — no individual PII)
- Click/capture timeline (counts only)
- Scanner-hit breakdown by vendor
- Sender posture (DMARC verdict + actually used)
- Operator notes

Hooks into the `tb_core_engagement` table introduced in 3.43 and the existing audit log (Phase 3.35).

### Phase 3.46 — Multi-operator + RBAC

Right now there is one `admin` account. For a tooling shop running multiple concurrent engagements per operator, we want:
- Role tiers: super-admin / operator / read-only
- Engagement-scoped permissions (operator A can't see engagement B's recipient list)
- Per-operator API tokens for the dispatcher endpoints (so a script-driven send doesn't need a session)

### Phase 3.47 — Recipient PII re-encrypt sweep

Migration command (CLI or `spear/manager/*.php` action) that walks every existing `tb_core_mailcamp_user_group` row, decrypts (passthrough handles plaintext today), re-encrypts via Phase 3.38 envelope. Forces the at-rest invariant rather than relying on the lazy "next-write-encrypts" pattern.

### Phase 3.48 — Backup + snapshot mechanic

DB dump + state-dir snapshot on a schedule. Encrypts the dump with the existing at-rest key. Stored in `spear/uploads/backups/` with rotation; optionally pushes to operator-configured S3 / WebDAV.

### Phase 3.49 — Operator-side audit log viewer page

Phase 3.35 captures everything; today only the last 10 entries surface on the Home dashboard. A dedicated `SettingsAuditLog.php` page with date/severity/kind filters, search, and CSV export.

---

## Quick reference

- Live operator panel: <https://ptbe.autodiscover.li/spear/>
- Public docs site: <https://taphish.t-alpha.ch/> (GitHub Pages)
- Repo: <https://github.com/tagmbh/TAPhish>
- Deploy: Actions → `Deploy to operator host (FTPS)` → Run workflow (untick Dry-run for live push)
- Tests: `vendor/bin/phpunit` (365/1146)
- Lint: `php -l <file>`

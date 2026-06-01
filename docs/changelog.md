---
layout: page
title: Changelog
permalink: /changelog.html
---

Running fork changelog. See individual commits on
[GitHub](https://github.com/tagmbh/TAPhish/commits/) for full
diffs and test counts.

## Phase 3 — Features + hardening on top of Phase 2

### 3.31 — TOTP recovery codes

Ten single-use codes generated on 2FA enrollment, format `xxxxx-xxxxx`
(50 bits of entropy each, RFC 4648 Crockford-ish alphabet — no
ambiguous `0/O/1/I/8/B`). Stored as bcrypt hashes; plaintext is shown
once at enrollment and once on regenerate.

Login path accepts either a TOTP code or an unused recovery code —
on a match the recovery row is marked `used_at = NOW()` and the same
string can't replay. Settings page shows remaining count and warns
under 3. "Regenerate codes" wipes the existing set and mints a fresh
ten; current 2FA code required so a stolen session can't quietly
issue new bypass tokens. Disabling 2FA also clears the codes.

Schema migration is idempotent (`tb_totp_recovery_codes` created on
first session boot if absent). 10 new tests, 38 assertions.

### 3.30 — `/status` alias for `/health` (Hostpoint compat)

Hostpoint Shared reserves `/health` and serves a 404 before PHP sees
the request. `status.php` is a one-line alias that requires
`health.php` so monitors work on hosts that intercept `/health`.

### 3.23 — `/health` endpoint for uptime monitors

Minimal JSON endpoint at the repo root: 200 + `{status:ok, time:...}`
when the app can talk to MySQL, 503 otherwise. Intentionally minimal
body — no PID, no DB host, no schema version. No session, no CSRF,
no auth.

### 3.22 — Client-side password-strength meter

Five-tier strength meter (Very weak / Weak / OK / Strong / Excellent)
under the new-password field on both the authenticated profile edit
modal and the unauthenticated forgot-password reset page. Penalizes
obvious bad picks (`password`, `123456`, `sniperphish`, `qwerty`, …).

### 3.21 — Per-campaign engagement notes

Free-text operator notes attached to every campaign. Stored inside
the existing `campaign_data` JSON column — no schema change. 2000-
char cap. Rendered on the Mail Campaign Dashboard above the timeline
AND on the Customer PDF report as an "Engagement notes" block between
the cover paragraphs and the headline-metrics table.

### 3.20 — Idle-timeout warning + 60-hour math bug fix

The previous idle timer ran every 60 seconds and used `idleMax=3600`
with a `>` comparison, giving a 60-hour effective session timeout
(auto-logout effectively never fired). This patch drops the tick to
1 second so `idleMax` means seconds (default 1 hour) and adds a
5-minute pre-logout warning modal with a "Stay signed in" button.
Tracks keystrokes too, not just mousemove.

### 3.18 — Per-recipient timezone-aware scheduling

When enabled in mconfig, each recipient is sent at their local target
hour. Timezone is inferred from the email's country-code TLD
(.ch → Europe/Zurich, .de → Europe/Berlin, …); generic TLDs (.com,
.org) fall back to the server timezone. Recipients outside their
window are deferred — no row created in `tb_data_mailcamp_live` — and
the campaign transitions to a new `camp_status = 5`. The main cron
loop's resume pass re-runs the campaign every tick; the inner send
loop dedups against existing rows so only the previously-deferred
recipients are sent on subsequent passes.

### 3.17 — AI landing-page generator (Claude API)

Operator describes a landing page in prose, backend proxies to
Anthropic `/v1/messages`, returned HTML drops into the existing
CodeMirror editor on `/spear/sniperhost/LandingPage`. API key in
browser localStorage (Hunter.io pattern), default model
`claude-3-5-haiku-latest`, system prompt clamps output to raw HTML.

### 3.16 — Deployment docs

Top-level docs page covering Hostpoint, Infomaniak, VPS, modern
PaaS, AWS Lightsail with a fit guide and a sample systemd unit for
the cron worker. Explicit "GitHub Pages can't run the app" callout.

### 3.15 — Click + submit engagement metric

Auto-complete metric becomes operator-configurable: opens-only,
opens + clicks, or opens + clicks + form submissions. Clicks and
submits join by RID across `tb_data_webpage_visit` and
`tb_data_webform_submit`.

### 3.14 — crt.sh subdomain enumeration

Free-tier OSINT alongside Hunter.io: pulls `*.<target>` from
Certificate Transparency logs, deduplicates, anchored subdomain
check defends against unrelated names (e.g. `myexample.com` does
NOT count as a subdomain of `example.com`).

### 3.13 — Hunter.io email-finder

Per-person lookup (first + last + domain → email + score) alongside
the Phase 3.8 domain-search. Same modal, same localStorage key.

### 3.12 — Cron-loop bounce auto-poll

Removes the manual "Refresh bounces" click: main cron loop now polls
each campaign every 60 minutes. State stored as a per-campaign touch
file in `spear/uploads/bounce_poll_state/`.

### 3.11 — A/B template variants

Operator picks an optional Template B for any campaign. Send loop
assigns each recipient A or B deterministically via
`crc32($rid) % 2` so the same RID always lands on the same variant
— no per-recipient storage needed.

### 3.10 — Defense-in-depth security headers

`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: strict-origin-when-cross-origin`, HSTS,
`Permissions-Policy`. Emitted from `session_manager.php` on every
authenticated request. CSP deferred to a separate slice
(requires migrating inline `onclick` handlers first).

### 3.9 — Force change of default password

Escalates the Phase 1 default-creds banner from warning to
enforcement: operator gets redirected to *Settings → My Profile*
until they change the `sniperphish` bootstrap password.

### 3.8 — OSINT Hunter.io domain-search

Discover company emails for a domain via Hunter.io v2/domain-search.
API key in browser localStorage. Modal on the User Group page;
selected rows import as recipients with a "OSINT/Hunter.io" note.

### 3.6 — CSRF-XHR header on download buttons

Latent regression fix: raw `XMLHttpRequest` calls (Quick Tracker
export, system log download, Web-Mail campaign export) didn't pick
up the Phase 2.2 `$.ajaxSetup` CSRF header. Three two-line patches.

### 3.5 — IMAP bounce-poll worker

"Refresh bounces" button on the Mail Campaign Dashboard. Opens the
sender mailbox, scans the last 14 days for delivery-failure
envelopes, pins each bounce to a recipient via the injected
`{{RID}}@spmailer.generated` Message-ID marker, flips the row to
`sending_status = 3` with a "Bounced: …" annotation.

### 3.4 — Features docs page

Top-level page covering Phase 2 capabilities (site cloner, CSRF,
bcrypt, SMTP presets) with usage notes for operators.

### 3.3 — Auto-complete campaign on engagement threshold

Once a campaign reaches `camp_status = 4` (mails done sending), the
cron loop transitions it to `camp_status = 3` (terminal) once the
share of recipients who engaged crosses the operator-configured
threshold (default 100%, 0 disables).

### 3.1 — Customer-facing campaign PDF report

Branded, client-ready PDF deliverable on the Mail Campaign Dashboard.
Headline KPIs + per-recipient timeline sorted with openers first.
Pure aggregation in `customer_report_aggregator.php`.

## Phase 2 — Feature work + hardening

### 2.6 — GH Pages docs site + explicit Jekyll workflow

`/docs/` directory rendered via `.github/workflows/jekyll.yml`.
Pages source switched from "Deploy from a branch" to "GitHub
Actions" mode for stability.

### 2.5 — Site Cloner tracker dropdown + Infomaniak/M365 presets

Site Cloner's tracker URL field becomes a dropdown of the operator's
existing Web Trackers. Infomaniak SSL/TLS + Microsoft 365
custom-domain SMTP presets added.

### 2.4 — Hostpoint SMTP provider preset

Two Hostpoint presets (SSL/465, TLS/587). Idempotent
`taphish_ensure_mail_presets()` ensures existing installs gain new
presets without manual SQL.

### 2.3 — bcrypt password storage with transparent legacy upgrade

`password_hash(PASSWORD_BCRYPT, ['cost' => 12])` for all new
passwords. Existing operators with the upstream unsalted SHA-256
keep working; first successful login rehashes transparently.
Reset tokens move to `bin2hex(random_bytes(32))`.

### 2.2 — CSRF protection across the admin panel

`spear/manager/csrf.php` with token issuance, rotation, extraction
(header / JSON body / form post), verification, and a
`csrf_require()` middleware. jQuery `$.ajaxSetup` auto-attaches
`X-CSRF-Token`. Wired into 13 dispatchers + the initial login form.

### 2.1 — Site Cloner module

Admin page at `/spear/SiteCloner`. Pure rewrite helpers in
`spear/manager/site_cloner_filters.php`. `ClonedSite` class
encapsulates cURL fetch with size/asset caps. SSRF guard blocks
private/loopback/reserved IPs by default.

## Phase 1 — Rebrand + hardening + CI scaffold

- Brand abstraction layer in `spear/config/brand.php`
- Auto-secure session cookie under HTTPS
- `executeCron()` arg validation, `mod.php` mime whitelist
- Default-creds banner on dashboard
- Campaign auto-pause on failure-rate spike
- Composer / PHPUnit / PHPCS / GitHub Actions CI matrix

## Upstream

Forked from [GemGeorge/SniperPhish](https://github.com/GemGeorge/SniperPhish).
The upstream README, credits, and `SPAbout.php` attribution
paragraph are kept intact.

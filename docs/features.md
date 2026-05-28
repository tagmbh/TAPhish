---
layout: page
title: Features
permalink: /features.html
---

This page documents the major capabilities the t-alpha fork has added on
top of upstream SniperPhish, with usage notes for operators running
authorized engagements. For the per-commit changelog see
[Changelog](changelog.html); for security model see
[Security](security.html).

## Site Cloner

`/spear/SiteCloner` (under **Hosted Pages → Site Cloner** in the menu).

Fetches a target URL over HTTPS and persists a rewritten copy under
`spear/sniperhost/cloned/<slug>/` ready to serve from your operator
infrastructure. The cloner:

- Validates the target URL: HTTP/HTTPS only, no localhost, no
  private/reserved IP ranges. Operators can opt in to private
  targets for lab fixtures via the **Allow private/localhost** checkbox.
- Caps response sizes (5 MiB HTML, 2 MiB per asset, 200 assets max) so a
  hostile or oversized target can't exhaust disk.
- Rewrites relative URLs in `<a href>`, `<form action>`, `<script src>`,
  `<link href>`, `<img src>`, `<iframe src>`, `<source src>`, `<video src>`,
  `<audio src>` to absolute, then optionally downloads CSS and images
  locally.
- Strips `<meta http-equiv="Content-Security-Policy">` so the cloned page
  works under your hostname.
- Optionally injects a tracker `<script src="…">` just before `</head>`.

The **Tracker** dropdown is populated from your existing Web Trackers
(no need to paste URLs). Picking one auto-fills the canonical
`{baseurl}/mod?tlink={tracker_id}` injection URL; the free-text field
underneath stays as an escape hatch for external trackers.

Pure URL/HTML transformations are unit-tested
(`tests/SiteClonerFiltersTest.php`) so the rewrite logic is verified
independently of network behavior.

## CSRF protection

`spear/manager/csrf.php` issues a 64-char hex token from
`random_bytes(32)` on session start, rotates it on login, and exposes it
to client-side JS as `window.TAPHISH_CSRF` via the menu include.
A `$.ajaxSetup` `beforeSend` hook attaches it as `X-CSRF-Token` on every
non-`GET` jQuery request.

Every state-changing dispatcher calls `csrf_require()` after the session
check; verification accepts the token from the `X-CSRF-Token` header,
the JSON body `_csrf` field, or a form-POST `_csrf` field. The initial
login form (`/spear/index.php`) emits a hidden `_csrf` and verifies on
POST.

Two paths are explicitly exempt with reasoning in code:

- **`re_login`** — runs after `session_destroy()` so there's no stored
  token to compare against. Credentials authenticate.
- **Public dashboard-share reads** (`amIPublic` paths) — unauthenticated
  by design for client deliverables.

If a dispatcher 403s with `CSRF token missing or invalid`, refresh the
page to pick up a fresh `window.TAPHISH_CSRF`.

## bcrypt password storage with transparent legacy upgrade

`spear/manager/password_hash_helper.php` exposes `hash_user_password`,
`verify_user_password`, `password_should_rehash`, and `make_secure_token`.

- **New passwords** are stored as bcrypt
  (`password_hash(PASSWORD_BCRYPT, ['cost' => 12])`).
- **Existing operators** with the upstream unsalted SHA-256 hash keep
  working: their first successful login (or change-password, or
  re-login) re-hashes their stored credential to bcrypt transparently.
  The `verify_user_password` helper accepts either format.
- **Password-reset tokens** moved from `md5(uniqid(rand(), true))` to
  `bin2hex(random_bytes(32))`.
- **The default-credentials banner** on the dashboard uses
  `verify_user_password('sniperphish', $stored)`, so it correctly fires
  for both legacy and post-migration installs.

Install seed populates the bootstrap `admin` account with a bcrypt hash
of `sniperphish` computed at install time.

## SMTP provider presets

`spear/manager/mail_presets.php` ships pre-configured `mail_sender`
templates beyond the upstream set, so operators on common European /
Swiss hosts don't have to look up SMTP / IMAP server settings:

| Provider | SMTP | IMAP |
|---|---|---|
| **Hostpoint (hostpoint.ch) — SSL** | `asmtp.mail.hostpoint.ch:465` | `imap.hostpoint.ch:993` |
| **Hostpoint (hostpoint.ch) — TLS** | `asmtp.mail.hostpoint.ch:587` | `imap.hostpoint.ch:993` |
| **Infomaniak (infomaniak.com) — SSL** | `mail.infomaniak.com:465` | `mail.infomaniak.com:993` |
| **Infomaniak (infomaniak.com) — TLS** | `mail.infomaniak.com:587` | `mail.infomaniak.com:993` |
| **Microsoft 365 — Custom domain (SMTP AUTH)** | `smtp.office365.com:587` | `outlook.office365.com:993` |

Existing installs receive the new presets on next page load via
`taphish_ensure_mail_presets()` — no manual SQL needed. Fresh installs
ship with them in the install SQL seed.

To add another preset:

1. Append an entry to `taphish_known_mail_presets()` in
   `spear/manager/mail_presets.php`.
2. Add the same row to the `tb_store` `mail_sender` block in
   `install_manager.php` so fresh installs ship with it.
3. Restart any long-running cron worker; the next admin page load runs
   the idempotent ensure.

The preset name must be unique across `tb_store` (the table has a
`PRIMARY KEY (name)`).

## CI matrix

`.github/workflows/ci.yml` runs the PHP test matrix against
**8.1 / 8.2 / 8.3** on every push to `main` and on PRs. The composer
script `composer run lint` runs the same `php -l` sweep CI uses.

`.github/workflows/jekyll.yml` builds and deploys this documentation
site from `/docs` on every change to `docs/**` or the workflow itself.
The Pages source is set to **GitHub Actions** mode.

## Customer-facing PDF report

`MailCmpDashboard` → **Customer Report** button. Generates a
branded, client-ready PDF deliverable summarizing a campaign's
headline KPIs and per-recipient timeline (openers first, sorted
by first-open time). Rendered via the existing TCPDF bundle; the
operator picks an *engagement name* that overrides the internal
campaign id on the cover page. Pure aggregation in
`spear/manager/customer_report_aggregator.php` is unit-tested.

## Auto-complete on engagement

MailConfig → **Auto-complete on Engagement** section. When the
share of recipients showing the selected engagement signal
crosses the threshold, the cron loop transitions the campaign
from tracking (`camp_status = 4`) to terminal (`camp_status = 3`).

Three signal options:

- **Opens only** — `mail_open_times` non-empty.
- **Opens + clicks** — also joins `tb_data_webpage_visit` by RID.
- **Opens + clicks + form submissions** — also joins
  `tb_data_webform_submit` by RID.

Threshold defaults to 100%; setting it to 0 disables the check
entirely.

## IMAP bounce-poll

Two ways to flip bounced recipients to `sending_status = 3` with
a `Bounced: <reason>` annotation:

- **Manual** — *Refresh bounces* button on the Mail Campaign
  Dashboard.
- **Automatic** — the main cron loop polls each campaign every
  60 minutes. State stored as a per-campaign touch file in
  `spear/uploads/bounce_poll_state/`.

Bounces are pinned to their originating recipient via the
`{{RID}}@spmailer.generated` Message-ID marker the cron worker
injects on every outbound message — same mechanism the
reply-tracker uses.

## OSINT

MailUserGroup → **OSINT** button. One modal, three lookups:

- **Hunter.io domain search** — list company emails for a target
  domain. Pasted API key is held in browser localStorage; the
  backend proxies the call but never persists the key.
- **Hunter.io email finder** — per-person lookup (first + last +
  domain → email + position + confidence score).
- **crt.sh subdomain enumeration** — free, no API key. Pulls
  `*.<target-domain>` from Certificate Transparency logs.

Selected rows from any of the three import as recipients of the
current user group with a *OSINT/Hunter.io* note.

## A/B template variants

`MailCampaignList` → campaign edit form → **Mail Template B
(A/B, optional)** dropdown next to the primary template
selector. When set, the send loop assigns each recipient A or B
deterministically via `crc32($rid) % 2` — same RID always lands
on the same variant, no per-recipient storage needed, and the
assignment is recoverable at report-time from the RID alone.

A new `{{VARIANT}}` placeholder (`A` or `B`) is exposed in the
template body so operators can tag variant-specific tracker
parameters.

## Per-recipient timezone-aware scheduling

MailConfig → **Per-Recipient Timezone Scheduling** section.
When enabled, each recipient is sent at their local target hour.
Timezone is inferred from the email's country-code TLD
(.ch → Europe/Zurich, .de → Europe/Berlin, …); generic TLDs
(.com, .org, .net) fall back to the server timezone from
`tb_main_variables`.

Recipients outside their local window are deferred — no row
created in `tb_data_mailcamp_live` — and the campaign moves to
`camp_status = 5`. The main cron loop's resume pass re-runs the
campaign on every tick; the send loop dedups against existing
rows so only the previously-deferred recipients are sent on
subsequent passes. The cycle finishes when every recipient has
been picked up in their own window.

## AI landing-page generator

`/spear/sniperhost/LandingPage` → **AI Generate** button.
Operator describes the page they want in prose, the backend
proxies to Anthropic `/v1/messages`, and the returned HTML drops
straight into the existing CodeMirror editor.

- API key in browser localStorage (same pattern as Hunter.io).
- Default model: `claude-3-5-haiku-latest`. Operator can switch
  to Sonnet / Opus per request.
- The system prompt clamps Claude to "raw HTML only, no markdown
  fences, no commentary". An `ai_landing_extract_html()` helper
  strips fences / cuts preludes / trims whitespace as
  belt-and-suspenders.

## Force change of default password

If the `admin` account is still using the bootstrap `sniperphish`
password (legacy SHA-256 or post-migration bcrypt — the check is
format-agnostic via `verify_user_password`), the operator is
redirected to *Settings → My Profile* with a red banner on every
authenticated page load until they change it. Clears the moment
the stored hash no longer matches.

## Defense-in-depth security headers

Every authenticated request from `session_manager.php` now emits:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- `Permissions-Policy: camera=(), microphone=(), geolocation=(),
  payment=(), usb=(), interest-cohort=()`

CSP is deferred to a separate slice — it requires migrating
inline `onclick` handlers to `addEventListener` first.

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

## Notes for follow-up engagement deliverables

Two more features are in open PRs at time of writing:

- **Customer-facing campaign PDF report** (PR #3) — a branded,
  client-ready PDF deliverable summarizing one campaign's headline
  KPIs and per-recipient timeline. Rendered via the existing TCPDF
  bundle. Available as a **Customer Report** button on the Mail
  Campaign Dashboard.
- **Auto-complete campaign on engagement threshold** (PR #7) —
  transitions a campaign from tracking (`camp_status = 4`) to terminal
  (`camp_status = 3`) once the share of recipients who opened the email
  crosses the operator-configured threshold (default 100%, 0 disables).
  New section under **Auto-pause on Failure Rate** in MailConfig.

Once merged, this page will be updated to describe them.

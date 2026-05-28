---
layout: page
title: Security
permalink: /security.html
---

> **Authorized use only.** TAPhish is intended for red-team
> engagements, internal awareness training, CTFs, and security
> research. Operating it against people or systems you don't have
> written permission to test is not a use case the maintainers
> support and is illegal in most jurisdictions.

## Threat model

The TAPhish admin panel itself is a high-value target on the operator's
infrastructure: it holds campaign targets, captured credentials, and
landing-page assets. The fork hardens the panel as if it were any
production web app — the offensive nature of the workload doesn't excuse
shipping a vulnerable operator console.

In scope:

- Web-side compromise of the operator panel (auth, CSRF, session, XSS,
  SSRF in operator-supplied URLs, file-upload boundary).
- Credential storage hygiene for operator accounts.
- Safe defaults that don't accidentally turn a misconfigured operator
  panel into a vulnerability.

Out of scope:

- The phishing-payload side of the workload (clones, landing pages,
  emails) is offensive content by design; the fork does not try to
  detect or block its use.
- Defending against motivated targets who have full control of their
  own browsers / inboxes — that's outside the threat model of any
  phishing simulator.

## What's been hardened

### Phase 1 (already on the public branch)

- **Session cookies auto-secure under HTTPS** —
  `secure => $is_https` in `createSession()`.
- **`mod.php`** — whitelisted `$_GET['type']`, uses `finfo_file()`,
  only serves `image/*` and `video/*` mime types.
- **`executeCron()`** — validates campaign IDs and uses
  `escapeshellargs` on shell-bound arguments.
- **Default-credentials banner** on the dashboard while admin still
  uses the bootstrap `sniperphish` password.

### Phase 2 (stacked on draft PR #1)

- **CSRF middleware** — `csrf_token()` / `csrf_verify()` /
  `csrf_require()` in `spear/manager/csrf.php`. Token is 32 random
  bytes (64-char hex), generated in the session boot block, rotated on
  login, exposed to JS via `window.TAPHISH_CSRF`, auto-attached to
  every jQuery AJAX call as `X-CSRF-Token`, and enforced on every
  state-changing dispatcher. Public dashboard-share reads
  (`amIPublic`) and the unauthenticated `re_login` recovery path are
  explicitly exempted with reasoning in code.
- **bcrypt password storage** — `password_hash(PASSWORD_BCRYPT, ['cost' => 12])`
  for all new and modified passwords. Existing operators with legacy
  unsalted SHA-256 hashes keep working; the first successful login
  rehashes transparently. The default-creds banner uses
  `verify_user_password('sniperphish', $stored)`, so it remains
  format-agnostic across the migration.
- **Reset tokens** — `bin2hex(random_bytes(32))` instead of
  `md5(uniqid(rand(), true))`.
- **Site Cloner SSRF guard** — blocks `localhost`, `*.localhost`, and
  private/reserved IP ranges (`FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE`)
  by default. Operators can opt in for lab fixtures via
  `allow_private`. Response and per-asset size caps (5 MiB / 2 MiB)
  and an asset-count cap (200) bound the blast radius of a bad URL.

## Known open items

These are tracked for Phase 3 (or marked as deliberately deferred):

- **Public-tracker endpoints** (`track.php`, `qt.php`, `mod.php`) accept
  unauthenticated input by design. Filtering is present but stricter
  bounds and rate limiting are still to do.
- **`error_reporting(E_ERROR | E_PARSE)` and `@`-suppression** in
  `session_manager.php` mask warnings rather than fixing them; a
  Phase-3 PHPStan pass is planned.
- **`c_data` cookie** is JS-readable (`HttpOnly: false`) and holds
  profile info (username, last login, timezone, display-pic name).
  Not session-fatal, but XSS-readable. Slated for a refactor that
  fetches the same data via an authenticated endpoint instead.
- **Initial-login CSRF** — currently gated by the boot-block token,
  but not via a rotated login-specific nonce. Adequate for the
  threat model; could be tightened.

## Reporting a vulnerability

Open a private security advisory on
[GitHub Security Advisories](https://github.com/tagmbh/TAPhish/security/advisories).
Please do not file public issues for unfixed vulnerabilities in the
operator panel.

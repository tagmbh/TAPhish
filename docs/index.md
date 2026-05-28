---
layout: page
title: TAPhish
---

> **For authorized security testing only.** TAPhish is intended for
> red-team engagements, employee phishing-awareness training, CTFs, and
> security research where the operator has written permission from the
> target organization. Using it against systems or people you do not
> have authorization to test is illegal in most jurisdictions and not a
> use case the maintainers support.

TAPhish is t-alpha's fork of the open-source
[SniperPhish](https://sniperphish.com) phishing-simulation toolkit. The
fork rebrands the project and modernizes it for current PHP runtimes
and current operator workflows.

## What's in the fork

**Phase 1 — Rebrand + hardening + CI scaffold**

- Brand abstraction layer (`spear/config/brand.php`) — change product
  name, company, tagline, version, and logo paths from one file.
- Auto-secure session cookie under HTTPS.
- `executeCron()` now validates and escapes its arguments;
  `mod.php` whitelists `$_GET['type']`, uses `finfo_file`, and only
  serves `image/*` and `video/*` responses.
- Default-credentials warning banner on the dashboard.
- Campaign auto-pause on high failure rate
  (`failure_pause_percent` / `failure_pause_window` in
  `mconfig_data`).
- Composer + PHPUnit + PHP_CodeSniffer scaffolding.
- GitHub Actions CI matrix across PHP 8.1 / 8.2 / 8.3.

**Phase 2 — Feature work and security hardening**

- **Site Cloner** — fetch a target URL over HTTPS, rewrite HTML for
  offline hosting, download CSS/images, strip Content-Security-Policy
  meta tags, and optionally inject a tracker JS link. SSRF guard
  blocks private/loopback/reserved IPs by default; size and asset-count
  caps bound the blast radius.
- **CSRF middleware** — 64-char hex token tied to the PHP session,
  rotated on login, attached automatically to every jQuery AJAX call
  via `$.ajaxSetup`, and enforced by `csrf_require()` on every
  state-changing dispatcher.
- **bcrypt password storage** — replaces unsalted SHA-256 with
  `password_hash(PASSWORD_BCRYPT, ['cost' => 12])`. Existing operators
  keep their passwords; the first successful login transparently
  rehashes. Password-reset tokens move from
  `md5(uniqid(rand(), true))` to `bin2hex(random_bytes(32))`.
- **Swiss / EU SMTP presets** — Hostpoint (SSL + TLS), Infomaniak
  (SSL + TLS), and Microsoft 365 with a custom domain. Existing
  installs gain new presets on next page load via an idempotent
  ensure-helper; new installs ship with them out of the box.
- **Site Cloner tracker integration** — the cloner UI now offers a
  dropdown of the operator's existing Web Trackers and auto-fills the
  injection URL with the canonical `{baseurl}/mod?tlink={tracker_id}`
  pattern. A free-text fallback stays for external trackers.

For the running list of items, see the
[Changelog](changelog.html); for per-feature usage notes see
[Features](features.html).

## Getting started

See the [Install guide](install.html).

## Security and responsible use

See the [Security notes](security.html) for the threat model the fork
assumes, what's been hardened in Phase 1 and Phase 2, and what's still
open.

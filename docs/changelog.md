---
layout: page
title: Changelog
permalink: /changelog.html
---

This is the running fork changelog. See individual commit messages on
[GitHub](https://github.com/tagmbh/TAPhish/commits/) for the full
diffs and test counts.

## Phase 2 — Feature work + hardening (draft PR #1)

### 2.5 — Site Cloner tracker dropdown + Infomaniak/M365 presets
*Commit `8d62240`*

- Site Cloner replaces the free-text tracker URL with a dropdown
  populated from the operator's existing Web Trackers; URL auto-fills
  the canonical `{baseurl}/mod?tlink={tracker_id}` pattern.
- New SMTP presets: Infomaniak SSL (`mail.infomaniak.com:465`),
  Infomaniak TLS (`mail.infomaniak.com:587`), Microsoft 365 with a
  custom domain (`smtp.office365.com:587` + `imap.outlook.office365.com:993`).
- Tests: 83 → 88.

### 2.4 — Hostpoint SMTP provider preset
*Commit `4fb6cce`*

- `Hostpoint (hostpoint.ch) - SSL` (`asmtp.mail.hostpoint.ch:465`) and
  `TLS` (`asmtp.mail.hostpoint.ch:587`) variants.
- Idempotent `taphish_ensure_mail_presets()` ensures existing installs
  gain new presets without manual SQL.
- Tests: 76 → 83.

### 2.3 — bcrypt password storage with transparent legacy upgrade
*Commit `9af5b85`*

- New helper `spear/manager/password_hash_helper.php`.
- `validateLogin`, `doReLogin`, `isCurrentPwdCorrect`,
  `addAccount`, `modifyAccount`, `doChangePwd`, and the install seed
  all migrated.
- Reset tokens move to `bin2hex(random_bytes(32))`.
- Default-creds banner now uses `verify_user_password(...)`, working
  against both legacy SHA-256 and bcrypt installs.
- Tests: 62 → 76.

### 2.2 — CSRF protection across the admin panel
*Commit `149b582`*

- `spear/manager/csrf.php` with token issuance, rotation, extraction
  (header / JSON body / form post), verification, and a
  `csrf_require()` middleware.
- jQuery `$.ajaxSetup` auto-attaches `X-CSRF-Token` on every non-GET.
- Wired into 13 dispatchers and the initial login form. Public
  dashboard-share reads and `re_login` are explicitly exempt with
  reasoning in code.
- Tests: 45 → 62.

### 2.1 — Site Cloner module
*Commit `66c9f59`*

- Admin page at `/spear/SiteCloner` (under Hosted Pages in the menu).
- Pure-rewrite helpers in `spear/manager/site_cloner_filters.php`:
  slug normalization, SSRF-safe URL check, CSP-meta strip, URL
  resolution, HTML rewrite + asset collection.
- `ClonedSite` class encapsulates cURL fetch with size/asset caps.
- Tests: 19 → 45.

## Phase 1 — Rebrand + hardening + CI/test scaffold
*Commit `58166f2`*

- Brand abstraction (`spear/config/brand.php`) + asset placeholders.
- Auto-secure session cookie, `executeCron()` arg validation,
  `mod.php` mime/whitelist hardening, default-creds banner.
- Campaign auto-pause on failure-rate spike.
- Composer / PHPUnit / PHPCS / GitHub Actions CI matrix.
- 19 baseline tests.

## Upstream

Forked from [GemGeorge/SniperPhish](https://github.com/GemGeorge/SniperPhish).
The upstream README, credits, and `SPAbout.php` attribution paragraph
are kept intact.

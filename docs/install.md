---
layout: page
title: Install
permalink: /install.html
---

> Use TAPhish only in environments where you have written
> authorization to run phishing simulations. The maintainers do not
> support installations targeting third parties without consent.

## Requirements

- Web server with PHP **8.1 or newer** (8.3 is the highest version in
  CI; 8.4 and 8.5 also pass locally).
- MySQL or MariaDB.
- Apache `.htaccess` rewrite support (or equivalent on nginx).
- For local development: Composer and `vendor/bin/phpunit`.

PHP extensions required by the application code: `mbstring`, `mysqli`,
`gd`, `fileinfo`, `curl`. (CI installs these explicitly.)

## Production install

```bash
git clone https://github.com/tagmbh/TAPhish.git
cd TAPhish
# Drop the contents under your webroot, e.g.
#   /var/www/html/  (Linux/Apache)
#   C:\xampp\htdocs (Windows/XAMPP)
```

1. Make sure your webserver serves `index.php` and obeys the bundled
   `.htaccess` (or translate it for nginx).
2. Open `http://yourhost/install` in a browser and follow the wizard.
   You'll be asked for MySQL credentials and an admin contact email.
3. After install, the wizard redirects to `/spear/`. Default login:
   - Username: `admin`
   - Password: `sniperphish`

   **Change this password immediately.** Until you do, the
   Phase-1 default-credentials banner on the dashboard reminds you.
   The banner check is format-agnostic so it works against both legacy
   SHA-256 installs and post-migration bcrypt installs.

## Local development

```bash
git clone https://github.com/tagmbh/TAPhish.git
cd TAPhish
composer install
vendor/bin/phpunit                          # full unit suite
vendor/bin/phpcs                             # style check (warnings only)
find . -name '*.php' -not -path './spear/libs/*' -not -path './vendor/*' \
  -print0 | xargs -0 -n1 -P4 php -l         # syntax sweep
```

CI runs the same matrix across PHP 8.1, 8.2, and 8.3 — see
`.github/workflows/ci.yml`.

## Upgrading

The fork ships with idempotent runtime helpers that bring existing
installs up to date without manual SQL:

- New SMTP provider presets are inserted on first page load via
  `taphish_ensure_mail_presets()` (Phase 2.4 + 2.5).
- Legacy SHA-256 passwords transparently upgrade to bcrypt on the
  next successful login (Phase 2.3).

Schema migrations beyond data top-ups currently require a manual SQL
step or a clean reinstall. Phase 3 will introduce a migrations runner.

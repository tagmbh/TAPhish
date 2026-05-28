<h1 align="center">TAPhish</h1>
<p align="center">
  <a href=""><img src="https://img.shields.io/static/v1?label=php&message=%3E=8.1&color=green&style=flat&logo=php"></a>
  <a href=""><img src="https://img.shields.io/static/v1?label=Platform&message=Linux/Windows&color=orange&style=flat"></a>
  <a href=""><img src="https://img.shields.io/static/v1?label=License&message=MIT&color=blue&style=flat"></a>
  <a href=""><img src="https://img.shields.io/badge/Contributions-Welcome-brightgreen.svg?style=flat"></a>
</p>

# TAPhish

TAPhish is a [t-alpha GmbH](https://t-alpha.de/) maintained fork of
[SniperPhish](https://sniperphish.com/) (originally created by Gem George). It
is a phishing simulation toolkit for security professionals to enhance user
awareness through controlled, authorized exercises. TAPhish combines phishing
emails and phishing landing pages and centrally tracks user actions.

Use only with explicit, written authorization from the targeted organization.

## Basic Requirements
* Operating System: Windows or Linux. macOS is not officially supported.
* Web Server: any web server supporting PHP 8.1 or higher.
* Database: MySQL / MariaDB.

## Installation
1. Clone this repo or download the latest release.
2. Put the contents in your web root folder.
3. Open the installation page `http://localhost/install` in your browser and follow the steps.
4. After installation, TAPhish will redirect to the login page `http://localhost/spear`.

> **Default login** — Username: `admin` &nbsp;Password: `sniperphish`
>
> **Change this immediately after the first login.** TAPhish shows a dismissible
> warning banner on the dashboard while the admin account still holds the
> default password.

## Rebranding

The product name, company name, tagline, logos, and primary colour are all
centralized in [`spear/config/brand.php`](spear/config/brand.php). To rebrand:

1. Edit the `BRAND_*` constants in `spear/config/brand.php`.
2. Drop replacement PNG/SVG files into `spear/images/brand/` using the
   filenames documented in [`spear/images/brand/README.md`](spear/images/brand/README.md).
3. Tune accent colours in `spear/css/brand.css` if needed.

No other files need to be touched for a rebrand.

## Main Features
* Web tracker code generation — track website visits and form submissions independently.
* Tracks data from phishing websites containing any number of pages.
* Create and schedule phishing mail campaigns.
* Combine your phishing site with email campaign for centrally tracking.
* Independent "Quick Tracker" module for one-off tracking of an email or page visit.
* Advanced report generation.
* Mail campaigns with QR/Bar code support (locally embedded or remote).
* Track phishing-message replies.
* Signed and encrypted mail (S/MIME).
* Advanced mail campaign customization — read receipt, TO/CC/BCC, etc.
* Anti-flood control for emails.
* **Auto-pause on excessive failures** (new in TAPhish) — configurable per-campaign threshold.
* Non-ASCII (Punycode transcription) support for email and domain.
* Auto-renaming attachments on the fly.

## Development

* PHP lint: `find . -name '*.php' -not -path './spear/libs/*' -not -path './vendor/*' -print0 | xargs -0 -n1 -P4 php -l`
* Install dev dependencies: `composer install`
* Run unit tests: `vendor/bin/phpunit`
* Run code style check (warnings only): `vendor/bin/phpcs`

Continuous integration runs the same checks on PHP 8.1 / 8.2 / 8.3 via
`.github/workflows/ci.yml`.

## Upstream credit
TAPhish is based on [SniperPhish](https://github.com/SniperPhish/SniperPhish)
by Gem George, with contributions from Joseph Nygil
([@j_nygil](https://twitter.com/j_nygil)) and Sreehari Haridas
([@sr33h4ri](https://twitter.com/sr33h4ri)). The original project documentation
remains an excellent reference: <https://docs.sniperphish.com/>.

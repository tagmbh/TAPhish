---
layout: page
title: Deployment
permalink: /deployment.html
---

> Use only on infrastructure you own or have written authorization to
> operate. Phishing simulations against systems or people you don't
> have permission to test are illegal in most jurisdictions and not a
> use case the maintainers support.

## Can it run on GitHub Pages?

**No.** GitHub Pages serves only static HTML, CSS, JS. The TAPhish
operator panel needs:

- A **PHP 8.1+ runtime** to serve `/spear/` and the `manager/*.php`
  JSON dispatchers
- A **MySQL** database for campaigns, recipients, templates, and
  tracking data
- A **long-running cron worker** (`spear/core/SniperPhish_Manager.php`)
  that loops every 5 seconds for scheduled sends, the
  auto-complete pass, and the throttled bounce-poll pass
- **Outbound SMTP** for sending
- **Outbound IMAP** for reply tracking and the bounce-poll worker

None of those run on Pages.

The docs site you're reading right now *is* hosted on Pages — it's
the only piece of the project that's static.

## Where it actually runs

In rough order of how cleanly the workload fits:

### Hostpoint (Swiss host) — ★★★★★

Phase 2.4 ships ready-made SMTP and IMAP presets for Hostpoint.

- **PHP 8.x + MySQL** bundled in the standard hosting plan
- **Outbound SMTP** via `asmtp.mail.hostpoint.ch:465` (SSL) or `587` (STARTTLS) — same credentials you log into Webmail with
- **IMAP** on `imap.hostpoint.ch:993`
- **EU jurisdiction** — relevant if you operate under GDPR
- Documented at <https://support.hostpoint.ch/de/hosting/e-mail>

Cron worker: Hostpoint exposes the `Tasks` panel for cron jobs. Point
one at `php -f /path/to/spear/core/SniperPhish_Manager.php` with
"every minute" — the script's own `isProcessRunning` guard keeps a
single instance.

### Infomaniak (Swiss host, sibling preset) — ★★★★★

Same shape as Hostpoint. Phase 2.5 ships the SMTP + IMAP presets
(`mail.infomaniak.com:465 / :587 / :993`). Cron jobs configured under
*Web hosting → Advanced → Scheduled tasks*.

### A small VPS — ★★★★

Hetzner CX22 (~€4/mo, EU), DigitalOcean Basic ($6/mo), Vultr Cloud
Compute, Linode Nanode. You get root, install LAMP, run the cron
worker as a systemd unit. Most flexible, slightly more setup.

A systemd unit roughly like this is enough:

```
[Unit]
Description=TAPhish cron worker
After=mysql.service

[Service]
ExecStart=/usr/bin/php -f /var/www/taphish/spear/core/SniperPhish_Manager.php
Restart=always
User=www-data
WorkingDirectory=/var/www/taphish

[Install]
WantedBy=multi-user.target
```

### Modern PaaS (Render / Railway / Fly.io) — ★★★

Both Render and Railway have first-class PHP services and managed
MySQL. You'll need a separate **worker** service for the long-running
cron loop (their "web" service types kill the process between
requests). Both can do that — Render's "Background Worker" service
type, Railway's worker process — but it's an extra moving part vs.
the all-in-one fit of Hostpoint / Infomaniak.

### AWS Lightsail LAMP — ★★★

A one-click LAMP image runs about $5/month. Pre-configured PHP and
MariaDB; SSH in and `git clone` the repo into `/opt/bitnami/apache/htdocs`.
Same cron-as-systemd story as the VPS option above.

### Not viable

- **GitHub Pages** — static only
- **Cloudflare Pages** — static only
- **Netlify** — static only (their Functions are JS/TS, not PHP)
- **Vercel** — primarily JS/Next; PHP support is community-only and
  fragile

## Once it's deployed

After you have a server with PHP + MySQL:

1. Clone or upload the repo to your webroot.
2. Open `http://yourhost/install` in a browser and run the installer
   wizard — see the [Install guide](install.html) for the full
   procedure and the default credentials.
3. **Change the default `admin/sniperphish` password immediately.**
   Phase 3.9 force-redirects you to *Settings → My Profile* until
   you do.
4. Configure your SMTP sender under *Email Campaign → Sender List* —
   pick the Hostpoint / Infomaniak / M365 preset if it matches your
   provider.
5. Register the cron worker per the host-specific guidance above.

See the [Security notes](security.html) for the threat model the
fork assumes; see [Features](features.html) for per-capability usage.

## TLS, firewalling, and access control

The operator panel **must not** be exposed to the public internet
without an authentication layer beyond `admin` + password. Real
deployments put it behind:

- Reverse-proxy basic auth or mTLS (Caddy, nginx, Cloudflare Access)
- IP allow-list at the firewall layer
- VPN-only access (Wireguard / Tailscale)

The public `track.php`, `qt.php`, and `mod.php` endpoints obviously
need to be reachable from the targets' browsers; the rest of the
panel doesn't.

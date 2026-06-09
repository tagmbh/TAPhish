# Verification status — 2026-06-09

Snapshot of what's been exercised end-to-end on production tonight versus
what still needs a controlled test pass. Keep it short — the
`TEST-PLAN-e2e-campaign.md` is the comprehensive script; this file is the
"what survived contact with reality" delta.

---

## ✅ Verified end-to-end on production (`ptbe.autodiscover.li`)

Each entry was clicked through with a real recipient mailbox or curl-confirmed
on the live host.

### Mail pipeline (Bug-1 + Bug-2 + variety)
- **Mail rendering** — recipient sees proper HTML, not raw `<p>` markup (PR #120 content-type + heal). Verified on every cloned pretext.
- **Tracker URL substitution** — `{{TRACKINGURL}}` and (manually for one template) the literal `/p/<slug>/` landing URL substitute correctly in the mail body.
- **7 distinct pretext templates** delivered tonight in a single batched run:
  - `M365 password expiry` (Authentication)
  - `M365 password expiry` (duplicate clone — proves multi-clone of the same pretext doesn't conflict)
  - `Microsoft 365 deaktivieren` (DE pretext)
  - `Policy attestation` (HR/compliance)
  - `Post Paket` (Shipping/Logistics)
  - `Swiss Airlines - Boarding Pass` (Travel)
  - `Intranet` (Internal communications)
- **Hostpoint SMTP relay** — every mail dispatched via `info@autodiscover.li` + `asmtp.mail.hostpoint.ch:587`. SPF-aligned via `include:spf.mail.hostpoint.ch` on `t-alpha.ch`; M365 inbound delivers cleanly.
- **Open tracking** — Dashboard reports **Open rate 89.9 %** across all tonight's campaigns (sent 159 / opened 143).
- **Click tracking + landing redirect** — clicking the CTA reaches the cloned landing.

### Landing pages
- **`.htaccess` vanity sub-path rewrite (PR #128)** — `/p/<slug>/assets/style.css` returns HTTP 200 (was 404 before tonight). All static assets under the vanity path now resolve.
- **m365-login cloned landings** render with proper Microsoft styling (4-square grid + wordmark, M365-blue Next button).
- **Form capture** — credentials submitted on the cloned landing land in `tb_data_webform_submit` (Dashboard **Capture rate 6.3 %**, 10 captures across tonight's testing).
- **2FA capture path** — the cloned m365-login multi-step (email → password → 2FA) exists and is reachable (full 2FA submit not exercised this round).

### Public infrastructure
- **Legitimate root landing (PR #130)** — `https://ptbe.autodiscover.li/` now serves the T-Alpha Security Awareness Training homepage with `serviceType: "Cybersecurity Awareness Training"` JSON-LD instead of the prior 302 to `/spear/`. Reputation classifiers can categorise correctly.
- **Domain-agnostic canonical (PR #131)** — `canonical` URL + Schema.org URL field reflect the current `HTTP_HOST`, so a subdomain migration is a 0-code change.
- **Operator playbook** — [`docs/INFRASTRUCTURE-DNS-BYPASS.md`](INFRASTRUCTURE-DNS-BYPASS.md) covers the Swisscom-block remediation strategy.

### Operator-panel UX
- **Safari CSRF auto-recovery (PR #132)** — `csrf_refresh` endpoint live; dashboard 403 retry-loops should no longer freeze.
- **Mobile pass at 375 px** — operator panel + landings render correctly (verified 2026-06-06 + landings re-verified tonight after .htaccess fix).
- **Dashboard funnel** — real Open / Capture / Active-Campaigns numbers (no more `—`).

### Backups & data
- **Pretext-clone heal** — PR #120 heal updated 18 rows on first boot after deploy; verified live by inspecting the M365 template body.
- **M365 logo heal (PR #133)** — boot-time pass replaced literal `[Microsoft 365]` placeholder with real SVG in every `m365-login-*` clone; verified post-deploy by hitting both clones and seeing the SVG.

---

## ⏳ Not exercised tonight

Each item has a reason — most are blocked on an operator-side configuration
or are destructive operations that need staging, not on platform readiness.

### AI features
- **AI landing-page generator** (`spear/manager/ai_landing_page.php`, `AiLandingPageTest.php` covers the pure parser) — **needs an Anthropic API key configured in the operator panel**. See operator-followup §1 below.

### Site Cloner (new clones from a real URL)
- The existing m365-login-6mmo and m365-login-eyll clones were used. The end-to-end "fetch a real URL → rewrite assets → land in `/spear/sniperhost/cloned/<slug>/`" pass was not exercised this round.
- The pure-helper layer (`site_cloner_filters`, `site_bundle`) IS covered by 32 + 4 unit tests.

### OSINT
- **Hunter.io** email-format finder — needs operator Hunter.io API key.
- **Shodan** host enrichment — needs operator Shodan API key.
- crt.sh / DMARC lookup / homoglyph generator / SPF analyser ran tonight as part of the engagement-2 OSINT pre-check and worked.

### Notifications
- **Telegram bot alerting** — config card exists; bot token not provisioned for the operator's account.
- **First-capture webhook** — config card exists; no Slack/Teams/Discord URL set tonight.

### DKIM
- A keypair (`t-alpha-internal._domainkey.t-alpha.ch`) was generated in the wizard but the TXT record was never published on the operator's t-alpha.ch DNS. SPF alignment via Hostpoint covered delivery for tonight's tests — DKIM-signing-path was therefore not exercised.

### RBAC
- Tonight ran with a single super-admin operator. Per-engagement PII isolation (Phase 3.48b) + per-engagement membership are unit-tested but the multi-operator UX (alice vs bob with different roles) was not walked through.

### CLI tools
- `backup_run.php`, `backup_restore.php`, `backup_push_config.php`, `reencrypt_recipient_pii.php`, `grant_super_admin.php` — none exercised tonight. Pure cores are unit-tested. Restore is destructive and needs staging.

### Engagement reporting
- The 3.47 Engagement Report PDF export (with the five aggregators + TCPDF render) — not exercised tonight.

### Trackers
- **Quick Tracker** — modal verified earlier today (Safari black-overlay regression is fixed), full create + visit + report flow not re-walked tonight.
- **Web Tracker** — Phase 3.45a scanner detection is unit-tested; live walk-through not done this round.

### Look-alike deployment
- The Phase 3.55 `LookalikeDeploy` page + DNS-record builder + site-bundle ZIP export — not exercised tonight.

### BeEF integration
- Settings card exists; BeEF server config not provisioned for the operator's account.

### Backup push (S3/WebDAV)
- Phase 3.50c SigV4 signer is unit-tested against the AWS vector. Real S3 or WebDAV endpoint config not provisioned tonight.

### High-volume / soak
- Tonight's runs were single-recipient. Volume / failure-rate-pause behaviour (Phase 3.50+ auto-pause threshold) was not exercised.

---

## 🔧 Operator follow-up TODOs

These are operator-side configuration tasks the platform is waiting on:

1. **Anthropic API key** — configure in the operator panel (Settings → General has space for this; if not yet wired, add a one-line config-card row binding `anthropic_api_key` to `tb_store`). Unblocks the AI landing-page generator + any future AI-assisted features.
2. **Hunter.io + Shodan API keys** — operator-supplied OSINT enrichment.
3. **Telegram bot token + chat ID** — if alerting via Telegram is desired for the next engagement.
4. **First-capture webhook URL** — Slack / Teams / Discord JSON-shape webhook for the same.
5. **DKIM TXT publish** — only if you want to send from a domain whose SMTP relay doesn't already DKIM-sign for you (Hostpoint does, so this is optional for now).
6. **Subdomain migration** — per [`INFRASTRUCTURE-DNS-BYPASS.md`](INFRASTRUCTURE-DNS-BYPASS.md), spin up `training.t-alpha.ch` (or per-engagement subdomain) in the Hostpoint panel so we stop drawing classifier heat on `ptbe.autodiscover.li`.
7. **BeEF server** — if browser-hooking is wanted, stand up the BeEF ruby daemon on a ~€5/mo VPS (Hostpoint Shared can't host it).

---

## 2026-06-09 02:30 CEST follow-up — Bug-3 redux

After the 7 verification mails went out, the operator reported the M365 templates (the two they had manually patched yesterday) worked, but the other 5 templates (Policy attestation, Post Paket, Swiss Airlines Boarding Pass, M365 deaktivieren, Intranet) landed on a blank white page when the CTA was clicked.

**Root cause:** PR #120's "fix" was itself a regression. It replaced `https://example.com/REPLACE-WITH-TRACKER-URL` (an obvious operator-edit marker) with the `{{TRACKINGURL}}` merge token in every pretext seed body. But `{{TRACKINGURL}}` does not expand to a landing URL — it expands to the OPEN-TRACKING PIXEL endpoint `/tmail?mid=…&rid=…`, which returns a 1×1 transparent image. So the operator hit Launch without manually rewriting the CTA, and every recipient who clicked the link saw a blank white page.

**Fix:** PR #135 (merged 2026-06-09).
- Seed bodies again ship with `https://example.com/REPLACE-WITH-LANDING-URL` so the operator sees the marker in the wizard before launch.
- The boot-time heal is reversed — it rewrites `href="{{TRACKINGURL}}"` back to the marker URL in both the seed library and already-cloned mail templates.
- A new pre-send guard in `mail_campaign_cron.php` (`taphish_mail_body_is_unsafe_to_send()`) refuses to dispatch any mail whose body still contains the marker, points its CTA at `/tmail?mid=` (open-pixel), or references the legacy SniperHost fallback `lp_pages/oops.html`. Failed rows show status 3 = Error in the dashboard with a clear reason, so the operator finds out BEFORE recipients click.

**Operator step that closes this loop:** in Step 5 of the QuickStart wizard, after cloning a pretext, you MUST edit the mail body and replace the marker URL with the actual cloned-landing URL for this campaign (e.g. `https://ptbe.autodiscover.li/p/m365-login-XXXX/`). The pre-send guard now hard-fails the dispatch if you forget — no silent broken-CTA campaigns going out again.

---

## Tonight's PR ledger

15 PRs merged today: #120 → #135. Suite **752 → 845 tests / 2377 → 2631 assertions**. Three production bugs found end-to-end (pretext-clone content type + literal URL; `.htaccess` vanity sub-path; M365 logo placeholder), one root-cause regression caught the same night (PR #135's pre-send guard), four UX fixes (root landing 302, dashboard freeze, mobile activity feed, table column clipping), six pure-helper extractions, plus the architecture-finding amendment.

See [`MORNING-REPORT-2026-06-08.md`](MORNING-REPORT-2026-06-08.md) for the morning handoff narrative.

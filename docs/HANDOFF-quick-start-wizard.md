# HANDOFF — Quick-Start Wizard: rebuild, live-bug-fixes, QA

**Audience:** a senior review agent tasked with (1) a complete, adversarial code
review of everything below, (2) working out a consolidated list of bug fixes +
improvements, (3) producing a plan, and (4) preparing a final write-out.

**You are inheriting working, deployed code.** Three PRs shipped to `main` and to
the production host (FTPS) over this engagement. Nothing here is half-finished;
your job is to find what we missed, harden it, and decide what is worth doing next.

---

## 0. TL;DR of what happened

TAPhish is a PHP 8.1+ phishing-simulation platform (SniperPhish fork; raw `mysqli`,
no framework, jQuery/Bootstrap-4 frontend, `.htaccess` extensionless routing,
one big AJAX dispatcher per area). The **Quick-Start Wizard** (`spear/QuickStart.php`
+ `spear/js/quick_start.js` + `spear/js/wizard_stepflow.js`) is a 7-step funnel that
should create a fully-linked Mail+Landing campaign end-to-end.

We did three things, in order:

1. **PR #142** — *Rebuilt* the wizard from an "advisory" tool (that bounced the
   operator to sub-menus and launched an **empty** campaign) into a true
   0→100 % "doer" funnel that commits every artifact and launches a fully-linked
   campaign. (merge `52f11fb`, feat `c6ce1f1`)
2. **PR #143** — *Fixed seven live bugs* the operator hit in production. Two were
   launch-blockers; the rest were OSINT/UX. Surfaced two **latent** bugs via a
   real-DB smoke test (see §3). (merge `15c9894`, fix `639a556`)
3. **PR #144** — *Browser-driven QA* (Playwright as a real user, full log access)
   confirmed all fixes live; found + fixed one cosmetic Hunter-lane message.
   Added the QA workflow doc. (merged 2026-06-09)

Verification at the end: **906 PHPUnit tests green**, real-DB HTTP smoke green,
full browser QA with **0 JS errors / 0 PHP errors**.

---

## 1. The funnel as it stands (architecture map)

7 steps, all in `spear/QuickStart.php` as `.step-wrap` divs; `wizard_stepflow.js`
shows one at a time and persists progress; `quick_start.js` owns what each step
*does* and exposes `window.TAPhishWizard`.

| Step | Does | Key server action(s) |
|---|---|---|
| 1 Engagement | commits engagement + scope allowlist | `save_engagement` |
| 2 OSINT | advisory lookups (DMARC, MX, look-alike, subdomains, Hunter, web, Shodan) | `email_posture_lookup`, `mx_classify_domain`, `homoglyph_check_candidates`, `osint_crt_sh_subdomains`, `osint_hunter_search`, `web_fingerprint`, `osint_shodan_host` |
| 3 Recipients | parses CSV (auto-detect), commits a scope-filtered user group | `wizard_recipient_preview`, `wizard_commit_recipients` |
| 4 Landing + Tracker | auto-creates/selects a web tracker, clones a landing (URL cloner OR library) with the tracker injected | `wizard_create_web_tracker`, `wizard_list_web_trackers`, `clone_site` (separate endpoint), `library_clone_to_my_sites` |
| 5 Mail | pretext picker + inline Summernote; wires CTA→landing + `{{TRACKER}}` pixel | `save_mail_template` |
| 6 Sender | select existing OR create SMTP profile inline; DKIM advanced | `get_sender_list`, `save_sender_list`, `wizard_generate_dkim` |
| 7 Pre-flight + Launch | runs 7 gates server-side, launches a fully-linked campaign (CAS draft→live) | `wizard_preflight`, `wizard_launch_campaign` |

**State persistence:** `taphish_wizard_state_normalize()` in `spear/manager/engagement.php`
whitelists the non-secret IDs/strings persisted across steps (`sender_list_id`,
`user_group_id`, `mail_template_id`, `tracker_id`, `clone_slug`, `landing_url`,
`campaign_type`, plus the original OSINT/DKIM fields). Resume reads them back.

**Pure helpers worth knowing:** `spear/manager/wizard_tracker_builder.php`
(`taphish_wizard_build_minimal_tracker`, `taphish_wizard_build_campaign_data`),
`spear/manager/recipient_import.php` (CSV parse/preview/scope), `spear/manager/preflight_checks.php` (the 7 gates).

**Central dispatcher:** `spear/manager/userlist_campaignlist_mailtemplate_manager.php`
(if-cascade, `csrf_require()`, `taphish_require_authorize_or_die()` default-deny RBAC
via `spear/manager/authz.php`).

---

## 2. What we improved (with root causes)

### PR #142 — the rebuild
- Recipients are **committed** (group + sealed PII), not just previewed.
- Landing is **cloned inline** with a **web tracker auto-created** and injected
  (`<base>/mod?tlink=<id>`). The wizard never tells the operator to "go to the
  Site Cloner menu" anymore.
- Mail body uses an **inline editor** and **auto-wires** the CTA + tracking pixel.
- Sender can be **created inline** (SMTP) or selected.
- Launch builds a **real `campaign_data`** (`user_group`+`mail_template`+`mail_sender`
  +`mail_config`) and CAS-transitions the engagement draft→live. Previously
  `campaign_data` was empty → the launched campaign did nothing.
- Review-round hardening: `engId()` ReferenceError that broke commit+launch;
  tracker `content_js` JSON-encodes interpolated host/id (no JS injection into the
  victim-served script); RBAC on the cloner endpoint; `wizard_launch_campaign`
  restricted to `super-admin`/`engagement_owner`.

### Two latent bugs surfaced by the real-DB smoke test (PR #143)
- **`sender_probe` gate** hard-failed on a missing probe, and launch always passes
  `sender_probe=null` → **launch could never go green**. Made the gate degrade to
  "ok with a note" (consistent with the webhook + landing gates); a *wired* probe
  that fails still hard-blocks. See `spear/manager/preflight_checks.php`. **This is
  a product decision worth re-reviewing** (see §4).
- **`tb_core_mailcamp_sender_list.sender_acc_pwd` was VARCHAR(50)** (2022 schema)
  but Phase 3.27 at-rest sealing produces a base64 envelope that overflows 50 →
  **every sender save fatals** on un-migrated installs (the normal MailSender page
  too). Added an idempotent boot migration to VARCHAR(512) in
  `spear/manager/secret_at_rest.php` (`secret_at_rest_ensure_sender_pwd_width`),
  wired in `session_manager.php`.

### PR #143 — the seven live bugs (all browser-verified in PR #144)
- **E1** Pre-flight "scope_allowlist is empty" despite Step-1 scope: `wizard_preflight`
  trusted the client `#eng_scope` field (empty after the form resets / on resume).
  Now loads scope from the engagement server-side (JS passes `engagement_id`).
- **E2** "CTA still points to the REPLACE-WITH-LANDING-URL marker": pretext seed
  templates ship that placeholder; `wireBody()` only appended a CTA, never replaced
  the marker. `wireBody()` now substitutes it with `landing_url?rid={{RID}}`.
- **C1** OSINT MX "no records found" while DMARC worked: `dns_get_record(...,DNS_MX)`
  returns empty on the host though `DNS_TXT` works. Added a `getmxrr()` fallback in
  `taphish_mx_lookup` (live path only; the unit resolver path is unchanged).
- **C2** Look-alike domains now validated for registrability via Hostpoint
  (`homoglyph_check_candidates`), each shows its punycode + a "register →" link.
- **C3** Hunter lane "key not configured" despite a saved key: the wizard lane never
  forwarded the localStorage key (`taphish_hunter_apikey`); now it does (mirrors
  the Shodan lane). **PR #144** additionally distinguishes "no key" from "key
  present but rejected by Hunter" in `renderHunter`.
- **D** Recipient CSV import auto-detects delimiter (comma/semicolon/tab), header
  rows, the email column (by regex), and name columns. Tests updated + extended.
- **A/B** Engagement window pre-fills (now / +14d) + validates end-after-start +
  clarifies the picker; authorised domains render a live chip preview.

---

## 3. How we verified (and the limits)

1. **Unit:** `./vendor/bin/phpunit` → **906 tests green** (1 pre-existing skip).
   New pure-unit coverage for tracker builder (incl. a JS-injection regression),
   `campaign_data` builder, state-normalize whitelist, CSV auto-detect, preflight gates.
2. **Real-DB HTTP smoke:** fresh MySQL + the app over `php -S`, driving every
   server action with realistic payloads, asserting DB rows. This is how the two
   latent bugs (§2) were caught.
3. **Browser QA (PR #144):** Playwright as a real operator through all 7 steps,
   reading browser console + network bodies + PHP error log + DB. All fixes
   confirmed live; **0 JS / 0 PHP errors**. See `docs/quick-start-wizard-qa-workflow.md`.

**NOT verified end-to-end (gaps for the reviewer to weigh):**
- **Real mail send.** The campaign launches with `camp_status=0`; the actual SMTP
  send happens in `mail_campaign_cron.php`. No real SMTP/IMAP was exercised
  (sender probe is degraded — see §4). RID/MID substitution + the tracker hit path
  (`track.php`, `mod.php`, `tmail.php`, `qt.php`) were not driven end-to-end.
- **Real external OSINT** beyond what a live `google.com` lookup hit locally
  (Hunter with a *valid* key, Shodan with a key, crt.sh rate limits).
- **Production host** itself — the deploy is FTPS-only; we could not reach the
  masked operator host. Verification was on an identical-code local instance.
- **No JS unit tests exist** in the repo; all wizard JS is verified only via the
  browser QA. `wireBody`, `renderHunter`, chip rendering, CSV-side behaviour have
  no automated regression net.

---

## 4. Known difficulties / risk areas / tech debt (review these hard)

1. **`sender_probe` gate is now advisory.** We made a deliberate call that a missing
   probe must not block launch (a fully-configured campaign was un-launchable
   otherwise). Trade-off: a broken sender is only caught at send time (the cron has
   auto-pause-on-failure). Consider wiring a real, time-boxed SMTP probe at launch,
   or a one-click "Test sender" in Step 6. Decide and document.
2. **`wizard_preflight` recipient_emails still come from the client** (only scope was
   moved server-side). For the *interactive* preview that's acceptable, but the
   gate's "can't be bypassed" property only fully holds for launch (which reads the
   committed group). Consider deriving preview recipients server-side too.
3. **Site cloner in the wizard can't clone private/localhost targets** (SSRF guard),
   and the landing probe (`taphish_preflight_http_get`) has **no SSRF guard** — an
   authenticated operator can point it at internal hosts. Pre-existing; flagged in
   the original review. Weigh hardening vs. operator-trust model.
3b. **`clone_site` RBAC** guard was added in the wizard work, but confirm no other
   caller path bypasses it.
4. **`datetime-local` is local-time, labelled UTC.** The backend treats the value as
   UTC. If an operator in CET picks 10:00 it is stored as 10:00 UTC. Low impact for
   windows, but it's a real semantic mismatch — decide whether to convert or relabel.
5. **CSV header heuristic.** "header = mentions mail OR no cell is a valid email"
   plus name-column regexes. Edge cases: a first *data* row of all-names-no-email
   would be dropped as a header; non-Latin headers; quoted fields with embedded
   delimiters. Good test surface.
6. **`renderHunter` / OSINT error rendering** relies on regex matching of error
   strings (`/api\s*key/i`). Brittle; a Hunter wording change breaks the branch.
   Prefer structured error codes from `osint_hunter.php`.
7. **Hostpoint integration** only checks *name validity/registrability*, not true
   availability; the "register →" link goes to a generic Hostpoint page (no
   confirmed prefill param). Honest but could be upgraded (RDAP/WHOIS availability).
8. **Full-tree FTPS re-upload** on every deploy (lftp can't trust FTP mtimes) — slow,
   and `delete_remote=false` means stale remote files are never pruned. Operational.
9. **Out-of-scope by product choice:** Quick-Tracker-only and Web-Tracker-only
   engagement types were deferred (operator chose Mail+Landing only). `campaign_type`
   is persisted to make this extensible, but only the full funnel is implemented.
10. **Pretext seed templates still carry `REPLACE-WITH-LANDING-URL`** by design; the
    wizard replaces it, but any non-wizard path (editing a template directly) can
    still ship the marker — the pre-send guard catches it, but the UX is a dead-end.

---

## 5. What still needs improvement (seeds for your plan)

- Decide the sender-probe story (§4.1) and implement consistently.
- Add a JS test harness (even a tiny node-based one) for `wireBody`, CSV-side, chips.
- Harden the landing/webhook probes against SSRF, or document the accepted risk.
- Server-side recipient derivation for the preview gate (§4.2).
- Structured OSINT error codes (§4.6) to kill the regex-based rendering.
- Real send-path E2E test (cron → SMTP test double → tracker hit → capture).
- Consider the deferred engagement types if the operator wants them.
- Revisit datetime/UTC semantics (§4.4).

---

## 6. How to run + test locally (the exact recipe we used)

No system MySQL/PHP is assumed; we used Homebrew PHP + Anaconda's MySQL.

```bash
export PATH="/opt/homebrew/opt/php/bin:/opt/anaconda3/bin:$PATH"
# 1. composer (phpunit lives in vendor/)
composer install
./vendor/bin/phpunit                      # 906 green

# 2. fresh MySQL on a private socket/port
DD=/tmp/qa_data; rm -rf "$DD"; mkdir -p "$DD"
mysqld --no-defaults --initialize-insecure --datadir="$DD" --basedir=/opt/anaconda3
mysqld --no-defaults --datadir="$DD" --basedir=/opt/anaconda3 \
  --socket=/tmp/qa.sock --port=33099 --bind-address=127.0.0.1 --pid-file=/tmp/qa.pid &
mysql --socket=/tmp/qa.sock -uroot -e "CREATE DATABASE taphish CHARACTER SET utf8mb4;"

# 3. write spear/config/db.php pointing at 127.0.0.1:33099 (gitignored!)
# 4. bootstrap schema via install_manager.php createTables()+modifySniperPhishSettings()
#    — IMPORTANT: pass the timezone as JSON '{"timezone":"UTC"}', not a bare string,
#    or getInClientTime_FD() throws on login.

# 5. serve with a router that emulates .htaccess (extensionless→.php, /p/<slug>/→cloned dir)
PHP_CLI_SERVER_WORKERS=6 php -d log_errors=1 -d error_log=/tmp/qa_php.log \
  -S 127.0.0.1:8099 router.php
#    PHP_CLI_SERVER_WORKERS>1 is REQUIRED: server-side probes (landing fetch, clone
#    fetch) call back into the same server → single-worker deadlock otherwise.

# 6. admin / sniperphish forces a password change on first login; either change it
#    in the UI or UPDATE tb_main with hash_user_password('...') to unblock the wizard.
```

Browser QA: Playwright MCP (`browser_navigate`/`snapshot`/`evaluate`/`network_requests`/
`console_messages`). The full step-by-step is in `docs/quick-start-wizard-qa-workflow.md`.
Clean-up afterwards: stop mysqld + `php -S`, remove `spear/config/db.php`,
`spear/config/secret.key`, and `spear/sniperhost/cloned/<test-slug>/` (all gitignored).

---

## 7. Your deliverable

1. **Complete code review** of PRs #142, #143, #144 (diff `c6ce1f1^..main` for the
   whole arc) — correctness, security (this is offensive-security tooling: RBAC,
   CSRF, SSRF, injection, secret handling), simplicity/DRY, and project conventions.
2. **Consolidated bug-fix + improvement list**, severity-ranked, each with a confirmed
   root cause (use `superpowers:systematic-debugging`; do not guess).
3. **A plan** sequencing the work (what ships now, what's a follow-up, what's a
   product decision for the operator).
4. **A final write-out** the operator can read: what changed, why, what's verified,
   what's still open, and the recommended next steps.

Start from `git log --oneline -8` and the three PRs; read this doc's §4 first —
that is where the real risk lives.

# TAPhish — End-to-End UI Test Plan (full example engagement)

A complete, manual, UI-driven walkthrough that exercises **every** operator-facing
function by running one realistic phishing engagement from setup to teardown. Each
case is **ID · Action · Expected**; tick the box when the expected result is observed.

> Scope: operator panel (`/spear/…`), the public tracking endpoints, and the CLI
> maintenance tools. Follow the phases **in order** — later phases depend on data
> created earlier. Estimated time: ~2–3 h for a full pass.

---

## 0. Preconditions & test data

**Environment**
- A TAPhish install you control (local `php -S` dev box, or a staging copy of the
  operator host — **do not run destructive RBAC/backup/restore tests against live
  production data**).
- A **target domain you own** for the simulated victim side (e.g. `acme-lab.test`
  pointing at a mailbox + browser you control). Never target a third party.
- One test SMTP account (sender) and 2–3 test recipient mailboxes you can open.
- Browser with devtools; a second private-window/second browser for RBAC tests.

**Reference accounts** (create in Phase 0)
| Role | Username | Purpose |
|---|---|---|
| super-admin | `admin` | full access (bootstrap account) |
| operator | `op_alice` | owns the example engagement |
| operator | `op_bob` | second operator — used for isolation/RBAC tests |
| read-only | `viewer` | read-only negative tests |

**Example engagement (the data used throughout)**
- Name / slug: **Acme Q3 Assessment** / `acme-q3`
- Window: today → +14 days
- Scope allow-list: `acme-lab.test`
- Look-alike domain: `acme-1ab.test` (homoglyph of `acme-lab.test`)
- Sender: `it-support@acme-1ab.test` (SMTP test account)
- Pretext: an IT "password expiry" / M365 re-auth theme
- Recipients CSV (`recipients.csv`):
  ```
  email,fname,lname
  target1@acme-lab.test,Dana,Reed
  target2@acme-lab.test,Sam,Okoro
  bad-row-no-at,Broken,Row
  out-of-scope@gmail.com,Outsider,Person
  ```
  (the last two rows exercise partial-import + scope-violation handling)

**Result legend:** ✅ pass · ❌ fail (file an issue with the case ID) · ⏭ skipped (note why)

---

## Phase 1 — Access, accounts & RBAC

### 1.1 Authentication
| ID | Action | Expected |
|---|---|---|
| ☐ A-01 | Visit site root `/` | Redirects to `/spear/` login |
| ☐ A-02 | Log in as `admin` with wrong password 5× | Login throttled per-IP after the limit; clear "too many attempts" message |
| ☐ A-03 | Log in as `admin` correctly | Lands on Home dashboard |
| ☐ A-04 | Enable 2FA (TOTP) under My Profile; scan QR; confirm a code | 2FA enabled; recovery codes shown **once** — save them |
| ☐ A-05 | Log out, log back in | Prompted for TOTP; valid code admits, invalid is rejected |
| ☐ A-06 | Log in using a **recovery code** instead of TOTP | Admitted; that code is now consumed (reuse fails) |
| ☐ A-07 | Trigger forced password change (new account) | `ChangePwd` blocks navigation until a strong password is set (strength meter responds) |
| ☐ A-08 | Sit idle past the idle timeout | Idle-timeout warning appears; session ends as designed |

### 1.2 Users & roles (super-admin)
| ID | Action | Expected |
|---|---|---|
| ☐ R-01 | Settings → User Settings: create `op_alice` (operator), `op_bob` (operator), `viewer` (read-only) | Accounts created with the chosen role; appear in the table with a Role selector |
| ☐ R-02 | Try to demote the **last** super-admin (id=1) | Blocked (anti-lockout); id=1 role selector pinned |
| ☐ R-03 | Demote a non-last super-admin | Allowed |
| ☐ R-04 | As `viewer`, attempt any write (e.g. create a campaign) | Denied — 403 / `forbidden`; an `AUTH/warn` line appears in the audit log |
| ☐ R-05 | Break-glass: CLI `php spear/manager/cli/grant_super_admin.php op_alice` | `op_alice` promoted to super-admin (verify in User Settings), then demote back |

### 1.3 API tokens (self-service)
| ID | Action | Expected |
|---|---|---|
| ☐ T-01 | As `op_alice`, Settings → API Tokens → mint a token | Plaintext `tphtk_…` shown **once**; never shown again |
| ☐ T-02 | Call a dispatcher action with `Authorization: Bearer tphtk_…` | Authenticated as `op_alice`; passes the same authz guard |
| ☐ T-03 | Bearer call to an action `op_alice` isn't allowed to perform | 403 `forbidden` (same policy as the UI) |
| ☐ T-04 | Revoke the token, retry the bearer call | Rejected |

---

## Phase 2 — Engagement via the QuickStart wizard (7 steps)

Log in as `op_alice`. Workspace → **Quick Start**.

### 2.1 Step 1 — Engagement metadata
| ID | Action | Expected |
|---|---|---|
| ☐ W1-01 | Fill name `Acme Q3 Assessment`, slug `acme-q3`, window, scope `acme-lab.test`, notes; Save | Engagement created; appears in the recent-engagements list; `ENGM` audit entry |
| ☐ W1-02 | Observe the auto-cascade | First scope domain pre-fills the OSINT target; a DKIM selector is derived from the slug; OSINT pre-check auto-runs |

### 2.2 Step 2 — OSINT pre-check fan-out (all lanes resolve independently)
| ID | Action | Expected |
|---|---|---|
| ☐ W2-01 | SPF / DMARC lane | Posture verdict shown (hardened / partially-hardened / monitoring / spf-only-strict / wide-open / unknown) |
| ☐ W2-02 | MX classifier lane | Provider bucket (cloud-mailbox / security-gateway / shared-host) + recommended pretext categories |
| ☐ W2-03 | Homoglyph lane | Look-alike domain candidates listed |
| ☐ W2-04 | crt.sh subdomain lane | Subdomains enumerated (or graceful empty) |
| ☐ W2-05 | Hunter email-format lane | Email pattern guess (needs Hunter key; graceful "not configured" without it) |
| ☐ W2-06 | Web-fingerprint lane | Title / generator / robots / security.txt summary |
| ☐ W2-07 | Shodan lane | Resolved IP + org/country + open ports + CVE refs (needs Shodan key in localStorage; graceful without) |
| ☐ W2-08 | Loading states | Each lane shows a shimmer skeleton, then its result — lanes never block each other |

### 2.3 Steps 3–7
| ID | Action | Expected |
|---|---|---|
| ☐ W3-01 | Step 3 pretext picker | Top-N pretexts ranked by the MX classifier's categories; one-click clone deep-links to an editable copy |
| ☐ W4-01 | Step 4 DKIM | Generates a real RSA-2048 record (`v=DKIM1; k=rsa; p=…`) + suggested SPF/DMARC records; selector validates |
| ☐ W5-01 | Step 5 recipients — upload `recipients.csv` | Preview shows per-domain breakdown; the 2 valid rows import; **partial-import** toast lists the malformed row; the `gmail.com` row is flagged out-of-scope and dropped |
| ☐ W6-01 | Step 6 landing | Picker exposes Site Cloner / AI-gen / Landing Library deep-links (no "planned" placeholders) |
| ☐ W7-01 | Step 7 pre-flight | The 5 gates evaluate (scope coverage, recipient count, DMARC-vs-sender, sender reachability, webhook reachability); **Launch stays disabled** until all green |
| ☐ W7-02 | Make all gates green, Launch | Campaign created (CAS-protected); redirect to `EngagementView?engagement_id=N` |
| ☐ W7-03 | Double-click Launch / re-launch | Rejected — no double-transition (compare-and-swap) |

### 2.4 Resume (Phase 3.56)
| ID | Action | Expected |
|---|---|---|
| ☐ WR-01 | Start a *second* engagement, advance to Step 4, leave | Progress persisted server-side |
| ☐ WR-02 | Reopen `QuickStart?engagement_id=N` | Restores saved step + non-secret state (target domain, DKIM selector); stepper reflects position |
| ☐ WR-03 | "Continue setup" deep-links | Present on the QuickStart recent list **and** EngagementView for draft engagements |

---

## Phase 3 — Manual building blocks (dedicated pages)

These reach the same features without the wizard. Stay as `op_alice`.

| ID | Page / action | Expected |
|---|---|---|
| ☐ B-01 | **User Group** → create: requires an **engagement selector**; upload a CSV | List created + stamped with the engagement; PII stored sealed |
| ☐ B-02 | User Group: view / edit a recipient / delete / copy / download CSV | All work; downloaded CSV shows decrypted rows; copy preserves the sealed blob |
| ☐ B-03 | **Email Template** → create with the Summernote editor; insert merge tokens (`{{fname}}`, tracking/POST URLs) | Saved; preview renders; tokens substitute at send |
| ☐ B-04 | Email Template → copy an existing template | Duplicated to the operator's list (`CLON` audit) |
| ☐ B-05 | **Sender List** → add an SMTP sender (host/port/user/password) | Saved; SMTP password stored encrypted at rest (not echoed back) |
| ☐ B-06 | **Configuration** (MailConfig) | Loads and saves campaign config |
| ☐ B-07 | **Sender Toolkit** → homoglyph + typo + TLD-swap generator for `acme-lab.test` | Candidate domains listed (incl. `acme-1ab.test`) |
| ☐ B-08 | Sender Toolkit → SPF/DMARC analyzer | Operator-facing verdict matches W2-01 |
| ☐ B-09 | **Pretext Library** → browse 12 starters; clone one | Cloned into the operator's template list; deep-link works |

---

## Phase 4 — Hosted / landing pages

| ID | Page / action | Expected |
|---|---|---|
| ☐ H-01 | **Site Cloner** → clone a benign target page | Clone succeeds; after-clone box shows the **public landing URL** (honours `X-Forwarded-Proto`) + Copy + Open buttons + 3-step "use in a campaign" hint |
| ☐ H-02 | Site Cloner → existing-clones table | Per-row Open / Copy-URL links work |
| ☐ H-03 | Site Cloner → tick **Inject BeEF hook** (default off) | Hook snippet spliced before `</body>` (verify in the cloned source); anti-malware warning surfaced |
| ☐ H-04 | **Landing Library** → 3 templates (m365-login, vpn-portal, sso-redirect) | Each card shows pattern badge + captured-fields list + "customize before launch" callout |
| ☐ H-05 | Landing Library → "Clone to my sites" modal | Destination-slug suggestion + tracker/POST overrides; clone substitutes `{{POST_URL}}`/`{{TRACKER_URL}}` |
| ☐ H-06 | Hosted Pages → Plain-Text / Files / Landing Page | Each editor loads and publishes |
| ☐ H-07 | **Deploy Landing Page** (LookalikeDeploy) → pick engagement + `acme-1ab.test` + a cloned page + hosting mode | Copy-paste DNS set emitted: web (A op-hosted / CNAME TAPhish-hosted) + SPF + DKIM + DMARC; IDN hosts punycoded |
| ☐ H-08 | LookalikeDeploy → **operator-hosted**: download bundle | `LookalikeBundleExport.php` streams a zip with `{{POST_URL}}`/`{{TRACKER_URL}}` substituted (text only; binary verbatim) |
| ☐ H-09 | LookalikeDeploy → **TAPhish-hosted**: publish | Returns `https://<host>/p/<slug>/`; visiting it serves the cloned page via the root `.htaccess` rewrite; publish/download audit-logged |

---

## Phase 5 — Trackers

| ID | Page / action | Expected |
|---|---|---|
| ☐ K-01 | **Quick Tracker** → generate a tracking link | `qt.php` link produced |
| ☐ K-02 | Quick Tracker Report | Hits listed after you visit the link (Phase 6) |
| ☐ K-03 | **Web Tracker** → New Tracker (TrackerGenerator) | Tracker created; appears in Tracker List |
| ☐ K-04 | Web Tracker Report | Renders (data appears after victim-side visits) |

---

## Phase 6 — Launch, send & victim-side flows

### 6.1 Campaign & send
| ID | Action | Expected |
|---|---|---|
| ☐ C-01 | **Campaign List** → the wizard-created campaign is present, linked to engagement `acme-q3` | `engagement_id` set; legacy campaigns stay NULL |
| ☐ C-02 | Configure schedule (timezone-aware) + a test send to your own mailbox | Test email arrives; merge tokens resolved; links point at the tracker/landing |
| ☐ C-03 | Launch the campaign; run the cron worker `php spear/core/SniperPhish_Manager.php` (or host Tasks) | Worker sends to the 2 in-scope recipients; single-instance guard prevents duplicates; `SEND` audit entries |
| ☐ C-04 | (If IMAP configured) bounce a message | Auto bounce-poll marks the recipient; campaign auto-complete fires when done |

### 6.2 Victim side (use your controlled mailbox/browser)
| ID | Action | Expected |
|---|---|---|
| ☐ V-01 | Open the phishing email (loads the pixel) → `tmail.php` | Open recorded for that recipient |
| ☐ V-02 | Click the link → `qt.php` → lands on the cloned page | Click recorded; redirected to the landing page |
| ☐ V-03 | Submit credentials on the landing page → `track.php` | Capture recorded (`CAPT`); **first-capture webhook fires** (Slack/Teams/Discord-shaped JSON) |
| ☐ V-04 | Submit a 2FA code (m365-login multi-step) | `is_2fa_capture` flagged; `code_2fa` stored; **repeat-capture webhook** fires on a *second* 2FA submit, then is suppressed (`repeat_webhook_sent`) |
| ☐ V-05 | Re-request the link from a known **scanner** (e.g. spoof a SafeLinks/Proofpoint UA, or a datacenter IP) | Classified as scanner; inserted with `is_scanner=1`; surfaces as `SCAN/warn`, **excluded from headline open-rate** |

---

## Phase 7 — Monitoring & reporting

| ID | Page / action | Expected |
|---|---|---|
| ☐ M-01 | **Home** dashboard | Metric strip + activity feed with per-kind pill badges (AUTH/CAMP/RECP/TMPL/SEND/SCAN/CAPT/ENGM/CLON/SYS); "Jump back in" launchpad tiles navigate correctly |
| ☐ M-02 | **Email Campaign Dashboard** (MailCmpDashboard) | Recipient table; "Captures · 2FA" badge; **"Hide scanner hits"** toggles `<tr data-scanner="1">` rows; KPIs exclude scanner opens and show a separate scanner-hit count |
| ☐ M-03 | **Web-MailCamp Dashboard** | Renders web-tracker + campaign data |
| ☐ M-04 | **Engagements** (EngagementView) → pick `acme-q3` | Header card + linked-campaigns table; status transition buttons (draft → live → completed/cancelled) work via compare-and-swap |
| ☐ M-05 | EngagementView → **Export PDF** | Streams `taphish-report-acme-q3-<ts>.pdf`; contains **counts-by-domain only, no individual emails/PII**; generation writes an audit line |
| ☐ M-06 | **Manage members** (EngagementMembers) → add `op_bob` by username | Roster updates; last-owner stranding guard prevents removing the final owner |
| ☐ M-07 | **Settings → Audit Log** (SettingsAuditLog) → filter by kind/severity/username/date/substring; paginate | Filters work against classified freeform log lines; 100-row pages |
| ☐ M-08 | Audit Log → **Export CSV** | `AuditLogExport.php` streams RFC-4180 CSV (capped); filtered export not truncated at the page cap |
| ☐ M-09 | **Logs** (SPLogs) + **About** (SPAbout) | Logs render; About shows brand + product version + `www.t-alpha.ch` link |

---

## Phase 8 — Integrations & toolkit

| ID | Page / action | Expected |
|---|---|---|
| ☐ I-01 | **Toolset Checker** (ToolsetChecker) | Runs probes (PHP version + extensions + writable dirs + SPF/DMARC/MX DNS + webhook reachability + /status liveness); verdict badge ready/caution/blocked |
| ☐ I-02 | Settings → General → **webhook** config; Test | Sends a test payload; first-capture alert (V-03) used this URL (stored encrypted in `tb_store`) |
| ☐ I-03 | Settings → General → **BeEF integration** (URL/user/password); Save + Test | Creds stored encrypted; Test reports reachable / unreachable / auth-failed; anti-malware warning surfaced |
| ☐ I-04 | Home → **BeEF hooked-browsers** widget | Polls every ~30 s while the tab is visible; in-scope vs out-of-scope chips vs active engagements; degrades on not_configured / unreachable |
| ☐ I-05 | (If configured) **Telegram** alerting | Capture alert delivered to the Telegram bot |
| ☐ I-06 | **Status / health** endpoints: `status.php`, `health.php` | Return liveness for uptime monitors |

---

## Phase 9 — Security & isolation (negative tests)

| ID | Action | Expected |
|---|---|---|
| ☐ S-01 | As `op_bob` (not a member of `acme-q3`), list recipient groups | Sees only his own/NULL-unscoped lists — **not** `acme-q3`'s PII lists |
| ☐ S-02 | As `op_bob`, directly request `op_alice`'s user-group by id (view/download/delete/copy/edit) | 403 `forbidden` + audit line (single-row guard) |
| ☐ S-03 | As `op_bob`, try to create a recipient list stamped to `acme-q3` | Rejected (not a member) |
| ☐ S-04 | Import a recipient outside the scope allow-list | Dropped + reported (as in W5-01) |
| ☐ S-05 | Replay/forge a request without the CSRF token | 403 (CSRF gate); token persists across refresh (no false 403 on normal use) |
| ☐ S-06 | As `viewer`, attempt the full dispatcher surface (spot-check writes) | Default-deny — every disallowed action returns 403, never a soft empty 200 |
| ☐ S-07 | Confirm secrets-at-rest | In the DB, `tb_core_mailcamp_user_group.user_data`, SMTP/IMAP passwords, webhook URL, BeEF creds are `enc1:`-prefixed (not plaintext) |
| ☐ S-08 | Confirm scanner isolation | Scanner hits don't pollute the open/click KPIs (M-02) |
| ☐ S-09 | Public landing page favicon | Cloned/hosted pages serve a **generic** favicon (no TAPhish mark — opsec) |

---

## Phase 10 — Backup, restore & data protection (CLI, Phase 3.49/3.50/3.50b)

> Run on staging or with disposable data — restore is destructive.

| ID | Action | Expected |
|---|---|---|
| ☐ D-01 | `php spear/manager/cli/reencrypt_recipient_pii.php --dry-run` | Reports rows that would be sealed; writes nothing |
| ☐ D-02 | `php spear/manager/cli/reencrypt_recipient_pii.php` | Seals any lingering plaintext rows; idempotent on re-run (0 sealed); refuses to run if the at-rest key is missing |
| ☐ D-03 | `php spear/manager/cli/backup_run.php --dry-run` | Lists tables + row counts + intended filename; writes nothing |
| ☐ D-04 | `php spear/manager/cli/backup_run.php` | Writes `spear/uploads/backups/taphish-backup-<UTC>.tapbak`; prints tables/rows/size/pruned; a deny-all `.htaccess` exists in the backups dir |
| ☐ D-05 | Try to fetch the backup over HTTP (`/spear/uploads/backups/<file>.tapbak`) | **Forbidden** (the `.htaccess` blocks web access) |
| ☐ D-06 | `php spear/manager/cli/backup_run.php --with-state` | Archive also contains `state/cloned|attachments|timages`; report shows a state-file count |
| ☐ D-07 | Run backup_run ≥ (keep+1) times (e.g. `--keep=2`) | Only the newest N `.tapbak` remain (rotation) |
| ☐ D-08 | `php spear/manager/cli/backup_restore.php --in=<file>.tapbak` | Decrypts to a `.sql` (DB-only) **or** extracts `db.sql` + `state/…` to a staging dir (`--with-state`); auto-detects gzip vs zip |
| ☐ D-09 | `backup_restore.php --in=<file> --apply --yes` (disposable DB) | Restores the DB; for a `--with-state` archive also copies state files back to live roots |
| ☐ D-10 | `backup_restore.php --apply` **without** `--yes` | Refuses; prints the decrypted `.sql` path; exits non-zero |
| ☐ D-11 | Restore with the wrong key / a tampered `.tapbak` | Fails cleanly (GCM auth); no half-written output |

---

## Phase 11 — Teardown

| ID | Action | Expected |
|---|---|---|
| ☐ Z-01 | EngagementView → transition `acme-q3` to **completed** (or **cancelled**) | Status updates; transition audit-logged |
| ☐ Z-02 | Remove test recipient lists / campaigns / cloned pages | Cleaned up; deletes audit-logged |
| ☐ Z-03 | Revoke test API tokens; disable/remove `op_bob`/`viewer` test accounts | Done |
| ☐ Z-04 | (Staging) drop the disposable DB used for restore tests | Done |

---

## Coverage matrix (feature → cases)

| Feature area | Phase(s) covered |
|---|---|
| Auth / 2FA / recovery codes / pwd change / throttle / idle timeout | A-01…A-08 |
| RBAC roles / anti-lockout / default-deny / break-glass CLI | R-01…R-05, S-06 |
| Bearer API tokens | T-01…T-04 |
| QuickStart wizard (7 steps) + resume | W1…W7, WR-01…WR-03 |
| OSINT (SPF/DMARC, MX, homoglyph, crt.sh, Hunter, web-fingerprint, Shodan) | W2-01…W2-08, B-07/B-08 |
| Pretext library | W3-01, B-09 |
| DKIM gen + sender posture | W4-01, B-08 |
| Recipient lists + PII seal + partial import + scope | W5-01, B-01/B-02, S-01…S-04, S-07 |
| Templates + merge tokens + copy | B-03/B-04, C-02 |
| SMTP senders (encrypted) + config | B-05/B-06 |
| Site Cloner + BeEF inject | H-01…H-03, I-03/I-04 |
| Landing Library | H-04/H-05 |
| Hosted Pages (plain/files/landing) | H-06 |
| Look-alike deploy + DNS + bundle + hosted publish | H-07…H-09 |
| Quick + Web trackers | K-01…K-04 |
| Campaign launch / cron / schedule / bounce / auto-complete | C-01…C-04 |
| Tracking endpoints (open/click/capture/2FA/scanner) | V-01…V-05 |
| Webhook + repeat + Telegram alerting | V-03/V-04, I-02/I-05 |
| Home / dashboards / KPIs / scanner hide | M-01…M-03, S-08 |
| Engagements / status / members / PDF report | M-04…M-06 |
| Audit log viewer + CSV export | M-07/M-08 |
| Logs / About / Toolset Checker / status / health | M-09, I-01, I-06 |
| Security: CSRF / isolation / scope / at-rest / opsec favicon | S-01…S-09 |
| Backup / restore / rotation / state snapshot / re-encrypt | D-01…D-11 |

---

## Known non-blockers (don't file these as bugs)

- **Open / Click rate tiles** on Home render `—` (the metrics blob isn't wired
  server-side yet; the JS handles real numbers once `home_manager.getHomeGraphsData`
  exposes them).
- `actions/checkout@v4` Node-20 deprecation — cosmetic CI warning only.
- `.is-error` trend class defined in CSS but not written by JS.
- A pre-existing `moment is not defined` warning in `common_scripts.js`.

## Sign-off

| | Name | Date | Result |
|---|---|---|---|
| Tester | | | |
| Reviewer | | | |

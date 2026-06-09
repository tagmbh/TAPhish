# REVIEW — Quick-Start Wizard (PRs #142 / #143 / #144)

**Reviewer role:** senior adversarial code review of the full-funnel Quick-Start
wizard arc, per `docs/HANDOFF-quick-start-wizard.md`.

**Scope reviewed:** `git diff c6ce1f1^..main` — the rebuild (#142), the seven
live-bug fixes + two latent-bug fixes (#143), and the Hunter-lane polish (#144).
21 files, ~2.5k insertions. The handoff's §4 risk list was the starting point.

**Bottom line:** the work is solid and the two security-critical gates the
operator cares about — *scope* and *recipients* — are genuinely non-bypassable at
launch (re-derived server-side from the engagement row and the sealed committed
group). The remaining findings are a **mail-body gate that still trusts the
client at launch**, a **reachable SSRF in the landing probe**, and a set of
lower-severity UX/robustness/test-debt items. Nothing here is a launch-blocker
for the feature; items 1 and 2 are worth fixing before the next engagement.

---

## A. Verified NOT bugs (so they don't get re-chased)

- **`getRandomId()`** (used in `saveMailTemplate`/`saveSender`) is defined in
  `spear/js/common_scripts.js:122`, which `QuickStart.php` loads before
  `quick_start.js`. No ReferenceError (this was the class of bug that bit #142's
  first round with `engId`).
- **RBAC is enforced.** The dispatcher calls
  `taphish_require_authorize_or_die($conn, action_type, …)` at
  `userlist_campaignlist_mailtemplate_manager.php:45` (default-deny). All three
  new actions (`wizard_create_web_tracker`, `wizard_list_web_trackers`,
  `wizard_commit_recipients`) are in `TAPHISH_POLICY`, and `wizard_launch_campaign`
  was correctly tightened to `super-admin`/`engagement_owner`. The separate
  `site_cloner_manager.php` endpoint now has its own `site_clone` authz gate.
- **`{{TRACKER}}` is the right token.** It is substituted into an `<img>`
  open-pixel at send time (`…manager.php:1566`), independent of the stored
  `timage_type` flag. `wireBody()` appends it exactly once and is **idempotent**
  on re-save: the landing-URL presence check prevents a second CTA, and the
  `{{TRACKER}}` presence check prevents a second pixel.
- **Tracker JS is XSS-safe.** `taphish_wizard_build_minimal_tracker` JSON-encodes
  the interpolated `tracker_id` and host with `JSON_HEX_*` flags before embedding
  them in the victim-served `content_js`. The regression test covers this.
- **Launch scope + recipients are server-trusted.** `wizard_launch_campaign`
  loads `scope_allowlist` from `taphish_engagement_get_by_id()` and the recipient
  emails from the sealed committed group via `recipient_data_unseal()`, then runs
  preflight on those — the JS `context` cannot spoof them. CAS `draft→live` with
  rollback-on-insert-failure is correct, and the campaign INSERT is parameterized.
- **`secret_at_rest_ensure_sender_pwd_width`** is idempotent (reads
  `information_schema` first, only widens when `< 512`) and guarded behind
  `function_exists`. Correct fix for the VARCHAR(50) overflow.
- **Landing public URL** `/spear/sniperhost/cloned/<slug>/` matches between the
  server (`ClonedSite::buildPublicUrl`) and the JS fallback (`publicUrlFromSlug`),
  so there is no URL-shape mismatch feeding the landing gate. (See F9 for the
  cosmetic `/p/<slug>/` observation.)

---

## B. Findings (severity-ranked)

### F1 — Launch-time mail-body gate trusts the client, not the stored template  *(Medium — security/correctness)*

**Root cause (confirmed in code).** `wizard_launch_campaign` moved *scope* and
*recipients* to trusted server sources, but the remaining preflight inputs still
come from the client `context`:

```php
'rendered_mail_body' => (string)($ctx['rendered_mail_body'] ?? ''),
'sender_domain'      => (string)($ctx['sender_domain'] ?? ''),
'target_domain'      => (string)($ctx['target_domain'] ?? ''),
'target_dmarc_policy'=> (string)($ctx['target_dmarc_policy'] ?? ''),
```

The campaign that actually sends is built from the **stored** `mail_template_id`
(validated to exist, but its *content* is never re-checked). So the CTA/marker
gate — the one whose whole job is "refuse to launch if the body still carries
`REPLACE-WITH-LANDING-URL` or points the `<a href>` at the tracking pixel" — is
validating a client-supplied string, not the template that goes out. An operator
(or a replayed request) can pass a clean `rendered_mail_body` while the persisted
template still contains the marker. The handoff flags this generically in §4.2 for
"recipient_emails"; in practice the live residual gap is the **mail body**.

**Fix.** At launch, load `mail_template_content` for the validated
`mail_template_id` and feed that to the body gate; derive `sender_domain` from the
stored sender's `sender_from`, and `target_domain`/`target_dmarc_policy` from the
engagement / persisted OSINT record. Then the launch gates depend only on
committed state. (The interactive Step-7 preflight can keep using the client
context — that one is advisory.)

### F2 — SSRF reachable through `landing_url` at preflight/launch  *(Medium — security)*

**Root cause (confirmed).** `taphish_preflight_http_get($url)` is invoked
server-side on `landing_url`, which is a client POST parameter
(`$landing_url !== '' ? $landing_url : $ctx['landing_url']`). Unlike the site
cloner, this probe has **no SSRF guard**, so an authenticated operator can point
the server's HTTP client at `http://169.254.169.254/…`, internal admin panels,
etc. The handoff lists this as pre-existing (§4.3), but the full-funnel launch now
routes an attacker-chosen URL straight into it.

**Fix.** Constrain `landing_url` to this host's own cloned landings (resolve the
slug and rebuild the URL server-side from `clone_slug`, rather than trusting the
URL), or run it through the same private-range/DNS-rebinding guard `ClonedSite`
uses for clone targets. This is offensive-security tooling with a trusted
operator model, so it's "harden vs. document the accepted risk" — but it should be
a conscious decision, recorded.

### F3 — `sender_probe` gate is now advisory with no compensating control  *(Low — product/safety)*

**Root cause (deliberate, §4.1).** `taphish_preflight_sender_reachable_gate(null)`
now returns `ok` instead of hard-failing, because launch always passes
`sender_probe = null`. Reasonable (a fully-configured campaign was otherwise
un-launchable), but the trade-off is that a broken sender is only discovered at
cron send-time. The gate's docblock and the handoff both promise a "Test sender"
action as the compensating control — **it does not exist yet**.

**Fix.** Add a one-click, time-boxed "Test sender" in Step 6 that runs the
existing `verifyMailboxAccess` and wires a *real* probe into the gate when the
operator has tested. Until then the degraded gate is acceptable but undocumented
in the UI.

### F4 — Hunter error routing is regex-on-error-string + localStorage sniff  *(Low — robustness)*

**Root cause (confirmed, §4.6).** `renderHunter` decides "no key" vs "key
rejected" with `/api\s*key/i.test(err)` over the human error string, then reads
`localStorage['taphish_hunter_apikey']` to pick the branch. A wording change in
`osint_hunter.php` silently mis-routes the message, and the localStorage sniff
couples the OSINT renderer to a Settings-page storage key.

**Fix.** Return a structured code from `osint_hunter_search` (e.g.
`error_code: 'no_key' | 'key_rejected' | 'rate_limited'`) and branch on that.
Removes the regex and the localStorage read.

### F5 — `runRecipientCommit` makes a redundant preview round-trip  *(Low — efficiency)*

**Root cause (confirmed).** Commit fires `wizard_recipient_preview` *and*
`wizard_commit_recipients`; the commit re-parses the same CSV server-side. The
preview call exists only to populate `WZ.recipient_emails` for the Step-7 summary
count — which launch ignores (it reads the committed group). One request would do.

**Fix.** Have `wizard_commit_recipients` return the in-scope emails (or just the
count), drop the extra preview POST in the commit path, and populate the summary
from the commit response.

### F6 — CSV header heuristic can swallow a headerless first data row  *(Low — correctness, edge case)*

**Root cause (confirmed, §4.5).** `$isHeader = $mentionsMail || !$anyCellIsEmail`.
A genuine first data row with no e-mail cell (e.g. a name-only row in a multi-part
import, or a row whose email column is blank) is classified as a header and
dropped. Acceptable for the common shapes, but it's a silent data-loss path.

**Fix.** Add unit cases for: headerless file whose first row has no valid email;
non-Latin header names; quoted fields with embedded delimiters. Decide whether a
no-email first row should be treated as a skipped *data* row (with an error) rather
than a header.

### F7 — `datetime-local` local-time stored as UTC  *(Low — semantics)*

**Root cause (confirmed, §4.4).** `prefillWindow()` builds the value from
`new Date()` local getters and the field is labelled "(UTC)", but the backend
stores the literal value as UTC. An operator in CET picking 10:00 stores 10:00 UTC.
Low impact for multi-day windows, real for scheduling.

**Fix.** Either convert local→UTC on submit, or relabel the field "local time" and
convert on read. Pick one and document.

### F8 — No JS regression net for the new wizard logic  *(Info — test debt)*

`wireBody` (idempotency-critical), `renderHunter`, scope-chip rendering, the
tracker/sender/clone flows, and the CSV client glue have **zero** automated tests;
only the PHP pure helpers are unit-covered (and well — 906 green). A tiny
node-based harness for `wireBody` and the CSV-side helpers would catch the most
likely future regressions.

### F9 — CTA exposes the internal clone path  *(Info — pre-existing, cosmetic)*

The canonical public/CTA URL is `/spear/sniperhost/cloned/<slug>/`, while a
prettier `/p/<slug>/` rewrite exists in `.htaccess` but is never used. The
phishing mail's CTA therefore shows the internal path — a mild fingerprinting
tell. Pre-existing; consider making `/p/<slug>/` the canonical `buildPublicUrl`
output.

---

## B′. Status (what shipped on this branch)

| # | Finding | Status |
|---|---------|--------|
| F1 | Launch gate trusts client mail body | **Fixed** — launch judges the stored template body + server-derived sender domain |
| F2 | SSRF via `landing_url` | **Fixed** — `taphish_landing_url_is_probeable()` restricts the probe to a cloned page on this host (both call sites) |
| F4 | Brittle Hunter error routing | **Fixed** — structured `err_code`; `renderHunter` branches on it, regex kept only as legacy fallback |
| F5 | Redundant preview round-trip | **Fixed** — commit returns the in-scope emails; the extra preview POST is gone |
| F6 | CSV header edge cases | **Fixed** — 4 tests added (quoted delimiter, German header, non-Latin fallback, and a characterization test pinning the no-email-first-row drop) |
| F7 | datetime-local vs UTC | **Fixed** — field relabelled to the operator's local time and converted local→UTC on submit (operator chose convert-on-submit) |
| F3 | Sender-probe advisory / "Test sender" | **Fixed** — Step 6 "Send test" button sends a real test mail via `send_test_mail_verification` (operator chose the SMTP path); the launch gate stays advisory by design |
| F8 | No JS regression net | **Fixed** — `wireBody`/`slugifyName` extracted to a pure module (`spear/js/wizard_pure.js`) with a zero-dependency node test (`tests/js/wizardPure.test.mjs`, 10 cases) |
| F9 | CTA exposes internal clone path | **Fixed** — `buildPublicUrl` + JS fallback emit the clean `/p/<slug>/` alias |

Test count: 921 PHPUnit green (was 910) + 10 node tests for the pure JS.

**All nine findings are now addressed.** Run the JS tests with `node tests/js/wizardPure.test.mjs`. Note: F9 relies on the `.htaccess` `/p/<slug>/` rewrite, and the local QA router must map the same alias; the F2 guard accepts both `/p/` and the legacy internal path, so a mixed deployment is safe.

## C. Plan (sequenced)

**Ship now (small, high-value, no product decision):**
1. **F1** — feed the stored template content + server-derived sender/target into
   the *launch* gates. Closes the only residual gate-bypass on the launch path.
2. **F5** — drop the redundant preview POST in commit. Pure cleanup.
3. **F6** — add the CSV edge-case unit tests (and decide the no-email-first-row
   behaviour while the parser is fresh).

**Ship next (one focused PR each):**
4. **F2** — constrain/guard `landing_url` (rebuild from slug server-side is the
   simplest robust fix).
5. **F4** — structured OSINT error codes; delete the regex + localStorage sniff.
6. **F8** — minimal JS test harness, starting with `wireBody` idempotency.

**Product decisions for the operator (document the call, then implement):**
7. **F3** — "Test sender" action + real probe wiring vs. accept the advisory gate.
8. **F7** — UTC-convert vs. relabel the engagement window.
9. **F9** — adopt `/p/<slug>/` as the canonical landing URL, or accept the path.

**Deferred by product choice (from the handoff, no action):** Quick-Tracker-only
and Web-Tracker-only engagement types (`campaign_type` is already persisted to
keep this extensible).

---

## D. Write-out for the operator

The Quick-Start wizard rebuild is in good shape: it now commits every artifact and
launches a fully-linked Mail+Landing campaign, and the two checks you most care
about — that recipients stay inside the authorised scope, and that the launch uses
the recipients you actually committed — are enforced on the server and **cannot be
faked from the browser**. The DB schema fix (sender-password column width) and the
sender-probe relaxation mean a fully-configured campaign will now actually launch
on a clean install, which it couldn't before.

Two things are worth fixing before the next live engagement:

- **The launch's "is the mail body wired correctly?" check still reads the body
  from your browser, not the saved template.** In normal use (you save & wire in
  Step 5, then launch) this is fine. But it means the check isn't a true safety
  net — if a template ever kept the `REPLACE-WITH-LANDING-URL` placeholder, the
  launch could still go through. We should make the launch re-read the stored
  template. *(F1)*
- **The launch fetches the landing URL you pass it, with no guard on where it
  points.** As the operator you're trusted, but in principle this lets the server
  be aimed at internal addresses. Worth constraining to your own cloned pages.
  *(F2)*

Everything else is polish: a "Test sender" button so you can verify SMTP before
launch instead of finding out at send time (F3); a clearer Hunter "key rejected vs
not configured" path that won't break if Hunter changes its wording (F4); and a
decision on whether the engagement window times are UTC or your local time (F7).

**Verified:** 906 PHPUnit tests green; the pure builder/parse/gate helpers are
well covered. **Not verified end-to-end:** the real SMTP send, the live
tracker-hit path, and external OSINT with valid keys — those need a send-path E2E
with a test double, which is the recommended next investment (F8).

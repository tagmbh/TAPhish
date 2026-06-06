# TAPhish — test-suite coverage map + plan

_Living document. Snapshot: 2026-06-06 — **751 tests / 2352 assertions**, ~48 PHPUnit files, all green._

## How testing works here

The suite is a **pure-helper / injectable-seam** design: business logic lives in
side-effect-free functions in `spear/manager/*.php` (DB/HTTP/clock/openssl passed
in as closures), so `vendor/bin/phpunit` runs **offline with no DB**. The bootstrap
`tests/Support/helpers_shim.php` `require_once`s each pure module under test. CI
runs the suite on PHP 8.1 / 8.2 / 8.3.

This is why coverage is near-total for the *helper* layer and ~zero for the
*dispatcher* layer: dispatchers (`*_manager.php`) read `$_POST`/`$_SESSION` and run
SQL directly, so they can't be loaded offline. Closing that gap needs a second
**integration tier** (below), not more pure tests.

## What's well covered (pure-helper tier)

~48 test files cover essentially every pure module, including the security- and
correctness-critical ones: `authz` (RBAC policy), `csrf`, `secret_at_rest`
(AES-256-GCM at-rest), `totp` + recovery codes, `login_throttle`,
`password_hash_helper`, `api_token`, `scanner_detect`, `recipient_import`,
`dkim_helper`, `backup_*` (incl. the hand-rolled SigV4 vs the AWS vector),
`recipient_reencrypt`, `engagement` (+ wizard state), `customer_report_aggregator`,
`dashboard_metrics`, `log_classifier`, the OSINT parsers, and now
`security_headers` (this round).

## Gaps

### A. Remaining *pure* wins (offline-testable; extract-pure-core pattern)

These live inside `spear/manager/common_functions.php` (587 LOC, only 3 tests today
via `IsValidEmailTest`). The file as a whole is I/O-coupled and can't load offline,
but individual functions are pure and high-value — extract each into a small module
(as was done for `dkim_helper`, `recipient_import`, etc.) and unit-test it:

| Target | Why it matters | Tests to write |
|---|---|---|
| `getMailerDSN()` | Builds the SMTP DSN from user/pass/host. A password containing `:` `/` `@` `#` `%` must be percent-encoded or the DSN breaks / mis-auths. | round-trip with special-char passwords; correct scheme per provider; no raw delimiter leaks into the DSN |
| `filterKeywords()` | Merge-token substitution (`{{FNAME}}` …) into mail/landing HTML. Adversary-controlled recipient fields flow here → report/template XSS + token-break risk. | special chars in values don't break the template; unknown tokens left intact; no double-substitution |
| `getQueryValsFromURL()` / encoding helpers | Used in report/link building. | malformed input, encoding round-trips |

`security_headers` (done this round) was the first of these — the pure header set is
now pinned (`taphish_security_headers_list()`), incl. CR/LF-injection and
duplicate-name guards.

### B. Dispatcher + DB/session layer (needs an **integration tier**)

14 top-level `*_manager.php` dispatchers and `session_manager.php` are untested
because they're DB/session-coupled. The highest-value behaviours to lock down (from
a security standpoint) are:

1. **Auth core** (`session_manager.php`): `validateLogin`, TOTP enforcement +
   rollback, session regeneration on privilege change, `isSessionValid` redirect.
2. **RBAC enforcement end-to-end** (`authz.php` DB facades + the dispatchers):
   `taphish_user_group_scope_where` / `_guard_or_die` / `_can_stamp` — operator A
   must not read/modify operator B's engagement's recipient lists; super-admin sees
   all; legacy `engagement_id IS NULL` stays visible. The *predicate* is unit-tested;
   the *SQL + dispatcher wiring* is not.
3. **Dispatcher default-deny**: every `*_manager.php` action goes through
   `taphish_require_authorize_or_die`; a forbidden action must return 403 +
   `{result:'forbidden'}` + an `AUTH/warn` audit line (never a soft empty 200).
4. **CSRF lifecycle**: token required on POST/PUT/DELETE, rotated correctly, GET
   exempt — against a real dispatcher.
5. **Crypto rotation** (`secret_at_rest` + `recipient_reencrypt` + a fixture row):
   data sealed under key A still decrypts after rotation; re-encrypt sweep leaves
   every row `enc1:` or empty.
6. **Cron/shell safety** (`common_functions.php::executeCron`): `campaign_id` must
   be `ctype_digit` before `escapeshellarg`; reject metacharacters / traversal.

**Proposed integration tier (future phase):** add a CI MySQL service + a
schema-bootstrap + fixtures, and a `tests/Integration/` group (separate phpunit
testsuite so the default offline run stays fast). Each test seeds rows, calls the
real DB facade / dispatcher with a faked session, and asserts on DB state + the
JSON response. This is the only way to cover auth/RBAC/crypto-rotation as the
operator actually hits them. Sizeable (CI infra + fixtures) — schedule as its own
slice; it does not block the pure-tier wins in (A).

### C. CLI tools (`spear/manager/cli/*.php`)

5 tools (`backup_run`/`restore`/`push_config`, `reencrypt_recipient_pii`,
`grant_super_admin`) are exercised today only via throwaway integration harnesses
during their phases. Their pure cores (e.g. `backup_*`, `recipient_reencrypt`) are
unit-tested; the argv-parsing + DB-mutation shells are not. Lower priority — they're
operator-run, not attacker-reachable.

## Recommended sequencing

1. **Now (pure, low-risk):** extract + test `getMailerDSN`, then `filterKeywords`,
   from `common_functions.php`. ✅ `security_headers` shipped this round.
2. **Next slice:** stand up the integration tier (CI MySQL + fixtures) and cover the
   Tier-1 security items: auth core, RBAC scoping SQL, dispatcher default-deny.
3. **Then:** crypto-rotation + CSRF lifecycle integration tests; CLI argv parsing.

Items 2–3 are larger (infra) and best done as dedicated phases; item 1 is
incremental and can land alongside other work.

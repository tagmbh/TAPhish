# PLAN — External self-hosted landing pages (look-alike subdomains via FTP)

**Status:** proposal / design. No code yet — this is the plan to review before building.

## 1. Goal

Today every cloned/library landing is served from the TAPhish host under
`/p/<slug>/` (→ `spear/sniperhost/cloned/<slug>/`). The operator wants the option
to **self-host the landing directly on a look-alike domain's own webspace** —
e.g. `https://owa.textilcolor.ch/`, `https://abacus.textilcolor.ch/` — pushed to
Hostpoint via FTP/FTPS, instead of (or in addition to) `/p/<slug>/`.

**Why it's worth it**
- **Real TLS for the look-alike.** A Hostpoint sub-domain gets its own
  Let's-Encrypt cert, so the victim's browser shows a valid cert for
  `owa.textilcolor.ch` — not the TAPhish host cert. This closes the exact TLS
  caveat already noted in `lookalike_deploy.php` ("the browser sees the host
  cert, not the look-alike").
- **Higher fidelity lure.** The bar URL is the look-alike domain end-to-end; no
  `/p/<slug>/` path tell and no cross-domain redirect.
- **Offload + isolation.** The capture page lives on separate webspace from the
  operator console.

## 2. What already exists (reuse, don't reinvent)

- **`lookalike_deploy.php`** (Phase 3.55): vanity-slug validation,
  `lookalike_hosted_url()`, and `lookalike_build_dns_records()` (A/CNAME/SPF/DKIM/
  DMARC advisory records, IDN punycode). The DNS half of "stand up a look-alike"
  is done.
- **`backup_push.php`** (Phase 3.50c): the **exact pattern** to copy for a remote
  push — pure `*_config_validate / _serialize / _deserialize / _mask /
  _from_request` helpers, secret **encrypted at rest** (Phase 3.38 envelope), and
  an **injectable transport** so the request-building is unit-tested without a
  network. It ships WebDAV + S3; we add **FTP/FTPS**.
- **`ClonedSite` / `site_bundle.php`**: produce the on-disk landing bundle
  (`spear/sniperhost/cloned/<slug>/` with rewritten assets + injected tracker)
  that we'd upload.
- **Wizard tracker builder**: the tracker's `content_js` already posts to an
  **absolute** `<webhook>/track` URL — so a landing hosted on another domain
  still reports back to the TAPhish host. Cross-origin is fine for `fetch`/XHR
  POST with no-cors, but see §6 (capture endpoint CORS).

## 3. Architecture

### 3.1 "External landing host" connection profiles
A new settings concept: **named FTP/FTPS targets** the operator can push landings
to. Each profile:

```
id, label, type(ftp|ftps), host, port, username, password(sealed),
remote_base_path, public_url_base   e.g. https://owa.textilcolor.ch/
```

- Stored in `tb_store` (or a small new table) as JSON; **password sealed at rest**
  via the Phase 3.38 envelope (mirror `taphish_push_merge_secret` so a blank
  password on save keeps the existing one; never echo the secret back).
- New pure helpers in a `landing_host.php` (mirroring `backup_push.php`):
  `landing_host_config_validate / _serialize / _deserialize / _mask /
  _from_request`. Unit-tested, no DB/network.

### 3.2 FTP transport (the new bit)
- An injectable transport `landing_host_ftp_push(array $cfg, string $localDir,
  string $remoteDir, callable $client = null)` that mirrors a local directory to
  the remote base path over **FTPS (explicit TLS preferred)**.
- Implementation options, in order of preference:
  1. **cURL** (`CURLOPT_UPLOAD` per file, `ftp://`/`ftps://`, `CURLOPT_USE_SSL =
     CURLUSESSL_ALL`) — already a dependency, streamable, testable via an
     injected client. Recursive dir walk in PHP.
  2. PHP `ftp_*` ext (if available) with `ftp_ssl_connect`.
- The request-building (paths, per-file targets, TLS opts) is pure + unit-tested;
  the actual socket call is the injected callable (same discipline as the S3
  SigV4 signer that's vector-tested offline).

### 3.3 Push pipeline
`build landing bundle (existing) → landing_host_ftp_push(profile, cloned/<slug>/,
remote_base) → record public_url = <public_url_base>` and use **that** as the
campaign's `landing_url`.

## 4. Wizard / Site-Cloner integration (the web experience)

Step 4 (Landing) gains a **landing-source choice**:

- ◉ **TAPhish-hosted** `/p/<slug>/` (today's default), or
- ◉ **External host** → pick a configured FTP profile → after clone, **push** the
  bundle there; `landing_url` becomes `https://owa.textilcolor.ch/`.

UI additions:
- A **"Manage landing hosts"** card (Settings → General, next to the backup-push
  card it visually rhymes with): add/edit/delete profiles, **masked** password, a
  **"Test connection"** button (connect + list base path, like backup-push's Test
  upload).
- In Step 4: a host dropdown, a **"Push to host"** action with progress + the
  resulting public URL as a clickable preview, and clear error surfacing.
- The launch SSRF guard (`taphish_landing_url_is_probeable`, F2) must be extended
  to **also** accept a landing_url whose host matches a configured external
  profile's `public_url_base` (otherwise the launch landing-probe would reject the
  external URL). Keep it an allow-list of known hosts, not "any URL".

## 5. DNS + cert (operator runbook, mostly existing)

For `owa.textilcolor.ch` on Hostpoint:
1. Add the sub-domain as webspace in Hostpoint (its own docroot) + enable
   Let's-Encrypt.
2. `lookalike_build_dns_records()` already emits the advisory A/SPF/DKIM/DMARC; we
   add a short "self-hosted" note (point the sub-domain's web at the Hostpoint
   webspace, not a CNAME to the TAPhish host).
3. Create an FTP sub-account for that webspace → enter it as a landing-host
   profile.

## 6. Cross-origin capture (must verify)

The injected tracker posts to `<taphish-host>/track` from the look-alike origin.
- Form-capture posts are simple `XHR/fetch` POSTs; for cross-origin we either send
  them **`mode:'no-cors'`** (fire-and-forget, no response needed — fine, the
  server records the hit) or add permissive CORS on `track.php`/`mod.php` for the
  capture endpoints only. **Decision needed** (§9). Lean: keep `no-cors` POSTs so
  no server CORS surface is opened.
- The open-pixel (`{{TRACKER}}` → `<img>`) is cross-origin-safe already.

## 7. Security

- FTP password **sealed at rest**; never returned to the page; masked in the UI.
- **FTPS (explicit TLS) required** by default; plain FTP only behind an explicit
  opt-in, with a warning (credentials + uploaded pages otherwise traverse in the
  clear).
- RBAC: managing landing hosts + pushing = `super-admin`/`operator` (default-deny,
  via the existing `authz.php` policy).
- The external host is **operator-controlled** look-alike webspace — but validate
  `host`/`remote_base_path` (no traversal) and constrain the launch landing-probe
  to configured hosts (§4).
- Don't upload the tracker's webhook secret anywhere client-visible (the tracker
  JS already only carries the public `<base>/track` URL).

## 8. Phasing

- **P1 — connection + manual push.** `landing_host.php` pure config helpers +
  FTPS transport + a Settings card (add/test/delete) + a "Push existing clone to
  host" action. Ship value without touching the wizard.
- **P2 — wizard integration.** Step-4 source toggle + push + `landing_url`
  wiring + extend the launch SSRF allow-list.
- **P3 — polish.** Multi-host management, per-engagement default host, push
  status/history, re-push on landing edit.
- **P4 — guidance.** "Self-hosted" DNS/cert runbook surfaced inline; optional
  one-click DNS-record copy (reuse `lookalike_build_dns_records`).

## 9. Open decisions (need operator/architect input)

1. **Cross-origin capture:** `no-cors` fire-and-forget POSTs (no server change,
   recommended) vs. opening CORS on the capture endpoints?
2. **Profile scope:** one global pool of landing hosts, or per-engagement?
3. **FTP vs SFTP:** Hostpoint sub-FTP accounts are FTPS-only (SFTP is main-account
   only) — so FTPS is the must-have; SFTP is a "nice later" for VPS targets.
4. **Re-push semantics:** overwrite remote on every push (simple) vs. diff/mirror?
   Start with overwrite of the slug dir; no remote prune by default.
5. **Cert automation:** rely on Hostpoint's per-subdomain Let's-Encrypt (manual
   toggle) — out of scope to automate from TAPhish.

## 10. Testing

- Pure unit tests for `landing_host_*` config helpers (validate/serialize/mask/
  from_request) and the FTP **request builder** (per-file remote paths, TLS opts)
  with an **injected transport** — same model as `BackupPushTest`/the SigV4
  vector test. No live FTP in CI.
- A manual/integration check against a throwaway FTPS target (a real push +
  fetch of the uploaded page), like the backup-push real-curl receiver test.
- Keep the existing 921 PHPUnit + node tests green.

---

**Recommendation:** build **P1** first (connection profiles + FTPS push of an
existing clone) — it's self-contained, reuses the backup-push pattern, and
immediately lets you stand up `owa.textilcolor.ch` as a real-cert landing. P2
(wizard) follows once the push path is proven.

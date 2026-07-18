# In-App Landing Deploy — Design Spec

**Date:** 2026-07-18
**Backlog item:** FEATURE-R2.4 (`docs/superpowers/specs/2026-07-16-ui-redesign-backlog.md`)
**Status:** ✅ ACTIVE (reinstated 2026-07-18). Journey: built as Phase 1 → deleted when a review
found the Phase 3.60/3.61 `landing_host` FTP feature and I merged toward it → **reinstated** after a
live diagnostic proved FTP is the wrong fit here. The connectivity test of the operator's two
configured FTP profiles returned **cURL 67 / FTP 530 (login denied)** on both, `last_push=null`
(never worked): on Hostpoint **FTP needs a separate account per host**, whereas the **account/SSH
reaches every `~/www/<host>/`**. Since the TAPhish app runs ON that same `azitufem` account, it writes
the landing **directly** into `~/www/<host>/` (this design) — no FTP, no keys, no per-host accounts.

**Two complementary features (kept clearly separate):**
- **This one — `landing_deploy` / "Push to Host":** same-account **local-FS copy** to a look-alike
  **host-root** `~/www/<host>/`, renders `{{POST_URL}}`, target+source allow-lists. The working path
  for the Textilcolor look-alike vhosts.
- **`landing_host` / Settings "External landing hosts":** remote **FTP/FTPS** push (sealed creds,
  slug-subdir, connectivity test, wizard auto-push) for hosts on **other** accounts/hosters. The
  `{{POST_URL}}` render was merged into its push path too (`landing_host_render_html`).

**Open follow-ups:** an **SFTP driver** (for external hosts that only offer SSH, using a provisioned
key) and library-source push in `landing_host`. The same-account case needs neither.

## Goal

Let the operator deploy a capture-landing to a look-alike host **directly from the TAPhish
web UI**, replacing today's manual `deploy_hostpoint.sh` (laptop → `scp` → `~/www/<host>/`),
and wire that deploy step into the QuickStart wizard so campaign setup can be automated
step-by-step.

## Locked decisions (from brainstorming)

1. **Scope:** Hostpoint hard-wired for the MVP — one hoster, no generic multi-hoster CRUD.
2. **Mechanism:** **Direct local filesystem copy.** Verified fact: the app
   (`~/www/deepaudit.ch`) and the look-alike hosts (`~/www/<host>/`) are the **same Hostpoint
   account** (`azitufem`) under the same `~/www/` parent, so the PHP process can write the
   landing files directly — no FTP/SFTP, no credentials, no outbound connection. (`ftp`,
   `ssh2`, `openssl`, `sodium` extensions are all present on the server for a future remote
   driver, but same-account deploy needs none of them.)
3. **Extensibility seam:** the write step sits behind a single swappable function so a future
   `sftp` driver for **external** hosters slots in without touching render/resolve/verify.
4. **Integration order:** deploy engine + standalone Hosted-Pages button **first** (Phase 1),
   then a QuickStart Step-4 hook (Phase 2). Remote SFTP driver is Phase 3 (out of scope now).

## Architecture

```
taphish_landing_deploy(source, host, driver='local', wwwBase)
  ├─ taphish_landing_deploy_resolve_target(host, wwwBase)  → validated abs docroot   [pure]
  ├─ taphish_landing_deploy_render(indexHtml, postUrl)     → {{POST_URL}} + beacon    [pure]
  ├─ taphish_landing_deploy_write_local(files, docroot)    → ⟵ THE SEAM (FS copy)     [IO]
  └─ taphish_landing_deploy_verify(host)                   → {http_code, ssl_ok}      [IO]
```

The only driver-specific step is `write`. A later `taphish_landing_deploy_write_sftp()` can
replace it (selected by the `driver` argument, default `'local'`) leaving every other function
unchanged. Procedural style, matching the rest of `spear/manager/`.

### Landing sources
- **Library variants:** `spear/sniperhost/library/<variant>/` (e.g. `owa-exchange-capture`,
  `myabacus-login-capture`, `m365-login-capture`, `fortigate-vpn-capture`). Each ships
  `index.html` + optional `learn.html` + `assets/`.
- **Cloned landings:** `spear/sniperhost/cloned/<slug>/`.
The engine takes a source directory, renders `index.html`, and copies `learn.html` + `assets/`.

### Render (faithful to `deploy_hostpoint.sh`)
- Replace `{{POST_URL}}` → `https://deepaudit.ch/track.php`.
- Drop the `{{TRACKER_URL_ATTR}}` open-pixel beacon line (unused on Hostpoint — rid/trackerId
  come from the URL). The inline `screen_res` beacon JS is unrelated and stays.

### Target resolution + allow-list (central safety control)
`taphish_landing_deploy_resolve_target(host, wwwBase)` returns `{ok, docroot?, error?}`:
- `host` must match `^[a-z0-9][a-z0-9.-]{0,62}$` (single safe segment — the leading-class rule
  makes a `..` segment or leading dot impossible; `/` is not in the class → no traversal).
- Reject protected names: `deepaudit.ch` (the app itself) and `config`.
- The target must be an **existing** directory under `wwwBase` (the look-alike vhosts are
  pre-provisioned with DNS + cert; creating new vhosts is out of scope). `realpath` containment
  under `realpath(wwwBase)` as defense-in-depth.
- `wwwBase` is injected so tests never touch real `~/www/`; production passes the real path.

## Components / files

| File | Change |
|------|--------|
| `spear/manager/landing_deploy.php` | **NEW** — the engine (resolve/render/write_local/verify/orchestrator + a `taphish_landing_deploy_list_targets(wwwBase)` and `..._list_sources()`). |
| `spear/manager/web_tracker_generator_list_manager.php` | Dispatcher actions: `landing_deploy`, `landing_deploy_targets`, `landing_deploy_verify`. |
| `spear/manager/authz.php` | Gate the 3 actions **operator-tier** (`['super-admin','operator']`). |
| `spear/HostedPages*.php` + a small JS | "Deploy landing" panel: pick source + target host → Deploy → show verify result. |
| `spear/QuickStart.php` / `quick_start.js` | **Phase 2** — Step-4 hook: deploy → live URL → auto-wire CTA into Step 5. |

## Data flow (Phase 1)
1. Hosted-Pages panel loads → `landing_deploy_targets` → `{sources[], targets[]}` (from the
   allow-list + `sniperhost/library|cloned`).
2. Operator picks source + host → `landing_deploy` → engine resolves/renders/writes/verifies.
3. Response: `{result, files_written[], verify:{http_code, ssl_ok}}` → panel shows outcome +
   the live URL.

## Error handling + safety
- **Allow-list rejection** → `{result:'failed', error}` (bad host, protected name, missing dir,
  traversal). No write attempted.
- **`.bak-YYYYMMDD`** of any existing `index.html` before overwrite (in-app deploy discipline;
  `.bak` is already 403-blocked at the web layer).
- **RBAC operator-tier** on all 3 actions; aggregate/`*` tier never gets deploy.
- **No plaintext secret** anywhere (local copy needs none).
- **Verify is mandatory** and surfaced to the operator (HTTPS code + cert result); a failed
  verify does not roll back (files are written) but is reported loudly.
- **Live-campaign note:** building/testing the engine touches only temp dirs and the repo. A
  real deploy to a live host is an explicit operator UI action; no capture/send-path code is
  modified by this feature.

## Testing (TDD)
- **Pure (RED-first):**
  - `render()` — `{{POST_URL}}` substitution + `{{TRACKER_URL_ATTR}}` line drop; leaves other
    markup (incl. `screen_res` beacon) intact.
  - `resolve_target()` — rejects traversal / leading-dot / `deepaudit.ch` / `config` / missing
    dir; accepts a valid existing host under an injected `wwwBase`.
  - `list_targets()` — enumerates `wwwBase` dirs minus protected, from a temp fixture.
- **Integration:** `write_local()` into a temp docroot → files present, `index.html` rendered,
  `learn.html`/`assets` copied, `.bak` created on re-deploy.
- **Structural guards:** the 3 dispatcher actions registered + authz-gated; Hosted-Pages UI
  wired to `landing_deploy*`.

## Phasing
- **Phase 1 (now):** engine (A–C) + Hosted-Pages standalone deploy button, fully tested.
- **Phase 2:** QuickStart Step-4 wizard hook (deploy → live URL → CTA auto-wire).
- **Phase 3 (future, out of scope):** `sftp` write driver + sealed connection config for
  **external** hosters (generic multi-hoster model).

## Out of scope (YAGNI)
Generic multi-hoster CRUD, credential storage/sealing, new-vhost provisioning (DNS/cert),
remote drivers — all deferred to Phase 3 or dropped until a real external-hoster need appears.

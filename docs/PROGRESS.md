---
layout: page
title: Progress + roadmap
permalink: /progress.html
---

# TAPhish — progress + roadmap

Living document. Updated when phases ship.

## Where we are

- Production fork live at <https://ptbe.autodiscover.li/spear/> (operator panel).
- **Test suite**: 593 tests / 1597 assertions, all green.
- **Last verified end-to-end**: Phase 3.45 (all 5 slices) — 2026-06-02. Playwright walked the QuickStart Wizard end-to-end (Steps 1–7 all render, stepper advances, DKIM gen produces real `v=DKIM1; k=rsa; p=…` records on Hostpoint, recipient preview surfaces partial-import errors, pre-flight evaluates 5 gates, CAS status transitions reject double-launch correctly, EngagementView reads + writes via the new dispatcher actions). One pre-existing `moment is not defined` warning in `common_scripts.js` — unrelated to 3.45.
- **CI/CD**: GitHub Actions → FTPS deploy to Hostpoint Shared.

## Phases completed

| Phase | What landed |
|---|---|
| 3.32 | Visual polish — operator-console aesthetic: design tokens, distinctive type stack, layered atmospheric backdrops, refined inputs/modals/badges |
| 3.33 | Dim mode for sidebar + Home dashboard. New metric strip + activity feed. Pure log-entry classifier with audit-log routing. |
| 3.33b | `t-alpha` brand identity (logo, color rebased on #0071BB sampled from the wordmark) |
| 3.33c | Sidebar `<ul>` background gap + drop dead `home_functions.js` + tighter cron class hygiene |
| 3.34 | Dim-panel rename + Bootstrap surface rules (.card/.form-control/.modal/.table/.dropdown/.alert). Settings pages opted in. |
| 3.35 | Audit-log coverage for campaign / recipient list / mail template lifecycle (9 new `logIt()` callsites + classifier rules for created/updated/deleted/copied) |
| 3.36 | Dim-panel on every remaining internal page (16 pages). Third-party widget themes for DataTables, Select2, Summernote, nav-tabs, list-group, custom-control. |
| 3.37 | Self-host the three brand fonts (Bricolage Grotesque + IBM Plex Sans + JetBrains Mono). Latin-subset variable woff2 under `spear/css/fonts/`. Drops Google Fonts CDN. |
| 3.38 | AES-256-GCM at-rest encryption for `tb_core_mailcamp_user_group.user_data` (recipient PII). `recipient_data_seal/unseal` wrappers. Transparent migration via passthrough; `getUserGroupList` no longer ships PII over AJAX. |
| 3.39 | Pretext + landing-page template library (12 starter pretexts across Authentication / Finance / HR / IT / Shipping). New `tb_core_pretext_library` + idempotent seed. Gallery page + clone-to-my-templates action. |
| 3.40 | URL-scanner detection on `qt.php` and `tmail.php`. Pure `taphish_classify_visitor()` covers ~30 known vendors (SafeLinks, Proofpoint, Mimecast, Cisco, etc.) by UA + datacenter PTR + sub-5s timing. Scanner hits skip the tracker INSERT and surface as `SCAN/warn` in the activity feed. |
| 3.41 | Pre-engagement sender toolkit. Homoglyph + typo + TLD-swap domain generator. SPF / DMARC posture analyzer with operator-facing verdict (hardened / partially-hardened / monitoring / spf-only-strict / wide-open / unknown). |
| 3.42 | First-capture webhook alerting (Slack / Teams / Discord compatible JSON shape). `code_2fa` column on `tb_data_webform_submit`. Webhook URL stored encrypted at-rest in `tb_store`. New SettingsGeneral card to configure. |
| 3.39+ hotfix | jQuery / waves.js / sidebarmenu.js path corrections in PretextLibrary + SenderToolkit |
| 3.34+ hotfix | `createSession()` was rotating `_csrf` on every page refresh — every authenticated AJAX 403'd. Preserve token across refresh, mint only on actual login. (Bug existed since CSRF gate was introduced; surfaced during Phase 3.39 QA.) |
| 3.34+ hotfix 2 | `.alert-success` / `.alert-info` / `.alert-primary` / `.alert-secondary` were light-text on Bootstrap-default pale-tinted bg on dim panels (most visible: SiteCloner result box). Added semantic-color tinted bg + matching fg, mirroring the alert-warning / alert-danger pattern. |
| 3.43a | Quick-Start Wizard — Step 1: engagement metadata. New `tb_core_engagement` (slug + window + scope_allowlist + notes + status). `spear/QuickStart.php` page with form + recent-engagements list + "use in campaign" links. Boot-time idempotent schema + `save_engagement` / `list_engagements` dispatcher + log_classifier `ENGM` / `CLON` rules. 22 new engagement helper tests. |
| 3.43b | Quick-Start Wizard — Step 2: OSINT pre-check fan-out. New `mx_classify.php` (24 known providers across cloud-mailbox / security-gateway / shared-host buckets + pretext-category recommendations). New `web_fingerprint.php` (title + generator + robots.txt + .well-known/security.txt). New dispatcher actions `mx_classify_domain` / `web_fingerprint`. QuickStart page grows a six-card OSINT panel that fans out SPF/DMARC + MX + homoglyph + crt.sh + Hunter + web-fingerprint in parallel. 26 new helper tests. |
| 3.43c | Quick-Start Wizard — Step 3: pretext picker filtered by detected tech stack. New `taphish_pretext_rank_for_categories` + `taphish_pretext_list_flat` pure helpers. New dispatcher action `list_pretexts_ranked`. QuickStart auto-renders top-8 pretexts ranked by the MX-classifier's `pretext_categories` once the MX lane resolves; one-click clone deep-links to the editable copy. 3 new ranking tests. |
| 3.43h | **Toolset Checker** standalone diagnostic page (`/spear/ToolsetChecker`, sidebar entry under Toolkit). Pure `toolset_checks.php` (PHP version + extensions + writable dirs + SPF/DMARC/MX DNS presence + webhook reachability + /status liveness). Verdict badge: ready / caution / blocked. Each probe is injectable so the unit suite stays offline; 15 new tests. |
| 3.44 | **Visual refresh**. Glassmorphism on every card (`backdrop-filter: blur(14px) saturate(140%)`). Atmospheric radial-gradient vignettes anchored to brand color (fixed, behind content, no scroll cost). Page title + card title each grow a gradient accent rule (animates wider on card hover). Buttons get a restrained gradient + clearer press feedback + glow on focus. Chip-style pill badges with per-kind tinting on the Home activity feed (AUTH/CAMP/RECP/TMPL/SEND/SCAN/CAPT/ENGM/CLON/SYS). Animated wizard stepper on QuickStart (active / done / pending with checkmark). Loading skeletons (shimmer pulse) replace the `…loading…` text in OSINT + pretext lanes. Table hover, sidebar gradient, modal frosted-glass surface, focus ring on inputs. Honours `prefers-reduced-motion`. |
| 3.45a | **Scanner traffic isolation**. New `is_scanner` + `scanner_reason` columns on `tb_data_mailcamp_live`, `tb_data_quick_tracker_live`, `tb_data_webform_submit` (idempotent boot-time migration). `tmail.php` + `qt.php` flip from "skip insert on scanner" to "insert with `is_scanner=1`" so dashboards can audit per-vendor breakdown without polluting metrics. New `taphish_should_filter_scanner_in_kpis()` helper. `customer_report_compute_kpis` excludes scanner opens from the headline open-rate and surfaces a separate `scanner_hit_count`. `MailCmpDashboard` grows a "Hide scanner hits" checkbox that toggles a CSS class on `<tr data-scanner="1">` rows. 7 new tests. |
| 3.45b | **Engagement ↔ Campaign linkage + EngagementView**. New nullable `engagement_id` column + index on `tb_core_mailcamp_list` (idempotent boot-time migration). `saveCampaignList` reads optional `engagement_id` from the payload — legacy campaigns stay NULL. New `taphish_engagement_transition_status` uses compare-and-swap (`UPDATE ... WHERE id = ? AND status = ?`) so a double-click can't double-transition. New `taphish_engagement_get_by_id` + `taphish_engagement_campaigns` helpers. New `/spear/EngagementView` page with engagement picker, header card, linked-campaigns table, and status transition buttons (draft/live/completed/cancelled). New sidebar entry under Workspace. 6 new tests. |
| 3.45c | **Quick-Start Step 4 (Sender / DKIM) + Step 5 (Recipients)**. New `spear/manager/dkim_helper.php` — pure RSA-2048 key-pair generator with injectable openssl seam + selector validator + SPF/DMARC suggested-record helpers. New `spear/manager/recipient_import.php` — pure CSV parser (UTF-8 BOM strip, CRLF/CR/LF, partial-import) + per-domain breakdown + scope-allowlist violation finder. `uploadUserCVS` no longer `die()`s on the first bad row — collects parse errors + drops out-of-scope recipients + returns `{result: 'partial', imported, skipped[]}`. `mail_user_group.js` upgraded to render the partial-import toast. New dispatcher actions: `wizard_generate_dkim`, `wizard_recipient_preview`. QuickStart grows Step 4 (DKIM render + DNS record blocks) + Step 5 (CSV preview with per-domain breakdown). 29 new tests. |
| 3.45d | **Quick-Start Step 6 (Landing) + Step 7 (Pre-flight + Launch)**. New `spear/manager/preflight_checks.php` — five pure gate evaluators (scope coverage, recipient count, DMARC-vs-sender, sender reachability, webhook reachability) + `run_all` aggregator. New dispatcher actions: `wizard_preflight`, `wizard_list_landing_options`, `wizard_launch_campaign` (CAS-protected: rolls engagement back to `draft` if the campaign INSERT fails). QuickStart Step 6 picker exposes Site Cloner, AI gen, and library shortcut deep-links. Step 7 renders the gate table; Launch button stays disabled until every gate is green. On success, the wizard redirects to `EngagementView?engagement_id=N`. 16 new tests. |
| 3.45e | **Captures + 2FA visibility + repeat webhook**. New `is_2fa_capture` + `repeat_webhook_sent` columns on `tb_data_webform_submit` (idempotent boot-time migration). New `taphish_should_send_repeat_capture_webhook` guard + `taphish_repeat_capture_webhook_payload` builder (`is_repeat: true` flag, `:repeat:` icon swap). New `taphish_capture_summary_for_campaign` aggregator. `track.php` now flags 2FA captures and fires the repeat-capture webhook on a second 2FA-bearing submit; the `repeat_webhook_sent` flag is stamped so it only fires once. New dispatcher `get_capture_summary_for_campaign`. `MailCmpDashboard` grows a "Captures · 2FA" badge alongside the recipient table. 6 new tests. |
| SiteCloner UX | After-clone box now shows publicly-accessible landing-page URL (built from request scheme/host honoring `X-Forwarded-Proto`) with Copy + Open buttons, plus a three-step "use this clone in a campaign" hint deep-linking to MailCampaign / WebTracker. Existing-clones table grows per-row open / copy URL links. |
| Branding | Footer was `t-alpha GmbH` (lowercase) — official brand is `T-Alpha GmbH`. Fixed in `brand.php` + tests. New `brand_copyright()` shape links to `www.t-alpha.ch` + appends product name + version. New SVG logos (`logo.svg` / `logo-text.svg` / `logo-icon.svg`) replace the old PNGs — crisp at any zoom, lead with `TAPhish` wordmark + small "BY T-ALPHA GMBH" caption. Old PNGs removed. |
| 3.46-pre | **Home launchpad + Shodan OSINT + wizard auto-fills**. Home gets a "Jump back in" tile grid (QuickStart, Engagements, Sender Toolkit, Toolset Checker, Pretext Library, Site Cloner, Campaigns, Settings) filling the previously-empty space below the activity feed. New `spear/manager/osint_shodan.php` mirrors the `osint_hunter` pattern (pure parser + injectable resolver seam + curl wrapper); new dispatcher action `osint_shodan_host`; sixth lane on QuickStart Step 2 (resolved IP + org/country + open ports + CVE refs + last-seen). Operator's Shodan API key lives in `localStorage` only, sent inline per request. After Step 1 saves, the wizard cascades the first scope domain into the OSINT target field, derives a DKIM selector from the slug, and auto-runs the OSINT pre-check. 16 new tests. |
| 3.47 | **Engagement reports + PDF export**. New `spear/manager/engagement_report.php` with five pure reducers (recipient counts by domain, capture timeline bucketed by UTC date, scanner-hit breakdown by vendor, 2FA summary with distinct-user dedup, sender posture summary) + a mysqli facade `engagement_report_aggregate` that joins everything for an engagement_id without ever returning individual emails (counts-by-domain only — PDF ships without PII). PDF rendering via the existing TCPDF bundle, no new dependency. New dedicated streaming endpoint `spear/EngagementReportExport.php` (not a dispatcher action — JSON-only dispatcher stays clean). EngagementView grows an "Export PDF" button next to the refresh control; click streams a `taphish-report-<slug>-<YYYYmmdd-HHMM>.pdf`. Generation emits an audit-log line so the operator has a record of who exported what when. 17 new tests covering all five reducers (incl. malformed-address rejection, scanner-exclusion default, 2FA distinct-user count, vendor blank → "unclassified"). |
| 3.46 | **Landing-page clone library**. New `spear/sniperhost/library/` ships hand-curated structural templates with placeholder branding (operators drop in the target's real logo/copy per engagement). Three templates covering the three common credential-collection patterns: `m365-login` (multi-step email → password → 2FA with `code_2fa` capture), `vpn-portal` (single-page user / password / OTP, modeled on generic SSL-VPN landings), `sso-redirect` (1.2 s spinner interstitial → form, generic SAML/IdP pattern). Each template uses `{{POST_URL}}` + `{{TRACKER_URL}}` placeholders that the clone action substitutes at copy-time. New `spear/manager/landing_library.php` pure helpers (list / template-files / substitute / clone-to-path; injectable roots so tests stay offline). New dispatcher actions `library_list` + `library_clone_to_my_sites`. New `LandingLibrary.php` gallery page with per-template card (pattern badge, captured-fields list, "customize before launch" callout) + "Clone to my sites" modal with destination slug suggestion + tracker/POST URL overrides. QuickStart Step 6 "Library shortcuts" card now reads from the library helper (no more "planned" labels) + deep-links to the gallery. New sidebar entry under Toolkit; Home launchpad gets a Landing Library tile. 16 new tests. |
| 3.52 | **BeEF integration (read-mostly surface)**. BYO BeEF (operator runs their own server, typically on a separate ~€5/mo VPS — Hostpoint Shared can't host the Ruby daemon). New `spear/manager/beef_integration.php` (hook-snippet builder, REST auth, hook list summarizer, scope validator, scope tagger, scope collector — all pure, injectable HTTP seam). Credentials encrypted at rest via the Phase 3.38 envelope (BeEF base URL + username + password as JSON in `tb_store`). New `tb_data_clone_meta` table (idempotent boot-time migration) tracks per-clone `beef_hook_enabled`. SettingsGeneral grows a BeEF integration block (URL + username + password, Save + Test buttons, anti-malware warning surfaced explicitly — SmartScreen/Sophos/Symantec/EDR signature-detect the hook). SiteCloner grows a per-clone "Inject BeEF hook" checkbox (default off); the cloner splices the snippet before `</body>` via the new pure `site_cloner_inject_hook` helper. Home gets a "BeEF hooked browsers" widget that polls every 30 s while the tab is visible, surfaces in-scope vs out-of-scope chips (matched against active engagements' `scope_allowlist`), degrades gracefully on not_configured / unreachable / auth_failed. New BEEF audit-log kind (info / warn / error). Module execution stays in BeEF's own UI — TAPhish never POSTs to `/api/modules/...`. 47 new tests. |

## What you can already do today

- Phishing campaigns end-to-end: recipient lists (PII encrypted) → mail templates (with merge tokens) → mail senders (SMTP password encrypted) → schedule + send via cron worker
- Pre-engagement intel: homoglyph candidates + SPF/DMARC posture (SenderToolkit page); subdomain enum via `osint_crt_sh`; email-format guessing via `osint_hunter`
- Pretext library with 12 curated starters; one-click clone to operator's own template list
- Scanner hits filtered from engagement metrics; webhook fires on first capture per recipient
- Operator audit log surfaces every campaign / recipient / template / login / 2FA event in the Home dashboard's activity feed
- 2FA with recovery codes; per-IP login throttle; secrets-at-rest envelope for SMTP/IMAP passwords AND recipient PII

## Known follow-ups (not blockers)

- `actions/checkout@v4` Node-20 deprecation (cosmetic CI warning; fix by bumping to v5 when convenient)
- Spec drift: the Phase 3.33 design doc describes `check_process` response shape that was adjusted at implementation time — JS is canonical
- `.is-error` Trend-Klasse in CSS definiert, JS schreibt sie nicht
- Open / Click rate tiles render `—` because the metrics blob isn't yet wired server-side (the JS already handles real numbers as soon as `home_manager.getHomeGraphsData` exposes them)
- Phase 3.38 integration tests (full encrypt round-trip with on-disk key) — pure-helper passthrough tier ships; integration tier deferred

## Roadmap — next phases

### Phase 3.48 — Multi-operator + RBAC *(highest impact, build next)*

Right now there is one `admin` account. For a tooling shop running multiple concurrent engagements per operator, we want:
- Role tiers: super-admin / operator / read-only
- Engagement-scoped permissions (operator A can't see engagement B's recipient list) — the FK from 3.45b is the scope unit
- Per-operator API tokens for the dispatcher endpoints (so a script-driven send doesn't need a session)

### Phase 3.49 — Recipient PII re-encrypt sweep

Migration command (CLI or `spear/manager/*.php` action) that walks every existing `tb_core_mailcamp_user_group` row, decrypts (passthrough handles plaintext today), re-encrypts via Phase 3.38 envelope. Forces the at-rest invariant rather than relying on the lazy "next-write-encrypts" pattern.

### Phase 3.50 — Backup + snapshot mechanic

DB dump + state-dir snapshot on a schedule. Encrypts the dump with the existing at-rest key. Stored in `spear/uploads/backups/` with rotation; optionally pushes to operator-configured S3 / WebDAV.

### Phase 3.51 — Operator-side audit log viewer page

Phase 3.35 captures everything; today only the last 10 entries surface on the Home dashboard. A dedicated `SettingsAuditLog.php` page with date/severity/kind filters, search, and CSV export.

---

## Quick reference

- Live operator panel: <https://ptbe.autodiscover.li/spear/>
- Public docs site: <https://taphish.t-alpha.ch/> (GitHub Pages)
- Repo: <https://github.com/tagmbh/TAPhish>
- Deploy: Actions → `Deploy to operator host (FTPS)` → Run workflow (untick Dry-run for live push)
- Tests: `vendor/bin/phpunit` (593/1597)
- Lint: `php -l <file>`

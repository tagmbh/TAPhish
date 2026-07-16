# TAPhish UI / IA redesign — plan (iterative, demo-verified)

**Date:** 2026-07-16 · **Branch:** feature/ui-ia-redesign · **Bug source of truth:**
[ui-redesign-backlog.md](2026-07-16-ui-redesign-backlog.md) · **Analysis:** 7-agent workflow.

## Locked decisions (operator, 2026-07-16)

1. **Trackers: fully unified** — one section (List/New/Reports) with a type attribute (open-pixel vs web/form).
2. **Campaigns under Engagements** — Engagements is the all-campaigns hub; every campaign gets an
   engagement; an explicit **Unscoped/Legacy** bucket holds pre-existing NULL rows.
3. **Dashboards + Reports under Engagements** — scoped tabs on an engagement + a global Reports generator.
4. **Structural refactors first (foundation)** — nav bootstrap + one shared DataTables backend before the
   feature consolidations.

## Target IA (≈6 groups, down from ~13)

- **Home**
- **Engagements** (hub) → tabs: Übersicht · Kampagnen · Dashboard (live) · Report (Export) · Members
- **Trackers** → Liste (type filter) · Neuer Tracker (per-tracker report on the detail drawer)
- **Reports** (global generator, scope: Engagement | Campaign | Tracker)
- **Assets / Library** → Templates · Sender · Konfiguration · Pretexts · Landing Pages
- **Settings** → Allgemein · Benutzer · API Tokens · (Toolset Checker)

## Roadmap (each point verified by a demo/TDD before locking)

- **P0 · Foundation (this step)** — shared platform layer, TDD:
  - `dt_server_response()` helper enforcing the DataTables contract (recordsTotal = unfiltered COUNT;
    **recordsFiltered = real filtered COUNT** [the Next bug]; data = LIMIT/OFFSET slice; search+sort in SQL).
    Route the 4 broken managers (quick_tracker:208, tracker_report:205, web_mail_campaign:436,
    mail_campaign:455) through it. Correct reference: settings_manager:867.
  - Nav bootstrap (sidebarmenu.js + custom.min.js + common_scripts.js) into the shared `z_footer` partial
    → fixes the "click Home first" dead-click on the 5 pages missing the include.
  - One `camp_status` label helper (replaces the 3 divergent decoders; covers status 5/6).
- **P1 · Engagements = hub** (demo APPROVED — [mockup](../../..)):
  - Add an **engagement selector** to the campaign builder (mail_campaign.js → send engagement_id; server
    already accepts it at mail_campaign_manager.php:85-110). Add `engagement_id` columns to the quick/web
    tracker tables + backfill. `getCampaignList` UNIONs mail+web+quick, membership-scoped, server-side paged.
  - List-level **Open + Delete** actions in renderPicker; drafts get both "Continue setup" AND Open/Delete
    (fixes undeletable abandoned drafts). Filter `list_engagements` by membership.
  - Explicit **Unscoped/Legacy** bucket for `engagement_id IS NULL`; per-row Zuordnen/Löschen.
- **P2 · Trackers unified** — one list (Type: open-pixel/web-form), per-row Report drawer; retire the stray
  "Web Tracker Report" leaf + the Select-Tracker modal; unify the 5 naming variants.
- **P3 · Reports/Analytics consolidated** — one generator on the tested `engagement_analytics` core (give
  it a dispatcher action): default **ALL**, one column-picker, one `download_report`; **one clean column
  per logical field** (`decode_fields`, distinct/latest value — fixes the 24×-concatenation + duplicate
  columns), **dedup to one row per victim** with per-hit drill-down. Plaintext credentials operator-tier.
- **P4 · One live dashboard** — fold Web-MailCamp into the Email Campaign Dashboard as a "show web tracker"
  toggle; retire the duplicate manager + nav leaf; place under Engagements.

## Also fixed along the way (from analysis)

"#" column namespace typo (web_tracker_list.js:200), malformed `aaSorting` default sort + wrong
`data-order` column, engagement→dashboard deep-link `campaign_id`→`mcamp`, status "undefined" for
tz-deferred, import-HTML modal fatal + **SSRF** (allowlist the fetch), dead pause-tracker handler.

## Process

Plan a point → build a demo/TDD to verify it → if it works, lock it here → next point. Implementation
lands in parallel with the live campaign so each step is tested against real data. Never a yolo plan.

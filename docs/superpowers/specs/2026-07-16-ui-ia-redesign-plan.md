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

- **P0 · Foundation** — ✅ **DONE, deployed + live-verified 2026-07-16** (commits b524beb, 38c9431,
  73a6910, f2754d7; suite 989 green):
  - ✅ `datatables_helper.php` (envelope/search/order/limit/**slice**) — 4 managers rewired so
    **recordsFiltered = real filtered COUNT** (the Next bug). Implementation note: search spans
    JSON/computed columns SQL LIKE can't reach, so the fix filters the full set in PHP and slices the
    page via `taphish_dt_slice` (rather than moving search to SQL). Live proof: 27-recipient campaign
    pages to page 2, disjoint rows, search count correct.
  - ✅ Nav bootstrap → shared **`z_navboot.php`** partial (NOT z_footer — it renders before jQuery loads).
    Locked by `NavBootstrapTest`. Live: sidebarmenu.js on all 5 pages.
  - ✅ One `camp_status` decoder (`js/camp_status.js`); both JS decoders delegate. Locked by
    `CampStatusDecoderTest`. Live: label(5)="Deferred", no "undefined". (code 6 never set; 3 overloaded → backlog.)
- **P1 · Engagements = hub** ← **NEXT** (demo APPROVED — [mockup](../../..)):
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

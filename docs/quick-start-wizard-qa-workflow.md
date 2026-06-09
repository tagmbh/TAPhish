# Quick-Start Wizard — End-to-End QA Workflow (browser-driven)

Acts like a real operator via Playwright against a running instance, capturing
browser console, network responses, PHP error log, and DB state at each step.

**Environment under test:** local instance, identical code to production
(`http://127.0.0.1:8096`, MySQL on socket, admin / sniperphish).

**Log sources checked at every step:**
- Browser console (`browser_console_messages`, level=error/warning)
- Network responses for each `…manager` AJAX call (`browser_network_requests`)
- PHP error log (`/tmp/tp4_php_errors.log`)
- DB rows (`tb_core_engagement`, `…_user_group`, `…_web_tracker_list`,
  `…_sender_list`, `…_template_list`, `…_mailcamp_list`), `tb_log`

## Steps

| # | Action (as user) | Expected | Bug focus |
|---|---|---|---|
| 0 | Load `/spear/`, log in admin/sniperphish | dashboard | session/CSRF |
| 1 | Open `/spear/QuickStart`; check console clean | no JS errors; `engId` defined | C1-regression (engId) |
| 2 | Step 1: verify start/end pre-filled; set end<start → expect error; type domains → chips render | datetime picker works; validation; chips | A/B |
| 3 | Save engagement | success, advances to Step 2 | save_engagement |
| 4 | Step 2 OSINT: run on a real domain (e.g. t-alpha.de) | MX shows records (getmxrr); DMARC; look-alike shows Hostpoint register links; Hunter shows key-state | C1, C2, C3 |
| 5 | Set Hunter key in localStorage, re-run | Hunter lane forwards key (no "not configured") | C3 |
| 6 | Step 3: paste a *weird* CSV (Email-first, semicolon, header) → preview + commit | auto-detected, committed count correct | D |
| 7 | Step 4: create tracker (auto), clone a landing | tracker_id; landing_url | tracker/clone |
| 8 | Step 5: pick pretext, Save & wire | body has real landing URL, no REPLACE marker | E2 |
| 9 | Step 6: create sender inline | sender saved | sender width |
| 10 | Step 7: Run pre-flight | scope=pass, mail_body=pass, all green | E1, E2 |
| 11 | Launch | campaign created, engagement live | launch wiring |
| 12 | Final log sweep | no PHP errors, no console errors, DB consistent | — |

Findings + troubleshooting recorded inline in the session.

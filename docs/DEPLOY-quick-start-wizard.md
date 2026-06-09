# DEPLOY — Quick-Start wizard review fixes (PR #146)

Companion to `docs/REVIEW-quick-start-wizard.md`. The deploy is FTPS-only to the
masked operator host, so this is the operator's checklist. **No DB migration is
required** — this branch adds no schema changes (the `sender_acc_pwd` widening
shipped earlier in #143).

## 1. Files to upload (runtime)

Upload these seven; tests/ and docs/ are not served and can be skipped.

```
spear/js/wizard_pure.js                                  ← NEW — must ship
spear/QuickStart.php
spear/js/quick_start.js
spear/manager/osint_hunter.php
spear/manager/preflight_checks.php
spear/manager/userlist_campaignlist_mailtemplate_manager.php
spear/sniperhost/ClonedSite.php
```

**Critical ordering risk:** `spear/js/wizard_pure.js` is a NEW dependency that
`QuickStart.php` loads *before* `quick_start.js`. If `QuickStart.php` ships but
`wizard_pure.js` does not, `TAPhishWizardPure` is undefined and the wizard's
`wireBody` / `slugifyName` throw a ReferenceError at runtime. **Upload
`wizard_pure.js` and `QuickStart.php` together (or `wizard_pure.js` first).**

Because the full-tree FTPS deploy doesn't prune, no stale-file cleanup is needed,
but confirm the new file actually landed (it's easy to miss a newly-added path in
an mtime-based sync — see review §4.8).

## 2. Preconditions already on the host (no action, just verify)

- **`.htaccess` `/p/<slug>/` rewrite** — F9 emits `/p/<slug>/` landing URLs. The
  rewrite already lives in `main`'s `.htaccess` (unchanged by this branch), so it
  should already be live. If clones 404 at `/p/...`, the rewrite/`mod_rewrite`
  isn't active on the host.

## 3. Post-deploy smoke (5 minutes, no campaign sent)

1. Open **Quick Start** with the browser console open → **no
   `TAPhishWizardPure is not defined`** and no other JS error on load. (Confirms
   the new file shipped and loads first.)
2. **Step 1** — the window fields read "your local time", prefill to now/+14d;
   saving an engagement succeeds. Spot-check the stored window is the UTC
   equivalent of what you typed.
3. **Step 4** — clone a throwaway target; the success URL is
   `https://<host>/p/<slug>/` (not `/spear/sniperhost/cloned/...`) and the link
   opens the cloned page.
4. **Step 6** — the **Send test** button is present; with a real sender selected,
   sending a test mail to your own inbox arrives.
5. **Step 2** — with no Hunter key configured, the Hunter lane says "API key not
   configured" (not a raw error); with a bad key, "rejected"/"malformed".
6. **Step 7** — pre-flight goes green for a fully-configured engagement; launch
   creates a campaign. (A landing URL pointing off-host is now refused by the
   probe — expected.)

## 4. Rollback

All changes are additive or behavioural within these seven files; reverting is a
re-upload of the previous versions of the six modified files and deleting
`spear/js/wizard_pure.js`. No data migration to undo.

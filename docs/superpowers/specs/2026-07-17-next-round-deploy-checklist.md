# Next-round capture fixes — deploy checklist (do BEFORE the next campaign wave)

Prepared + tested 2026-07-17 (branch `feature/ui-ia-redesign`), **not deployed** because they touch the
recipient-facing capture path. `#4` (mail-reply) is already live (read-only, safe). Guarded by
`NextRoundCaptureFixesTest`.

## ✅ Already live
- **#4 mail-reply "Loading error!"** — `spear/manager/common_functions.php` `getMailReplied` fails soft
  (guarded `imap_open`, no `error` key). Deployed + verified (`get_mail_replied` → `{reply_count_unique,
  msg_info}`, panel shows "0 replied").

## ⏳ Deploy at the next wave (recipient-facing capture)

### #2 visit-beacon + #3 screen-res — the m365 landing
Files: `spear/sniperhost/library/m365-login-capture/index.html` (adds a page-0 visit POST on load +
`screen_res` on every capture POST). The sink (`track.php`) already stores both — no backend change.
**Deploy:** re-run the landing deploy so the look-alike host(s) get the new `index.html`:
```
spear/sniperhost/library/m365-login-capture/deploy_hostpoint.sh   # renders + scp's index.html+learn.html
```
(One nuance: the script still `sed`-drops the unused `{{TRACKER_URL_ATTR}}` `<script>` — that's fine now,
the page-0 beacon is inline in `index.html`, so nothing else is needed.)
**Verify after the first hits:** `tb_data_webpage_visit` gains page-0 rows (pure-clicks measurable), and
`tb_data_webform_submit.screen_res` is a real `WxH` instead of `Failed`.

### #1 open-pixel guarantee — the send cron
File: `spear/core/mail_campaign_cron.php` (appends `{{TRACKER}}` when `timage_type==1` and the body lacks
it). **Deploy** with the usual discipline (`.bak` + base-check + `php -l`).
**Reality check:** the pixel code was already intact + un-drifted on the server — 0% opens is mostly
**client-side image-blocking** (Gmail proxy / Outlook block remote images), which no server fix changes.
This guarantee only ensures a *tracking* template (timage_type==1) always ships the pixel even if saved
outside the wizard. Don't expect high open rates from pixels regardless.

## Optional upgrades (same area)
- **City-level geo**: swap `spear/config/geo/dbip-country-lite.mmdb` → `dbip-city-lite.mmdb` (~150MB).
  `taphish_geo_from_mmdb_record` already projects city/coords/timezone. See `spear/config/geo/README.md`.

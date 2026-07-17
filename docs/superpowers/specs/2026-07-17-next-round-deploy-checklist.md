# Next-round capture fixes — deploy checklist (do BEFORE the next campaign wave)

Prepared + tested 2026-07-17 (branch `feature/ui-ia-redesign`), **not deployed** because they touch the
recipient-facing capture path. `#4` (mail-reply) is already live (read-only, safe). Guarded by
`NextRoundCaptureFixesTest`.

## ★★ DEPLOYED 2026-07-17 ~17:20 UTC (operator go given: "all 4 variants together")
- **Cron pixel guarantee** → LIVE (server == HEAD; fresh-exec per campaign, so it takes effect at the next
  send — no daemon restart needed).
- **Landings** → deployed via `deploy_campaign_landings.sh` to **owa, abacus, sharepoint, feed**. Post-deploy
  confirmed: page-0 beacon + `screen_res`×4 live on all 4; **pretexts intact** (`Outlook`, `Abacus ERP`,
  `Anmelden bei Ihrem Konto`×2); http=200, cert OK. The beacon was added to ALL branded variants
  (owa-exchange-capture, myabacus-login-capture, fortigate-vpn-capture) — each diffed against its LIVE host
  as PURELY the beacon (0 pretext drift) before deploy.
- **Self-test:** beacon fires on load with a real `screen_res` (2560x1440) on both an m365 host (sharepoint)
  and a branded host (owa) — verified by intercepting the track.php POST. track.php stores `page==0` →
  `tb_data_webpage_visit` (+screen_res) and validates the tracker (unknown test id → 0 rows, no pollution).
- **⚠ remote.texti1color.ch (FortiGate / Quishing 5kogr0) NOT deployed** — it is operator/user-managed and
  explicitly excluded from `deploy_campaign_landings.sh`. The `fortigate-vpn-capture` repo variant IS ready
  (beacon added); its rendered form diffs against live remote as a clean beacon-only 11-line update. **To
  apply (operator decision, their host):**
  `scp -i ~/.ssh/taphish_hostpoint_ed25519 <rendered fortigate index.html> azitufem@sl2084.web.hostpoint.ch:~/www/remote.texti1color.ch/index.html` (back up first).
- **Real DB verification** happens at the 20-07 wave: `tb_data_webpage_visit` gains page-0 rows;
  `tb_data_webform_submit.screen_res` is a real `WxH`, not `Failed`.

## ~~READINESS RE-VERIFIED 2026-07-17 15:42 UTC~~ (superseded by the DEPLOYED section above)
- **Timing:** scheduler alive; **nothing in-flight** (0 campaigns at st=2). **12 campaigns armed (st=1)**,
  next wave **20-07-2026 07:15 UTC ≈ 09:15 Europe/Zurich (CEST)** … through 24-07. 4 fired 16-07 (st=4).
  → Deploy in the quiet window BEFORE the 20-07 wave (e.g. 19-07, or 20-07 before 07:15 UTC). Because the
  campaign is ARMED and these touch the send/capture path, **this deploy needs explicit operator go** — do
  NOT deploy autonomously.
- **Prep intact + tested:** `NextRoundCaptureFixesTest` 4/4 green (page-0 beacon, `screen_res`×4,
  getMailReplied soft-fail, cron pixel-guarantee).
- **Cron deploy is a CLEAN forward-add:** server `spear/core/mail_campaign_cron.php` == clean base
  `51a3306` (sha `866ef63…`); target == HEAD (sha `7115902…`). The diff is exactly the 10-line
  `timage_type===1 && strpos(...'{{TRACKER}}')===false → $_body.='{{TRACKER}}'` block. No server-only edits
  to preserve. (An earlier full-history search *looked* like drift — that was a shell-loop artifact; the
  direct `diff` confirms a clean base.)
- **m365 landing prep present:** `spear/sniperhost/library/m365-login-capture/index.html` has the page-0
  visit beacon + `screen_res` on all 4 posts; `deploy_hostpoint.sh` ready.

### Wave-time deploy commands (run with operator go, in the pre-wave quiet window)
```
# 1) send cron (clean forward-add of the pixel guarantee)
SSHK=~/.ssh/taphish_hostpoint_ed25519; HOST=azitufem@sl2084.web.hostpoint.ch; ROOT=/home/azitufem/www/deepaudit.ch
ssh -i $SSHK $HOST "cd $ROOT && sha256sum spear/core/mail_campaign_cron.php"   # expect 866ef63… (base 51a3306)
ssh -i $SSHK $HOST "cd $ROOT && cp -p spear/core/mail_campaign_cron.php spear/core/mail_campaign_cron.php.bak-\$(date +%Y%m%d)"
scp -i $SSHK -q spear/core/mail_campaign_cron.php $HOST:$ROOT/spear/core/mail_campaign_cron.php
ssh -i $SSHK $HOST "cd $ROOT && /usr/local/php83/bin/php -l spear/core/mail_campaign_cron.php && sha256sum spear/core/mail_campaign_cron.php"  # expect 7115902…
# 2) m365 landing (page-0 beacon + screen_res) — redeploy to the look-alike host(s)
bash spear/sniperhost/library/m365-login-capture/deploy_hostpoint.sh
# 3) after the first post-deploy hits: tb_data_webpage_visit gains page-0 rows; tb_data_webform_submit.screen_res is a real WxH (not "Failed")
```

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

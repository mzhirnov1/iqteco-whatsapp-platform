#!/usr/bin/env bash
# wa-lib-version-check.sh — daily check: did whatsapp-web.js (our pinned
# commit's upstream) or the WhatsApp Web build mirror move since we last looked?
#
# Reports to the log always; sends a Telegram message (same optional
# /etc/wa-watchdog-telegram.env as the watchdog) only when something CHANGED
# since the previous run, so the alert is news, not noise.
#
# Policy reminder (docs/ops): we do NOT auto-upgrade. A new upstream release
# is a prompt to read its changelog; an upgrade goes through
# wa-rolling-update.sh --build with a canary. The emergency lever for a WA Web
# build breaking us is the waWebVersion pin in instance/src/client.js.
#
# Install: cp to /usr/local/sbin/, then /etc/cron.d/wa-lib-version-check:
#   17 9 * * * root /usr/local/sbin/wa-lib-version-check.sh

set -uo pipefail

REPO_DIR="${WA_LIBCHECK_REPO:-/root/whatsapp-platform}"
STATE_DIR="/var/lib/wa-watchdog"          # reuse the watchdog's state home
LOG="${WA_LIBCHECK_LOG:-/var/log/wa-lib-versions.log}"
TG_ENV="${WA_WATCHDOG_TG_ENV:-/etc/wa-watchdog-telegram.env}"
UPSTREAM="https://github.com/wwebjs/whatsapp-web.js.git"
RELEASES_API="https://api.github.com/repos/wwebjs/whatsapp-web.js/releases/latest"
WAVER_API="https://api.github.com/repos/wppconnect-team/wa-version/commits/main"

mkdir -p "$STATE_DIR"
ts()  { date '+%Y-%m-%dT%H:%M:%S%:z'; }
log() { echo "[$(ts)] $*" >> "$LOG"; }
tg()  {
  [ -f "$TG_ENV" ] || return 0
  # shellcheck disable=SC1090
  . "$TG_ENV" 2>/dev/null || return 0
  [ -n "${TG_TOKEN:-}" ] && [ -n "${TG_CHAT:-}" ] || return 0
  curl -s -m 10 -o /dev/null "https://api.telegram.org/bot$TG_TOKEN/sendMessage" \
    --data-urlencode "chat_id=$TG_CHAT" --data-urlencode "text=[wa-lib-check] $1" || true
}

# what we run: the commit pinned in package.json
pinned=$(grep -o 'whatsapp-web.js#[0-9a-f]\{40\}' "$REPO_DIR/instance/package.json" | cut -d'#' -f2)
[ -n "$pinned" ] || { log "cannot read pin from package.json"; exit 1; }

# what upstream has
head=$(git ls-remote "$UPSTREAM" refs/heads/main 2>/dev/null | awk '{print $1}')
release=$(curl -s -m 20 "$RELEASES_API" 2>/dev/null | grep -o '"tag_name": *"[^"]*"' | head -1 | cut -d'"' -f4)
waver=$(curl -s -m 20 "$WAVER_API" 2>/dev/null | grep -o '"sha": *"[0-9a-f]\{40\}"' | head -1 | cut -d'"' -f4)

[ -n "$head" ] || { log "git ls-remote failed"; exit 1; }

state="$STATE_DIR/libcheck.last"
prev=$(cat "$state" 2>/dev/null || echo "")
cur="head=$head release=${release:-?} waver=${waver:-?}"
echo "$cur" > "$state"

behind=""
[ "$head" != "$pinned" ] && behind=" (pin ${pinned:0:7} is BEHIND main ${head:0:7})"
log "pin=${pinned:0:7} main=${head:0:7} release=${release:-?} wa-version=${waver:0:7}$behind"

# alert only on change, and only about the part that changed
if [ -n "$prev" ] && [ "$prev" != "$cur" ]; then
  msg=""
  case "$prev" in (*"head=$head"*) ;; (*) msg="whatsapp-web.js main moved to ${head:0:7}${release:+ (latest release: $release)}${behind}. Read the changelog; upgrade via wa-rolling-update.sh --build with a canary." ;; esac
  case "$prev" in (*"release=${release:-?}"*) ;; (*) msg="${msg:-whatsapp-web.js released ${release:-?} — read the changelog before upgrading.}" ;; esac
  case "$prev" in (*"waver=${waver:-?}"*) ;; (*) msg="${msg:-New WhatsApp Web build mirrored in wa-version — watch the wa-watchdog getChats probe for breakage.}" ;; esac
  [ -n "$msg" ] && tg "$msg"
fi
exit 0

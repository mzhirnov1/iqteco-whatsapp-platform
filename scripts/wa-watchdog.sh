#!/usr/bin/env bash
# wa-watchdog — external container-level health watchdog for the iqteco
# WhatsApp/Telegram instance gateway (admin.wa.iqteco.com).
#
# WHY: the in-process self-healing (heartbeat -> reboot, qr_loop ->
# resetSession) lives INSIDE the instance process. When it hangs during startup
# (puppeteer/Chromium launch stalls, a corrupt RemoteAuth session, etc.) it
# never binds :8080, so nginx returns 502 and the in-process healer can never
# run. podman still reports the container "running", so nothing recovers it
# (observed: wa-1101008394 stuck 20h at 502).
#
# DETECTION: probe each running wa-/tg-<id> container's public API with a bogus
# token. A live app answers 401/403 within the timeout; a hung/unreachable
# upstream yields 502 (or curl timeout -> 000). Healthy-but-notAuthorized
# instances answer 401, so qr_loop / WhatsApp-ban churn is LEFT ALONE.
#
# REMEDIATION: recreate the container via the platform's own
# InstanceManager::reboot (scripts/wa-recover.php) — i.e. stop -> rm -> run,
# re-applying the stored static --ip6 and env. A raw `podman restart`/`start`
# does NOT re-apply --ip6 and leaves the container OFF THE NETWORK (still 502);
# that is why the platform sudoers allowlists run/stop/rm but not restart.
# For WhatsApp instances stuck on a provably-corrupt session (RemoteAuth zip in
# GridFS smaller than CORRUPT_MAX bytes — a real session is megabytes), the
# corrupt session is dropped before recreate so the client starts fresh at QR.
#
# SCOPE: only state=running containers. 'created'/'exited' are owned by
# wa-pool-keeper / wa-pending-delete and are deliberately not touched here.
#
# Runs as ROOT (needs `podman ps`); recreation is delegated to wa-recover.php
# run as www-data (uses the existing `sudo podman run/stop/rm` allowlist).
# Installed by the systemd timer wa-watchdog.timer (every 2 min).

set -uo pipefail

API_BASE="${WA_WATCHDOG_API_BASE:-https://api.wa.iqteco.com}"
MONGO_URI="${WA_WATCHDOG_MONGO:-mongodb://10.89.0.1:27017/iqteco_wa}"
RECOVER_PHP="${WA_WATCHDOG_RECOVER:-/var/www/admin.wa.iqteco.com/scripts/wa-recover.php}"
STATE_DIR="/var/lib/wa-watchdog"
LOG="${WA_WATCHDOG_LOG:-/var/log/wa-watchdog.log}"
PROBE_TIMEOUT=8       # seconds for the HTTP probe
BOOT_GRACE=180        # skip a container younger than this (still booting)
MAX_PER_HOUR=4        # after N recreates in a rolling hour: give up + log (manual)
CORRUPT_MAX=10240     # GridFS session zip <= this many bytes == corrupt -> wipe
PODMAN=/usr/bin/podman

mkdir -p "$STATE_DIR"
now=$(date +%s)
ts()  { date '+%Y-%m-%dT%H:%M:%S%:z'; }
log() { echo "[$(ts)] $*" >> "$LOG"; }

restarts_last_hour() {  # $1=name
  local f="$STATE_DIR/$1.restarts"
  [ -f "$f" ] || { echo 0; return; }
  awk -v c=$((now-3600)) '$1>=c' "$f" | wc -l | tr -d ' '
}
record_restart() {      # $1=name
  local f="$STATE_DIR/$1.restarts"
  echo "$now" >> "$f"
  awk -v c=$((now-3600)) '$1>=c' "$f" > "$f.tmp" 2>/dev/null && mv "$f.tmp" "$f"
}
last_restart() {        # $1=name
  local f="$STATE_DIR/$1.restarts"
  [ -f "$f" ] && tail -n1 "$f" 2>/dev/null || echo 0
}

# GridFS session size in bytes for a WA instance, -1 if none
session_size() {        # $1=id
  mongosh --quiet "$MONGO_URI" --eval \
    "const f=db.wa_sessions.files.find({filename:'RemoteAuth-$1.zip'}).sort({uploadDate:-1}).limit(1).toArray(); print(f[0]?f[0].length:-1)" \
    2>/dev/null | tr -dc '0-9-'
}
wipe_session() {        # $1=id
  mongosh --quiet "$MONGO_URI" --eval \
    "const fn='RemoteAuth-$1.zip'; const ids=db.wa_sessions.files.find({filename:fn},{_id:1}).toArray().map(d=>d._id); db.wa_sessions.chunks.deleteMany({files_id:{\$in:ids}}); db.wa_sessions.files.deleteMany({filename:fn});" \
    >/dev/null 2>&1
}

checked=0; recovered=0; gaveup=0

while IFS='|' read -r name state; do
  [ -n "$name" ] || continue
  id="${name#*-}"; kind="${name%%-*}"
  checked=$((checked+1))

  # age guard: don't probe/recreate a container that is still booting
  started_raw=$($PODMAN inspect "$name" --format '{{.State.StartedAt}}' 2>/dev/null)
  started_epoch=$(date -d "$started_raw" +%s 2>/dev/null || echo 0)
  if [ "${started_epoch:-0}" -gt 0 ] && [ $((now - started_epoch)) -lt "$BOOT_GRACE" ]; then
    continue
  fi

  code=$(curl -s -m "$PROBE_TIMEOUT" -o /dev/null -w '%{http_code}' \
         "$API_BASE/waInstance$id/getStateInstance/WATCHDOG_PROBE" 2>/dev/null)

  # healthy: any non-5xx, non-000 HTTP response (401/403/200/404 …)
  if [ "$code" != "000" ] && [ "${code:0:1}" != "5" ]; then
    continue
  fi

  # unhealthy: hung/unreachable upstream
  lr=$(last_restart "$name")
  if [ $((now - lr)) -lt "$BOOT_GRACE" ]; then
    log "$name: unhealthy (HTTP $code) — recreated $((now-lr))s ago, awaiting boot"
    continue
  fi
  cnt=$(restarts_last_hour "$name")
  if [ "$cnt" -ge "$MAX_PER_HOUR" ]; then
    log "$name: STILL unhealthy (HTTP $code) after ${cnt} recreates/h — GIVING UP, needs manual"
    gaveup=$((gaveup+1))
    continue
  fi

  # WA escalation: drop a provably-corrupt session so the client can boot to QR
  if [ "$kind" = "wa" ]; then
    sz=$(session_size "$id"); sz=${sz:--1}
    if [ "$sz" -ge 0 ] && [ "$sz" -le "$CORRUPT_MAX" ]; then
      log "$name: corrupt session in GridFS (${sz}B) → wiping before recreate"
      wipe_session "$id"
    fi
  fi

  log "$name: unhealthy (HTTP $code) → recreate via InstanceManager (#$((cnt+1))/h)"
  out=$(sudo -u www-data /usr/bin/php "$RECOVER_PHP" "$id" 2>&1 | tail -n1)
  if echo "$out" | grep -q 'reboot=OK'; then
    log "$name: recreated OK"
    recovered=$((recovered+1))
  else
    log "$name: RECREATE FAILED — $out"
  fi
  record_restart "$name"
done < <($PODMAN ps --format '{{.Names}}|{{.State}}' 2>/dev/null | grep -E '^(wa|tg)-[0-9]+\|running$')

# keep the log quiet on healthy runs; emit a summary only when we acted
if [ "$recovered" -gt 0 ] || [ "$gaveup" -gt 0 ]; then
  log "run: checked=$checked recovered=$recovered gaveup=$gaveup"
fi
exit 0

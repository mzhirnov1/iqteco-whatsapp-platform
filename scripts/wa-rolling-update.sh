#!/usr/bin/env bash
# wa-rolling-update.sh — roll RUNNING WhatsApp containers onto wa-instance:latest
# with session preservation, one at a time, verifying each before the next.
#
# Usage:
#   wa-rolling-update.sh [--build] [--canary <id>] [--yes] [--dry-run]
#
#   --build       tag current :latest as :rollback-YYYYMMDD, then rebuild :latest
#                 from instance/Containerfile before rolling.
#   --canary <id> recreate this instance first (default: first running one).
#   --yes         don't pause for confirmation after the canary.
#   --dry-run     print the plan and run pre-flight checks only.
#
# WHY a script: sessions survive a recreate ONLY because RemoteAuth restores
# them from the GridFS backup (wa_sessions bucket, synced every 60s). The
# procedure is therefore: verify the backup is fresh -> recreate via
# InstanceManager::reboot (wa-recover.php) -> verify the instance came back
# authorized WITHOUT a QR and its read side (getChats) actually works — the
# 2026-08 incident was an instance that stayed "authorized" for two days
# while getChats served 500s.
#
# Parked containers (Exited 0) are skipped on purpose: they are recreated
# from :latest by ensureRunning() on next demand, so they pick the new image
# up for free.
#
# Rollback: podman tag localhost/wa-instance:rollback-YYYYMMDD localhost/wa-instance:latest
#           then re-run this script without --build.
#
# Runs as ROOT (podman, mongosh); recreation is delegated to wa-recover.php
# as www-data, same as the watchdog.

set -uo pipefail

API_BASE="${WA_UPDATE_API_BASE:-https://api.wa.iqteco.com}"
MONGO_URI="${WA_UPDATE_MONGO:-mongodb://10.89.0.1:27017/iqteco_wa}"
RECOVER_PHP="${WA_UPDATE_RECOVER:-/var/www/admin.wa.iqteco.com/scripts/wa-recover.php}"
REPO_DIR="${WA_UPDATE_REPO:-/root/whatsapp-platform}"
BACKUP_MAX_AGE=900    # seconds: refuse to recreate if the session backup is older
BACKUP_MIN_SIZE=1048576  # bytes: a real session zip is megabytes (watchdog: <=10KB == corrupt)
BOOT_TIMEOUT=150      # seconds to wait for the recreated instance
PAUSE_BETWEEN=60      # seconds between instances after a successful verify
PODMAN=/usr/bin/podman

BUILD=0; CANARY=""; YES=0; DRY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --build)   BUILD=1 ;;
    --canary)  CANARY="${2:-}"; shift ;;
    --yes)     YES=1 ;;
    --dry-run) DRY=1 ;;
    *) echo "unknown arg: $1" >&2; exit 1 ;;
  esac
  shift
done

say() { echo "[$(date '+%H:%M:%S')] $*"; }
die() { echo "[$(date '+%H:%M:%S')] FATAL: $*" >&2; exit 1; }

mongo_eval() { mongosh --quiet "$MONGO_URI" --eval "$1" 2>/dev/null; }

instance_token() {  # $1=id
  mongo_eval "const i=db.instances.findOne({idInstance:'$1'},{apiToken:1}); print(i&&i.apiToken?i.apiToken:'')" | tr -d '[:space:]'
}
instance_state() {  # $1=id
  mongo_eval "const i=db.instances.findOne({idInstance:'$1'},{state:1}); print(i&&i.state?i.state:'')" | tr -d '[:space:]'
}
# "OK <bytes> <age-seconds>" or "MISSING" / "STALE <age>" / "SMALL <bytes>"
backup_status() {   # $1=id
  mongo_eval "
    const f=db.wa_sessions.files.find({filename:'RemoteAuth-$1.zip'}).sort({uploadDate:-1}).limit(1).toArray()[0];
    if(!f){print('MISSING')}
    else{
      const age=Math.floor((Date.now()-f.uploadDate.getTime())/1000);
      if(f.length<$BACKUP_MIN_SIZE){print('SMALL '+f.length)}
      else if(age>$BACKUP_MAX_AGE){print('STALE '+age)}
      else{print('OK '+f.length+' '+age)}
    }"
}

verify_instance() { # $1=id $2=pre_state -> 0 ok / 1 fail; prints reason on fail
  local id="$1" pre="$2" tok deadline code body
  tok=$(instance_token "$id")
  [ -n "$tok" ] || { echo "no apiToken in Mongo"; return 1; }

  deadline=$(( $(date +%s) + BOOT_TIMEOUT ))
  while :; do
    body=$(curl -s -m 10 "$API_BASE/waInstance$id/getStateInstance/$tok" 2>/dev/null)
    case "$body" in (*'"authorized"'*) break ;; esac
    if [ "$(date +%s)" -ge "$deadline" ]; then
      # a previously-unauthorized instance is not expected to authorize
      if [ "$pre" != "authorized" ]; then return 0; fi
      echo "did not return to authorized in ${BOOT_TIMEOUT}s (last: ${body:-<none>})"; return 1
    fi
    sleep 5
  done

  # the incident check: authorized is not enough, the read side must work
  code=$(curl -s -m 60 -o /dev/null -w '%{http_code}' "$API_BASE/waInstance$id/getChats/$tok" 2>/dev/null)
  case "$code" in
    2*) return 0 ;;
    *)  echo "getChats HTTP $code after recreate"; return 1 ;;
  esac
}

# ---- plan ----------------------------------------------------------------
mapfile -t RUNNING < <($PODMAN ps --format '{{.Names}}' 2>/dev/null | grep -E '^wa-[0-9]+$' | sed 's/^wa-//')
[ "${#RUNNING[@]}" -gt 0 ] || die "no running wa- containers"

ORDER=()
if [ -n "$CANARY" ]; then
  for i in "${RUNNING[@]}"; do [ "$i" = "$CANARY" ] && ORDER+=("$i"); done
  [ "${#ORDER[@]}" -eq 1 ] || die "--canary $CANARY is not among running instances: ${RUNNING[*]}"
  for i in "${RUNNING[@]}"; do [ "$i" = "$CANARY" ] || ORDER+=("$i"); done
else
  ORDER=("${RUNNING[@]}")
fi

say "plan: ${#ORDER[@]} running instance(s): ${ORDER[*]} (canary: ${ORDER[0]})"
say "parked (exited) containers pick :latest up on next demand — skipped"

# ---- pre-flight ----------------------------------------------------------
fail=0
for id in "${ORDER[@]}"; do
  st=$(instance_state "$id")
  bs=$(backup_status "$id")
  case "$bs" in
    OK*) say "pre-flight wa-$id: state=$st backup=$bs" ;;
    *)   if [ "$st" = "authorized" ]; then
           say "pre-flight wa-$id: state=$st backup=$bs — WOULD LOSE SESSION, excluded"; fail=1
         else
           say "pre-flight wa-$id: state=$st backup=$bs (not authorized — QR flow, safe)"
         fi ;;
  esac
done
[ "$fail" -eq 1 ] && [ "$DRY" -eq 0 ] && die "pre-flight failed for an authorized instance; fix backups first (see above)"
[ "$DRY" -eq 1 ] && { say "dry-run: stopping here"; exit 0; }

# ---- build ---------------------------------------------------------------
if [ "$BUILD" -eq 1 ]; then
  tag="rollback-$(date +%Y%m%d)"
  say "tagging current :latest as :$tag"
  $PODMAN tag localhost/wa-instance:latest "localhost/wa-instance:$tag" || die "tag failed"
  say "building wa-instance:latest"
  $PODMAN build -q -t wa-instance:latest -f "$REPO_DIR/instance/Containerfile" "$REPO_DIR/instance/" >/dev/null \
    || die "build failed — :latest may be half-tagged, check podman images"
fi

# ---- roll ----------------------------------------------------------------
n=0
for id in "${ORDER[@]}"; do
  n=$((n+1))
  pre=$(instance_state "$id")
  say "[$n/${#ORDER[@]}] recreating wa-$id (state=$pre)"
  out=$(sudo -u www-data /usr/bin/php "$RECOVER_PHP" "$id" 2>&1 | tail -n1)
  echo "$out" | grep -q 'reboot=OK' || die "wa-$id recreate failed: $out — remaining instances untouched"

  reason=$(verify_instance "$id" "$pre") || die "wa-$id FAILED verify: $reason
Roll back with: $PODMAN tag localhost/wa-instance:rollback-$(date +%Y%m%d) localhost/wa-instance:latest && re-run without --build. Remaining instances untouched."
  say "[$n/${#ORDER[@]}] wa-$id verified OK"

  if [ "$n" -eq 1 ] && [ "$YES" -eq 0 ] && [ "${#ORDER[@]}" -gt 1 ]; then
    printf "canary wa-%s healthy. Continue with the remaining %d? [y/N] " "$id" $(( ${#ORDER[@]} - 1 ))
    read -r ans
    case "$ans" in (y|Y|yes) ;; (*) say "stopped after canary — rest still on the old image"; exit 0 ;; esac
  fi
  [ "$n" -lt "${#ORDER[@]}" ] && sleep "$PAUSE_BETWEEN"
done

say "done: ${#ORDER[@]} instance(s) rolled onto :latest"

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
# Pre-flight (since 06.09.2026) also EXCLUDES — not fails — an instance whose
# session backup is bloated (> BACKUP_MAX_SIZE or > 3x the run's median) or
# whose getChats already fails: restoring such a profile is what got
# 1101008595 logged out by WhatsApp 66s after a green verify. Verify itself now
# holds VERIFY_HOLD seconds watching the state before calling an instance OK.
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
BACKUP_MAX_SIZE=${WA_UPDATE_BACKUP_MAX:-157286400}  # bytes (150 MB): healthy zips are 25-60 MB. 06.09.2026 the one
                         # 251 MB backup (1101008595) was the one WhatsApp logged out 66s after
                         # restore — a bloated profile is excluded from the roll, not rolled first.
BACKUP_MEDIAN_X=3        # also exclude a backup larger than N x the median of this run's backups
VERIFY_HOLD=${WA_UPDATE_VERIFY_HOLD:-120}  # seconds to keep watching after the read side answers: the
                         # 06.09 LOGOUT came 66s after "verified OK", 10s after ready proves nothing
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

  # the incident check: authorized is not enough, the read side must work.
  # getStateInstance flips to authorized a couple of seconds before the routes
  # do (466 until onReady sets the flag) — 04.09.2026 that race failed a healthy
  # canary 18s after recreate. Give the read side a minute to catch up.
  local attempt=0
  while :; do
    code=$(curl -s -m 60 -o /dev/null -w '%{http_code}' "$API_BASE/waInstance$id/getChats/$tok" 2>/dev/null)
    case "$code" in
      2*) break ;;
    esac
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 6 ]; then echo "getChats HTTP $code after recreate"; return 1; fi
    sleep 10
  done

  # hold: a restored session can still be revoked by the phone a minute later
  # (06.09.2026: LOGOUT 66s after this point). Keep polling state for
  # VERIFY_HOLD seconds — any flip away from authorized fails the verify —
  # then prove the read side once more.
  [ "$pre" = "authorized" ] || return 0
  local hold_until=$(( $(date +%s) + VERIFY_HOLD )) t0
  t0=$(date +%s)
  while [ "$(date +%s)" -lt "$hold_until" ]; do
    sleep 10
    body=$(curl -s -m 10 "$API_BASE/waInstance$id/getStateInstance/$tok" 2>/dev/null)
    case "$body" in
      (*'"authorized"'*) ;;
      (*) echo "lost authorization $(( $(date +%s) - t0 ))s after coming back (state: ${body:-<none>}) — session revoked on restore?"; return 1 ;;
    esac
  done
  code=$(curl -s -m 60 -o /dev/null -w '%{http_code}' "$API_BASE/waInstance$id/getChats/$tok" 2>/dev/null)
  case "$code" in
    2*) return 0 ;;
    *)  echo "getChats HTTP $code at the end of the ${VERIFY_HOLD}s hold"; return 1 ;;
  esac
}

# live read-side probe BEFORE recreate: a profile whose getChats already fails
# (the IDB "r"/"t" class) is not something we want to snapshot-and-restore.
chats_ok_now() {   # $1=id -> 0 ok / 1 failing (prints code)
  local tok code
  tok=$(instance_token "$1"); [ -n "$tok" ] || { echo "no token"; return 1; }
  code=$(curl -s -m 60 -o /dev/null -w '%{http_code}' "$API_BASE/waInstance$1/getChats/$tok" 2>/dev/null)
  case "$code" in
    2*) return 0 ;;
    *)  echo "$code"; return 1 ;;
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
declare -A PRE_STATE PRE_BACKUP
SIZES=()
for id in "${ORDER[@]}"; do
  st=$(instance_state "$id")
  bs=$(backup_status "$id")
  PRE_STATE[$id]="$st"; PRE_BACKUP[$id]="$bs"
  case "$bs" in
    OK*) SIZES+=("$(echo "$bs" | awk '{print $2}')") ;;
  esac
done
# median of this run's healthy backups — the bloated one stands out against its peers
MEDIAN=0
if [ "${#SIZES[@]}" -gt 0 ]; then
  MEDIAN=$(printf '%s\n' "${SIZES[@]}" | sort -n | awk '{a[NR]=$1} END{n=NR; if(n%2){print a[(n+1)/2]} else {print int((a[n/2]+a[n/2+1])/2)}}')
fi
KEEP=()
for id in "${ORDER[@]}"; do
  st="${PRE_STATE[$id]}"; bs="${PRE_BACKUP[$id]}"
  case "$bs" in
    OK*)
      sz=$(echo "$bs" | awk '{print $2}')
      if [ "$sz" -gt "$BACKUP_MAX_SIZE" ]; then
        say "pre-flight wa-$id: state=$st backup=$bs — $((sz/1048576)) MB > cap $((BACKUP_MAX_SIZE/1048576)) MB, bloated profile: EXCLUDED from this roll (06.09.2026 class)"
        continue
      fi
      if [ "$MEDIAN" -gt 0 ] && [ "$sz" -gt $((MEDIAN * BACKUP_MEDIAN_X)) ]; then
        say "pre-flight wa-$id: state=$st backup=$bs — $((sz/1048576)) MB > ${BACKUP_MEDIAN_X}x median $((MEDIAN/1048576)) MB: EXCLUDED from this roll"
        continue
      fi
      if [ "$st" = "authorized" ]; then
        if ! rc=$(chats_ok_now "$id"); then
          say "pre-flight wa-$id: state=$st backup=$bs — getChats HTTP $rc BEFORE recreate, read side already broken: EXCLUDED (recover it separately)"
          continue
        fi
      fi
      say "pre-flight wa-$id: state=$st backup=$bs getChats=OK"
      KEEP+=("$id") ;;
    *)   if [ "$st" = "authorized" ]; then
           say "pre-flight wa-$id: state=$st backup=$bs — WOULD LOSE SESSION, excluded"; fail=1
         else
           say "pre-flight wa-$id: state=$st backup=$bs (not authorized — QR flow, safe)"
           KEEP+=("$id")
         fi ;;
  esac
done
[ "$fail" -eq 1 ] && [ "$DRY" -eq 0 ] && die "pre-flight failed for an authorized instance; fix backups first (see above)"
if [ "${#KEEP[@]}" -ne "${#ORDER[@]}" ]; then
  ORDER=("${KEEP[@]}")
  [ "${#ORDER[@]}" -gt 0 ] || die "every running instance was excluded by pre-flight — nothing to roll"
  say "plan after pre-flight: ${#ORDER[@]} instance(s): ${ORDER[*]} (canary: ${ORDER[0]})"
fi
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

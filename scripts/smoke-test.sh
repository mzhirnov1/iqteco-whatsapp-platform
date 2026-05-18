#!/usr/bin/env bash
# smoke-test.sh — end-to-end проверка после фазы 2
# Использование: SMOKE_PHONE=79991234567 SMOKE_WEBHOOK=https://requestbin.example/wa bash scripts/smoke-test.sh
set -euo pipefail

BASE_ADMIN="${ADMIN_URL:-https://admin.wa.iqteco.com}"
BASE_API="${API_URL:-https://api.wa.iqteco.com}"
SMOKE_PHONE="${SMOKE_PHONE:?set SMOKE_PHONE env var}"
SMOKE_WEBHOOK="${SMOKE_WEBHOOK:?set SMOKE_WEBHOOK env var}"

log() { echo "[smoke] $*"; }

log "1. create instance (admin must be logged in via cookie ADMIN_COOKIE env)"
RESP=$(curl -sS -b "$ADMIN_COOKIE" -X POST "$BASE_ADMIN/instances/new" \
    -d "auth_method=qr&webhook_url=$SMOKE_WEBHOOK")
echo "$RESP"
ID=$(echo "$RESP" | jq -r '.idInstance')
TOKEN=$(echo "$RESP" | jq -r '.apiToken')

log "2. wait for QR and scan it on $BASE_ADMIN/instances/$ID"
log "   Press ENTER after scanning..."
read -r

log "3. send test message"
curl -sS -X POST "$BASE_API/waInstance${ID}/sendMessage/${TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "{\"chatId\":\"${SMOKE_PHONE}@c.us\",\"message\":\"smoke test\"}"
echo

log "4. check getStateInstance"
curl -sS "$BASE_API/waInstance${ID}/getStateInstance/${TOKEN}"
echo

log "5. reboot"
curl -sS "$BASE_API/waInstance${ID}/reboot/${TOKEN}"
echo

log "done. check $SMOKE_WEBHOOK for incomingMessageReceived webhook on reply"

#!/usr/bin/env bash
# podman-network-init.sh — создание podman сети wa-net с IPv6 /64
set -euo pipefail

log() { echo "[podman-network] $*"; }

if [[ $EUID -ne 0 ]]; then
    echo "must be root" >&2; exit 1
fi

SUBNET="${IPV6_SUBNET:-2a01:4f8:221:2d8d::/64}"
GATEWAY="${IPV6_GATEWAY:-2a01:4f8:221:2d8d::1}"
NETNAME="${WA_NET:-wa-net}"

if podman network exists "$NETNAME" 2>/dev/null; then
    log "network $NETNAME already exists"
    podman network inspect "$NETNAME" | jq '.[0] | {name, subnets, ipv6_enabled}'
    exit 0
fi

log "creating network $NETNAME with subnet $SUBNET"
podman network create \
    --ipv6 \
    --subnet "$SUBNET" \
    --gateway "$GATEWAY" \
    --opt isolate=false \
    "$NETNAME"

log "verify"
podman network inspect "$NETNAME" | jq '.[0] | {name, subnets, ipv6_enabled}'

log "done. quick test: podman run --rm --network $NETNAME alpine sh -c 'ip -6 addr show'"

#!/usr/bin/env bash
# wa-direct-egress — keep instance containers off the host's shared egress IP.
#
# WHY: netavark NATs every container behind the host address, so all our
# WhatsApp numbers reached WhatsApp from one and the same IPv6
# (2a01:4f8:221:2d8d::2). Two dozen accounts sharing one datacentre address is
# the classic ferm/farm signature — we have LOGOUTs to show for it, the most
# recent on the number's own registration day. Each container already owns a
# routable /128 from the podman subnet; skipping the masquerade lets it use it.
#
# HONEST LIMIT: every /128 still sits inside the same /64, and abuse systems
# generally aggregate IPv6 reputation per /64 (Spamhaus lists at /64, so do the
# big CDNs) because a host can trivially cycle addresses inside its own /64.
# So this buys per-instance attribution and helps only if WhatsApp weighs /128
# at all. Real separation needs additional /64s or per-instance proxies.
#
# WHY A TIMER: the rule lives inside a chain netavark GENERATES. Any network
# re-create (and podman restarts) rewrites that chain and silently drops our
# rule, quietly restoring NAT. So we re-assert it instead of setting it once.
# Idempotent: does nothing when the rule is already in place.

set -uo pipefail

SUBNET="${WA_EGRESS_SUBNET:-2a01:4f8:221:2d8d:c0a8::/80}"
LOG="${WA_EGRESS_LOG:-/var/log/wa-direct-egress.log}"
NFT=/usr/sbin/nft

log() { echo "[$(date '+%Y-%m-%dT%H:%M:%S%:z')] $*" >> "$LOG"; }

# The chain name is a hash of the network id, so it changes whenever the network
# is re-created — always resolve it from POSTROUTING rather than hard-coding it.
chain=$("$NFT" list chain ip6 nat POSTROUTING 2>/dev/null \
  | awk -v net="$SUBNET" '$0 ~ net { for (i = 1; i <= NF; i++) if ($i == "jump") print $(i + 1) }' \
  | head -1)

if [ -z "$chain" ]; then
  # No container network yet (or podman not up) — nothing to assert.
  exit 0
fi

# Already bypassing? Then there is nothing to do.
if "$NFT" list chain ip6 nat "$chain" 2>/dev/null | grep -qE "saddr ${SUBNET//\//\\/}.*accept"; then
  exit 0
fi

# Only act when that chain actually masquerades — otherwise this is some other
# topology and we should not be poking at it.
if ! "$NFT" list chain ip6 nat "$chain" 2>/dev/null | grep -q masquerade; then
  log "chain $chain has no masquerade rule; leaving it alone"
  exit 0
fi

if "$NFT" insert rule ip6 nat "$chain" ip6 saddr "$SUBNET" counter accept 2>/dev/null; then
  log "restored direct egress for $SUBNET in chain $chain"
else
  log "FAILED to insert direct-egress rule for $SUBNET in chain $chain"
  exit 1
fi
exit 0

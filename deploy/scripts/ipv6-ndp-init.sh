#!/usr/bin/env bash
# ipv6-ndp-init.sh — настройка ndppd для проксирования NDP-запросов
# Использовать только если Hetzner /64 настроена как bridged (не routed).
# Если routed — этот скрипт не нужен.
set -euo pipefail

log() { echo "[ipv6-ndp] $*"; }

if [[ $EUID -ne 0 ]]; then
    echo "must be root" >&2; exit 1
fi

UPLINK_IF="${UPLINK_IF:-eth0}"
SUBNET="${IPV6_SUBNET:-2a01:4f8:221:2d8d::/64}"

if ! command -v ndppd >/dev/null; then
    apt-get install -y ndppd
fi

log "writing /etc/ndppd.conf"
cat >/etc/ndppd.conf <<EOF
route-ttl 30000

proxy ${UPLINK_IF} {
    router yes
    timeout 500
    autowire no
    keepalive yes
    retries 3
    promiscuous no
    ttl 30000

    rule ${SUBNET} {
        auto
    }
}
EOF

systemctl enable ndppd
systemctl restart ndppd
log "ndppd active on ${UPLINK_IF} for ${SUBNET}"
log "check status: systemctl status ndppd; ndppd -d -c /etc/ndppd.conf"

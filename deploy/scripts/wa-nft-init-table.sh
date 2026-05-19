#!/usr/bin/env bash
# wa-nft-init-table.sh — единовременная инициализация nftables таблицы wa_traffic.
# Вызывается из bootstrap.sh после установки nftables.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "must be root" >&2; exit 1
fi

# Создаём отдельную таблицу — не трогаем существующие правила.
/usr/sbin/nft add table inet wa_traffic 2>/dev/null || true
/usr/sbin/nft add chain inet wa_traffic prerouting '{ type filter hook prerouting priority 0 ; }' 2>/dev/null || true
/usr/sbin/nft add chain inet wa_traffic postrouting '{ type filter hook postrouting priority 0 ; }' 2>/dev/null || true

echo "[wa-nft-init] table inet wa_traffic ready"
/usr/sbin/nft list table inet wa_traffic

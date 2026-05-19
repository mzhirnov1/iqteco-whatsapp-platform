#!/usr/bin/env bash
# wa-nft-add-counter <idInstance> <ipv6>
# Создаёт два counter'а (in/out) для контейнера и добавляет соответствующие rules.
# Sudo-обёртка для PHP админки.
set -euo pipefail

ID="${1:?usage: wa-nft-add-counter <idInstance> <ipv6>}"
IPV6="${2:?usage: wa-nft-add-counter <idInstance> <ipv6>}"

if [[ ! "$ID" =~ ^[0-9]{6,20}$ ]]; then
    echo "invalid idInstance: $ID" >&2; exit 1
fi
# IPv6 валидация
if ! /usr/bin/getent ahosts "$IPV6" >/dev/null 2>&1; then
    # getent может не вернуть для немаршрутизируемых; используем python для проверки
    /usr/bin/python3 -c "import ipaddress, sys; ipaddress.IPv6Address('$IPV6')" || { echo "invalid IPv6: $IPV6" >&2; exit 1; }
fi

C_IN="wa-${ID}-in"
C_OUT="wa-${ID}-out"

# Идемпотентно: если уже есть — пропускаем
/usr/sbin/nft add counter inet wa_traffic "$C_IN" 2>/dev/null || true
/usr/sbin/nft add counter inet wa_traffic "$C_OUT" 2>/dev/null || true

# Добавляем rules. Используем comment с idInstance чтобы потом найти/удалить.
/usr/sbin/nft add rule inet wa_traffic prerouting ip6 daddr "$IPV6" counter name "\"$C_IN\"" comment "\"wa-instance=$ID\"" 2>/dev/null || true
/usr/sbin/nft add rule inet wa_traffic postrouting ip6 saddr "$IPV6" counter name "\"$C_OUT\"" comment "\"wa-instance=$ID\"" 2>/dev/null || true

echo "[wa-nft-add-counter] ok id=$ID ipv6=$IPV6"

#!/usr/bin/env bash
# wa-nft-del-counter <idInstance>
# Удаляет rules+counters для инстанса. Идемпотентно.
set -euo pipefail

ID="${1:?usage: wa-nft-del-counter <idInstance>}"
if [[ ! "$ID" =~ ^[0-9]{6,20}$ ]]; then
    echo "invalid idInstance: $ID" >&2; exit 1
fi

C_IN="wa-${ID}-in"
C_OUT="wa-${ID}-out"

# Удаляем rules. nft требует handle — получаем через -a list.
delete_rules_by_comment() {
    local chain="$1"
    local comment="wa-instance=${ID}"
    /usr/sbin/nft -a list chain inet wa_traffic "$chain" 2>/dev/null | \
        awk -v c="$comment" '$0 ~ "comment \""c"\"" { for(i=1;i<=NF;i++) if($i=="handle"){print $(i+1); break} }' | \
    while read -r h; do
        [[ -n "$h" ]] && /usr/sbin/nft delete rule inet wa_traffic "$chain" handle "$h" 2>/dev/null || true
    done
}

delete_rules_by_comment prerouting
delete_rules_by_comment postrouting

/usr/sbin/nft delete counter inet wa_traffic "$C_IN" 2>/dev/null || true
/usr/sbin/nft delete counter inet wa_traffic "$C_OUT" 2>/dev/null || true

echo "[wa-nft-del-counter] ok id=$ID"

#!/usr/bin/env bash
# wa-nginx-map-reload — устанавливается в /usr/local/bin/wa-nginx-map-reload
# Вызывается через sudo allowlist из PHP админки.
# Делает безопасный reload nginx после регенерации /etc/nginx/wa-instances.map.
set -euo pipefail

MAP_FILE="${MAP_FILE:-/etc/nginx/wa-instances.map}"

if [[ ! -f "$MAP_FILE" ]]; then
    echo "map file missing: $MAP_FILE" >&2
    exit 1
fi

# Validate nginx config before reload
if ! /usr/sbin/nginx -t 2>/dev/null; then
    /usr/sbin/nginx -t
    exit 1
fi

/usr/sbin/nginx -s reload

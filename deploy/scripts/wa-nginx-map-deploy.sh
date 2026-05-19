#!/usr/bin/env bash
# wa-nginx-map-deploy — copies /run/wa-admin/wa-instances.map →
# /etc/nginx/wa-instances.map and reloads nginx. Called via sudo from PHP.
set -euo pipefail

SRC=/run/wa-admin/wa-instances.map
DST=/etc/nginx/wa-instances.map

[[ -f "$SRC" ]] || { echo "source missing: $SRC" >&2; exit 1; }

install -m 0644 -o root -g root "$SRC" "$DST"
/usr/sbin/nginx -t 2>/dev/null || { /usr/sbin/nginx -t; exit 1; }
/usr/sbin/nginx -s reload

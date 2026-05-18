#!/usr/bin/env bash
# bootstrap.sh — первичная подготовка хоста wa.iqteco.com
# Запускать от root. Идемпотентно.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { echo "[bootstrap] $*"; }

if [[ $EUID -ne 0 ]]; then
    echo "must be root" >&2; exit 1
fi

log "apt update"
apt-get update -y

log "ensure base packages"
apt-get install -y --no-install-recommends \
    curl gnupg ca-certificates lsb-release \
    nginx certbot \
    ndppd vnstat nftables jq \
    php-cli php-fpm php-mongodb php-mbstring php-curl php-xml php-zip \
    git

log "ensure Node.js 18 (NodeSource)"
if ! command -v node >/dev/null || ! node -v | grep -q '^v1[89]\.\|^v[2-9][0-9]\.'; then
    curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
    apt-get install -y nodejs
fi

log "ensure composer"
if ! command -v composer >/dev/null; then
    curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

log "ensure MongoDB 6 reachable"
if ! command -v mongosh >/dev/null; then
    log "WARNING: mongosh not installed; install MongoDB shell manually"
fi

log "enable IPv6 forwarding (for podman network)"
cat >/etc/sysctl.d/99-wa-ipv6.conf <<'EOF'
net.ipv6.conf.all.forwarding=1
net.ipv6.conf.all.proxy_ndp=1
net.ipv6.conf.default.forwarding=1
EOF
sysctl --system >/dev/null

log "create wa directories"
mkdir -p /var/www/admin.wa.iqteco.com /var/log/wa /etc/wa
chown -R www-data:www-data /var/log/wa

log "install sudoers allowlist (for admin panel)"
install -m 0440 "$SCRIPT_DIR/../sudoers/wa-admin" /etc/sudoers.d/wa-admin 2>/dev/null || \
    log "skip sudoers (file not present yet)"

log "bootstrap done"
log "next: run podman-upgrade.sh, then podman-network-init.sh, then mongo-init.js"

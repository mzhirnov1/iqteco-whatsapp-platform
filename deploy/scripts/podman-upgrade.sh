#!/usr/bin/env bash
# podman-upgrade.sh — обновление Podman 3.4 → 4.x на Ubuntu 22.04
# Источник: Kubic libcontainers project / jammy-backports
set -euo pipefail

log() { echo "[podman-upgrade] $*"; }

if [[ $EUID -ne 0 ]]; then
    echo "must be root" >&2; exit 1
fi

CURRENT_VERSION="$(podman --version 2>/dev/null | awk '{print $3}' || echo "none")"
log "current podman version: $CURRENT_VERSION"

if [[ "$CURRENT_VERSION" =~ ^[4-9]\. ]]; then
    log "already on podman 4+, nothing to do"
    exit 0
fi

log "checking for running containers (safety)"
RUNNING=$(podman ps -q 2>/dev/null | wc -l || echo 0)
if [[ "$RUNNING" -gt 0 ]]; then
    log "WARNING: $RUNNING running containers detected"
    podman ps
    read -r -p "proceed anyway? [y/N] " CONFIRM
    [[ "${CONFIRM:-N}" =~ ^[yY]$ ]] || exit 1
fi

log "trying jammy-backports first"
if grep -q "jammy-backports" /etc/apt/sources.list /etc/apt/sources.list.d/*.list 2>/dev/null; then
    log "jammy-backports already enabled"
else
    add-apt-repository -y "deb http://archive.ubuntu.com/ubuntu jammy-backports main universe"
fi

apt-get update -y
BACKPORT_VERSION="$(apt-cache policy podman 2>/dev/null | awk '/jammy-backports/{print $1; exit}' || true)"

if apt-get install -y -t jammy-backports podman 2>&1 | tee /tmp/podman-upgrade.log; then
    log "installed podman from backports"
else
    log "backports failed, falling back to Kubic OBS repository"
    OS_ID="$(. /etc/os-release && echo "${ID}_${VERSION_ID}")"
    REPO_URL="https://download.opensuse.org/repositories/devel:/kubic:/libcontainers:/stable/x${OS_ID}"
    echo "deb $REPO_URL/ /" >/etc/apt/sources.list.d/devel-kubic-libcontainers.list
    curl -fsSL "$REPO_URL/Release.key" | gpg --dearmor >/etc/apt/trusted.gpg.d/devel-kubic-libcontainers.gpg
    apt-get update -y
    apt-get install -y podman netavark aardvark-dns
fi

log "new podman version: $(podman --version)"
log "verifying network backend"
podman info --format '{{.Host.NetworkBackend}}' || true

log "done. you can now run podman-network-init.sh"

#!/usr/bin/env bash
# build-image.sh — локальная сборка образа wa-instance
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TAG="${TAG:-wa-instance:latest}"

cd "$REPO_ROOT/instance"

echo "[build-image] building $TAG"
podman build -t "$TAG" -f Containerfile .

echo "[build-image] done: $TAG"
podman images "$TAG"

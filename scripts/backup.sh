#!/usr/bin/env bash
# backup.sh — дамп MongoDB (включая GridFS) в /var/backup.
# Запуск из cron daily. Хранит N=14 последних дампов.
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backup/wa}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
MONGO_URI="${MONGO_URI:-mongodb://wa_admin@127.0.0.1:27017/iqteco_wa?authSource=iqteco_wa}"

mkdir -p "$BACKUP_DIR"

TS=$(date +%Y-%m-%d_%H-%M-%S)
ARCHIVE="$BACKUP_DIR/wa-$TS.gz"

echo "[backup] dumping → $ARCHIVE"
mongodump --uri="$MONGO_URI" --gzip --archive="$ARCHIVE" --quiet

# Удаляем старые
echo "[backup] pruning files older than $RETENTION_DAYS days"
find "$BACKUP_DIR" -name 'wa-*.gz' -mtime "+$RETENTION_DAYS" -delete

echo "[backup] done. size: $(du -h "$ARCHIVE" | awk '{print $1}')"
ls -la "$BACKUP_DIR" | tail -5

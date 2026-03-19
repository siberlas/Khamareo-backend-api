#!/bin/bash
set -euo pipefail

# ── Khamareo Daily Database Backup ──────────────────────────
# Crontab : 0 3 * * * /opt/khamareo/scripts/backup.sh >> /var/log/khamareo-backup.log 2>&1

BACKUP_DIR="/opt/khamareo/backups"
COMPOSE_FILE="/opt/khamareo/docker-compose.prod.yml"
RETENTION_DAYS=30
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p "$BACKUP_DIR"

echo "[$(date)] Backup en cours..."

# Dump database
docker compose -f "$COMPOSE_FILE" exec -T db \
    pg_dump -U khamareo -Fc khamareo \
    > "$BACKUP_DIR/khamareo_${DATE}.dump"

# Compress
gzip "$BACKUP_DIR/khamareo_${DATE}.dump"

# Cleanup old backups
find "$BACKUP_DIR" -name "khamareo_*.dump.gz" -mtime +${RETENTION_DAYS} -delete
find "$BACKUP_DIR" -name "pre-deploy-*.dump" -mtime +7 -delete

BACKUP_SIZE=$(du -h "$BACKUP_DIR/khamareo_${DATE}.dump.gz" | cut -f1)
echo "[$(date)] Backup terminé : khamareo_${DATE}.dump.gz ($BACKUP_SIZE)"

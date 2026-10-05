#!/bin/bash
# =============================================================================
# restore_db.sh — Database Restoration Script
# Usage: sudo bash restore_db.sh <archive_file_path> [--confirm]
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

ARCHIVE="${1:-}"
CONFIRM="${2:-}"

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
    echo "[ERROR] Database archive file does not exist: $ARCHIVE" >&2
    echo "Usage: sudo bash restore_db.sh <archive.sql.gz> --confirm" >&2
    exit 1
fi

if [[ "$CONFIRM" != "--confirm" ]]; then
    echo "=================================================================="
    echo " WARNING: THIS OPERATION WILL OVERWRITE THE CURRENT DATABASE!     "
    echo "=================================================================="
    echo "Target Archive: $ARCHIVE"
    echo ""
    echo "To execute this restore, pass the --confirm flag:"
    echo "  sudo bash restore_db.sh $ARCHIVE --confirm"
    exit 1
fi

SCRIPT_DIR="$(dirname "$0")"

# 1. Verify archive integrity prior to restoring
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Verifying archive integrity before restore..."
if [[ -f "${SCRIPT_DIR}/verify_backup.sh" ]]; then
    bash "${SCRIPT_DIR}/verify_backup.sh" "$ARCHIVE"
fi

# 2. Source database credentials from .env
ENV_FILE="${SCRIPT_DIR}/../laravel-panel/.env"
if [[ -f "$ENV_FILE" ]]; then
    DB_HOST="$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '127.0.0.1')"
    DB_PORT="$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '3306')"
    DB_DATABASE="$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'emailsaas')"
    DB_USERNAME="$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'emailsaas')"
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
else
    DB_HOST="127.0.0.1"
    DB_PORT="3306"
    DB_DATABASE="emailsaas"
    DB_USERNAME="emailsaas"
    DB_PASSWORD=""
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Restoring database '$DB_DATABASE' from archive..."

MYSQL_CMD="mysql"
if ! command -v "$MYSQL_CMD" &>/dev/null; then
    if command -v mariadb &>/dev/null; then
        MYSQL_CMD="mariadb"
    else
        echo "[ERROR] Neither mysql nor mariadb client found in PATH." >&2
        exit 1
    fi
fi

# Decompress and stream directly into database engine
gunzip -c "$ARCHIVE" | MYSQL_PWD="$DB_PASSWORD" "$MYSQL_CMD" \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USERNAME" \
    --default-character-set=utf8mb4 \
    "$DB_DATABASE"

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Database restoration completed successfully."
exit 0

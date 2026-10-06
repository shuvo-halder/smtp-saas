#!/bin/bash
# =============================================================================
# restore_db.sh — Database Restoration Script
# Usage: sudo bash restore_db.sh <archive_file_path> [--confirm]
# Supports OpenSSL AES-256-CBC encrypted dumps (.sql.gz.enc)
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

ARCHIVE="${1:-}"
CONFIRM="${2:-}"

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
    echo "[ERROR] Database archive file does not exist: $ARCHIVE" >&2
    echo "Usage: sudo bash restore_db.sh <archive.sql.gz|archive.sql.gz.enc> --confirm" >&2
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

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# 1. Verify archive integrity prior to restoring
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Verifying archive integrity before restore..."
if [[ -f "${SCRIPT_DIR}/verify_backup.sh" ]]; then
    bash "${SCRIPT_DIR}/verify_backup.sh" "$ARCHIVE"
fi

# 2. Source database credentials from .env
ENV_FILE="${SCRIPT_DIR}/../laravel-panel/.env"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_DATABASE="emailsaas"
DB_USERNAME="emailsaas"
DB_PASSWORD=""
ENC_KEY=""
ENC_KEY_PATH=""

if [[ -f "$ENV_FILE" ]]; then
    DB_HOST="$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '127.0.0.1')"
    DB_PORT="$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '3306')"
    DB_DATABASE="$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'emailsaas')"
    DB_USERNAME="$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'emailsaas')"
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
    ENC_KEY="$(grep -E '^BACKUP_ENCRYPTION_KEY=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
    ENC_KEY_PATH="$(grep -E '^BACKUP_ENCRYPTION_KEY_PATH=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
fi

if [[ -z "$ENC_KEY" && -n "$ENC_KEY_PATH" && -f "$ENC_KEY_PATH" ]]; then
    ENC_KEY="$(head -n 1 "$ENC_KEY_PATH" | tr -d '\r\n')"
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

# Decompress and stream directly into database engine (with decryption if needed)
if [[ "$ARCHIVE" == *.enc ]]; then
    if [[ -z "$ENC_KEY" ]]; then
        echo "[ERROR] Archive is encrypted, but no decryption key (BACKUP_ENCRYPTION_KEY / BACKUP_ENCRYPTION_KEY_PATH) found." >&2
        exit 1
    fi
    echo "  [DECRYPTION] Decrypting AES-256-CBC stream on-the-fly directly to database engine..."
    openssl enc -d -aes-256-cbc -pbkdf2 -pass pass:"$ENC_KEY" -in "$ARCHIVE" \
        | gunzip -c \
        | MYSQL_PWD="$DB_PASSWORD" "$MYSQL_CMD" \
            --host="$DB_HOST" \
            --port="$DB_PORT" \
            --user="$DB_USERNAME" \
            --default-character-set=utf8mb4 \
            "$DB_DATABASE"
else
    gunzip -c "$ARCHIVE" \
        | MYSQL_PWD="$DB_PASSWORD" "$MYSQL_CMD" \
            --host="$DB_HOST" \
            --port="$DB_PORT" \
            --user="$DB_USERNAME" \
            --default-character-set=utf8mb4 \
            "$DB_DATABASE"
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Database restoration completed successfully."
exit 0

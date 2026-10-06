#!/bin/bash
# =============================================================================
# backup_db.sh — Transaction-Safe MariaDB/MySQL Backup Script
# Usage: sudo bash backup_db.sh [backup_dir]
# Supports OpenSSL AES-256-CBC encryption at rest (.sql.gz.enc)
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

LOCK_FILE="/var/lock/emailsaas_backup_db.lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    echo "[ERROR] Another database backup is currently running. Exiting." >&2
    exit 1
fi

TIMESTAMP="$(date +'%Y%m%d_%H%M%S')"
BACKUP_DIR="${1:-/var/backups/emailsaas/db}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

# Source environment variables if .env exists
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/../laravel-panel/.env"

DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_DATABASE="emailsaas"
DB_USERNAME="emailsaas"
DB_PASSWORD=""
ENC_ENABLED="false"
ENC_KEY=""
ENC_KEY_PATH=""

if [[ -f "$ENV_FILE" ]]; then
    DB_HOST="$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '127.0.0.1')"
    DB_PORT="$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '3306')"
    DB_DATABASE="$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'emailsaas')"
    DB_USERNAME="$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'emailsaas')"
    DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
    ENC_ENABLED="$(grep -E '^BACKUP_ENCRYPTION_ENABLED=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo 'false')"
    ENC_KEY="$(grep -E '^BACKUP_ENCRYPTION_KEY=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
    ENC_KEY_PATH="$(grep -E '^BACKUP_ENCRYPTION_KEY_PATH=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
fi

if [[ -z "$ENC_KEY" && -n "$ENC_KEY_PATH" && -f "$ENC_KEY_PATH" ]]; then
    ENC_KEY="$(head -n 1 "$ENC_KEY_PATH" | tr -d '\r\n')"
fi

IS_ENCRYPTED=false
ARCHIVE_EXT=".sql.gz"

if [[ "$ENC_ENABLED" == "true" || -n "$ENC_KEY" ]]; then
    if [[ -z "$ENC_KEY" ]]; then
        echo "[ERROR] Backup encryption is enabled, but no valid key or key path was found." >&2
        exit 1
    fi
    IS_ENCRYPTED=true
    ARCHIVE_EXT=".sql.gz.enc"
fi

FINAL_ARCHIVE="${BACKUP_DIR}/db_backup_${TIMESTAMP}${ARCHIVE_EXT}"
TMP_ARCHIVE="${FINAL_ARCHIVE}.tmp"
MANIFEST_FILE="${FINAL_ARCHIVE}.manifest.json"

cleanup() {
    if [[ -f "$TMP_ARCHIVE" ]]; then
        echo "[CLEANUP] Removing incomplete temporary backup: $TMP_ARCHIVE"
        rm -f "$TMP_ARCHIVE"
    fi
}
trap cleanup EXIT ERR INT TERM

# Detect mysqldump or mariadb-dump
DUMP_BIN=""
if command -v mariadb-dump &>/dev/null; then
    DUMP_BIN="mariadb-dump"
elif command -v mysqldump &>/dev/null; then
    DUMP_BIN="mysqldump"
else
    echo "[ERROR] Neither mariadb-dump nor mysqldump was found in PATH." >&2
    exit 1
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting transaction-safe database backup with ${DUMP_BIN}..."

if [[ "$IS_ENCRYPTED" == "true" ]]; then
    echo "  [ENCRYPTION] Encrypting with OpenSSL AES-256-CBC PBKDF2..."
    MYSQL_PWD="$DB_PASSWORD" "$DUMP_BIN" \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --hex-blob \
        --default-character-set=utf8mb4 \
        "$DB_DATABASE" \
        | gzip -c \
        | openssl enc -aes-256-cbc -salt -pbkdf2 -pass pass:"$ENC_KEY" > "$TMP_ARCHIVE"
else
    MYSQL_PWD="$DB_PASSWORD" "$DUMP_BIN" \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        --hex-blob \
        --default-character-set=utf8mb4 \
        "$DB_DATABASE" \
        | gzip -c > "$TMP_ARCHIVE"
fi

# Atomic promotion
mv "$TMP_ARCHIVE" "$FINAL_ARCHIVE"
chmod 600 "$FINAL_ARCHIVE"

# Calculate SHA-256
SHA256_HASH="$(sha256sum "$FINAL_ARCHIVE" | awk '{print $1}')"
FILE_SIZE="$(stat -c %s "$FINAL_ARCHIVE" 2>/dev/null || stat -f %z "$FINAL_ARCHIVE" 2>/dev/null || wc -c < "$FINAL_ARCHIVE")"

CIPHER_VAL="null"
if [[ "$IS_ENCRYPTED" == "true" ]]; then
    CIPHER_VAL="\"aes-256-cbc\""
fi

# Generate JSON Manifest
cat <<EOF > "$MANIFEST_FILE"
{
  "type": "database",
  "filename": "$(basename "$FINAL_ARCHIVE")",
  "path": "$FINAL_ARCHIVE",
  "created_at": "$(date -u +'%Y-%m-%dT%H:%M:%SZ')",
  "size_bytes": $FILE_SIZE,
  "sha256": "$SHA256_HASH",
  "database": "$DB_DATABASE",
  "engine": "$DUMP_BIN",
  "compressed": true,
  "encrypted": $IS_ENCRYPTED,
  "cipher": $CIPHER_VAL,
  "verified": false,
  "restore_tested": false,
  "offsite_copied": false,
  "offsite_status": "PENDING"
}
EOF
chmod 600 "$MANIFEST_FILE"

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Database backup successfully created: $FINAL_ARCHIVE ($FILE_SIZE bytes, SHA256: $SHA256_HASH)"
trap - EXIT ERR INT TERM
exit 0

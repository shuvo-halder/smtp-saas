#!/bin/bash
# =============================================================================
# backup_vmail.sh — Mail Storage (/var/vmail) Backup Script
# Usage: sudo bash backup_vmail.sh [backup_dir] [vmail_source]
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

LOCK_FILE="/var/lock/emailsaas_backup_vmail.lock"
exec 200>"$LOCK_FILE"
if ! flock -n 200; then
    echo "[ERROR] Another mail storage backup is currently running. Exiting." >&2
    exit 1
fi

TIMESTAMP="$(date +'%Y%m%d_%H%M%S')"
BACKUP_DIR="${1:-/var/backups/emailsaas/vmail}"
VMAIL_SOURCE="${2:-/var/vmail}"

if [[ ! -d "$VMAIL_SOURCE" ]]; then
    echo "[ERROR] Mail storage source directory $VMAIL_SOURCE does not exist." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

FINAL_ARCHIVE="${BACKUP_DIR}/vmail_backup_${TIMESTAMP}.tar.gz"
TMP_ARCHIVE="${FINAL_ARCHIVE}.tmp"
MANIFEST_FILE="${FINAL_ARCHIVE}.manifest.json"

cleanup() {
    if [[ -f "$TMP_ARCHIVE" ]]; then
        echo "[CLEANUP] Removing incomplete temporary backup: $TMP_ARCHIVE"
        rm -f "$TMP_ARCHIVE"
    fi
}
trap cleanup EXIT ERR INT TERM

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Starting mail storage backup for ${VMAIL_SOURCE}..."

# Archive preserving numeric IDs, permissions, timestamps, and symlinks
# Note: Maildir format is crash-consistent; message files are atomically written
tar --create \
    --gzip \
    --numeric-owner \
    --preserve-permissions \
    --file="$TMP_ARCHIVE" \
    -C "$VMAIL_SOURCE" .

# Atomic promotion
mv "$TMP_ARCHIVE" "$FINAL_ARCHIVE"
chmod 600 "$FINAL_ARCHIVE"

# Calculate SHA-256
SHA256_HASH="$(sha256sum "$FINAL_ARCHIVE" | awk '{print $1}')"
FILE_SIZE="$(stat -c %s "$FINAL_ARCHIVE" 2>/dev/null || stat -f %z "$FINAL_ARCHIVE" 2>/dev/null || wc -c < "$FINAL_ARCHIVE")"

# Generate JSON Manifest
cat <<EOF > "$MANIFEST_FILE"
{
  "type": "mail_storage",
  "filename": "$(basename "$FINAL_ARCHIVE")",
  "path": "$FINAL_ARCHIVE",
  "source_path": "$VMAIL_SOURCE",
  "created_at": "$(date -u +'%Y-%m-%dT%H:%M:%SZ')",
  "size_bytes": $FILE_SIZE,
  "sha256": "$SHA256_HASH",
  "compressed": true,
  "verified": false
}
EOF
chmod 600 "$MANIFEST_FILE"

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Mail storage backup successfully created: $FINAL_ARCHIVE ($FILE_SIZE bytes, SHA256: $SHA256_HASH)"
trap - EXIT ERR INT TERM
exit 0

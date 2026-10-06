#!/bin/bash
# =============================================================================
# restore_vmail.sh — Mail Storage Restoration Script
# Usage: sudo bash restore_vmail.sh <archive_file_path> [--confirm] [target_dir]
# Supports OpenSSL AES-256-CBC encrypted archives (.tar.gz.enc)
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

ARCHIVE="${1:-}"
CONFIRM="${2:-}"
TARGET_DIR="${3:-/var/vmail}"

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
    echo "[ERROR] Mail storage archive file does not exist: $ARCHIVE" >&2
    echo "Usage: sudo bash restore_vmail.sh <archive.tar.gz|archive.tar.gz.enc> --confirm [/var/vmail]" >&2
    exit 1
fi

if [[ "$CONFIRM" != "--confirm" ]]; then
    echo "=================================================================="
    echo " WARNING: THIS OPERATION WILL OVERWRITE FILES IN $TARGET_DIR!     "
    echo "=================================================================="
    echo "Target Archive: $ARCHIVE"
    echo "Destination:    $TARGET_DIR"
    echo ""
    echo "To execute this restore, pass the --confirm flag:"
    echo "  sudo bash restore_vmail.sh $ARCHIVE --confirm $TARGET_DIR"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# 1. Verify archive integrity prior to restoring
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Verifying mail archive integrity before restore..."
if [[ -f "${SCRIPT_DIR}/verify_backup.sh" ]]; then
    bash "${SCRIPT_DIR}/verify_backup.sh" "$ARCHIVE"
fi

# 2. Source decryption key if needed
ENV_FILE="${SCRIPT_DIR}/../laravel-panel/.env"
ENC_KEY=""
ENC_KEY_PATH=""

if [[ -f "$ENV_FILE" ]]; then
    ENC_KEY="$(grep -E '^BACKUP_ENCRYPTION_KEY=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
    ENC_KEY_PATH="$(grep -E '^BACKUP_ENCRYPTION_KEY_PATH=' "$ENV_FILE" | cut -d '=' -f2- | tr -d '"'\'' ' || echo '')"
fi

if [[ -z "$ENC_KEY" && -n "$ENC_KEY_PATH" && -f "$ENC_KEY_PATH" ]]; then
    ENC_KEY="$(head -n 1 "$ENC_KEY_PATH" | tr -d '\r\n')"
fi

# 3. Ensure target directory exists
mkdir -p "$TARGET_DIR"

# 4. Extract archive preserving permissions and timestamps (with on-the-fly decryption if needed)
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Extracting maildir contents to $TARGET_DIR..."

if [[ "$ARCHIVE" == *.enc ]]; then
    if [[ -z "$ENC_KEY" ]]; then
        echo "[ERROR] Archive is encrypted, but no decryption key (BACKUP_ENCRYPTION_KEY / BACKUP_ENCRYPTION_KEY_PATH) found." >&2
        exit 1
    fi
    echo "  [DECRYPTION] Decrypting AES-256-CBC stream on-the-fly directly to tar extraction..."
    openssl enc -d -aes-256-cbc -pbkdf2 -pass pass:"$ENC_KEY" -in "$ARCHIVE" \
        | tar --extract \
            --gzip \
            --preserve-permissions \
            -C "$TARGET_DIR"
else
    tar --extract \
        --gzip \
        --preserve-permissions \
        --file="$ARCHIVE" \
        -C "$TARGET_DIR"
fi

# 5. Enforce strict vmail ownership (UID/GID 5000:5000) and Maildir permissions
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Enforcing vmail ownership and Maildir permissions..."
if id -u vmail &>/dev/null && id -g vmail &>/dev/null; then
    chown -R vmail:vmail "$TARGET_DIR"
else
    # Fallback to numeric 5000:5000 if vmail user is not yet created
    chown -R 5000:5000 "$TARGET_DIR"
fi

# Secure directory permissions
chmod 750 "$TARGET_DIR"
find "$TARGET_DIR" -type d -exec chmod 700 {} + 2>/dev/null || true
find "$TARGET_DIR" -type f -exec chmod 600 {} + 2>/dev/null || true

# 6. Reload Dovecot to detect restored mailboxes if active
if command -v systemctl &>/dev/null && systemctl is-active --quiet dovecot; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] Reloading Dovecot service..."
    systemctl reload dovecot || true
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Mail storage restoration completed successfully to $TARGET_DIR."
exit 0

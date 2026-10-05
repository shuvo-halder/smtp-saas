#!/bin/bash
# =============================================================================
# restore_vmail.sh — Mail Storage Restoration Script
# Usage: sudo bash restore_vmail.sh <archive_file_path> [--confirm] [target_dir]
# Part of EmailSaaS Step 17 Disaster Recovery Framework
# =============================================================================

set -euo pipefail

ARCHIVE="${1:-}"
CONFIRM="${2:-}"
TARGET_DIR="${3:-/var/vmail}"

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
    echo "[ERROR] Mail storage archive file does not exist: $ARCHIVE" >&2
    echo "Usage: sudo bash restore_vmail.sh <archive.tar.gz> --confirm [/var/vmail]" >&2
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

SCRIPT_DIR="$(dirname "$0")"

# 1. Verify archive integrity prior to restoring
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Verifying mail archive integrity before restore..."
if [[ -f "${SCRIPT_DIR}/verify_backup.sh" ]]; then
    bash "${SCRIPT_DIR}/verify_backup.sh" "$ARCHIVE"
fi

# 2. Ensure target directory exists
mkdir -p "$TARGET_DIR"

# 3. Extract archive preserving permissions and timestamps
echo "[$(date +'%Y-%m-%d %H:%M:%S')] Extracting maildir contents to $TARGET_DIR..."
tar --extract \
    --gzip \
    --preserve-permissions \
    --file="$ARCHIVE" \
    -C "$TARGET_DIR"

# 4. Enforce strict vmail ownership (UID/GID 5000:5000) and Maildir permissions
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

# 5. Reload Dovecot to detect restored mailboxes if active
if command -v systemctl &>/dev/null && systemctl is-active --quiet dovecot; then
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] Reloading Dovecot service..."
    systemctl reload dovecot || true
fi

echo "[$(date +'%Y-%m-%d %H:%M:%S')] Mail storage restoration completed successfully to $TARGET_DIR."
exit 0

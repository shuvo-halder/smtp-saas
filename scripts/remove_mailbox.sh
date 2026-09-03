#!/bin/bash
# =============================================================================
# remove_mailbox.sh — Remove a virtual mailbox
# Usage: sudo bash remove_mailbox.sh <email>
# =============================================================================

set -euo pipefail

EMAIL="${1:-}"
MAIL_VHOSTS_DIR="/var/vmail"
ARCHIVE_DIR="/var/vmail_archive"

[[ -z "$EMAIL" ]] && echo "ERROR: Email required" && exit 1

DOMAIN="${EMAIL#*@}"
LOCAL="${EMAIL%@*}"

echo "[remove_mailbox] Removing mailbox: $EMAIL"

# 2. Archive mailbox directory (don't immediately delete)
MAILDIR="${MAIL_VHOSTS_DIR}/${DOMAIN}/${LOCAL}"
if [[ -d "$MAILDIR" ]]; then
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    mkdir -p "${ARCHIVE_DIR}/${DOMAIN}"
    mv "$MAILDIR" "${ARCHIVE_DIR}/${DOMAIN}/${LOCAL}_${TIMESTAMP}"
    echo "[remove_mailbox] Maildir archived to ${ARCHIVE_DIR}/${DOMAIN}/${LOCAL}_${TIMESTAMP}"
fi

# 3. Reload Postfix
postfix reload

echo "[remove_mailbox] Done. Mailbox $EMAIL deactivated."
exit 0

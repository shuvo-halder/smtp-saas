#!/bin/bash
# =============================================================================
# remove_mailbox.sh — Remove a virtual mailbox
# Usage: sudo bash remove_mailbox.sh <email>
# =============================================================================

set -euo pipefail

EMAIL="${1:-}"
MAIL_VHOSTS_DIR="/var/mail/vhosts"
ARCHIVE_DIR="/var/mail/archive"

source /etc/emailsaas/.env

[[ -z "$EMAIL" ]] && echo "ERROR: Email required" && exit 1

DOMAIN="${EMAIL#*@}"
LOCAL="${EMAIL%@*}"

echo "[remove_mailbox] Removing mailbox: $EMAIL"

# 1. Deactivate in MySQL (soft delete — keep data 30 days)
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" << SQLEOF
UPDATE virtual_users SET is_active = 0 WHERE email = '${EMAIL}';
SQLEOF

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

#!/bin/bash
# =============================================================================
# add_mailbox.sh — Create a new virtual mailbox
# Usage: sudo bash add_mailbox.sh <email> <password> <domain_id> [quota_mb]
# Called by: Laravel PostfixService via shell_exec()
# =============================================================================

set -euo pipefail

EMAIL="${1:-}"
PASSWORD="${2:-}"
DOMAIN_ID="${3:-}"
QUOTA_MB="${4:-1024}"   # Default 1 GB
MAIL_VHOSTS_DIR="/var/vmail"

[[ -z "$EMAIL" ]]    && echo "ERROR: Email required"     && exit 1

# Extract domain from email
DOMAIN="${EMAIL#*@}"
LOCAL="${EMAIL%@*}"

# Validate email format
if ! echo "$EMAIL" | grep -qP '^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$'; then
    echo "ERROR: Invalid email format: $EMAIL"
    exit 1
fi

echo "[add_mailbox] Creating mailbox: $EMAIL"

# 4. Create Maildir directory
MAILDIR="${MAIL_VHOSTS_DIR}/${DOMAIN}/${LOCAL}/"
mkdir -p "${MAILDIR}"/{cur,new,tmp}
chown -R vmail:vmail "${MAILDIR}"
chmod -R 700 "${MAILDIR}"

# 5. Reload Postfix
postfix reload

echo "[add_mailbox] Done. Mailbox $EMAIL created successfully."
exit 0

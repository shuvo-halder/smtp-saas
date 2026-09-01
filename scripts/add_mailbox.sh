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
MAIL_VHOSTS_DIR="/var/mail/vhosts"

source /etc/emailsaas/.env

[[ -z "$EMAIL" ]]    && echo "ERROR: Email required"     && exit 1
[[ -z "$PASSWORD" ]] && echo "ERROR: Password required"  && exit 1
[[ -z "$DOMAIN_ID" ]] && echo "ERROR: Domain ID required" && exit 1

# Extract domain from email
DOMAIN="${EMAIL#*@}"
LOCAL="${EMAIL%@*}"

# Validate email format
if ! echo "$EMAIL" | grep -qP '^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$'; then
    echo "ERROR: Invalid email format: $EMAIL"
    exit 1
fi

echo "[add_mailbox] Creating mailbox: $EMAIL (quota: ${QUOTA_MB}MB)"

# 1. Hash password using Dovecot (SHA512-CRYPT)
HASHED_PASS=$(doveadm pw -s SHA512-CRYPT -p "$PASSWORD")

# 2. Convert MB to bytes for quota storage
QUOTA_BYTES=$(( QUOTA_MB * 1024 * 1024 ))

# 3. Insert into MySQL virtual_users
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" << SQLEOF
INSERT INTO virtual_users (domain_id, email, password, quota)
VALUES (${DOMAIN_ID}, '${EMAIL}', '${HASHED_PASS}', ${QUOTA_BYTES})
ON DUPLICATE KEY UPDATE password='${HASHED_PASS}', quota=${QUOTA_BYTES};
SQLEOF

# 4. Create Maildir directory
MAILDIR="${MAIL_VHOSTS_DIR}/${DOMAIN}/${LOCAL}/"
mkdir -p "${MAILDIR}"/{cur,new,tmp}
chown -R vmail:vmail "${MAILDIR}"
chmod -R 700 "${MAILDIR}"

# 5. Reload Postfix
postfix reload

echo "[add_mailbox] Done. Mailbox $EMAIL created successfully."
exit 0

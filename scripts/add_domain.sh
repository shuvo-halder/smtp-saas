#!/bin/bash
# =============================================================================
# add_domain.sh — Provision a new virtual domain
# Usage: sudo bash add_domain.sh <domain> <user_id>
# Called by: Laravel PostfixService via shell_exec()
# =============================================================================

set -euo pipefail

DOMAIN="${1:-}"
USER_ID="${2:-}"
MAIL_VHOSTS_DIR="/var/mail/vhosts"

# Load DB credentials from env file
source /etc/emailsaas/.env

[[ -z "$DOMAIN" ]]   && echo "ERROR: Domain required"  && exit 1
[[ -z "$USER_ID" ]]  && echo "ERROR: User ID required" && exit 1

# Validate domain format
if ! echo "$DOMAIN" | grep -qP '^[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$'; then
    echo "ERROR: Invalid domain format: $DOMAIN"
    exit 1
fi

echo "[add_domain] Adding domain: $DOMAIN (user_id=$USER_ID)"

# 1. Insert into MySQL virtual_domains
mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" << SQLEOF
INSERT IGNORE INTO virtual_domains (name, user_id) VALUES ('${DOMAIN}', ${USER_ID});
SQLEOF

# 2. Create mailbox directory
DOMAIN_DIR="${MAIL_VHOSTS_DIR}/${DOMAIN}"
mkdir -p "$DOMAIN_DIR"
chown -R vmail:vmail "$DOMAIN_DIR"
chmod 755 "$DOMAIN_DIR"

# 3. Reload Postfix maps
postmap /etc/postfix/mysql-virtual-domains.cf
postfix reload

# 4. Setup DKIM key for this domain
bash "$(dirname "$0")/setup_dkim.sh" "$DOMAIN"

echo "[add_domain] Done. Domain $DOMAIN added successfully."
exit 0

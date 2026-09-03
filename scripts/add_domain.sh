#!/bin/bash
# =============================================================================
# add_domain.sh — Provision a new virtual domain
# Usage: sudo bash add_domain.sh <domain> <user_id>
# Called by: Laravel PostfixService via shell_exec()
# =============================================================================

set -euo pipefail

DOMAIN="${1:-}"
USER_ID="${2:-}"
MAIL_VHOSTS_DIR="/var/vmail"

[[ -z "$DOMAIN" ]]   && echo "ERROR: Domain required"  && exit 1

# Validate domain format
if ! echo "$DOMAIN" | grep -qP '^[a-zA-Z0-9][a-zA-Z0-9\-]{0,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$'; then
    echo "ERROR: Invalid domain format: $DOMAIN"
    exit 1
fi

echo "[add_domain] Adding domain: $DOMAIN"

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

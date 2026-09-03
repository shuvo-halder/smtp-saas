#!/bin/bash
# =============================================================================
# remove_domain.sh — Remove a virtual domain
# Usage: sudo bash remove_domain.sh <domain>
# =============================================================================

set -euo pipefail

DOMAIN="${1:-}"
MAIL_VHOSTS_DIR="/var/vmail"
ARCHIVE_DIR="/var/vmail_archive"

[[ -z "$DOMAIN" ]] && echo "ERROR: Domain required" && exit 1

echo "[remove_domain] Removing domain: $DOMAIN"

# 2. Archive domain mailbox directory
DOMAIN_DIR="${MAIL_VHOSTS_DIR}/${DOMAIN}"
if [[ -d "$DOMAIN_DIR" ]]; then
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    mkdir -p "${ARCHIVE_DIR}"
    mv "$DOMAIN_DIR" "${ARCHIVE_DIR}/${DOMAIN}_${TIMESTAMP}"
    echo "[remove_domain] Maildir archived."
fi

# 3. Remove DKIM keys for this domain
DKIM_KEY_DIR="/etc/opendkim/keys/${DOMAIN}"
if [[ -d "$DKIM_KEY_DIR" ]]; then
    rm -rf "$DKIM_KEY_DIR"
fi
# Remove from OpenDKIM keytable
sed -i "/^default\._domainkey\.${DOMAIN}/d" /etc/opendkim/keytable
sed -i "/^${DOMAIN}/d" /etc/opendkim/signingtable
systemctl reload opendkim

# 4. Reload Postfix
postfix reload

echo "[remove_domain] Done. Domain $DOMAIN removed."
exit 0

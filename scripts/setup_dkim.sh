#!/bin/bash
# =============================================================================
# setup_dkim.sh — Generate DKIM keys and configure OpenDKIM for a domain
# Usage: sudo bash setup_dkim.sh <domain>
# =============================================================================

set -euo pipefail

DOMAIN="${1:-}"
DKIM_SELECTOR="default"
DKIM_DIR="/etc/opendkim/keys"
OPENDKIM_CONF="/etc/opendkim.conf"

[[ -z "$DOMAIN" ]] && echo "ERROR: Domain required" && exit 1

echo "[setup_dkim] Generating DKIM keys for: $DOMAIN"

# 1. Create key directory
mkdir -p "${DKIM_DIR}/${DOMAIN}"

# 2. Generate 2048-bit RSA key pair
opendkim-genkey \
    -b 2048 \
    -d "$DOMAIN" \
    -D "${DKIM_DIR}/${DOMAIN}" \
    -s "$DKIM_SELECTOR" \
    -v

# 3. Set permissions
chown -R opendkim:opendkim "${DKIM_DIR}/${DOMAIN}"
chmod 700 "${DKIM_DIR}/${DOMAIN}"
chmod 600 "${DKIM_DIR}/${DOMAIN}/${DKIM_SELECTOR}.private"

# 4. Configure OpenDKIM keytable
KEYTABLE_FILE="/etc/opendkim/keytable"
SIGNINGTABLE_FILE="/etc/opendkim/signingtable"
TRUSTED_HOSTS_FILE="/etc/opendkim/trusted.hosts"

# Remove old entry if exists
grep -v "^${DKIM_SELECTOR}\._domainkey\.${DOMAIN}" "${KEYTABLE_FILE}" > /tmp/keytable.tmp 2>/dev/null || true
mv /tmp/keytable.tmp "${KEYTABLE_FILE}" 2>/dev/null || true

grep -v "^@${DOMAIN}" "${SIGNINGTABLE_FILE}" > /tmp/signingtable.tmp 2>/dev/null || true
mv /tmp/signingtable.tmp "${SIGNINGTABLE_FILE}" 2>/dev/null || true

# Add new entry
echo "${DKIM_SELECTOR}._domainkey.${DOMAIN} ${DOMAIN}:${DKIM_SELECTOR}:${DKIM_DIR}/${DOMAIN}/${DKIM_SELECTOR}.private" >> "${KEYTABLE_FILE}"
echo "@${DOMAIN} ${DKIM_SELECTOR}._domainkey.${DOMAIN}" >> "${SIGNINGTABLE_FILE}"

# Trusted hosts
if ! grep -q "^${DOMAIN}$" "${TRUSTED_HOSTS_FILE}" 2>/dev/null; then
    echo "${DOMAIN}" >> "${TRUSTED_HOSTS_FILE}"
    echo "*.${DOMAIN}" >> "${TRUSTED_HOSTS_FILE}"
fi

# 5. Main OpenDKIM config (idempotent)
cat > "${OPENDKIM_CONF}" << DKIMEOF
AutoRestart             Yes
AutoRestartRate         10/1h
UMask                   002
Syslog                  yes
SyslogSuccess           Yes
LogWhy                  Yes
Canonicalization        relaxed/simple
ExternalIgnoreList      refile:${TRUSTED_HOSTS_FILE}
InternalHosts           refile:${TRUSTED_HOSTS_FILE}
KeyTable                refile:${KEYTABLE_FILE}
SigningTable            refile:${SIGNINGTABLE_FILE}
Mode                    sv
PidFile                 /run/opendkim/opendkim.pid
SignatureAlgorithm      rsa-sha256
UserID                  opendkim:opendkim
Socket                  inet:8891@localhost
DKIMEOF

# 6. Add OpenDKIM milter to Postfix (if not already there)
postconf -e "milter_protocol = 2"
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = inet:localhost:8891"
postconf -e "non_smtpd_milters = inet:localhost:8891"

# 7. Reload services
systemctl restart opendkim
postfix reload

# 8. Output DNS TXT record
echo ""
echo "═══════════════════════════════════════════════════════"
echo "  DKIM DNS Record for: ${DOMAIN}"
echo "  Add this TXT record to your DNS:"
echo "───────────────────────────────────────────────────────"
echo "  Name: ${DKIM_SELECTOR}._domainkey.${DOMAIN}"
cat "${DKIM_DIR}/${DOMAIN}/${DKIM_SELECTOR}.txt"
echo "═══════════════════════════════════════════════════════"

# Output raw public key for database storage
PUBLIC_KEY=$(grep -v "^-" "${DKIM_DIR}/${DOMAIN}/${DKIM_SELECTOR}.private" | openssl rsa -pubout 2>/dev/null | tr -d '\n' | sed 's/-----BEGIN PUBLIC KEY-----//;s/-----END PUBLIC KEY-----//')

# Write DKIM TXT value to file for Laravel to read
TXT_VALUE=$(cat "${DKIM_DIR}/${DOMAIN}/${DKIM_SELECTOR}.txt" | grep -oP '"[^"]*"' | tr -d '"\n ')
echo "${TXT_VALUE}" > "/tmp/dkim_${DOMAIN}.txt"
echo "DKIM_TXT_VALUE=${TXT_VALUE}"

echo "[setup_dkim] Done. DKIM configured for $DOMAIN."
exit 0

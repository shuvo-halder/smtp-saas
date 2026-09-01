#!/bin/bash
# =============================================================================
# setup_fail2ban.sh — Configure Fail2ban for email server protection
# =============================================================================

set -euo pipefail

echo "[fail2ban] Configuring Fail2ban..."

cat > /etc/fail2ban/jail.local << 'EOF'
[DEFAULT]
bantime  = 3600       ; 1 hour ban
findtime = 600        ; 10 minutes window
maxretry = 5          ; 5 failed attempts
banaction = ufw
ignoreip = 127.0.0.1/8 ::1

# ─── Postfix ───────────────────────────────────────────────────────────────────
[postfix]
enabled  = true
port     = smtp,465,submission
logpath  = /var/log/mail.log
maxretry = 3

[postfix-sasl]
enabled  = true
port     = smtp,465,submission,imap,imaps,pop3,pop3s
logpath  = /var/log/mail.log
maxretry = 3

# ─── Dovecot ───────────────────────────────────────────────────────────────────
[dovecot]
enabled  = true
port     = pop3,pop3s,imap,imaps,submission,465,sieve
logpath  = /var/log/dovecot.log
maxretry = 3

# ─── Roundcube ─────────────────────────────────────────────────────────────────
[roundcube-auth]
enabled  = true
port     = http,https
logpath  = /var/log/roundcube/userlogins.log
maxretry = 5

# ─── Nginx ─────────────────────────────────────────────────────────────────────
[nginx-http-auth]
enabled  = true
port     = http,https
logpath  = /var/log/nginx/error.log

[nginx-limit-req]
enabled  = true
port     = http,https
logpath  = /var/log/nginx/error.log
maxretry = 10

[nginx-botsearch]
enabled  = true
port     = http,https
logpath  = /var/log/nginx/access.log
maxretry = 2
EOF

systemctl restart fail2ban
systemctl enable fail2ban

echo "[fail2ban] Done. Status:"
fail2ban-client status

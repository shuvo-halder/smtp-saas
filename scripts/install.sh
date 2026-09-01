#!/bin/bash
# =============================================================================
# Email SaaS Platform — Full Server Installation Script
# Compatible: Ubuntu 22.04 LTS
# Usage: sudo bash install.sh
# =============================================================================

set -euo pipefail

# ─── Colors ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# ─── Configuration ─────────────────────────────────────────────────────────────
MAIL_DOMAIN="mail.yourdomain.com"       # Primary mail server hostname
DB_NAME="emailsaas"
DB_USER="emailsaas"
DB_PASS="$(openssl rand -base64 24)"    # Auto-generated secure password
MAIL_VHOSTS_DIR="/var/mail/vhosts"
MAIL_USER="vmail"
MAIL_GROUP="vmail"
MAIL_UID=5000

# ─── Root check ────────────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && error "This script must be run as root (sudo)."

info "Starting Email SaaS Installation on Ubuntu 22.04..."

# ─── 1. System Update ──────────────────────────────────────────────────────────
info "Updating system packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
    curl wget git unzip software-properties-common \
    gnupg2 ca-certificates lsb-release apt-transport-https \
    net-tools dnsutils ufw fail2ban
success "System updated."

# ─── 2. Hostname & /etc/hosts ──────────────────────────────────────────────────
info "Configuring hostname..."
hostnamectl set-hostname "$MAIL_DOMAIN"
SERVER_IP=$(curl -s ifconfig.me)
echo "$SERVER_IP $MAIL_DOMAIN" >> /etc/hosts
success "Hostname set to $MAIL_DOMAIN"

# ─── 3. MySQL Installation ─────────────────────────────────────────────────────
info "Installing MySQL 8.0..."
apt-get install -y -qq mysql-server mysql-client

# Secure MySQL + create database
mysql -e "
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    FLUSH PRIVILEGES;
"

# Create mail database schema
mysql "${DB_NAME}" << 'SQLEOF'
CREATE TABLE IF NOT EXISTS `virtual_domains` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50) NOT NULL,
  `user_id`     INT NOT NULL,
  `is_active`   TINYINT(1) DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `virtual_users` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `domain_id`    INT NOT NULL,
  `email`        VARCHAR(100) NOT NULL,
  `password`     VARCHAR(150) NOT NULL,
  `quota`        BIGINT DEFAULT 1073741824,   -- 1 GB in bytes
  `is_active`    TINYINT(1) DEFAULT 1,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  FOREIGN KEY (`domain_id`) REFERENCES `virtual_domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `virtual_aliases` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `domain_id`  INT NOT NULL,
  `source`     VARCHAR(100) NOT NULL,
  `destination` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`domain_id`) REFERENCES `virtual_domains`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQLEOF

success "MySQL installed and database created."

# ─── 4. Postfix Installation ───────────────────────────────────────────────────
info "Installing Postfix..."
debconf-set-selections <<< "postfix postfix/mailname string ${MAIL_DOMAIN}"
debconf-set-selections <<< "postfix postfix/main_mailer_type string 'Internet Site'"
apt-get install -y -qq postfix postfix-mysql libsasl2-modules
success "Postfix installed."

# ─── 5. Dovecot Installation ───────────────────────────────────────────────────
info "Installing Dovecot..."
apt-get install -y -qq \
    dovecot-core dovecot-imapd dovecot-pop3d \
    dovecot-lmtpd dovecot-mysql
success "Dovecot installed."

# ─── 6. Virtual Mail User ──────────────────────────────────────────────────────
info "Creating vmail system user..."
groupadd -g ${MAIL_UID} ${MAIL_GROUP} 2>/dev/null || true
useradd -g ${MAIL_GROUP} -u ${MAIL_UID} ${MAIL_USER} -d ${MAIL_VHOSTS_DIR} -m -s /usr/sbin/nologin 2>/dev/null || true
mkdir -p ${MAIL_VHOSTS_DIR}
chown -R ${MAIL_USER}:${MAIL_GROUP} ${MAIL_VHOSTS_DIR}
success "vmail user created (UID: ${MAIL_UID})."

# ─── 7. Nginx Installation ─────────────────────────────────────────────────────
info "Installing Nginx..."
apt-get install -y -qq nginx
systemctl enable nginx
success "Nginx installed."

# ─── 8. PHP 8.3 Installation ───────────────────────────────────────────────────
info "Installing PHP 8.3..."
add-apt-repository -y ppa:ondrej/php
apt-get update -qq
apt-get install -y -qq \
    php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-bcmath php8.3-curl php8.3-zip php8.3-intl php8.3-redis \
    php8.3-gd php8.3-imap php8.3-cli
success "PHP 8.3 installed."

# ─── 9. Redis Installation ─────────────────────────────────────────────────────
info "Installing Redis..."
apt-get install -y -qq redis-server
systemctl enable redis-server
success "Redis installed."

# ─── 10. Composer ──────────────────────────────────────────────────────────────
info "Installing Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
success "Composer installed."

# ─── 11. Certbot (Let's Encrypt) ───────────────────────────────────────────────
info "Installing Certbot..."
apt-get install -y -qq certbot python3-certbot-nginx
success "Certbot installed."

# ─── 12. OpenDKIM ──────────────────────────────────────────────────────────────
info "Installing OpenDKIM..."
apt-get install -y -qq opendkim opendkim-tools
mkdir -p /etc/opendkim/keys
chown -R opendkim:opendkim /etc/opendkim
success "OpenDKIM installed."

# ─── 13. SpamAssassin + ClamAV ─────────────────────────────────────────────────
info "Installing SpamAssassin & ClamAV..."
apt-get install -y -qq spamassassin spamc clamav clamav-daemon amavisd-new
freshclam
systemctl enable spamassassin clamav-daemon amavisd
success "Spam & virus protection installed."

# ─── 14. Roundcube Webmail ─────────────────────────────────────────────────────
info "Installing Roundcube..."
apt-get install -y -qq roundcube roundcube-mysql
success "Roundcube installed."

# ─── 15. Copy Config Files ─────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PARENT_DIR="$(dirname "$SCRIPT_DIR")"

info "Copying configuration files..."

# Postfix
cp "${PARENT_DIR}/postfix-config/main.cf"                      /etc/postfix/main.cf
cp "${PARENT_DIR}/postfix-config/master.cf"                    /etc/postfix/master.cf
cp "${PARENT_DIR}/postfix-config/mysql-virtual-domains.cf"     /etc/postfix/mysql-virtual-domains.cf
cp "${PARENT_DIR}/postfix-config/mysql-virtual-mailboxes.cf"   /etc/postfix/mysql-virtual-mailboxes.cf
cp "${PARENT_DIR}/postfix-config/mysql-virtual-aliases.cf"     /etc/postfix/mysql-virtual-aliases.cf

# Inject DB credentials
for f in /etc/postfix/mysql-virtual-*.cf; do
    sed -i "s/__DB_USER__/${DB_USER}/g; s/__DB_PASS__/${DB_PASS}/g; s/__DB_NAME__/${DB_NAME}/g" "$f"
done
chmod 640 /etc/postfix/mysql-virtual-*.cf
chown root:postfix /etc/postfix/mysql-virtual-*.cf

# Dovecot
cp "${PARENT_DIR}/dovecot-config/dovecot.conf"            /etc/dovecot/dovecot.conf
cp "${PARENT_DIR}/dovecot-config/dovecot-sql.conf.ext"    /etc/dovecot/dovecot-sql.conf.ext
cp "${PARENT_DIR}/dovecot-config/conf.d/10-auth.conf"     /etc/dovecot/conf.d/10-auth.conf
cp "${PARENT_DIR}/dovecot-config/conf.d/10-mail.conf"     /etc/dovecot/conf.d/10-mail.conf
cp "${PARENT_DIR}/dovecot-config/conf.d/10-master.conf"   /etc/dovecot/conf.d/10-master.conf
cp "${PARENT_DIR}/dovecot-config/conf.d/10-ssl.conf"      /etc/dovecot/conf.d/10-ssl.conf

sed -i "s/__DB_USER__/${DB_USER}/g; s/__DB_PASS__/${DB_PASS}/g; s/__DB_NAME__/${DB_NAME}/g" /etc/dovecot/dovecot-sql.conf.ext
chmod 640 /etc/dovecot/dovecot-sql.conf.ext

success "Config files copied."

# ─── 16. UFW Firewall ──────────────────────────────────────────────────────────
info "Configuring firewall..."
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 25/tcp    # SMTP
ufw allow 587/tcp   # SMTP Submission
ufw allow 465/tcp   # SMTP SSL
ufw allow 143/tcp   # IMAP
ufw allow 993/tcp   # IMAP SSL
ufw allow 110/tcp   # POP3
ufw allow 995/tcp   # POP3 SSL
ufw allow 80/tcp    # HTTP
ufw allow 443/tcp   # HTTPS
ufw --force enable
success "Firewall configured."

# ─── 17. Start Services ────────────────────────────────────────────────────────
info "Starting all services..."
systemctl restart postfix dovecot nginx php8.3-fpm mysql redis-server
systemctl enable postfix dovecot nginx php8.3-fpm mysql redis-server
success "All services started."

# ─── 18. Obtain SSL Certificate ────────────────────────────────────────────────
info "Obtaining SSL certificate for ${MAIL_DOMAIN}..."
certbot certonly --standalone --non-interactive --agree-tos \
    --email admin@${MAIL_DOMAIN} \
    -d ${MAIL_DOMAIN} \
    --pre-hook "systemctl stop nginx" \
    --post-hook "systemctl start nginx"
success "SSL certificate obtained."

# ─── Done ──────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║        Email SaaS Server Installation Complete!          ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  ${BLUE}Server Hostname:${NC} ${MAIL_DOMAIN}"
echo -e "  ${BLUE}Server IP:${NC}       ${SERVER_IP}"
echo -e "  ${BLUE}DB Name:${NC}         ${DB_NAME}"
echo -e "  ${BLUE}DB User:${NC}         ${DB_USER}"
echo -e "  ${BLUE}DB Password:${NC}     ${DB_PASS}  ${RED}(SAVE THIS NOW!)${NC}"
echo ""
echo -e "  ${YELLOW}Next Steps:${NC}"
echo -e "    1. Save the DB password above"
echo -e "    2. Deploy Laravel panel: cd laravel-panel && composer install"
echo -e "    3. Configure .env with the DB credentials"
echo -e "    4. Run: php artisan migrate"
echo -e "    5. Run: bash scripts/setup_dkim.sh"
echo -e "    6. Run: bash scripts/setup_fail2ban.sh"
echo ""

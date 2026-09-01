# EmailSaaS — Managed Business Email Platform

> Google Workspace / Microsoft 365 এর সাশ্রয়ী বিকল্প। নিজের server এ চালান, নিজের brand এ বিক্রি করুন।

## 📦 Project Structure

```
email-saas/
├── scripts/                    # Server automation scripts
│   ├── install.sh              # Full server setup (Ubuntu 22.04)
│   ├── add_domain.sh           # Domain provisioning
│   ├── add_mailbox.sh          # Mailbox creation
│   ├── remove_mailbox.sh       # Mailbox removal
│   ├── remove_domain.sh        # Domain removal
│   ├── setup_dkim.sh           # DKIM key generation
│   └── setup_fail2ban.sh       # Security hardening
├── postfix-config/             # Postfix MTA configuration
│   ├── main.cf                 # Main Postfix config
│   ├── master.cf               # Service definitions
│   ├── mysql-virtual-domains.cf
│   ├── mysql-virtual-mailboxes.cf
│   └── mysql-virtual-aliases.cf
├── dovecot-config/             # Dovecot IMAP/LMTP
│   ├── dovecot.conf
│   ├── dovecot-sql.conf.ext    # MySQL auth backend
│   └── conf.d/
│       ├── 10-auth.conf
│       ├── 10-mail.conf
│       ├── 10-master.conf
│       └── 10-ssl.conf
├── roundcube-config/           # Webmail configuration
│   └── config.inc.php
├── nginx-config/               # Web server config
│   └── emailsaas.conf
└── laravel-panel/              # Customer & Admin panel
    ├── app/
    │   ├── Models/             # Eloquent models
    │   │   ├── User.php
    │   │   ├── Domain.php
    │   │   ├── Mailbox.php
    │   │   ├── Plan.php
    │   │   └── Invoice.php
    │   ├── Http/Controllers/
    │   │   ├── DomainController.php
    │   │   ├── MailboxController.php
    │   │   ├── BillingController.php
    │   │   └── Admin/AdminDashboardController.php
    │   └── Services/
    │       ├── PostfixService.php      # Mail server provisioning
    │       ├── DnsVerificationService.php
    │       └── BillingService.php      # SSLCommerz payment
    ├── database/migrations/
    ├── resources/views/
    └── routes/web.php
```

---

## 🚀 Deployment Guide

### Step 1: Server Requirements
- Ubuntu 22.04 LTS VPS
- RAM: ন্যূনতম 2GB (4GB+ recommended)
- Storage: ন্যূনতম 20GB
- Public IP with PTR (Reverse DNS) record

### Step 2: DNS Setup (আগে করুন)
আপনার mail server এর জন্য:
```
mail.yourdomain.com    A      <YOUR_SERVER_IP>
panel.yourdomain.com   A      <YOUR_SERVER_IP>
webmail.yourdomain.com A      <YOUR_SERVER_IP>
```

**PTR Record** (Reverse DNS): আপনার VPS provider এ server IP এর জন্য `mail.yourdomain.com` PTR record সেট করুন।

### Step 3: Server Installation
```bash
# সার্ভারে SSH করুন
ssh root@YOUR_SERVER_IP

# Project clone করুন
git clone <your-repo-url> /opt/emailsaas
cd /opt/emailsaas

# install.sh এ আপনার domain সেট করুন
nano scripts/install.sh   # MAIL_DOMAIN="mail.yourdomain.com" পরিবর্তন করুন

# Installation চালান
sudo bash scripts/install.sh
```

### Step 4: Laravel Panel Deploy
```bash
cd /opt/emailsaas/laravel-panel

# Dependencies install
composer install --no-dev --optimize-autoloader

# Environment setup
cp .env.example .env
nano .env    # DB password, domain, SSLCommerz credentials সেট করুন

# Application setup
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan config:cache
php artisan route:cache

# Web server permissions
chown -R www-data:www-data /opt/emailsaas/laravel-panel
chmod -R 755 storage bootstrap/cache
```

### Step 5: Nginx Configuration
```bash
cp /opt/emailsaas/nginx-config/emailsaas.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/emailsaas.conf /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

### Step 6: SSL Certificates
```bash
certbot certonly --nginx \
  -d panel.yourdomain.com \
  -d webmail.yourdomain.com
```

### Step 7: Sudo Permissions for Laravel
```bash
visudo
# নিচের lines যোগ করুন:
www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/add_domain.sh
www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/remove_domain.sh
www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/add_mailbox.sh
www-data ALL=(ALL) NOPASSWD: /opt/emailsaas/scripts/remove_mailbox.sh
```

### Step 8: Security Hardening
```bash
sudo bash scripts/setup_fail2ban.sh
```

---

## 💰 Default Pricing Plans

| Plan | Price/Month | Domains | Mailboxes | Storage |
|------|-------------|---------|-----------|---------|
| Starter | ৳199 | 1 | 5/domain | 1 GB each |
| Business | ৳499 | 3 | 20/domain | 5 GB each |
| Enterprise | ৳1499 | Unlimited | Unlimited | 50 GB each |

---

## 🔧 Admin Login

```
URL:      https://panel.yourdomain.com/admin
Email:    admin@yourdomain.com
Password: ChangeMe@1234!  ← প্রথম login এ পরিবর্তন করুন!
```

---

## 📧 Email Ports

| Service | Port | Protocol |
|---------|------|----------|
| SMTP (Incoming) | 25 | Plain |
| SMTP Submission | 587 | STARTTLS |
| SMTPS | 465 | SSL/TLS |
| IMAP | 143 | STARTTLS |
| IMAPS | 993 | SSL/TLS |

---

## 🛡️ Security Features

- ✅ TLS 1.2/1.3 encryption
- ✅ DKIM signing (per domain)
- ✅ SPF verification
- ✅ DMARC policy
- ✅ Fail2ban (brute force protection)
- ✅ SpamAssassin (spam filtering)
- ✅ ClamAV (virus scanning)
- ✅ Rate limiting (Postfix + Nginx)
- ✅ Firewall (UFW)

---

## 📝 Tech Stack

| Component | Technology |
|-----------|-----------|
| OS | Ubuntu 22.04 LTS |
| Web Server | Nginx |
| SMTP | Postfix |
| IMAP | Dovecot |
| Webmail | Roundcube |
| Backend | Laravel 11 (PHP 8.3) |
| Database | MySQL 8.0 |
| Cache/Queue | Redis |
| SSL | Let's Encrypt |
| DKIM | OpenDKIM |
| Spam | SpamAssassin |
| Antivirus | ClamAV |
| Payment | SSLCommerz |

---

## 📞 Support

কোনো সমস্যায় issue তৈরি করুন অথবা যোগাযোগ করুন।

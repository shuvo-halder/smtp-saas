# Infrastructure

EmailSaaS is deployed as a consolidated stack on an Ubuntu Linux VPS.

## Core Infrastructure

### Next.js Frontend
- **Runtime:** Node.js v20+
- **Process Manager:** PM2
- **Port:** 3000
- **Routing:** Nginx reverse proxies wildcard `*.mailsaas.com` to port 3000.

### Laravel API Backend
- **Runtime:** PHP 8.2+ (FPM)
- **Web Server:** Nginx (`panel.mailsaas.com`)
- **Queue Manager:** Supervisor (`/etc/supervisor/conf.d/mailsaas-worker.conf`)
- **Scheduler:** Cron (`* * * * * cd /var/www/email-saas/laravel-panel && php artisan schedule:run`)

### Mail Delivery (Postfix MTA)
- **Ports:** 25 (SMTP), 587 (Submission), 465 (SMTPS)
- **Configuration:** `/etc/postfix/main.cf`
- **Data Source:** MariaDB (`mysql-virtual-mailbox-domains.cf`, `mysql-virtual-mailbox-maps.cf`, `mysql-virtual-alias-maps.cf`)
- **Delivery Path:** Routes virtual mail to `/var/vmail/`

### Mail Reading (Dovecot MDA/IMAP)
- **Ports:** 143 (IMAP), 993 (IMAPS)
- **Configuration:** `/etc/dovecot/dovecot.conf`
- **Authentication:** `dovecot-sql.conf.ext` queries the `mailboxes` table (SHA512-CRYPT).
- **Master User:** Enabled for Webmail SSO.

### Webmail Client (Roundcube)
- **Web Server:** Nginx (`webmail.mailsaas.com`)
- **Auth Flow:** Intercepts 60s OTPs from Redis and executes Dovecot Master User login.

## Filesystem Boundaries

| Path | Purpose | Permissions |
|---|---|---|
| `/var/vmail` | Active maildir storage | `vmail:vmail` (5000:5000) |
| `/var/vmail_archive` | Soft-deleted maildir archives | `vmail:vmail` (5000:5000) |
| `/etc/opendkim/keys` | Domain DKIM private keys | `opendkim:opendkim` |
| `/var/www/email-saas/scripts` | Directory generating bash scripts | `root:root` (chmod +x) |

**Security Note:** `PostfixService.php` invokes shell scripts via `sudo` requiring specific `/etc/sudoers` bypass configurations for `www-data` to execute scripts located solely in `/var/www/email-saas/scripts/`.

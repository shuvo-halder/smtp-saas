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
  - `tenant:suspend-expired`: Runs every minute to enforce subscription lifecycle.
  - `outbound:usage-sync`: Runs hourly (`0 * * * *`) with `withoutOverlapping(15)` and atomic lock `Cache::lock('outbound_usage_sync_lock', 600)` to sync Redis counters to MariaDB `tenant_outbound_usage`.

### Mail Delivery (Postfix MTA)
- **Ports:** 25 (SMTP), 587 (Submission), 465 (SMTPS)
- **Configuration:** `/etc/postfix/main.cf`
- **Data Source:** MariaDB (`mysql-virtual-mailbox-domains.cf`, `mysql-virtual-mailbox-maps.cf`, `mysql-virtual-alias-maps.cf`, `mysql-virtual-sender-login-maps.cf`)
- **Delivery Path:** Routes virtual mail to `/var/vmail/`
- **Outbound Quota Enforcement:** `smtpd_data_restrictions` delegates to Laravel Policy Daemon at `127.0.0.1:10031` with `smtpd_policy_service_default_action = DUNNO` (fail-open).

### SMTP Policy Daemon (Outbound Policy & Abuse Recording - Step 12 / Step 16B.4)
- **Command:** `php artisan policy:serve --host=127.0.0.1 --port=10031`
- **Process Manager:** Supervisor (`/etc/supervisor/conf.d/mailsaas-policy.conf`)
- **Protocol:** Postfix Policy Delegation Protocol (TCP stream)
- **Log Path:** `storage/logs/policy.log`
- **Quota & Abuse Ledger Integration:** Enforces tenant and mailbox outbound recipient quotas atomically in Redis (`OutboundQuotaService`). On quota rejection, records an abuse incident in `abuse_incidents` via `AbuseIncidentService` using an atomic Redis `SET NX` daily cooldown to prevent high-frequency write amplification. Persistence executes fail-safely without altering or delaying SMTP quota rejection (`REJECT 554 5.7.1`).

### Mail Log Parser Daemon (Step 15 / Step 16B.4)
- **Command:** `php artisan mail:process-log`
- **Process Manager:** Supervisor (`/etc/supervisor/conf.d/mailsaas-log-parser.conf`)
- **Telemetry Pipeline:** Tails Postfix/Dovecot delivery logs (`/var/log/mail.log`), correlates Queue IDs, tracks delivery outcomes, detects bounce patterns (daily hard bounce limits, consecutive bounce streaks, high bounce rates), and automatically records persistent abuse incidents in `abuse_incidents` via `AbuseDetectionService` -> `AbuseIncidentService`.

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

## Backup & Disaster Recovery Infrastructure (Step 17)

EmailSaaS provides an enterprise backup and disaster recovery subsystem protecting both relational state (MariaDB/MySQL) and mail object storage (`/var/vmail`).

### Backup Storage Layout

| Path | Purpose | Permissions |
|---|---|---|
| `storage/backups/db` (or `/var/backups/emailsaas/db`) | Transaction-safe compressed database dumps (`.sql.gz`) with SHA-256 manifests | `0700` directory, `0600` archives |
| `storage/backups/vmail` (or `/var/backups/emailsaas/vmail`) | Maildir storage archives (`.tar.gz`) preserving numeric ownership (5000:5000) with manifests | `0700` directory, `0600` archives |

### Artisan Console Commands

- `backup:run`: Master execution suite with optional `--verify`, `--only-db`, `--only-vmail`, and `--no-prune`.
- `backup:database`: Generates transaction-safe gzip database dump with SHA-256 manifest.
- `backup:vmail`: Archives `/var/vmail` Maildir directory with SHA-256 manifest.
- `backup:verify`: Validates physical readability, gzip stream integrity, SHA-256 checksums, and optional isolated restore.
- `backup:status`: Displays tabular health status, archive ages, storage footprint, and offsite state.
- `backup:prune`: Enforces retention policies (7 daily, 4 weekly, 3 monthly) with non-negotiable protection of newest and single backups.

### Native Linux Shell Scripts

- `scripts/backup_db.sh`: Native `mariadb-dump` / `mysqldump` with `--single-transaction --quick --routines --triggers --events --hex-blob`, gzip compression, atomic `.tmp` promotion, and `flock` concurrency locking.
- `scripts/backup_vmail.sh`: Native tar archive of `/var/vmail` with `--numeric-owner --preserve-permissions`, gzip compression, and `flock` locking.
- `scripts/verify_backup.sh`: Verifies archive readability, `gzip -t` stream integrity, and SHA-256 match against manifest.
- `scripts/restore_db.sh`: Interactive database restoration from verified `.sql.gz` archive with safety confirmations.
- `scripts/restore_vmail.sh`: Mail storage restoration from verified `.tar.gz` archive with automatic `vmail:vmail` (5000:5000) ownership and 700/600 permissions enforcement.
- `scripts/disaster_recovery.sh`: End-to-end bare-metal / disaster recovery orchestrator coordinating database restoration, maildir extraction, cache clearing, and service restarts.

### Automated Scheduling

Registered in `laravel-panel/routes/console.php`:
- `backup:run --verify`: Scheduled daily at `02:00 UTC` (`withoutOverlapping(30)`).
- `backup:prune`: Scheduled daily at `03:00 UTC` (`withoutOverlapping(15)`).

### Offsite Replication Posture

- **Status:** `OFFSITE BACKUP — PENDING INFRASTRUCTURE`
- Local snapshots are fully automated and verified. Remote synchronization to encrypted S3-compatible cold storage or secondary SSH vault is architected and pending infrastructure provisioning.

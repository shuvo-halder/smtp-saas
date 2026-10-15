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
  - `tenant:suspend-expired`: Runs every minute (`withoutOverlapping(10)`) to enforce subscription lifecycle.
  - `outbound:usage-sync`: Runs hourly (`0 * * * *`) with `withoutOverlapping(15)` and atomic lock `Cache::lock('outbound_usage_sync_lock', 600)` to sync Redis counters to MariaDB `tenant_outbound_usage`.
  - `mail:process-log`: Runs every 5 minutes with `withoutOverlapping(10)` and atomic lock `Cache::lock('mail_process_log_lock', 300)`.
  - `backup:run --verify`: Runs daily at 02:00 UTC (`withoutOverlapping(30)`).
  - `backup:prune`: Runs daily at 03:00 UTC (`withoutOverlapping(15)`).
  - `scheduled-tasks:prune`: Runs daily at 04:00 UTC (`withoutOverlapping(15)`) to prune task execution records older than 7 days.

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

## Backup & Disaster Recovery Infrastructure (Step 17 & 17.2)

EmailSaaS provides an enterprise backup and disaster recovery subsystem protecting both relational state (MariaDB/MySQL) and mail object storage (`/var/vmail`).

### Backup Storage Layout

| Path | Purpose | Permissions |
|---|---|---|
| `storage/backups/db` (or `/var/backups/emailsaas/db`) | Transaction-safe compressed and AES-256 encrypted database dumps (`.sql.gz`, `.sql.gz.enc`) with SHA-256 manifests | `0700` directory, `0600` archives |
| `storage/backups/vmail` (or `/var/backups/emailsaas/vmail`) | Maildir storage archives (`.tar.gz`, `.tar.gz.enc`) preserving numeric ownership (5000:5000) with manifests | `0700` directory, `0600` archives |

### Encryption at Rest (Step 17.2)
- Backups support system-level AES-256-CBC PBKDF2 encryption at rest.
- Encryption keys are configured via `BACKUP_ENCRYPTION_KEY` or `BACKUP_ENCRYPTION_KEY_PATH` outside backup directories.
- If encryption is enabled and key is missing, operations fail closed immediately.

### Artisan Console Commands

- `backup:run`: Master execution suite with optional `--verify`, `--sync-offsite`, `--only-db`, `--only-vmail`, and `--no-prune`.
- `backup:database`: Generates transaction-safe gzip database dump (optionally AES-256 encrypted) with SHA-256 manifest.
- `backup:vmail`: Archives `/var/vmail` Maildir directory (optionally AES-256 encrypted) with SHA-256 manifest.
- `backup:verify`: Validates physical readability, OpenSSL envelope, gzip stream integrity, SHA-256 checksums, and optional isolated restore.
- `backup:status`: Displays tabular health status, encryption posture, archive ages, storage footprint, and offsite state.
- `backup:prune`: Enforces calendar-aware GFS retention policies (7 daily, 4 weekly, 3 monthly) with non-negotiable protection of newest and single backups.

### Native Linux Shell Scripts

- `scripts/backup_db.sh`: Native `mariadb-dump` / `mysqldump` with `--single-transaction --quick --routines --triggers --events --hex-blob`, gzip compression, optional OpenSSL AES-256-CBC encryption, atomic `.tmp` promotion, and `flock` concurrency locking.
- `scripts/backup_vmail.sh`: Native tar archive of `/var/vmail` with `--numeric-owner --preserve-permissions`, gzip compression, optional OpenSSL AES-256-CBC encryption, and `flock` locking.
- `scripts/verify_backup.sh`: Verifies archive readability, OpenSSL envelope / `gzip -t` stream integrity, and SHA-256 match against manifest.
- `scripts/restore_db.sh`: Database restoration from verified `.sql.gz` or `.sql.gz.enc` archive with stream decryption directly to database engine and safety confirmations.
- `scripts/restore_vmail.sh`: Mail storage restoration from verified `.tar.gz` or `.tar.gz.enc` archive with stream decryption, automatic `vmail:vmail` (5000:5000) ownership, and 700/600 permissions enforcement.
- `scripts/sync_offsite.sh`: Provider-neutral offsite replication supporting `rsync`, `scp`, `local`, and `custom` transports with fail-safe local preservation.
- `scripts/disaster_recovery.sh`: Hardened bare-metal / disaster recovery orchestrator coordinating database restoration, maildir extraction, cache clearing, migration status inspection, controlled migration execution (`--run-migrations`), and service restarts.

### Automated Scheduling & Installation

- Registered in `laravel-panel/routes/console.php`:
  - `tenant:suspend-expired`: Scheduled every minute (`withoutOverlapping(10)`).
  - `outbound:usage-sync`: Scheduled hourly (`withoutOverlapping(15)`).
  - `mail:process-log`: Scheduled every 5 minutes (`withoutOverlapping(10)`).
  - `backup:run --verify`: Scheduled daily at `02:00 UTC` (`withoutOverlapping(30)`).
  - `backup:prune`: Scheduled daily at `03:00 UTC` (`withoutOverlapping(15)`).
  - `scheduled-tasks:prune`: Scheduled daily at `04:00 UTC` (`withoutOverlapping(15)`).
- Idempotently installed in `scripts/install.sh` (Step 19):
  `* * * * * cd /var/www/email-saas/laravel-panel && php artisan schedule:run >> /dev/null 2>&1`

### Offsite Replication & Recovery Status Matrix

| Component | Status | Verification Notes |
|---|---|---|
| Local Snapshots & Verification | `VERIFIED` | Automated SHA-256 digests and isolated restore tests pass. |
| Encryption at Rest | `VERIFIED` | OpenSSL AES-256-CBC PBKDF2 cross-compatible between PHP and bash. |
| Offsite Transport Abstraction | `VERIFIED` | `BackupOffsiteService` and `sync_offsite.sh` implemented with fail-safe semantics. |
| Real MariaDB Engine Restore | `PARTIALLY VERIFIED` | SQL dump syntax, transactions, and schema verified; live daemon pending remote infrastructure (`PENDING INFRASTRUCTURE`). |
| Real Dovecot MDA Restore | `PARTIALLY VERIFIED` | Maildir extraction and numeric permissions verified; live Dovecot indexing pending remote infrastructure (`PENDING INFRASTRUCTURE`). |
| Point-in-Time Recovery (PITR) | `NOT IMPLEMENTED` | Architecture relies on daily point-of-backup snapshots (Option B). |
| Standalone Backup Status HTTP API | `REQUIRES ARCHITECTURE APPROVAL` | Step 18 governance enforced; tested 404. |

---

## Staging Deployment & Live Infrastructure Acceptance Audit (Step 20)

### Staging Infrastructure Audit Findings
1. **Installer Parameterization Gap (`scripts/install.sh`):**
   - Hardcoded values: `MAIL_DOMAIN="mail.yourdomain.com"`, `DB_NAME="emailsaas"`, `DB_USER="emailsaas"`.
   - Hostname modification: unconditionally runs `hostnamectl set-hostname $MAIL_DOMAIN`.
   - Firewall reset: unconditionally executes `ufw --force reset` and enables default deny incoming, which terminates active SSH sessions if non-standard SSH ports are used.
   - Let's Encrypt validation: attempts standalone certbot against `mail.yourdomain.com`, which requires public DNS resolution.
   - **Remediation Requirement:** `scripts/install.sh` must be refactored to accept flags (e.g. `--domain=staging.example.com`, `--staging`, `--skip-ssl`, `--skip-ufw`) before running on shared or staging infrastructure.
2. **Database Isolation & Network Boundary (`laravel-panel/.env`):**
   - The default `.env` configuration contains `DB_HOST=103.131.144.61`.
   - In staging or local development, attempting to run database-driven cache, queues, or migrations against this external IP causes TCP timeouts or risks accidental production data corruption.
   - All staging operations must strictly enforce `DB_HOST=127.0.0.1` or dedicated staging database containers.
3. **Scheduler Verification:**
   - Evaluated schedule list under isolated configuration (`php artisan schedule:list`).
   - Confirmed all 6 scheduled tasks (`tenant:suspend-expired`, `outbound:usage-sync`, `mail:process-log`, `backup:run`, `backup:prune`, `scheduled-tasks:prune`) are registered with exact frequencies and overlap mutexes.

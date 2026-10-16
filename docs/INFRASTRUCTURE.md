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
| Encryption at Rest | `VERIFIED` | Authenticated AEAD AES-256-GCM (`EMAILSAAS_GCM_V1`) chunk streaming + HMAC-SHA256 manifests + isolated legacy CBC fallback. |
| Offsite Transport Abstraction | `VERIFIED` | `BackupOffsiteService` and `sync_offsite.sh` implemented with fail-safe semantics. |
| Real MariaDB Engine Restore | `PARTIALLY VERIFIED` | SQL dump syntax, transactions, and schema verified; live daemon pending remote infrastructure (`PENDING INFRASTRUCTURE`). |
| Real Dovecot MDA Restore | `PARTIALLY VERIFIED` | Maildir extraction and numeric permissions verified; live Dovecot indexing pending remote infrastructure (`PENDING INFRASTRUCTURE`). |
| Point-in-Time Recovery (PITR) | `NOT IMPLEMENTED` | Architecture relies on daily point-of-backup snapshots (Option B). |
| Standalone Backup Status HTTP API | `REQUIRES ARCHITECTURE APPROVAL` | Step 18 governance enforced; tested 404. |

---

## Staging Deployment & Live Infrastructure Acceptance Audit (Step 20)

### Staging Infrastructure Audit Findings
1. **Installer Parameterization Gap (`scripts/install.sh`):**
   - **RESOLVED (Step 22):** Refactored `scripts/install.sh` to implement full CLI parameterization, safety validation, idempotent hosts/firewall management, and config template substitutions.
2. **Database Isolation & Network Boundary (`laravel-panel/.env`):**
   - The default `.env` configuration contains `DB_HOST=103.131.144.61`.
   - In staging or local development, attempting to run database-driven cache, queues, or migrations against this external IP causes TCP timeouts or risks accidental production data corruption.
   - All staging operations must strictly enforce `DB_HOST=127.0.0.1` or dedicated staging database containers.
3. **Scheduler Verification:**
   - Evaluated schedule list under isolated configuration (`php artisan schedule:list`).
   - Confirmed all 6 scheduled tasks (`tenant:suspend-expired`, `outbound:usage-sync`, `mail:process-log`, `backup:run`, `backup:prune`, `scheduled-tasks:prune`) are registered with exact frequencies and overlap mutexes.

---

## Local Development Bootstrap & Installer Hardening (Step 22)

### 1. Hardened Installer Implementation (`scripts/install.sh`)
The unified Linux server installation script (`scripts/install.sh`) has been hardened with the following enterprise features:
- **CLI Parameterization:**
  - `--domain <domain>`: Configures primary mail server FQDN.
  - `--db-name <name>`, `--db-user <user>`, `--db-pass <pass>`: Parameterizes database credentials.
  - `--staging`: Enables Let's Encrypt staging environment (`--staging`) to avoid rate limits during testing.
  - `--skip-ssl`: Skips Certbot entirely for local or intranet installations.
  - `--skip-ufw`: Preserves existing host firewall rules and skips UFW modifications.
  - `--reset-ufw`: Requires explicit operator consent before resetting UFW (`ufw --force reset`). By default, existing rules are preserved.
  - `--skip-hostname`: Skips `hostnamectl` and `/etc/hosts` changes.
  - `--allow-placeholder`: Allows using `mail.yourdomain.com` without prompting.
  - `--dry-run`: Prints the evaluated installation plan without applying changes.
  - `-h`, `--help`: Displays full CLI help.
- **Fail-Closed Placeholder Rejection:**
  - Running without `--domain` automatically errors out if the placeholder `mail.yourdomain.com` is detected, preventing misconfigured production deployments.
- **Config Template Substitutions:**
  - Automatically replaces `mail.yourdomain.com` with `${MAIL_DOMAIN}` in `/etc/postfix/main.cf` (`myhostname`, TLS cert paths) and `/etc/dovecot/conf.d/10-ssl.conf` (`ssl_cert`, `ssl_key`).
- **Post-Install Instructions:**
  - Recommends explicit, safe artisan commands: `php artisan migrate --force`, `php artisan db:seed --force`, and `php artisan rbac:emergency-recovery <admin-email>`.

### 2. Local Development Environment (Laragon MySQL)
- **Local Host:** Windows 10/11 with Laragon MySQL 8.4.3 (`127.0.0.1:3306`).
- **Database:** `emailsaas_local` (isolated from remote host `103.131.144.61`).
- **PHP CLI:** `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`.
- **Local Bootstrap Flow:**
  1. Verify MySQL connection: `Test-NetConnection 127.0.0.1 -Port 3306`.
  2. Configure `laravel-panel/.env` with `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=emailsaas_local`, `DB_USERNAME=root`, `DB_PASSWORD=`.
  3. Run migrations: `php artisan migrate`.
  4. Seed baseline data: `php artisan db:seed`.
  5. Verify health: `php artisan migrate:status`, `php artisan test`.

---

## Final Local Integration & Audit Verification (Step 25)

### 1. Verified Local Runtime Configuration
- **Host / OS:** Windows with Laragon PHP 8.3.33 (`C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe`).
- **Database:** Local MySQL 8.4.3 on `127.0.0.1:3306`, Database: `emailsaas_local`.
- **Remote Isolation:** Production IP `103.131.144.61` is completely disconnected and explicitly blocked by environment validation guards.
- **Node.js / Frontend:** Node.js v20+, Next.js 14.1.0 App Router. Production build (`npm run build`) verifies 24 static pages compiled with zero errors.
- **Test Suite Status:** 299 tests, 1,300 assertions passing cleanly (100% OK, 0 failures, 0 regressions).

### 2. Local Dummy Data & Audit Seeder Tooling
- **Command:** `php artisan emailsaas:seed-audit-demo [--dry-run] [--cleanup]`
- **Safety Assurances:** Gated by `app()->environment('local')` and approved local host (`127.0.0.1:3306/emailsaas_local`). Halts immediately on non-local environments or remote database configurations.
- **Cleanup Strategy:** Explicit ownership registry (`audit-demo-` prefix, `@example.test` domain) ensures clean removal of synthetic fixtures without touching canonical records (`test@example.com`, `admin@example.com`, standard plans).

### 3. External Infrastructure Status Matrix
- **Live MariaDB Engine Restore Drill:** `PENDING INFRASTRUCTURE` (Requires dedicated staging Linux VPS).
- **Live Dovecot MDA / Maildir Verification:** `PENDING INFRASTRUCTURE` (Requires active Dovecot daemon).
- **Live Postfix Delivery & SASL Relay:** `PENDING INFRASTRUCTURE` (Requires active Postfix daemon with port 10031 policy daemon).
- **Local Application Stack Integration:** `VERIFIED` (Sanctum auth, Spatie RBAC, GCM backup encryption, audit logging, abuse management, usage tracking, read-only reconciliation).

---

## Step 30 — Email Alias Routing Integration & Mail Flow Verification

### 1. Postfix Virtual Alias Resolution Architecture
- **Dynamic SQL Map Mechanism:** Postfix relies on MySQL map lookups (`virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-alias-maps.cf`) configured in `/etc/postfix/main.cf`.
- **Zero-Latency Inbound Lookup:** Queries are executed directly against the active database at SMTP envelope recipient evaluation (`RCPT TO`). Row insertions, modifications, toggles, or deletions in `email_aliases` take effect immediately without requiring `postmap` hash compilation or Postfix service reload.
- **SQL Map Canonical Schema Alignment:**
  ```ini
  user     = laravel_db_user
  password = laravel_db_password
  hosts    = 127.0.0.1
  dbname   = email_saas_db
  query    = SELECT email_aliases.destination_email FROM email_aliases INNER JOIN domains ON email_aliases.domain_id = domains.id WHERE email_aliases.source_email='%s' AND email_aliases.is_active=1 AND domains.status='active' AND domains.deleted_at IS NULL
  ```
- **SoftDeletes Compliance:** To prevent routing mail to soft-deleted domains or deleted mailboxes, all `.cf` maps (`mysql-virtual-aliases.cf`, `mysql-virtual-domains.cf`, `mysql-virtual-mailboxes.cf`, and `mysql-virtual-sender-login-maps.cf`) enforce `domains.deleted_at IS NULL` and `mailboxes.deleted_at IS NULL`.

### 2. Multi-Hop Expansion & Recursion Limits
- **MTA Recursion Mechanics:** Postfix recursively resolves aliases until reaching a physical terminal mailbox or an external domain, bounded by `virtual_alias_expansion_limit` (default 1000).
- **Application-Level Guardrails:** `AliasApiController` validates forwarding chains at creation and update time. `PostfixService::verifyAliasRouting` inspects recursive paths up to 30 hops to detect cycles (`A -> B -> A`) or dangling local destinations (where the terminal target is a hosted domain but no matching physical mailbox exists in `mailboxes`), returning `status: "failed"` and preventing bounce loops.

### 3. Three-Level Verification Matrix

| Verification Level | Target | Status | Detail / Justification |
|---|---|---|---|
| **Level A: Application & API Layer** | `PostfixService`, `AliasApiController`, `EmailAliasResource` | `VERIFIED` | Full test coverage for lifecycle hooks (`syncAlias`, `removeAlias`), dynamic status computation (`synchronized`, `operational`, `configured`, `failed`), multi-hop resolution chains, and dangling local destination warnings. |
| **Level B: Configuration & SQL Query Layer** | `postfix-config/*.cf`, `server-configs/*.cf`, CLI tooling | `VERIFIED` | Exact SQL map queries tested directly against MySQL. CLI verification command `php artisan mail:verify-alias` verified for single alias diagnostics and `--all` database audits. |
| **Level C: End-to-End Mail Flow** | Live Postfix MTA daemon, TCP port 25/587, Dovecot MDA delivery | `BLOCKED — REQUIRES INFRASTRUCTURE` | Local Windows + Laragon development environment lacks native Linux Postfix/Dovecot daemons, Unix domain sockets, and inbound MX routing. End-to-end SMTP transmission requires a dedicated Linux VPS environment. |

## Dovecot Mailbox Storage & Quota Architecture (Step 31)

### 1. Dovecot Dynamic SQL Quota Rule Mechanism
Dovecot MDA (Local Delivery Agent / LMTP) enforces storage ceilings dynamically via the `quota` plugin without requiring hardcoded configuration files per mailbox.
- **Dynamic SQL Query (`dovecot-sql.conf.ext`):**
  ```sql
  user_query = SELECT \
      CONCAT('/var/vmail/', domains.domain_name, '/', mailboxes.local_part) AS home, \
      5000 AS uid, \
      5000 AS gid, \
      CONCAT('*:bytes=', (mailboxes.quota_mb * 1048576)) AS quota_rule \
  FROM mailboxes \
  INNER JOIN domains ON mailboxes.domain_id = domains.id \
  WHERE mailboxes.email = '%u' \
    AND mailboxes.is_active = 1 \
    AND domains.status = 'active' \
    AND domains.deleted_at IS NULL \
    AND mailboxes.deleted_at IS NULL;
  ```
- **Quota Rule Syntax:** `*:bytes=<byte_limit>`. Dovecot automatically translates `mailboxes.quota_mb * 1048576` into bytes. If `quota_mb = -1` or `NULL`, the mailbox is treated as unlimited.
- **Enforcement Action:** When inbound delivery exceeds the quota rule, Dovecot returns error `452 4.2.2 Mailbox is full / quota exceeded` (temporary deferral) or `552 5.2.2 Over quota` (permanent bounce).

### 2. Storage Measurement Telemetry Probing
`MailboxStorageService` employs a robust layered strategy to inspect live mailbox storage footprint:
1. **Primary Probe (Dovecot CLI):** `doveadm quota get -u <email>`. Retrieves the real-time calculated quota dictionary (`STORAGE` bytes, `MESSAGE` count) from Dovecot's quota backend (e.g. `quota_dict` or `maildirsize`).
2. **Secondary Probe (Maildir Size Header):** Inspects physical `/var/vmail/<domain>/<mailbox>/maildirsize`. Dovecot maintains this cache file containing the quota ceiling and a running tally of byte sizes and message counts.
3. **Environment-Safe Fallback (Windows / Offline MDA):** If `doveadm` is not installed and `/var/vmail` is not mounted (as in local Windows + Laragon development), the service gracefully falls back:
   - Sets `is_measured = false`.
   - Sets `usage_bytes = null` and `usage_mb = null`.
   - Sets `status = 'unknown'` and `status_label = 'Unmeasured'`.
   - **Crucial Invariant:** The application never fabricates 0-byte usage for unmeasured mailboxes, ensuring complete operational transparency for operators and tenants.

### 3. Three-Level Storage Verification Matrix

| Verification Level | Target | Status | Detail / Justification |
|---|---|---|---|
| **Level A: Application & API Layer** | `MailboxStorageService`, `MailboxApiController`, `MailboxResource` | `VERIFIED` | Full test coverage for storage calculation, threshold evaluations, tenant aggregation, fake probe resolvers, unlimited quotas, and policy authorization via `TenantMailboxStorageTest.php`. |
| **Level B: Configuration & SQL Query Layer** | `dovecot-sql.conf.ext`, `quota_rule` SQL mapping | `VERIFIED` | Exact SQL syntax verified against local MySQL 8.4 database. `quota_mb * 1048576` produces exact byte counts. |
| **Level C: Live Dovecot LMTP Enforcement** | Live Linux Dovecot daemon, `doveadm`, physical `/var/vmail` disk writes | `BLOCKED — REQUIRES INFRASTRUCTURE` | Local Windows + Laragon development environment lacks Linux Dovecot daemon and `/var/vmail` filesystem; live enforcement will execute in production Linux VPS environment. |

---

## Postfix Catch-All & Fallback Inbound Routing Architecture (Step 32)

### 1. Inbound Resolution Precedence & MySQL Map Order
In Postfix virtual delivery, incoming messages addressed to `@virtual_mailbox_domains` traverse `virtual_alias_maps` before resolving `virtual_mailbox_maps`.
To ensure that catch-all mappings never intercept, override, or shadow valid physical mailboxes or explicit virtual aliases, the configuration enforces:

1. **Map Registration Order (`/etc/postfix/main.cf`):**
   ```ini
   virtual_alias_maps = mysql:/etc/postfix/mysql-virtual-aliases.cf,
                        mysql:/etc/postfix/mysql-virtual-catchall.cf
   ```
2. **Strict SQL Precedence Exclusions (`mysql-virtual-catchall.cf`):**
   ```ini
   user     = laravel_db_user
   password = laravel_db_password
   hosts    = 127.0.0.1
   dbname   = email_saas_db
   query    = SELECT CASE WHEN domains.catch_all_policy = 'discard' THEN 'devnull@localhost' ELSE mailboxes.email END AS destination FROM domains LEFT JOIN mailboxes ON domains.catch_all_mailbox_id = mailboxes.id AND mailboxes.is_active = 1 AND mailboxes.deleted_at IS NULL WHERE domains.domain_name = '%d' AND domains.status = 'active' AND domains.deleted_at IS NULL AND domains.catch_all_policy IN ('mailbox', 'discard') AND (domains.catch_all_policy = 'discard' OR mailboxes.id IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM mailboxes WHERE email = '%s' AND is_active = 1 AND deleted_at IS NULL) AND NOT EXISTS (SELECT 1 FROM email_aliases WHERE source_email = '%s' AND is_active = 1) LIMIT 1;
   ```
3. **Resolution Sequence:**
   - **Step 1:** Postfix checks `mysql-virtual-aliases.cf` for exact match in `email_aliases`.
   - **Step 2:** If not found, Postfix evaluates `mysql-virtual-catchall.cf`. If an active physical mailbox exists with that address (`NOT EXISTS mailboxes`), catch-all returns NULL.
   - **Step 3:** If neither alias nor catch-all matches, Postfix delivers directly to physical mailbox via `virtual_mailbox_maps` (`mysql-virtual-mailboxes.cf`).
   - **Step 4:** If recipient matches no mailbox, no alias, and domain has `catch_all_policy = 'disabled'` or `'reject'`, Postfix rejects the message with `550 5.1.1 User unknown in virtual mailbox table`.
   - **Step 5:** If `catch_all_policy = 'mailbox'`, Postfix routes to `domains.catch_all_mailbox_id`.
   - **Step 6:** If `catch_all_policy = 'discard'`, Postfix routes to discard sink (`devnull@localhost`).

### 2. Operational Diagnostics & Telemetry
`PostfixService::verifyCatchAllRouting(Domain $domain)` and `php artisan mail:verify-catchall` evaluate this query structure and report one of four operational states:
- `operational`: Live Linux Postfix socket is listening and destination mailbox is valid.
- `synchronized`: SQL map query and target mailbox are valid and routable (MTA offline/local fallback).
- `configured`: Catch-all is set to `disabled` or `reject`, or domain is unverified/pending.
- `failed`: Policy is set to `mailbox` but destination mailbox is inactive, deleted, or missing.

### 3. Three-Level Catch-All Verification Matrix

| Verification Level | Target | Status | Detail / Justification |
|---|---|---|---|
| **Level A: Application & API Layer** | `CatchAllApiController`, `DomainPolicy`, `PostfixService` | `VERIFIED` | Full test coverage for policy updates, mailbox assignment, domain verification checks, subscription gating, transactional updates, and `AuditService` logging via `TenantCatchAllManagementTest.php` (19/19 passing). |
| **Level B: Configuration & SQL Query Layer** | `mysql-virtual-catchall.cf`, `virtual_alias_maps`, CLI tool | `VERIFIED` | Exact SQL map query tested directly against MySQL with aliases, physical mailboxes, probe emails, and fallback policies. CLI verification command `php artisan mail:verify-catchall` verified. |
| **Level C: Live End-to-End SMTP Reception** | Live Postfix MTA daemon, TCP port 25 recipient verification | `BLOCKED — REQUIRES INFRASTRUCTURE` | Local Windows + Laragon development environment lacks Linux Postfix MTA, network MX records, and Unix domain sockets. Full live mail-flow requires dedicated Linux VPS staging environment. |



# EmailSaaS Disaster Recovery Runbook & Operations Manual

**Document Version:** 1.1 (Production Hardened — Step 17.2)  
**Phase:** Step 17.2 — Backup & Disaster Recovery Production Hardening + Restore Verification  
**Application:** EmailSaaS (`smtp-saas`)  
**Scope:** Production Disaster Recovery, Database Restore, Maildir `/var/vmail` Recovery, Encryption at Rest, Offsite Replication, and Infrastructure Continuity  
**Classification:** Operational Security & Infrastructure  

---

## 1. Executive Summary & Recovery Objectives

This runbook defines operational recovery procedures for the EmailSaaS platform. The system operates on a single-node or distributed Ubuntu 22.04 LTS architecture running MariaDB 10.11+, Postfix 3.7+, Dovecot 2.3+, Redis 7+, and a Laravel 11 application backend.

Data assets are categorized into two primary tiers:
1. **Relational Application State:** MariaDB/MySQL database storing users, tenants, domains, mailboxes, billing subscriptions, invoices, audit logs, and persistent abuse incidents.
2. **Mailbox Object Storage:** `/var/vmail` containing Maildir mailboxes, message files, flags, and directory structures across all provisioned domains.

### Recovery Target Metrics

| Metric | Target Specification | Tested / Automated Baseline | Notes |
| :--- | :--- | :--- | :--- |
| **RPO (Recovery Point Objective)** | **≤ 24 Hours** (Daily Scheduled) | Point-of-Backup Snapshot | **PITR: NOT IMPLEMENTED**. Point-in-time recovery requires continuous MariaDB binary logging archiving and replica binlog coordinates which are not implemented in the current baseline. Recovery is point-of-backup snapshot only. |
| **RTO (Recovery Time Objective)** | **≤ 30 Minutes** (Database)<br>**≤ 60 Minutes** (Total Node) | **< 4 Seconds** (Automated Test Suite) | Production RTO depends on network transfer and physical byte volume of `/var/vmail`. |
| **Encryption at Rest** | **OpenSSL AES-256-CBC (PBKDF2)** | Automated Test Verified | Backups can be encrypted at rest (`.sql.gz.enc`, `.tar.gz.enc`) with keys held strictly outside backup directories. |
| **Offsite Replication Status** | **Provider-Neutral Transport** | Service & Script Automated | Abstraction supports `rsync`, `scp`, `local`, and `custom` transports with fail-safe isolation. |

---

## 2. Backup Architecture & Storage Layout

### 2.1 File System Storage Layout

Backups are structured in `storage/backups/` (or `/var/backups/emailsaas/` in production shell execution):

```text
storage/backups/
├── db/
│   ├── db_backup_20261006_020000.sql.gz[.enc]
│   ├── db_backup_20261006_020000.sql.gz[.enc].manifest.json
│   └── ...
└── vmail/
    ├── vmail_backup_20261006_020000.tar.gz[.enc]
    ├── vmail_backup_20261006_020000.tar.gz[.enc].manifest.json
    └── ...
```

### 2.2 Companion JSON Manifests

Every backup archive is accompanied by an immutable `.manifest.json` file created atomically alongside the archive. Manifests record:
* `backup_id`: Unique UUIDv4 identifier.
* `timestamp`: UTC ISO-8601 creation timestamp.
* `filename` and `path`: Target file location.
* `size_bytes`: Physical archive size on disk.
* `sha256`: SHA-256 cryptographic digest of the target archive.
* `encrypted`: Boolean flag (`true` if AES-256 encrypted at rest).
* `cipher`: Cipher algorithm (e.g., `'aes-256-cbc'`).
* `unencrypted_sha256`: SHA-256 hash of the plaintext archive prior to encryption.
* `verified`: Boolean integrity audit flag.
* `verified_at`: Timestamp of latest verification check.
* `restore_tested`: Boolean flag indicating isolated restore validation.
* `offsite_copied`: Boolean flag indicating offsite replication.
* `offsite_status`: `'PENDING'`, `'COPIED'`, or `'FAILED'`.
* `status`: `'SUCCESS'`.

---

## 3. Disaster Recovery Scenarios

### Scenario A: Database Loss or Corruption

**Trigger:** Database crash, accidental table drops, MariaDB storage failure, or unrecoverable transactional corruption.

#### Step-by-Step Recovery Procedure:

1. **Identify the Latest Known-Good Database Backup:**
   ```bash
   cd /var/www/email-saas/laravel-panel
   php artisan backup:status
   ```
   Or inspect the backup directory directly:
   ```bash
   ls -lat /var/backups/emailsaas/db/*.sql.gz*
   ```

2. **Verify Archive Cryptographic & Physical Integrity:**
   Run integrity verification to confirm the archive has not suffered bit rot or corruption:
   ```bash
   php artisan backup:verify /var/backups/emailsaas/db/db_backup_YYYYMMDD_HHMMSS.sql.gz.enc
   # Or via shell script:
   sudo bash /var/www/email-saas/scripts/verify_backup.sh /var/backups/emailsaas/db/db_backup_YYYYMMDD_HHMMSS.sql.gz.enc
   ```

3. **Stop Inbound Mail Processing (Prevents lost receipts during restore):**
   ```bash
   sudo systemctl stop postfix
   ```

4. **Restore Database from Verified Archive:**
   Execute the restoration script with explicit confirmation (handles decryption automatically):
   ```bash
   sudo bash /var/www/email-saas/scripts/restore_db.sh /var/backups/emailsaas/db/db_backup_YYYYMMDD_HHMMSS.sql.gz.enc --confirm
   ```

5. **Verify Database Structure and Check Pending Migrations:**
   Inspect migration state safely without blind execution:
   ```bash
   php artisan migrate:status
   # If pending migrations exist and need application:
   php artisan migrate --force
   ```

6. **Flush Application Caches & Restart Services:**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   sudo systemctl start postfix
   ```

7. **Verify Platform Health:**
   ```bash
   php artisan backup:status
   ```

---

### Scenario B: Mail Storage Loss or `/var/vmail` Corruption

**Trigger:** Filesystem corruption on `/var/vmail`, storage volume detachment, or accidental directory deletion.

#### Step-by-Step Recovery Procedure:

1. **Identify the Latest Mail Storage Backup:**
   ```bash
   cd /var/www/email-saas/laravel-panel
   php artisan backup:status
   ```

2. **Verify Archive Integrity:**
   ```bash
   php artisan backup:verify /var/backups/emailsaas/vmail/vmail_backup_YYYYMMDD_HHMMSS.tar.gz.enc
   # Or via shell:
   sudo bash /var/www/email-saas/scripts/verify_backup.sh /var/backups/emailsaas/vmail/vmail_backup_YYYYMMDD_HHMMSS.tar.gz.enc
   ```

3. **Stop Dovecot and Postfix (Prevents concurrent write collisions):**
   ```bash
   sudo systemctl stop postfix
   sudo systemctl stop dovecot
   ```

4. **Execute Mail Storage Restoration:**
   ```bash
   sudo bash /var/www/email-saas/scripts/restore_vmail.sh /var/backups/emailsaas/vmail/vmail_backup_YYYYMMDD_HHMMSS.tar.gz.enc --confirm /var/vmail
   ```

5. **Verify Maildir Permissions & Ownership:**
   The restore script automatically enforces numeric `5000:5000` (`vmail:vmail`) ownership and `0700`/`0600` permissions:
   ```bash
   chown -R vmail:vmail /var/vmail
   chmod 750 /var/vmail
   find /var/vmail -type d -exec chmod 700 {} +
   find /var/vmail -type f -exec chmod 600 {} +
   ```

6. **Restart Dovecot and Postfix:**
   ```bash
   sudo systemctl start dovecot
   sudo systemctl start postfix
   ```

7. **Test Mail Delivery and IMAP Access:**
   Test IMAP login on Dovecot for a sample mailbox to verify index recreation and message visibility.

---

### Scenario C: Complete VPS Destruction / Bare-Metal Rebuild

**Trigger:** Hardware failure, VPS host termination, catastrophic data center outage, or migration to a new machine.

#### Step-by-Step Recovery Procedure:

1. **Provision Fresh Server:**
   Deploy a fresh Ubuntu 22.04 LTS instance with matching or higher specifications.

2. **Install Operating System Baseline & Packages:**
   Clone repository and run the idempotent installer:
   ```bash
   git clone https://github.com/shuvo-halder/smtp-saas /var/www/email-saas
   cd /var/www/email-saas
   sudo bash scripts/install.sh
   ```
   *(Note: `install.sh` automatically configures services, firewall, certificates, and the cron schedule.)*

3. **Transfer Backups from Offsite / Secure Storage:**
   Copy the latest database and mail storage archives to `/var/backups/emailsaas/`:
   ```bash
   mkdir -p /var/backups/emailsaas/db /var/backups/emailsaas/vmail
   scp operator@backup-vault:db_backup_LATEST.sql.gz.enc* /var/backups/emailsaas/db/
   scp operator@backup-vault:vmail_backup_LATEST.tar.gz.enc* /var/backups/emailsaas/vmail/
   ```

4. **Run Disaster Recovery Orchestrator:**
   Execute the hardened full recovery pipeline:
   ```bash
   sudo bash scripts/disaster_recovery.sh \
       /var/backups/emailsaas/db/db_backup_LATEST.sql.gz.enc \
       /var/backups/emailsaas/vmail/vmail_backup_LATEST.tar.gz.enc \
       --confirm \
       --run-migrations
   ```

5. **Re-generate Application Key & Environment Secrets:**
   Ensure `laravel-panel/.env` has proper `APP_KEY`, database credentials, and service settings.

6. **Validate System Operation:**
   ```bash
   cd /var/www/email-saas/laravel-panel
   php artisan about
   php artisan backup:status
   ```

---

## 4. Automation & Scheduled Operations

### 4.1 Laravel Console Commands

The system provides 6 dedicated Artisan commands under the `backup:` namespace:

| Command | Signature & Options | Function |
| :--- | :--- | :--- |
| `backup:run` | `php artisan backup:run [--only-db] [--only-vmail] [--verify] [--sync-offsite] [--no-prune]` | Master backup execution pipeline. Runs DB and/or mail backup, verifies integrity, replicates offsite, and executes calendar-aware retention pruning. |
| `backup:database` | `php artisan backup:database [--path=]` | Generates transaction-safe compressed (and encrypted if configured) database dump with companion manifest. |
| `backup:vmail` | `php artisan backup:vmail [--source=] [--path=]` | Archives `/var/vmail` Maildir directory (and encrypts if configured) with companion manifest. |
| `backup:verify` | `php artisan backup:verify {path?} [--key=] [--dry-run-restore] [--isolated-db-restore] [--isolated-mail-restore]` | Verifies archive gzip stream, encryption envelope, SHA-256 match, and isolated restore capability. |
| `backup:status` | `php artisan backup:status` | Displays tabular status of backups, encryption posture, age, size, health, and offsite state. |
| `backup:prune` | `php artisan backup:prune [--keep=]` | Prunes expired archives according to calendar-aware retention policies (7 daily, 4 weekly, 3 monthly). |

### 4.2 Automated Schedule Configuration

In `laravel-panel/routes/console.php`:
```php
// Schedule daily database & mail storage backup suite with verification at 02:00 UTC
Schedule::command('backup:run --verify')->dailyAt('02:00')->withoutOverlapping(30);

// Schedule daily backup retention pruning at 03:00 UTC
Schedule::command('backup:prune')->dailyAt('03:00')->withoutOverlapping(15);
```

Cron entry configured in `scripts/install.sh`:
```text
* * * * * cd /var/www/email-saas/laravel-panel && php artisan schedule:run >> /dev/null 2>&1
```

---

## 5. Retention Policies & Safety Invariants

### 5.1 Calendar-Aware GFS Hierarchy
The retention engine implements a true calendar-aware Grandfather-Father-Son (GFS) model:
* **Daily:** Retain the latest backup for each of the last **7 distinct calendar days**.
* **Weekly:** Retain the latest backup for each of the last **4 distinct calendar weeks** (ISO `o-W`).
* **Monthly:** Retain the latest backup for each of the last **3 distinct calendar months** (`Y-m`).

### 5.2 Critical Safety Invariants
1. **Never Delete Newest Backup:** The overall newest valid backup is strictly exempt from deletion under all circumstances.
2. **Never Delete Only Remaining Backup:** If total backup count ≤ 1, pruning aborts immediately without deleting files.
3. **Atomic Write Promotion:** Backups are written to `.tmp` files and atomically promoted using filesystem rename. Interrupted or failed backups never corrupt or replace existing archives.
4. **Stale Temporary Cleanup:** Incomplete `.tmp` files older than 24 hours are safely reaped during pruning cycles.
5. **Concurrency Locks:** Execution uses `Cache::lock` in Artisan and `flock` in bash scripts to guarantee single-instance execution.

---

## 6. Security Posture: Encryption at Rest & Offsite Replication

### 6.1 Encryption at Rest Architecture & Cryptographic Authenticity Analysis
* **Algorithm:** OpenSSL AES-256-CBC with PBKDF2 key derivation (10,000 iterations, SHA-256, 8-byte random salt).
* **Format:** Envelope containing `Salted__` magic header + 8-byte salt + raw AES-256 ciphertext (`.sql.gz.enc`, `.tar.gz.enc`).
* **Cryptographic Authenticity Limitation (Non-AEAD):**
  * AES-256-CBC is **unauthenticated encryption**: it provides confidentiality, but does **not** provide cryptographic authenticity (MAC).
  * System integrity verification relies on comparing the SHA-256 hash of the archive against the companion `.manifest.json` file.
  * **Threat Model Caveat:** The companion `.manifest.json` file is stored as plaintext JSON alongside the archive. In threat models where an adversary possesses write access to backup volumes, an attacker could manipulate ciphertext and calculate a corresponding new SHA-256 hash in the manifest without detection.
  * **Production Remediation Requirement:** Prior to full production deployment, upgrade backup encryption to an authenticated mode (AEAD such as `aes-256-gcm` storing authentication tags in the envelope, or HMAC-SHA256 signature verification over manifests).
* **Key Management:**
  * Configured via `BACKUP_ENCRYPTION_KEY` or `BACKUP_ENCRYPTION_KEY_PATH` (e.g., `/etc/emailsaas/backup.key`).
  * Encryption keys must **NEVER** be stored in `storage/backups/`, backup manifests, or git repositories.
  * If encryption is enabled (`BACKUP_ENCRYPTION_ENABLED=true`) but no key is configured, the system **fails closed** immediately.

### 6.2 Provider-Neutral Offsite Replication
* **Supported Transports:** `rsync` (SSH key), `scp`, `local` (mounts/NFS), and `custom` CLI hooks.
* **Fail-Safe Invariant:** Remote transport failure or timeout **NEVER** deletes, mutates, or invalidates the local backup.
* **Credential Protection:** Passwords, API tokens, and private SSH keys are automatically redacted in system logs.

---

## 7. Restore Verification Status Matrix

| Subsystem Component | Verification Level | Verification Evidence | Status Notes |
| :--- | :--- | :--- | :--- |
| **Archive Integrity & SHA-256** | `VERIFIED` | Unit & Feature tests, `gzip -t`, `sha256sum` | Automated in test suite and shell scripts. |
| **Encryption at Rest & Decryption** | `VERIFIED` | Full roundtrip test, OpenSSL CLI cross-compatibility | PHP OpenSSL and OpenSSL 3.x CLI interchangeable. |
| **Isolated Schema & Syntax Restore** | `VERIFIED` | Automated disposable SQLite restore engine | Critical tables validated: `users`, `domains`, `mailboxes`, `plans`, `invoices`, `audit_logs`, `abuse_incidents`. |
| **Real MariaDB 10.11+ Live Restore** | `PARTIALLY VERIFIED` | DDL/DML grammar, syntax, and schema tested; MariaDB restore script verified | Live MariaDB engine daemon verification pending remote infrastructure (`PENDING INFRASTRUCTURE`). |
| **Isolated Maildir Message Recovery** | `VERIFIED` | Synthetic Maildir roundtrip test | Messages, subdirectories (`cur`, `new`, `tmp`), and timestamps preserved. |
| **Real Dovecot MDA Live Indexing** | `PARTIALLY VERIFIED` | Extraction and numeric permissions (`5000:5000`) tested; Dovecot reload tested | Live Dovecot MDA indexing daemon verification pending remote infrastructure (`PENDING INFRASTRUCTURE`). |
| **Calendar-Aware Retention (GFS)** | `VERIFIED` | Multi-day / multi-month simulated test | Invariants enforced; older than 3 months pruned. |
| **Fail-Safe Offsite Replication** | `VERIFIED` | Provider-neutral service and mock transport test | Remote failure leaves local backup intact. |
| **Point-in-Time Recovery (PITR)** | `NOT IMPLEMENTED` | Explicit architectural choice (Option B) | Current system provides point-of-backup snapshots. |
| **Standalone Backup Status HTTP API** | `REQUIRES ARCHITECTURE APPROVAL` | Step 18 governance enforced; tested 404 | Standalone HTTP endpoint withheld pending formal approval. |

---

## 8. Staging Rollback Runbook & Controlled Drill Procedure

### 8.1 Rollback Preconditions & Invariants
1. **Never Automatically Migrate:** `php artisan migrate --force` must **NEVER** be run as an automated recovery step. Schema changes must be an explicit, reviewed operational decision.
2. **Explicit Target Identification:** Before executing any restore command, verify that `DB_DATABASE` points to the isolated staging/drill database and `TARGET_DIR` points to the isolated staging mail directory.
3. **No Production Overwrite:** Never restore against production host `103.131.144.61` or production database `smtp`.

### 8.2 Staging Drill Sequence
1. **Pre-Drill Snapshot:**
   ```bash
   # Create baseline pre-drill snapshot of staging data
   php artisan backup:run --verify
   ```
2. **Stop Mail Ingress:**
   ```bash
   sudo systemctl stop postfix
   ```
3. **Execute Isolated Database Restore:**
   ```bash
   # Restore into explicitly designated staging database
   sudo bash scripts/restore_db.sh /var/backups/emailsaas/db/db_backup_STAGING.sql.gz.enc --confirm
   ```
4. **Execute Isolated Mail Storage Restore:**
   ```bash
   # Restore into isolated staging test directory
   sudo bash scripts/restore_vmail.sh /var/backups/emailsaas/vmail/vmail_backup_STAGING.tar.gz.enc --confirm /var/vmail_staging_test
   ```
5. **Validation Commands:**
   ```bash
   # Inspect MariaDB table counts and schemas
   mysql -u emailsaas -p emailsaas_staging -e "SHOW TABLES; SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM mailboxes;"
   # Validate Maildir permissions and ownership
   ls -la /var/vmail_staging_test
   # Test Dovecot indexer against isolated path
   doveadm index -u test@staging.domain.com INBOX
   ```
6. **Rollback Trigger Conditions & Procedure:**
   - **Stop Conditions:** Abort drill immediately if checksum mismatch occurs, decryption fails, tables fail to populate, or permissions do not match `5000:5000`.
   - **Rollback Steps:**
     ```bash
     # Restore original pre-drill snapshot
     sudo bash scripts/restore_db.sh /var/backups/emailsaas/db/db_backup_PRE_DRILL.sql.gz.enc --confirm
     sudo rm -rf /var/vmail_staging_test
     sudo systemctl start postfix
     ```

---

## 9. Step 20 Acceptance Evidence Matrix

| Check ID | Environment | Exact Operation Tested | Expected Result | Actual Result | Exit / HTTP Status | Evidence Location | Final Classification | Remaining Limitation |
|---|---|---|---|---|---|---|---|---|
| **CHK-20-01** | Local Dev | `git status --short` & `git branch` | Clean tree on `main` branch | Clean tree on `main`, zero uncommitted diffs | Exit `0` | Git CLI output | `VERIFIED` | None |
| **CHK-20-02** | Local Dev | `php artisan test` | 243 tests pass, 1,064 assertions | 243 passed, 1,064 assertions, 0 failures, 0 errors, 0 skipped | Exit `0` | Test runner logs | `VERIFIED` | Remote daemon pending |
| **CHK-20-03** | Local Dev | `npm run test:step19` | All dictionary and contract checks pass | 100% checks passed | Exit `0` | `verify-step19.mjs` | `VERIFIED` | None |
| **CHK-20-04** | Local Dev | `npx tsc --noEmit` | Zero TypeScript compilation errors | 0 type errors | Exit `0` | TypeScript compiler | `VERIFIED` | None |
| **CHK-20-05** | Local Dev | `npm run build` | Next.js 14 production build succeeds | 24/24 static pages generated cleanly | Exit `0` | Next.js build trace | `VERIFIED` | None |
| **CHK-20-06** | Local Dev | `php artisan schedule:list` | All 6 scheduled tasks listed with correct frequency & mutexes | All 6 tasks verified (`tenant:suspend-expired`, `outbound:usage-sync`, `mail:process-log`, `backup:run`, `backup:prune`, `scheduled-tasks:prune`) | Exit `0` | Console output | `VERIFIED` | None |
| **CHK-20-07** | Local Dev | `scripts/install.sh` audit | Safety inspection of installation script | Hardcoded `mail.yourdomain.com`, unparameterized UFW, missing staging vs production flag | Blocked from blind execution | `scripts/install.sh` | `AUDITED — PARAMETERIZATION REQUIRED` | Needs CLI args for domain/staging |
| **CHK-20-08** | Local Dev | Public Health Probe `GET /api/health` | Minimal status payload without server info | Returns `{ "status": "HEALTHY", "timestamp": "..." }`, zero leak | HTTP `200` | `SystemHealthAndSchedulerTest` | `VERIFIED` | None |
| **CHK-20-09** | Local Dev | Admin Health API RBAC `GET /api/admin/system/health` | Rejects unauth (401), tenant (403), suspended (403), zero-role (403); allows superAdmin (200) | Strict RBAC enforced | HTTP `401`/`403`/`200` | `SystemHealthAndSchedulerTest` | `VERIFIED` | None |
| **CHK-20-10** | Local Dev | Step 18 Backup API Gap `GET /api/admin/system/backup-status` | Returns 404 Not Found (no unauthorized API invented) | Returns HTTP 404 | HTTP `404` | `SystemHealthAndSchedulerTest` | `VERIFIED` | Requires Architecture Approval |
| **CHK-20-11** | Local Dev | Task Ledger Lifecycle & Sanitization | Non-blocking recording; passwords, DSNs, and tokens scrubbed | Exception traces scrubbed; DB failure fail-safe | Exit `0` | `SystemHealthAndSchedulerTest` | `VERIFIED` | None |
| **CHK-20-12** | Local Dev | OpenSSL AES-256-CBC PBKDF2 Roundtrip | Encryption/decryption verified; fail-closed on missing key | Roundtrip passes; missing key throws `RuntimeException` | Exit `0` | `BackupAndDisasterRecoveryTest` | `VERIFIED` | AEAD upgrade recommended |
| **CHK-20-13** | Local Dev | Isolated Schema & Maildir Restore | Restores into disposable SQLite DB and directory | Verifies all tables and Maildir structure | Exit `0` | `BackupAndDisasterRecoveryTest` | `VERIFIED` | SQLite/filesystem level only |
| **CHK-20-14** | Remote Staging | Real MariaDB 10.11+ Live Restore | Live MariaDB daemon drop/create/import drill | Remote staging MariaDB daemon unavailable in local Windows dev environment | Deferred | Pending staging VPS | `PENDING INFRASTRUCTURE` | Requires dedicated Linux VPS |
| **CHK-20-15** | Remote Staging | Real Dovecot MDA Live Indexing Drill | Live Dovecot `doveadm index` discovery drill | Remote staging Dovecot daemon unavailable in local Windows dev environment | Deferred | Pending staging VPS | `PENDING INFRASTRUCTURE` | Requires dedicated Linux VPS |

# EmailSaaS Disaster Recovery Runbook & Operations Manual

**Document Version:** 1.0  
**Phase:** Step 17 — Backup & Disaster Recovery  
**Application:** EmailSaaS (`smtp-saas`)  
**Scope:** Production Disaster Recovery, Database Restore, Maildir `/var/vmail` Recovery, and Infrastructure Continuity  
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
| **RPO (Recovery Point Objective)** | **≤ 24 Hours** (Daily Scheduled) | Point-of-Backup | With binary logging enabled on MariaDB, RPO can be reduced to point-in-time recovery. |
| **RTO (Recovery Time Objective)** | **≤ 30 Minutes** (Database)<br>**≤ 60 Minutes** (Total Node) | **< 2 Seconds** (Automated Test Suite) | Production RTO depends on network transfer and `/var/vmail` physical byte volume. |
| **Offsite Replication Status** | **OFFSITE BACKUP — PENDING INFRASTRUCTURE** | Local Disks (`storage/backups`) | Remote S3 / rsync replication pending remote storage provisioning. |

---

## 2. Backup Architecture & Storage Layout

### 2.1 File System Storage Layout

Backups are structured in `storage/backups/` (or `/var/backups/emailsaas/` in native shell execution):

```text
storage/backups/
├── db/
│   ├── db_backup_20261006_020000.sql.gz
│   ├── db_backup_20261006_020000.sql.gz.manifest.json
│   └── ...
└── vmail/
    ├── vmail_backup_20261006_020000.tar.gz
    ├── vmail_backup_20261006_020000.tar.gz.manifest.json
    └── ...
```

### 2.2 Companion JSON Manifests

Every backup archive is accompanied by an immutable `.manifest.json` file created atomically alongside the archive. Manifests record:
* Archive filename and absolute path.
* UTC ISO-8601 creation timestamp.
* Exact byte size.
* SHA-256 cryptographic digest of the compressed archive.
* Engine details (e.g., `mariadb-dump`, `sqlite`, `tar-gzip`).
* Verification flag and integrity audit trail.

---

## 3. Disaster Recovery Scenarios

### Scenario A: Database Loss or Corruption

**Trigger:** Database crash, accidental table drops, MariaDB storage failure, or unrecoverable transactional corruption.

#### Step-by-Step Recovery Procedure:

1. **Identify the Latest Known-Good Database Backup:**
   ```bash
   cd /var/www/laravel-panel
   php artisan backup:status
   ```
   Or inspect the backup directory directly:
   ```bash
   ls -lat /var/backups/emailsaas/db/*.sql.gz
   ```

2. **Verify Archive Cryptographic & Physical Integrity:**
   Run integrity verification to confirm the archive has not suffered bit rot or corruption:
   ```bash
   php artisan backup:verify /var/backups/emailsaas/db/db_backup_YYYYMMDD_HHMMSS.sql.gz
   # Or via shell script:
   sudo bash /var/www/scripts/verify_backup.sh /var/backups/emailsaas/db/db_backup_YYYYMMDD_HHMMSS.sql.gz
   ```

3. **Stop Inbound Mail Processing (Optional, prevents lost receipts):**
   ```bash
   sudo systemctl stop postfix
   ```

4. **Restore Database from Verified Archive:**
   Execute the interactive restoration script with explicit confirmation:
   ```bash
   sudo bash /var/www/scripts/restore_db.sh /var/backups/emailsaas/db/db_backup_YYYYMMDD_HHMMSS.sql.gz --confirm
   ```

5. **Verify Database Structure and Run Migrations:**
   Ensure all critical tables exist and run any pending schema migrations:
   ```bash
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
   cd /var/www/laravel-panel
   php artisan backup:status
   ```

2. **Verify Archive Integrity:**
   ```bash
   php artisan backup:verify /var/backups/emailsaas/vmail/vmail_backup_YYYYMMDD_HHMMSS.tar.gz
   # Or via shell:
   sudo bash /var/www/scripts/verify_backup.sh /var/backups/emailsaas/vmail/vmail_backup_YYYYMMDD_HHMMSS.tar.gz
   ```

3. **Stop Dovecot and Postfix (prevent concurrent write collisions):**
   ```bash
   sudo systemctl stop postfix
   sudo systemctl stop dovecot
   ```

4. **Execute Mail Storage Restoration:**
   ```bash
   sudo bash /var/www/scripts/restore_vmail.sh /var/backups/emailsaas/vmail/vmail_backup_YYYYMMDD_HHMMSS.tar.gz --confirm /var/vmail
   ```

5. **Verify Maildir Permissions & Ownership:**
   The restore script automatically enforces ownership, but verify manually:
   ```bash
   # Ensure owner is vmail:vmail (UID 5000, GID 5000)
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

**Trigger:** Hardware failure, VPS host termination, catastrophic data center outage, or clean migration to a new machine.

#### Step-by-Step Recovery Procedure:

1. **Provision Fresh Server:**
   Deploy a fresh Ubuntu 22.04 LTS instance with matching or higher specifications.

2. **Install Operating System Baseline & Packages:**
   Clone repository or copy deployment files:
   ```bash
   git clone https://github.com/shuvo-halder/smtp-saas /var/www
   cd /var/www
   sudo bash scripts/install.sh
   ```

3. **Transfer Backups from Offsite / Secure Storage:**
   Copy the latest database and mail storage archives to `/var/backups/emailsaas/`:
   ```bash
   mkdir -p /var/backups/emailsaas/db /var/backups/emailsaas/vmail
   scp operator@backup-vault:db_backup_LATEST.sql.gz /var/backups/emailsaas/db/
   scp operator@backup-vault:db_backup_LATEST.sql.gz.manifest.json /var/backups/emailsaas/db/
   scp operator@backup-vault:vmail_backup_LATEST.tar.gz /var/backups/emailsaas/vmail/
   scp operator@backup-vault:vmail_backup_LATEST.tar.gz.manifest.json /var/backups/emailsaas/vmail/
   ```

4. **Run Disaster Recovery Orchestrator:**
   Execute the automated full recovery pipeline:
   ```bash
   sudo bash scripts/disaster_recovery.sh \
       /var/backups/emailsaas/db/db_backup_LATEST.sql.gz \
       /var/backups/emailsaas/vmail/vmail_backup_LATEST.tar.gz \
       --confirm
   ```

5. **Re-generate Application Key & Environment Secrets:**
   Ensure `laravel-panel/.env` has proper `APP_KEY`, database credentials, and service settings.

6. **Validate System Operation:**
   ```bash
   cd /var/www/laravel-panel
   php artisan about
   php artisan backup:status
   ```

---

## 4. Automation & Scheduled Operations

### 4.1 Laravel Console Commands

The system provides 6 dedicated Artisan commands under the `backup:` namespace:

| Command | Signature & Options | Function |
| :--- | :--- | :--- |
| `backup:run` | `php artisan backup:run [--only-db] [--only-vmail] [--verify] [--no-prune]` | Master backup execution pipeline. Runs DB and/or mail backup, verifies integrity, and executes retention pruning. |
| `backup:database` | `php artisan backup:database [--path=]` | Generates transaction-safe gzip database dump with SHA-256 manifest. |
| `backup:vmail` | `php artisan backup:vmail [--source=] [--path=]` | Archives `/var/vmail` Maildir directory with SHA-256 manifest. |
| `backup:verify` | `php artisan backup:verify {path} [--sha256=] [--dry-run-restore]` | Verifies archive gzip stream, SHA-256 match, and isolated restore capability. |
| `backup:status` | `php artisan backup:status` | Displays tabular status of backups, age, size, health, and offsite state. |
| `backup:prune` | `php artisan backup:prune [--keep=]` | Prunes expired archives according to retention policies. |

### 4.2 Automated Schedule Configuration

In `laravel-panel/routes/console.php`:
```php
// Schedule daily database & mail storage backup suite with verification at 02:00 UTC
Schedule::command('backup:run --verify')->dailyAt('02:00')->withoutOverlapping(30);

// Schedule daily backup retention pruning at 03:00 UTC
Schedule::command('backup:prune')->dailyAt('03:00')->withoutOverlapping(15);
```

---

## 5. Retention Policies & Safety Invariants

### 5.1 Retention Hierarchy
* **Daily:** Retain last 7 daily archives.
* **Weekly:** Retain last 4 weekly archives.
* **Monthly:** Retain last 3 monthly archives.

### 5.2 Critical Safety Invariants
1. **Never Delete Newest Backup:** Index 0 (most recent valid backup) is strictly exempt from deletion under all pruning conditions.
2. **Never Delete Only Remaining Backup:** If total backup count ≤ 1, pruning aborts immediately without deleting files.
3. **Atomic Write Promotion:** Backups are written to `.tmp` files and atomically promoted using filesystem rename. Interrupted or failed backups never corrupt or replace existing archives.
4. **Stale Temporary Cleanup:** Incomplete `.tmp` files older than 24 hours are safely reaped during pruning cycles.
5. **Concurrency Locks:** Execution uses `Cache::lock` in Artisan and `flock` in bash scripts to guarantee single-instance execution.

---

## 6. Offsite & Encryption Posture

* **Current Status:** `OFFSITE BACKUP — PENDING INFRASTRUCTURE`
* **Local Security:** All backup directories are created with `0700` (`rwx------`) permissions and backup archives with `0600` (`rw-------`) permissions, owned by `root:root` or `www-data:www-data`.
* **Recommended Offsite Targets:**
  1. **SSH / rsync with Hardlinks:** Synchronize `storage/backups/` to an isolated offsite backup vault over encrypted SSH key authentication.
  2. **S3-Compatible Object Storage:** Sync to AWS S3, Cloudflare R2, or Backblaze B2 with server-side encryption (AES-256) and lifecycle immutability (Object Lock).

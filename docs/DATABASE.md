# Database Architecture

EmailSaaS utilizes a single, shared MariaDB relational database (`email_saas_db`). It acts as the ultimate source of truth for both the Laravel Application and the physical Mail Daemons (Postfix & Dovecot).

## Core Tables

### `users` (Tenants)
- **Primary Key:** `id`
- **Fields:** `name`, `email`, `password`, `status` (`active`, `suspended`, `pending`), `plan_id`, `plan_expires_at`
- **Role:** Core tenant identity. `IdentifyTenant` middleware binds requests to a `User` instance.
- **Relationships:** `hasMany(Domain::class)`, `hasManyThrough(Mailbox::class, Domain::class)`, `hasMany(Invoice::class)`, `belongsTo(Plan::class)`.

### `plans`
- **Primary Key:** `id`
- **Fields:** `name`, `max_domains`, `max_mailboxes_per_domain`, `storage_mb_per_mailbox`, `price_monthly`, `price_yearly`, `daily_outbound_recipients`, `mailbox_daily_outbound_recipients`
- **Role:** Defines subscription constraints.
- **Quota Semantics:** `-1` denotes unlimited. `0` denotes disabled. Positive integers denote the finite daily UTC window limit.

### `tenant_outbound_usage`
- **Primary Key:** `id`
- **Foreign Key:** `user_id` (Belongs to `User`)
- **Fields:** `usage_date`, `recipient_count`, `created_at`, `updated_at`
- **Unique Constraint:** `(user_id, usage_date)`
- **Role:** Durable historical reporting ledger for outbound SMTP quota usage.
- **Synchronization Engine:** Populated and maintained by `OutboundUsageSyncService` / `php artisan outbound:usage-sync` running on an hourly schedule.
- **Ledger Semantics:** Monotonic update (`recipient_count` only increases as Redis counter rises; never decrements even if Redis cache resets/flushes). Idempotent on repeated executions. Missing Redis keys do not fabricate zero rows.
- **Model:** `App\Models\TenantOutboundUsage`.

### `domains` (Virtual Domains)
- **Primary Key:** `id`
- **Foreign Key:** `user_id` (Belongs to `User`)
- **Fields:** `domain_name` (UNIQUE), `status` (`pending`, `active`, `suspended`), `mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `dkim_public_key`, `catch_all_policy` (varchar(32), default `'disabled'`), `catch_all_mailbox_id` (nullable bigint unsigned, FK -> `mailboxes.id` on delete set null)
- **Catch-All Policies:** `disabled`, `reject`, `mailbox`, `discard`. Default is `disabled` (rejects unknown recipients during SMTP validation).
- **Integration:** 
  - Postfix (`mysql-virtual-mailbox-domains.cf`) queries this table directly to determine if it should accept mail for a domain. `status` must be `active`.
  - Postfix (`mysql-virtual-catchall.cf` / `mysql-virtual-catchall-maps.cf`) queries catch-all rules where `domains.status = 'active'`, `domains.deleted_at IS NULL`, `domains.catch_all_policy IN ('mailbox', 'discard')`, routing unknown recipients to the designated active destination mailbox or `discard` sink, strictly subordinate to physical mailboxes and explicit aliases.

### `mailboxes` (Virtual Users)
- **Primary Key:** `id`
- **Foreign Key:** `domain_id` (Belongs to `Domain`)
- **Fields:** `local_part`, `email` (UNIQUE), `password` (SHA512-CRYPT), `quota_mb`, `is_active`, `is_catchall` (boolean, default false - reflects whether mailbox is the current designated catch-all recipient)
- **Integration:** 
    - Dovecot (`dovecot-sql.conf.ext`) authenticates IMAP/POP3 logins directly against the `password` column using `SHA512-CRYPT`.
    - Postfix (`mysql-virtual-mailbox-maps.cf`) queries this table to resolve the physical `/var/vmail/` delivery path.
- **Tenant Security:** Mutated exclusively through Laravel controllers using `DB::transaction`. Bash scripts do NOT insert records here.

### `email_aliases` (Virtual Aliases - Step 29)
- **Primary Key:** `id` (bigint unsigned, auto-increment)
- **Foreign Key:** `domain_id` (bigint unsigned -> `domains.id` with `cascadeOnDelete()`)
- **Fields:** `source_email` (varchar), `destination_email` (varchar), `is_active` (boolean, default true), `created_at`, `updated_at`
- **Unique Constraint:** `(domain_id, source_email)`
- **Integration:**
  - Postfix (`mysql-virtual-alias-maps.cf` and `mysql-virtual-sender-login-maps.cf`) queries this table where `is_active = 1` and `domains.status = 'active'` to rewrite inbound delivery destinations and map permitted sender logins.
- **Model:** `App\Models\EmailAlias` (`domain()` relation, scopes for tenant filtering, search, and status).
- **Invariants:** Cannot collide with existing physical `mailboxes.email`. Cycle detection prevents direct and multi-hop circular forwarding loops. Enforces plan quota `max_aliases_per_domain`. Cascades on domain deletion.

### `invoices`
- **Primary Key:** `id`
- **Foreign Key:** `user_id`, `plan_id`
- **Fields:** `invoice_number`, `total`, `status` (`pending`, `paid`, `failed`, `cancelled`), `transaction_id`
- **Role:** Payment ledger for SSLCommerz IPN verification.

### `audit_logs` (Administrative Ledger - Step 16B.1)
- **Primary Key:** `id` (bigint unsigned, auto-increment)
- **Foreign Key:** `actor_user_id` (nullable bigint unsigned -> `users.id` with `onDelete('set null')`)
- **Fields:** `actor_email` (varchar), `action` (varchar), `entity_type` (varchar, nullable), `entity_id` (bigint unsigned, nullable), `before_state` (json, nullable), `after_state` (json, nullable), `reason` (text, nullable), `ip_address` (varchar(45), nullable), `user_agent` (text, nullable), `request_id` (varchar(100), nullable), `created_at` (timestamp, indexed)
- **Role:** Application-level append-only audit ledger recording administrative mutations, operational actions, and security status changes.
- **Indexes:** `['entity_type', 'entity_id']`, `action`, `actor_user_id`, `created_at`.
- **Model:** `App\Models\AuditLog` (`const UPDATED_AT = null;`, array casts for states; no update or delete mutations exist in application logic).
- **Redaction Invariant:** Centralized recursive secret scrubbing (`AuditService::sanitizeState()`) ensures that no plaintext passwords, SHA512-CRYPT hashes, API keys, or security tokens are ever stored in `before_state` or `after_state`.
- **Retention Status:** NOT YET DEFINED — REQUIRES ARCHITECTURE APPROVAL; no automated deletion or pruning routine runs.

### `roles` (Step 16B.2)
- **Primary Key:** `id` (bigint unsigned, auto-increment)
- **Fields:** `name` (varchar), `guard_name` (varchar, default `'web'`), `created_at`, `updated_at`
- **Unique Constraint:** `(name, guard_name)`
- **Seeded Records:** `Super Admin`, `Deliverability Operator`, `Customer Support` (all under `'web'` guard).

### `permissions` (Step 16B.2)
- **Primary Key:** `id` (bigint unsigned, auto-increment)
- **Fields:** `name` (varchar), `guard_name` (varchar, default `'web'`), `created_at`, `updated_at`
- **Unique Constraint:** `(name, guard_name)`
- **Granular Permissions:** 13 granular permissions across stats, plans, users, domains, mailboxes, invoices, smtp, and audit logs.

### `model_has_roles` (Step 16B.2)
- **Fields:** `role_id` (FK -> `roles.id`), `model_type` (varchar), `model_id` (bigint unsigned), `provenance` (varchar(64), nullable)
- **Primary Key / Index:** `(role_id, model_id, model_type)`
- **Provenance Tracking:** Distinguishes automated initial data migration backfill (`provenance = 'migration_step_16b2_backfill'`) from subsequent runtime administrative assignments, allowing non-destructive migration rollback.

### `role_has_permissions` & `model_has_permissions` (Step 16B.2)
- Standard Spatie Permission pivot tables linking roles to permissions and direct model permissions under the `'web'` guard.

### `governance_locks` (Step 16B.2)
- **Primary Key:** `lock_name` (varchar(64))
- **Fields:** `locked_at` (timestamp, nullable), `created_at`, `updated_at`
- **Role:** Dedicated row-level mutex table used by `SuperAdminGovernanceService` (`SELECT ... FOR UPDATE` on `'super_admin_governance'`) to prevent concurrent de-escalation of the platform's last remaining active Super Admin.

### `abuse_incidents` (Abuse Incident Ledger - Step 16B.4)
- **Primary Key:** `id` (bigint unsigned, auto-increment)
- **UUID:** `uuid` (char(36), unique index)
- **Foreign Keys:**
  - `tenant_id` (nullable bigint unsigned -> `users.id` with `nullOnDelete()`)
  - `domain_id` (nullable bigint unsigned -> `domains.id` with `nullOnDelete()`)
  - `mailbox_id` (nullable bigint unsigned -> `mailboxes.id` with `nullOnDelete()`)
  - `resolved_by` (nullable bigint unsigned -> `users.id` with `nullOnDelete()`)
- **Historical Snapshot Columns:**
  - `tenant_email` (varchar, nullable)
  - `domain_name` (varchar, nullable)
  - `mailbox_email` (varchar, nullable)
  - Preserves immutable identity context even if the parent tenant, domain, or mailbox is permanently deleted or purged.
- **Classification & Metrics:**
  - `incident_type` (varchar(64), indexed) — e.g. `smtp_quota_exceeded`, `daily_hard_bounce_limit_exceeded`, `consecutive_hard_bounces_exceeded`, `high_hard_bounce_rate`.
  - `severity` (varchar(32), indexed) — `critical`, `high`, `medium`, `warning`, `low`.
  - `status` (varchar(32), indexed) — `open`, `resolved`, `dismissed`.
  - `detection_source` (varchar(64)) — `policy_daemon`, `log_parser`, etc.
  - `summary` (varchar(500)) — concise human-readable description.
  - `threshold_value` (varchar(64), nullable) — threshold breached.
  - `observed_value` (varchar(64), nullable) — value observed during breach.
- **Evidence & Deduplication:**
  - `evidence` (json, nullable) — structured event details, recursively sanitized via `AuditService::sanitizeState()` to guarantee zero plaintext passwords, tokens, hashes, or payload secrets are stored.
  - `idempotency_key` (varchar(191), nullable, unique index) — deduplication key preventing duplicate entries from concurrent daemon iterations or alert bursts.
- **Lifecycle & Attribution:**
  - `occurred_at` (timestamp, indexed) — exact detection timestamp.
  - `resolved_at` (timestamp, nullable) — resolution timestamp.
  - `resolved_by` (FK -> `users.id`, nullable) — administrator who resolved/dismissed the incident.
  - `resolution_notes` (text, nullable) — administrative justification.
  - `created_at`, `updated_at` (timestamps).
- **Model:** `App\Models\AbuseIncident`.
- **Audit Integration:** Administrative resolutions (`resolve`, `dismiss`) trigger immutable audit records in `audit_logs` via `AuditService::record()`. Routine background ingestion from daemons does not pollute `audit_logs`.

### `scheduled_task_runs` (Operational Scheduler Ledger - Step 19)
- **Primary Key:** `id` (bigint unsigned, auto-increment)
- **UUID:** `uuid` (char(36), unique index)
- **Fields:**
  - `task_name` (varchar(191), indexed) — Canonical command identifier (e.g. `tenant:suspend-expired`, `outbound:usage-sync`, `mail:process-log`, `backup:run --verify`, `backup:prune`).
  - `expression` (varchar(64), nullable) — Cron expression (e.g. `* * * * *`, `0 * * * *`).
  - `status` (varchar(32), indexed) — Execution state: `running`, `success`, `failed`, `skipped`.
  - `started_at` (timestamp, indexed) — Execution start timestamp.
  - `finished_at` (timestamp, nullable) — Execution completion timestamp.
  - `duration_ms` (unsigned integer, nullable) — Execution duration in milliseconds.
  - `failure_summary` (varchar(500), nullable) — Sanitized error summary (passwords, tokens, API keys, DSN credentials, and cryptographic hashes are strictly redacted before persistence).
  - `created_at`, `updated_at` (timestamps).
- **Model:** `App\Models\ScheduledTaskRun`.
- **Retention & Pruning Policy:** Retained for 7 days; automatically pruned via daily scheduled task (`scheduled-tasks:prune`) at 04:00 UTC using `withoutOverlapping(15)`.

### `delivery_logs` (Outbound Delivery Activity Ledger - Step 28)
- **Primary Key:** `id` (bigint unsigned, auto-increment)
- **Foreign Keys:**
  - `tenant_id` (nullable bigint unsigned -> `users.id` with `nullOnDelete()`)
  - `domain_id` (nullable bigint unsigned -> `domains.id` with `nullOnDelete()`)
  - `mailbox_id` (nullable bigint unsigned -> `mailboxes.id` with `nullOnDelete()`)
- **Fields:**
  - `sender_email` (varchar(255), indexed)
  - `recipient_email` (varchar(255), indexed)
  - `queue_id` (varchar(64), nullable, indexed) — Postfix queue ID
  - `message_id` (varchar(255), nullable, indexed) — RFC 5322 Message-ID
  - `status` (varchar(32), indexed) — Outcome classification: `delivered`, `deferred`, `bounced`, `rejected`, `accepted`
  - `dsn_code` (varchar(32), nullable) — Enhanced delivery status code (e.g., `2.0.0`, `4.4.1`, `5.1.1`, `5.7.1`)
  - `smtp_code` (unsigned smallint, nullable) — 3-digit RFC 5321 reply code (e.g., `250`, `450`, `550`, `554`)
  - `response_text` (text, nullable) — Sanitized MTA response text or policy daemon rejection reason
  - `occurred_at` (timestamp, indexed) — Event timestamp
  - `created_at`, `updated_at` (timestamps)
- **Composite Indexes:**
  - `['tenant_id', 'occurred_at']`, `['tenant_id', 'status', 'occurred_at']`, `['mailbox_id', 'occurred_at']`
- **Model:** `App\Models\DeliveryLog`
- **Data Ingestion Channels:**
  1. `AbuseDetectionService::processEvent`: downstream Postfix syslog delivery events parsed by `mail:process-log` daemon.
  2. `PolicyDecisionService::recordQuotaAbuseIncident`: inbound submission rejections triggered by outbound recipient quota exhaustion or inactive account states.

## Cross-System Coupling
> **CRITICAL RULE:** Do NOT alter the schemas of `domains` or `mailboxes` without simultaneously verifying and updating `/etc/postfix/mysql-virtual-mailbox-*.cf` and `/etc/dovecot/dovecot-sql.conf.ext`. The mail stack relies precisely on the current table names and column structures.


## Database Backup & Disaster Recovery (Step 17 & 17.2)

EmailSaaS enforces transaction-safe, consistent point-in-time snapshot backups of the MariaDB relational database.

### 1. Dump Engine & Consistency Parameters
- **Production Engine:** `mariadb-dump` (or `mysqldump`).
- **Flags:**
  - `--single-transaction`: Guarantees an ACID-consistent snapshot for InnoDB tables without locking reads or writes.
  - `--quick`: Streams rows directly rather than buffering entire tables into memory.
  - `--routines --triggers --events`: Preserves stored routines, triggers, and scheduler events.
  - `--hex-blob`: Dumps binary columns (such as UUIDs or binary tokens) in hex format to eliminate character-set encoding corruption.
  - `--default-character-set=utf8mb4`: Enforces standard UTF-8 multibyte character set.
- **Compression & Encryption:** Streamed directly into gzip (`.sql.gz`), with optional OpenSSL AES-256-CBC PBKDF2 encryption at rest (`.sql.gz.enc`). Temporary files use atomic `.tmp` promotion to ensure incomplete dumps never pose as valid archives.
- **PITR Posture:** **PITR: NOT IMPLEMENTED**. The current architecture relies on daily point-of-backup consistent snapshots. Continuous binary log shipping and point-in-time replay are not implemented in the baseline.

### 2. Cryptographic Checksum & Companion Manifest
- Every database dump generates a companion `.manifest.json` containing:
  - Archive filename, absolute path, and file size in bytes.
  - UTC ISO-8601 creation timestamp.
  - SHA-256 cryptographic digest of the final archive.
  - `encrypted` boolean, `cipher` algorithm, and `unencrypted_sha256`.
  - `verified`, `verified_at`, `restore_tested`, and `restore_tested_at`.
  - `offsite_copied` and `offsite_status`.
  - Engine and database name.
- Manifests and archives are saved with `0600` permissions in `0700` directories.

### 3. Isolated Restore Verification
- Backups are tested before emergencies using `BackupVerificationService`:
  - Physical readability check and gzip decompression stream validation (with on-the-fly decryption if encrypted).
  - SHA-256 hash verification against the manifest.
  - Isolated dry-run restore into a disposable SQLite database instance (in test environments) or temporary staging schema.
  - Programmatic assertions on critical tables (`users`, `domains`, `mailboxes`, `plans`, `invoices`, `audit_logs`, `abuse_incidents`) and row counts.
  - Production and active databases are NEVER modified during restore verification.
- In production disaster recovery (`scripts/disaster_recovery.sh`), migration state is displayed via `php artisan migrate:status`, and pending migrations are executed only with explicit `--run-migrations`, failing closed immediately on any error.

## Local Database Bootstrap & Seeder Architecture (Step 22)

EmailSaaS enforces a clean, deterministic, and idempotent local development database bootstrap workflow under Laragon MySQL / MariaDB.

### 1. Local Connection & Runtime
- **Database Engine:** MySQL 8.4.3 / MariaDB 10.x running on `127.0.0.1:3306`.
- **Database Name:** `emailsaas_local`.
- **User Credentials:** `root` with empty password locally; external IPs are strictly forbidden.
- **PHP Runtime:** PHP 8.3 / 8.2 with `pdo_mysql`, `mysqli`, `openssl`, `sodium`, `pdo_sqlite`, `mbstring`, `intl`, `curl`.

### 2. Migration Inventory & Ordering
All 18 core migrations are applied in strict dependency order:
1. `0001_01_01_000001_create_cache_table.php` (Cache lock and cache stores)
2. `0001_01_01_000002_create_jobs_table.php` (Queue jobs, failed jobs, batches)
3. `2024_01_01_000001_create_plans_table.php` (Subscription plan definitions)
4. `2024_01_01_000002_create_users_table.php` (Tenant and admin accounts)
5. `2024_01_01_000003_create_domains_table.php` (Virtual email domains)
6. `2024_01_01_000004_create_mailboxes_table.php` (Virtual mail accounts & email aliases)
7. `2024_01_01_000005_create_invoices_table.php` (Billing ledger)
8. `2024_01_01_000006_add_password_to_mailboxes.php` (Mailbox authentication hashes)
9. `2026_09_07_211409_add_outbound_quotas_to_plans_table.php` (Plan daily outbound limits)
10. `2026_09_07_211410_create_tenant_outbound_usage_table.php` (Outbound daily quota tracking ledger)
11. `2026_09_23_000001_create_audit_logs_table.php` (Security and administrative audit trail)
12. `2026_10_03_000001_create_permission_tables.php` (Spatie RBAC schema)
13. `2026_10_03_000002_create_governance_locks_table.php` (Super Admin last-admin concurrency mutex)
14. `2026_10_03_000003_seed_rbac_and_backfill_legacy_admins.php` (Initial role/permission definitions & legacy backfill)
15. `2026_10_06_000001_create_abuse_incidents_table.php` (Abuse detection and resolution ledger)
16. `2026_10_15_000001_create_scheduled_task_runs_table.php` (Scheduler run metrics and execution ledger)
17. `2026_10_16_000001_create_delivery_logs_table.php` (Relational delivery and activity log ledger)
18. `2026_10_17_000001_add_catch_all_fields_to_domains_table.php` (Domain catch-all policy and fallback mailbox routing)

### 3. Seeder Inventory & Idempotency Rules
- **`PlanSeeder` (`Database\Seeders\PlanSeeder`):**
  - Seeds the 3 baseline subscription plans (`starter`, `business`, `enterprise`) with explicit domain, mailbox, storage, and outbound rate limits.
  - Implements `Plan::firstOrCreate(['slug' => ...], [...])`.
  - Guaranteed idempotent: re-executing `PlanSeeder` produces zero duplicate rows and zero constraint errors.
- **`DatabaseSeeder` (`Database\Seeders\DatabaseSeeder`):**
  - Coordinates child seeders (`PlanSeeder`).
  - Implements environment gating: `if (app()->environment('local', 'testing'))`.
  - Non-production environments seed:
    - `test@example.com` (Tenant user associated with `starter` plan, `is_admin = false`, 0 roles).
    - `admin@example.com` (Super Admin user, `is_admin = true`, assigned `super_admin` role via `assignRole()`).
  - Production environments strictly execute `PlanSeeder` only. No default users or hardcoded passwords are ever created in production.
- **Initial Super Admin Bootstrap:**
  - In production and staging, administrators are never created via seeders with static credentials. Initial Super Admin access must be created intentionally via CLI:
    `php artisan rbac:emergency-recovery <admin-email>`

## Tenant Usage, SMTP Quota & Billing Reconciliation (Step 24)

### 1. Quota & Usage Accounting Invariants
- **Real-Time Redis Key Format:** `outbound:tenant:{id}:recipients:daily:{Y-m-d}` (TTL: 48h).
- **Accounting Invariant:** `OutboundQuotaService` always supplies `$tenantKey` to the atomic Lua script whenever Redis is evaluated, guaranteeing that tenants with unlimited plan quotas (`-1`) who have restricted mailboxes still have their total recipient count captured in Redis and synchronized to `tenant_outbound_usage`.
- **Durable MariaDB Ledger:** `tenant_outbound_usage` records are strictly monotonic (`recipient_count` only increases with Redis activity; never decrements).
- **Missing Plan Posture:** If a tenant has no plan assigned, `OutboundQuotaService` fails closed with `REJECTED_QUOTA`.

### 2. Billing & Domain Lifecycle Invariants
- **Cancelled Invoices:** IPN webhooks targeting invoices with `status = 'cancelled'` are strictly rejected, preventing corrupt billing activations.
- **Pessimistic Concurrency Locking:** `BillingService::markInvoicePaid()` locks both `invoices` and `users` rows using `lockForUpdate()`, guaranteeing idempotency and race condition prevention.
- **Domain State Preservation:** Auto-suspension (`tenant:suspend-expired`) only marks `active` domains as `suspended`, leaving `pending` domains as `pending`. Billing reactivation restores only `suspended` domains, guaranteeing unverified/pending domains are never prematurely activated.

### 3. Read-Only Reconciliation Audit Engine
- **Engine:** `App\Services\Reconciliation\TenantUsageReconciliationService`
- **CLI Tool:** `php artisan tenant:reconcile-usage {--tenant=} {--date=} {--detailed} {--json}`
- **Automated Schedule:** Runs daily at 05:00 UTC with `withoutOverlapping(15)` mutex in `routes/console.php`.
- **Audit Scope:**
  - Detects `USAGE_LEDGER_DESYNC` when Redis ephemeral counts lead MariaDB historical rows.
  - Detects `MAILBOX_SUM_EXCEEDS_TENANT` when mailbox Redis counters exceed tenant totals.
  - Detects `TENANT_OVER_QUOTA` and `MAILBOX_OVER_QUOTA`.
  - Detects `ACTIVE_EXPIRED_SUBSCRIPTION` and `ACTIVE_WITHOUT_PLAN`.
  - Detects `SUSPENDED_TENANT_ACTIVE_DOMAINS`.
  - Detects `PAID_INVOICE_MISSING_PAID_AT` and `PAID_INVOICE_CURRENCY_MISMATCH`.
  - Strictly read-only: performs zero database mutations and zero Redis modifications.

---

## 18. Local Synthetic Data Fixtures & Verification Invariants (Step 25)
- **Local Environment Gating:** `SeedAuditDemoCommand` (`php artisan emailsaas:seed-audit-demo`) strictly executes only in `local` environment against `127.0.0.1:3306/emailsaas_local`. Halts on production IP `103.131.144.61` or unapproved database host.
- **Idempotency Guarantee:** Synthetic records utilize explicit query checks (`where('email', ...)`, `where('domain_name', ...)`, `where('invoice_number', ...)`, `where('idempotency_key', ...)`). Re-running the command reuses existing records and inserts 0 duplicates.
- **Controlled Discrepancy Fixtures:**
  - `audit-demo-expired@example.test`: Seeded with `plan_expires_at` in the past to verify reconciliation engine detection (`ACTIVE_EXPIRED_SUBSCRIPTION`).
  - `audit-demo-overquota@example.test`: Seeded with 550 recipient usage on a 500-recipient starter plan to verify `TENANT_OVER_QUOTA` alerting.
  - `audit-demo-suspended@example.test`: Seeded with suspended state to verify domain suspension invariants.
- **Safe Cleanup Guarantee:** Running `php artisan emailsaas:seed-audit-demo --cleanup` targets only records in the explicit synthetic ownership registry (`audit-demo-` prefix, `@example.test` domain), deleting them in safe relational order (mailboxes -> domains -> invoices -> usage -> incidents -> audit logs -> users). Canonical baseline users (`test@example.com`, `admin@example.com`) and subscription plans are permanently preserved.

---

## 19. Email Aliases & Postfix SQL Map Resolution Architecture (Step 29 & Step 30)

### 1. `email_aliases` Schema & Relational Invariants
- **Table:** `email_aliases`
- **Columns:**
  - `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
  - `domain_id`: `BIGINT UNSIGNED`, foreign key referencing `domains(id)` with `ON DELETE CASCADE`.
  - `source_email`: `VARCHAR(255) NOT NULL`, virtual routing address (e.g. `support@tenantdomain.com`).
  - `destination_email`: `VARCHAR(255) NOT NULL`, forwarding target address (internal mailbox or external email).
  - `is_active`: `TINYINT(1) DEFAULT 1`, routing toggle switch.
  - `created_at`, `updated_at`: `TIMESTAMP NULL`.
- **Integrity Constraints:**
  - `UNIQUE(source_email)`: Prevents duplicate alias creation across tenants and domains.
  - Foreign key constraint cascades deletion: deleting a domain deletes all associated aliases automatically.
  - Application-level uniqueness check enforces mutual exclusion with `mailboxes.email` (an alias cannot collide with a physical mailbox on the same domain).
  - Loop and cycle prevention rejects self-forwarding (`source === destination`) and multi-hop cyclic graphs (`A -> B -> C -> A`).

### 2. Postfix Dynamic MySQL Map Specifications
Postfix evaluates routing lookups at inbound SMTP connection time. To prevent routing to soft-deleted or unverified entities, all `.cf` configuration queries explicitly enforce `domains.status = 'active'`, `domains.deleted_at IS NULL`, and `mailboxes.deleted_at IS NULL`:

1. **Virtual Alias Maps (`postfix-config/mysql-virtual-aliases.cf` & `server-configs/mysql-virtual-alias-maps.cf`):**
   ```sql
   SELECT email_aliases.destination_email
   FROM email_aliases
   INNER JOIN domains ON email_aliases.domain_id = domains.id
   WHERE email_aliases.source_email = '%s'
     AND email_aliases.is_active = 1
     AND domains.status = 'active'
     AND domains.deleted_at IS NULL;
   ```
2. **Virtual Domains Map (`postfix-config/mysql-virtual-domains.cf`):**
   ```sql
   SELECT domain_name
   FROM domains
   WHERE domain_name = '%s'
     AND status = 'active'
     AND deleted_at IS NULL;
   ```
3. **Virtual Mailbox Maps (`postfix-config/mysql-virtual-mailboxes.cf`):**
   ```sql
   SELECT CONCAT(domains.domain_name, '/', mailboxes.local_part, '/')
   FROM mailboxes
   INNER JOIN domains ON mailboxes.domain_id = domains.id
   WHERE mailboxes.email = '%s'
     AND mailboxes.is_active = 1
     AND domains.status = 'active'
     AND domains.deleted_at IS NULL
     AND mailboxes.deleted_at IS NULL;
   ```
4. **Sender Login Maps (`postfix-config/mysql-virtual-sender-login-maps.cf`):**
   ```sql
   SELECT mailboxes.email
   FROM mailboxes
   INNER JOIN domains ON mailboxes.domain_id = domains.id
   WHERE mailboxes.email = '%s'
     AND mailboxes.is_active = 1
     AND domains.status = 'active'
     AND domains.deleted_at IS NULL
     AND mailboxes.deleted_at IS NULL
   UNION
   SELECT email_aliases.destination_email
   FROM email_aliases
   INNER JOIN domains ON email_aliases.domain_id = domains.id
   WHERE email_aliases.source_email = '%s'
     AND email_aliases.is_active = 1
     AND domains.status = 'active'
     AND domains.deleted_at IS NULL;
   ```

## Mailbox Storage & Dovecot Quota Enforcement (Step 31)

### 1. Schema & Field Semantics
- **`mailboxes.quota_mb` (`INT`, default: `1024`, nullable):**
  - Defines the storage ceiling for the virtual mailbox in Megabytes.
  - Value `-1` or `NULL` indicates an unlimited storage allocation.
  - Values $\ge 0$ define the hard disk quota enforced during incoming LDA / LMTP message delivery.
- **`plans.storage_limit_mb` (`INT`, default: `10240`):**
  - Defines the total tenant storage capacity across all provisioned mailboxes.
  - Managed dynamically via `Plan` limits; sum of tenant's `quota_mb` allocations must not exceed `storage_limit_mb` unless the plan allows unlimited storage (`-1`).

### 2. Dovecot SQL Dynamic Quota Rule Mapping
Dovecot LDA and IMAP/POP3 engines query `dovecot-sql.conf.ext` to resolve virtual mailbox destinations and enforce per-mailbox storage limits:
```sql
# dovecot-config/dovecot-sql.conf.ext & server-configs/dovecot-sql.conf.ext
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
- Multiplier `1048576` converts `mailboxes.quota_mb` into exact bytes for Dovecot's `quota_rule` parameter `*:bytes=<limit>`.
- Inbound deliveries exceeding `quota_rule` are deferred or rejected by Dovecot with SMTP 452/552 (Mailbox quota exceeded).


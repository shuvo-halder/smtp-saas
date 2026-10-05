# Database Architecture

EmailSaaS utilizes a single, shared MariaDB relational database (`email_saas_db`). It acts as the ultimate source of truth for both the Laravel Application and the physical Mail Daemons (Postfix & Dovecot).

## Core Tables

### `users` (Tenants)
- **Primary Key:** `id`
- **Fields:** `name`, `email`, `password`, `status` (`active`, `suspended`, `pending`), `plan_id`, `plan_expires_at`
- **Role:** Core tenant identity. `IdentifyTenant` middleware binds requests to a `User` instance.

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
- **Fields:** `domain_name` (UNIQUE), `status` (`pending`, `active`, `suspended`), `mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `dkim_public_key`
- **Integration:** Postfix (`mysql-virtual-mailbox-domains.cf`) queries this table directly to determine if it should accept mail for a domain. `status` must be `active`.

### `mailboxes` (Virtual Users)
- **Primary Key:** `id`
- **Foreign Key:** `domain_id` (Belongs to `Domain`)
- **Fields:** `local_part`, `email` (UNIQUE), `password` (SHA512-CRYPT), `quota_mb`, `is_active`
- **Integration:** 
    - Dovecot (`dovecot-sql.conf.ext`) authenticates IMAP/POP3 logins directly against the `password` column using `SHA512-CRYPT`.
    - Postfix (`mysql-virtual-mailbox-maps.cf`) queries this table to resolve the physical `/var/vmail/` delivery path.
- **Tenant Security:** Mutated exclusively through Laravel controllers using `DB::transaction`. Bash scripts do NOT insert records here.

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
- **Composite Indexes:**
  - `['tenant_id', 'created_at']`, `['incident_type', 'created_at']`, `['status', 'created_at']`.
- **Model:** `App\Models\AbuseIncident`.
- **Audit Integration:** Administrative resolutions (`resolve`, `dismiss`) trigger immutable audit records in `audit_logs` via `AuditService::record()`. Routine background ingestion from daemons does not pollute `audit_logs`.

## Cross-System Coupling
> **CRITICAL RULE:** Do NOT alter the schemas of `domains` or `mailboxes` without simultaneously verifying and updating `/etc/postfix/mysql-virtual-mailbox-*.cf` and `/etc/dovecot/dovecot-sql.conf.ext`. The mail stack relies precisely on the current table names and column structures.



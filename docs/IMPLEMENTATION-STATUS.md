# Implementation Status

## Authentication
- [x] Login API
- [x] Registration API
- [x] Sanctum authentication & CSRF
- [x] Logout
- [x] User endpoint for current context
- [x] Authorization middleware (`EnsureAdmin` [hardened with suspended status check], `EnsureActiveSubscription`)

## Multi-Tenancy
- [x] Next.js Edge wildcard routing
- [x] Laravel Tenant identification (`IdentifyTenant` middleware)
- [x] Tenant context binding (`app('tenant')`)
- [x] Tenant-aware queries (Route model binding + `$request->user()->domains()`)
- [x] Cross-tenant access protection (Eloquent Policies)

## Mail Infrastructure (Postfix / Dovecot)
- [x] MariaDB native SQL queries configured
- [x] Postfix MTA mapped to `domains` and `mailboxes` tables
- [x] Dovecot IMAP mapped to `domains` and `mailboxes` tables
- [x] Mailbox directory creation decoupled from DB writing in shell scripts
- [x] `/var/vmail` and `/var/vmail_archive` standardization
- [x] DKIM generation (`setup_dkim.sh`)

## Legacy Cleanup
- [x] Remove obsolete Laravel web controllers and Blade files
- [x] Clear unused `routes/web.php` authenticated endpoints
- [x] Remove duplicate legacy migration `0001_01_01_000000_create_users_table.php` (restoring clean test suite)

## Domains & Mailboxes
- [x] Domain CRUD API
- [x] Domain DNS verification API
- [x] Mailbox creation API (with `DB::transaction`)
- [x] Mailbox password generation and hashing (SHA512-CRYPT)
- [x] Mailbox suspend/activate toggle API
- [x] Soft-deletion and archival (`remove_mailbox.sh`)

## Webmail
- [x] Roundcube configuration
- [x] Dovecot Master User logic
- [x] OTP Generation API
- [x] Redis caching of 60s OTP keys
- [x] Frontend one-click SSO redirect

## Billing & Subscription
- [x] Plans CRUD (DB)
- [x] Checkout Initiation API
- [x] SSLCommerz Gateway redirect
- [x] IPN / Webhook handler
- [x] Signature, amount, and currency validation (Strict BDT match)
- [x] Invoice and User atomic updates (`DB::transaction` with pessimistic `lockForUpdate()`)
- [x] Payment Success/Fail/Cancel routes
- [x] Tenant Suspension Redesign & Billing Lifecycle Integration (Step 16B.3 IMPLEMENTED & VERIFIED):
  - [x] Hardened `tenant:suspend-expired` command with Spatie administrative role and `is_admin` exemption
  - [x] Per-tenant locked transaction with `lockForUpdate()` and re-check of `plan_expires_at` inside lock
  - [x] Invariant domain scoping: auto-suspension and admin suspension only target `status = 'active'` domains, preserving `pending` domains
  - [x] Independent mailbox active state preserved across billing suspension and reactivation (zero mailbox state resets)
  - [x] Pessimistic lock and idempotency verification on `invoices` and `users` in `BillingService::markInvoicePaid()`
  - [x] Strict IPN currency validation (`currency` matching `invoice->currency` [BDT]) in addition to amount checking
  - [x] Dual audit logging: `AuditService::record()` logging on auto-suspension, payment activation, and admin suspend/activate
  - [x] Comprehensive test coverage in `BillingLifecycleTest.php` (18 passing tests, 67 assertions)

## Background Automation
- [x] System Cron configured
- [x] `tenant:suspend-expired` command (Hardened with `where('is_admin', false)` and Spatie administrative role exemptions, per-tenant pessimistic locks, and persistent audit logging)
- [x] Supervisor queue worker (`mailsaas-worker.conf`)

## Backup & Disaster Recovery
- `[ ]` Database backup pipeline
- `[ ]` `/var/vmail` offsite sync pipeline

## Admin Control Plane
- `[x]` Admin Dashboard (Stats, System Health, Revenue)
- `[x]` Tenants Datatable & Toggle (Suspend/Activate)
- `[x]` Tenant Details (Drilldown into Domains/Mailboxes/Invoices)
- `[x]` Global Domains Datatable
- `[x]` Global Mailboxes Datatable
- `[x]` Plans Datatable (CRUD fully implemented)
- `[x]` Invoices Datatable
- `[x]` Subscription Management (Display-only view enforced)
- `[x]` SMTP Management & Observability (Step 16A IMPLEMENTED):
  - `[x]` Cluster-wide SMTP deliverability overview (attempts, hard/soft bounces, bounce rate, queue size, active abuse alerts)
  - `[x]` Live Redis availability detection with graceful degradation (null metrics + warning banner on disconnection)
  - `[x]` Tenant SMTP metrics table with plan limits, live usage, and daily quota progress indicators
  - `[x]` Mailbox SMTP metrics table with consecutive hard bounce failure streak tracking
  - `[x]` Mailbox administrative toggle (active/disabled) with strict parent domain/tenant/subscription invariant validation
  - `[x]` Mailbox consecutive hard bounce streak reset in Redis (`outbound:abuse:mailbox:{id}:consecutive_hard`)
  - `[x]` Mailbox password reset (SHA512-CRYPT hashed, one-time admin display, zero plaintext persistence)
  - `[x]` Active abuse warnings dashboard with jump-to-mailbox navigation
  - `[x]` Dedicated operational audit logging channel (`storage/logs/admin-smtp.log`)
    - `[x]` Persistent administrative `audit_logs` table (Step 16B.1 IMPLEMENTED)
    - `[x]` Tenant-level manual suspension (Step 16B.3 IMPLEMENTED)
    - `[x]` Granular RBAC / Spatie permissions (Step 16B.2 IMPLEMENTED & VERIFIED)
    - `[x]` Persistent abuse incident history table (Step 16B.4 IMPLEMENTED & VERIFIED)
- `[x]` Admin Audit Logs (Step 16B.1 IMPLEMENTED):
  - `[x]` Relational append-only application audit ledger & `AuditLog` Eloquent model
  - `[x]` Centralized `AuditService` with recursive sensitive key/hash redaction and fail-safe persistence
  - `[x]` Dual logging with pre-logging secret redaction (persistent MariaDB ledger + `storage/logs/admin-smtp.log` operational file log)
  - `[x]` Read-only Admin API (`GET /api/admin/audit-logs`, `GET /api/admin/audit-logs/{id}`) with bounded pagination (<= 50) and filters
- `[x]` Admin User Lifecycle & Session Verification (Step 16B.2 Phase A IMPLEMENTED)
- `[x]` Granular RBAC (Roles/Permissions) (Step 16B.2 IMPLEMENTED & VERIFIED):
  - `[x]` Spatie Permission guard locked to `web` (`RBAC-DEC-01`)
  - `[x]` Super Admin explicit permission assignment; zero `Gate::before` bypass (`RBAC-DEC-02`)
  - `[x]` Mailbox password reset authorized for Super Admin + Deliverability Operator (`RBAC-DEC-03`)
  - `[x]` Tenant administrator segregation in suspend/activate endpoints (`RBAC-DEC-04`)
  - `[x]` Operational authorization denial logging to `storage/logs/security.log` (`RBAC-DEC-05`)
  - `[x]` Dedicated `governance_locks` table & `SuperAdminGovernanceService` concurrency protection (`RBAC-DEC-06`)
  - `[x]` Emergency recovery CLI `rbac:emergency-recovery` with `--reactivate` status preservation (`RBAC-DEC-07`)
  - `[x]` Explicit test factory states in `UserFactory` (`superAdmin`, `deliverabilityOperator`, `customerSupport`, `withoutRoles`) (`RBAC-DEC-08`)
  - `[x]` Pure-DML backfill migration with provenance preservation on rollback (`RBAC-DEC-09`, `Supplementary Decision 2`)
  - `[x]` Zero default privileges for new administrators; fail-closed least privilege (`RBAC-DEC-10`)
  - `[x]` Granular route middleware `RequireAdminPermission` across all administrative endpoints
  - `[x]` Tenant invoice authorization hardening in `InvoicePolicy::view()` (Finding 6 Remediation: explicit `admin.invoices.read` required; legacy `is_admin` bypass eliminated)
- `[x]` Admin Deliverability, Abuse & DR Health Visibility (Step 18 IMPLEMENTED):
  - `[x]` Persistent Abuse Incident Ledger UI (`/admin/smtp?tab=incidents`) with summary metrics (Open, Critical, High, Resolved, Total in view), severity badges, multi-criteria filters (status, severity, incident type, detection source, search, date range), and paginated data table
  - `[x]` Incident Investigation Modal displaying UUID, type, severity, detection source, timestamps, immutable snapshot entities (tenant, domain, mailbox), metric observation breaches, and sanitized evidence payload inspector (HTML-safe JSON)
  - `[x]` Incident Resolution & Dismissal workflow with status selection (`resolved` / `dismissed`), optional operational notes (max 1000 chars), duplicate submission prevention, and RBAC error handling (`admin.smtp.mailbox.toggle` required)
  - `[x]` Deliverability Overview integration (`/admin/smtp?tab=overview`) with real cluster telemetry, mail queue size, and active abuse warnings
  - `[x]` Disaster Recovery & Backup Health Dashboard (`/admin/backup-dr`) displaying verified backup pipelines, cryptographic posture (OpenSSL AES-256-CBC PBKDF2), provider-neutral offsite replication, and restore verification matrix
  - `[x]` DR Health HTTP API Gap documented with status `REQUIRES ARCHITECTURE APPROVAL`, providing complete proposed endpoint contract (`GET /api/admin/system/backup-status`) and security boundaries
  - `[x]` Admin navigation sidebar updated with `Backup & DR` (`ShieldCheck`)
  - `[x]` Zero-hardcoded-English policy strictly enforced with comprehensive `next-intl` localization dictionaries in `frontend/messages/en.json`
  - `[x]` Frontend verification test suite (`npm run test:step18`) and Next.js production build (`npm run build`) 100% passing


## SMTP Architecture & Management
- `[x]` Direct Dovecot/Postfix SQL Binding
- `[x]` Suspension / Active State Enforcement
- `[x]` Postfix `smtpd_sender_login_maps` implementation (Vulnerability Fix)
- `[x]` Mailbox Creation API Password Fix (Mass Assignment Bug Fix)
- `[x]` SMTP Hourly/Daily Outbound Quotas:
  - `[x]` MariaDB quota limits & historical usage ledger (Step 11)
  - `[x]` Redis atomic quota service & Lua enforcement (Step 12)
  - `[x]` Laravel SMTP Policy Daemon (`policy:serve`) (Step 13)
  - `[x]` Postfix outbound quota integration at `smtpd_data_restrictions` (Step 13)
  - `[x]` Redis -> MariaDB Usage Synchronization (`outbound:usage-sync` hourly scheduler & idempotent monotonic ledger sync) (Step 14)
- `[x]` Outbound Bounce / Usage Log Parsing / Abuse Detection (Step 15 IMPLEMENTED WITH DEPLOYMENT REQUIREMENT - HARDENED)
  - `[x]` Streaming log parser with inode + offset cursor persistence in Redis (`outbound:abuse:parser:cursor`)
  - `[x]` Log rotation tail draining (`mail.log.1` read to completion before resetting cursor to 0 on new file)
  - `[x]` Transactional cursor checkpointing (`saveCursor` deferred until after successful batch evaluation; zero mutations on `--dry-run`)
  - `[x]` Intermediate filter discrimination (`NormalizedMailEvent::TYPE_INTERMEDIATE_FILTER_HANDOFF` on `smtp-amavis`/`127.0.0.1:10024`; never mutates delivery success or resets consecutive hard bounces)
  - `[x]` Queue ID alias correlation (`outbound:abuse:qid_alias:{newQid} => oldQid`) mapping Amavis reinjections (`queued as <NEW_QID>`) back to original sender
  - `[x]` Atomic soft bounce deduplication (`outbound:abuse:seen:{queueId}:{recipient}:{classification}` with 24h TTL) preventing overcounting of deferred retries
  - `[x]` Deterministic RFC 3463 bounce classifier (`SUCCESS`, `HARD_BOUNCE`, `SOFT_BOUNCE`, `UNKNOWN`)
  - `[x]` Sender-to-tenant attribution (`Mailbox -> Domain -> User`) with system fallbacks
  - `[x]` Atomic Redis counters (`outbound:abuse:tenant:*`, `outbound:abuse:mailbox:*`) with 48h TTL
  - `[x]` Abuse threshold detection (10% bounce rate on >=20 accepted outbound attempts, 50 daily hard bounces, 15 consecutive hard bounces)
  - `[x]` Race-safe alert cooldown (`SET NX` 24h) and structured logging (`storage/logs/abuse.log`)
  - `[x]` Artisan command `mail:process-log` (`--lines=1000`, `--dry-run`, `--path=`) scheduled every 5 minutes with overlap protection
  - `[x]` Deployment requirement: Ubuntu `/var/log/mail.log` requires read permissions (`adm` group or POSIX ACL for `www-data`)
- `[x]` Admin SMTP Management & Observability (Step 16A IMPLEMENTED)
- `[x]` Persistent Abuse Incident Ledger (Step 16B.4 IMPLEMENTED & VERIFIED):
  - `[x]` Relational `abuse_incidents` ledger table with auto-increment `id` and unique `uuid`
  - `[x]` Cascade-safe design with `nullOnDelete()` foreign keys and immutable snapshot columns (`tenant_email`, `domain_name`, `mailbox_email`)
  - `[x]` Deduplication via deterministic `idempotency_key` unique index
  - `[x]` Eloquent `AbuseIncident` model with scopes (`open`, `resolved`, `forTenant`, `ofType`, `severity`) and dual ID/UUID route binding
  - `[x]` Centralized `AbuseIncidentService` with recursive sensitive secret scrubbing via `AuditService::sanitizeState()`
  - `[x]` Log Parser detector integration in `AbuseDetectionService::emitAlert()` with fail-safe dispatch
  - `[x]` Policy Daemon detector integration in `PolicyDecisionService` with atomic Redis `SET NX` daily cooldown and fail-safe SMTP rejection preservation
  - `[x]` Administrative API endpoints (`GET /api/admin/smtp/incidents`, `GET /api/admin/smtp/incidents/{incident}`, `POST /api/admin/smtp/incidents/{incident}/resolve`)
  - `[x]` Granular RBAC enforcement (`admin.smtp.read` and `admin.smtp.mailbox.toggle`)
  - `[x]` Persistent administrative audit logging via `AuditService::record()` on incident resolution and dismissal
  - `[x]` Full test coverage in `tests/Feature/AbuseIncidentLedgerTest.php` (16 passing tests, 81 assertions; test suite total: 214 passing, 888 assertions)

## Backup & Disaster Recovery (Step 17 & 17.2 IMPLEMENTED & HARDENED)
- `[x]` Dedicated `backup` logging channel in `config/logging.php` (`storage/logs/backup.log`)
- `[x]` Enterprise configuration in `config/backup.php` (storage paths, retention limits, critical tables, encryption at rest, offsite transport settings)
- `[x]` Transaction-safe compressed database dumps with SHA-256 manifests (`DatabaseBackupService`)
- `[x]` Mail storage (`/var/vmail`) archiving preserving Maildir structure & numeric ownership (`MailStorageBackupService`)
- `[x]` Production-ready AES-256-CBC PBKDF2 encryption at rest (`.sql.gz.enc` and `.tar.gz.enc`) with fail-closed security (`BackupEncryptionService`)
- `[x]` Provider-neutral offsite replication abstraction (`BackupOffsiteService` and `scripts/sync_offsite.sh`) supporting `rsync`, `scp`, `local`, and `custom` transports with fail-safe local preservation
- `[x]` Multi-tier integrity verification engine (`BackupVerificationService`):
  - `[x]` Physical file existence and non-empty size check
  - `[x]` OpenSSL envelope header (`Salted__`) and gzip magic header (`\x1f\x8b`)
  - `[x]` On-the-fly decryption and stream decompression integrity
  - `[x]` Cryptographic SHA-256 validation against companion JSON manifests
- `[x]` Isolated dry-run database restore verification into disposable database validating table structures and row counts
- `[x]` Isolated dry-run mail storage restore verification validating `cur`, `new`, and `tmp` Maildir folders
- `[x]` Calendar-aware retention service (`BackupRetentionService`):
  - `[x]` Grandfather-Father-Son (GFS) bucket allocation (7 daily, 4 weekly, 3 monthly calendar snapshots)
  - `[x]` Invariant: Newest backup (index 0) is NEVER pruned
  - `[x]` Invariant: Single remaining backup is NEVER pruned
  - `[x]` Stale `.tmp` cleanup for files older than 24 hours
- `[x]` Complete suite of 6 Artisan commands:
  - `[x]` `backup:run` (master pipeline with `--only-db`, `--only-vmail`, `--verify`, `--sync-offsite`, `--no-prune`)
  - `[x]` `backup:database` (transaction-safe dump with `--path=`, `--connection=`, optional encryption)
  - `[x]` `backup:vmail` (mail archive with `--source=`, `--path=`, optional encryption)
  - `[x]` `backup:verify` (archive integrity, decryption verification, SHA-256, and isolated restore test)
  - `[x]` `backup:status` (tabular overview of backup health, encryption posture, storage footprint, and offsite state)
  - `[x]` `backup:prune` (calendar-aware retention policy enforcement)
- `[x]` Native Linux production scripts in `scripts/`:
  - `[x]` `scripts/backup_db.sh` (mariadb-dump/mysqldump with `--single-transaction`, gzip, optional AES-256 encryption, SHA-256 manifest, flock)
  - `[x]` `scripts/backup_vmail.sh` (tar archive with `--numeric-owner --preserve-permissions`, gzip, optional AES-256 encryption, manifest, flock)
  - `[x]` `scripts/verify_backup.sh` (archive verification, decryption test, `gzip -t`, SHA-256 cross-reference)
  - `[x]` `scripts/restore_db.sh` (interactive database restore with on-the-fly stream decryption and safety confirmation)
  - `[x]` `scripts/restore_vmail.sh` (interactive mail restore with on-the-fly stream decryption, enforcing `vmail:vmail` 5000:5000 and 700/600 permissions)
  - `[x]` `scripts/sync_offsite.sh` (provider-neutral replication script with fail-safe local preservation)
  - `[x]` `scripts/disaster_recovery.sh` (hardened bare-metal VPS disaster recovery orchestrator with migration status inspection, fail-closed `--run-migrations`, and cache clearing)
  - `[x]` `scripts/install.sh` (Step 19: idempotent Laravel scheduler cron installation)
- `[x]` Automated scheduling registered in `routes/console.php` (daily `backup:run --verify` at 02:00, daily `backup:prune` at 03:00)
- `[x]` Comprehensive operator disaster recovery runbook (`docs/DISASTER-RECOVERY-RUNBOOK.md`) covering Scenarios A, B, and C
- `[x]` PITR Architecture Decision: Option B (`PITR: NOT IMPLEMENTED`; point-of-backup daily snapshots documented)
- `[x]` Verification Matrix:
  - Local Backups, Encryption & Isolated Restores: `VERIFIED`
  - Real MariaDB 10.11+ Live Restore: `PARTIALLY VERIFIED` (SQL syntax, transactions, and schema verified; live daemon pending remote infrastructure)
  - Real Dovecot MDA Live Restore: `PARTIALLY VERIFIED` (Maildir extraction and permissions verified; live Dovecot indexing pending remote infrastructure)
- `[x]` Feature test suite (`tests/Feature/BackupAndDisasterRecoveryTest.php`): 14 passing tests, 92 assertions (test suite total: 228 passing, 980 assertions, 0 failures, 0 regressions)

## Operational Reliability, Scheduled Jobs & System Health (Step 19 IMPLEMENTED & VERIFIED)
- `[x]` Scheduler reliability audit:
  - `[x]` Added `withoutOverlapping(10)` mutex to `tenant:suspend-expired` preventing concurrency collisions under load
  - `[x]` Verified mutex locks across all 6 scheduled tasks (`outbound:usage-sync`, `mail:process-log`, `backup:run`, `backup:prune`, `tenant:suspend-expired`, `scheduled-tasks:prune`)
  - `[x]` Confirmed UTC timezone consistency across scheduling routines
- `[x]` Scheduled task execution tracking ledger:
  - `[x]` Migration `2026_10_15_000001_create_scheduled_task_runs_table.php` provisioning relational `scheduled_task_runs` table with UUID and composite indexes
  - `[x]` Eloquent model `ScheduledTaskRun` with scopes (`successful`, `failed`, `recent`, `forTask`)
  - `[x]` `ScheduledTaskTrackerService` implementing fail-safe execution tracking for task starting, completion, failure, and skipping
  - `[x]` Single-point failure sanitization stripping DSNs, passwords, Bearer tokens, hashes, and secrets
  - `[x]` Automated 7-day retention pruning via daily scheduled task (`scheduled-tasks:prune`) at 04:00 UTC
- `[x]` Native Laravel 11 Console Event listeners wired in `AppServiceProvider`:
  - `[x]` `ScheduledTaskStarting` -> `recordStart()`
  - `[x]` `ScheduledTaskFinished` -> `recordFinish()` with duration in milliseconds
  - `[x]` `ScheduledTaskFailed` -> `recordFailure()` with sanitized error summary
  - `[x]` `ScheduledTaskSkipped` -> `recordSkipped()` with reason
- `[x]` Multi-component `SystemHealthService`:
  - `[x]` Application core status (PHP version, Laravel version, environment, timezone, debug mode)
  - `[x]` Database connectivity and latency measurement (`SELECT 1`) with sanitized error trapping
  - `[x]` Redis connectivity and latency measurement with graceful degradation (`fail-open` mode detection)
  - `[x]` Scheduler freshness calculation based on execution heartbeat (`fresh` <= 5m, `delayed` 5-15m, `stale` > 15m)
  - `[x]` 24-hour task failure reliability monitoring
  - `[x]` Queue health monitoring (pending jobs, failed jobs in 24h)
  - `[x]` Backup posture inspection from trusted local companion manifests (respecting Step 18 governance; zero unapproved HTTP endpoints)
  - `[x]` Composite system status resolution (`HEALTHY`, `DEGRADED`, `UNHEALTHY`, `UNKNOWN`, `NOT_CONFIGURED`)
- `[x]` API Endpoints:
  - `[x]` Minimal public liveness probe (`GET /api/health` and `/up`)
  - `[x]` Authenticated Admin System Health diagnostics (`GET /api/admin/system/health`, protected by `EnsureAdmin` and `admin.stats.read`)
  - `[x]` Authenticated Admin Task Runs history (`GET /api/admin/system/tasks`, bounded max 50/page)
- `[x]` Admin System Health Dashboard UI:
  - `[x]` Responsive Next.js 14 page at `/admin/system-health`
  - `[x]` Dynamic status banner with live heartbeat timestamps and manual refresh
  - `[x]` 7 Component diagnostic posture cards
  - `[x]` Recent scheduled task execution ledger table with status pills, durations, and failure inspection modal
  - `[x]` RBAC 403 Forbidden handling and error retry states
  - `[x]` Admin navigation sidebar updated with `System Health` (`Activity` icon)
  - `[x]` Strict zero-hardcoded-English policy enforced via `next-intl` dictionaries in `frontend/messages/en.json`
- `[x]` Verification Matrix:
  - `[x]` Automated frontend test suite (`npm run test:step19`): 100% passed
  - `[x]` TypeScript typecheck (`npx tsc --noEmit`): 0 errors
  - `[x]` Next.js production build (`npm run build`): 24/24 static pages generated cleanly
  - `[x]` Feature test suite (`tests/Feature/SystemHealthAndSchedulerTest.php`): 15 tests, 84 assertions passing
  - `[x]` Full regression test suite (`php artisan test`): **243 passed / 1064 assertions / 0 failures / 0 regressions**

---

## Step 20 — Staging Deployment, Live Infrastructure Acceptance & Disaster Recovery Drill

**Phase:** Staging Deployment Review, Live Infrastructure Acceptance Audit, and Disaster Recovery Runbook Hardening
**Verdict:** **`PARTIALLY VERIFIED`** / **`BLOCKED — REQUIRES INFRASTRUCTURE`**

- `[x]` Pre-Deployment Baseline & Health Verification:
  - `[x]` Backend: 243 tests / 1,064 assertions passing cleanly (0 failures, 0 errors, 0 skipped).
  - `[x]` Frontend: TypeScript typecheck 0 errors, Step 19 tests 100% passed, Next.js 14 production build succeeds with 24/24 static pages.
  - `[x]` Git tree clean on `main` branch.
- `[x]` Scheduler & Cron Audit:
  - `[x]` All 6 tasks verified via `php artisan schedule:list` (`tenant:suspend-expired`, `outbound:usage-sync`, `mail:process-log`, `backup:run`, `backup:prune`, `scheduled-tasks:prune`).
  - `[x]` Mutex configuration verified across all jobs.
  - `[x]` System cron installation in `scripts/install.sh` verified as idempotent.
- `[x]` Infrastructure & Security Audit:
  - `[x]` Identified hardcoded domain (`mail.yourdomain.com`), unconditional UFW resets, and certbot challenge gaps in `scripts/install.sh`. Documented parameterization requirements prior to staging execution.
  - `[x]` Identified remote database host `DB_HOST=103.131.144.61` in `.env.example`; documented strict isolation safeguards preventing accidental execution against production databases.
  - `[x]` Cryptographic authenticity analysis: documented that OpenSSL AES-256-CBC is non-AEAD (unauthenticated) and relies on unauthenticated plaintext JSON manifests. Documented AEAD recommendation.
- `[x]` Disaster Recovery Runbook Hardening:
  - `[x]` Authored Section 8: Staging Rollback Runbook with preconditions, baseline snapshots, validation commands, stop conditions, and strict prohibition on automated `migrate --force`.
  - `[x]` Authored Section 9: Complete 15-point Acceptance Evidence Matrix.
- `[ ]` Live Infrastructure Verification Status:
  - `[x]` Local Backup Snapshots & AES Encryption: `VERIFIED`
  - `[x]` Isolated Schema & Dir Restore Verification: `VERIFIED`
  - `[ ]` Real MariaDB 10.11+ Live Daemon Restore: `PENDING INFRASTRUCTURE` (Requires dedicated Linux VPS with running MariaDB daemon).
  - `[x]` Point-in-Time Recovery (PITR): `NOT IMPLEMENTED` (Option B accepted).
  - `[x]` Standalone Backup HTTP API: `REQUIRES ARCHITECTURE APPROVAL` (Maintained 404).

## Step 21 — Authenticated Backup Encryption & Cryptographic Integrity

**Phase:** Cryptographic Hardening, AEAD Migration & Integrity Verification
**Verdict:** **`IMPLEMENTED — TEST-VERIFIED`**

- `[x]` Authenticated AEAD Encryption (AES-256-GCM):
  - `[x]` Replaced unauthenticated OpenSSL AES-256-CBC default with Authenticated Encryption with Associated Data (**AES-256-GCM**).
  - `[x]` Implemented chunked streaming envelope format (`EMAILSAAS_GCM_V1`) in `BackupEncryptionService.php`.
  - `[x]` 40-byte binary header: 16-byte magic (`EMAILSAAS_GCM_V1`) + 16-byte random salt + 8-byte random nonce prefix.
  - `[x]` Framing: 4-byte length (uint32 BE) + 1-byte terminal flag (`isLast`) + 16-byte GCM authentication tag + ciphertext chunk.
  - `[x]` Per-chunk 12-byte nonce: 8-byte nonce prefix + 4-byte big-endian chunk counter.
  - `[x]` Associated Authenticated Data (AAD): `EMAILSAAS_GCM_V1 || chunkIndex (4B BE) || isLast (1B)`.
  - `[x]` Constant memory usage ($O(1)$, ~64 KB buffers) guaranteed across arbitrarily large files.
- `[x]` Fail-Closed Security & Integrity Verification:
  - `[x]` Full verification of authentication tag before emitting decrypted plaintext.
  - `[x]` Atomic write promotion with automatic `.tmp` file cleanup on failure.
  - `[x]` Protection against bit-flipping, tag tampering, chunk reordering, dropped terminal chunk, and truncated files.
- `[x]` Companion Manifest HMAC-SHA256 Signing:
  - `[x]` Implemented `computeManifestHmac()` and `verifyManifestHmac()` in `BackupEncryptionService.php`.
  - `[x]` Canonical key sorting and signature generation across manifest metadata.
  - `[x]` Automated manifest HMAC verification in `BackupVerificationService` and `backup:verify`.
  - `[x]` Re-signing support during verified lifecycle state transitions.
- `[x]` Backward Compatibility & Legacy Isolation:
  - `[x]` Isolated backward compatibility for legacy OpenSSL AES-256-CBC (`Salted__`) backups.
  - `[x]` Automatic detection and isolation via `inspectArchive()`.
  - `[x]` Explicit security classification: `LEGACY — AUTHENTICITY NOT GUARANTEED` (`authenticated: false`).
- `[x]` CLI Decryption Tool & Shell Script Modernization:
  - `[x]` Artisan command `backup:decrypt {archive} {target?} {--key=} {--stdout}` supporting direct binary streaming to Unix pipes.
  - `[x]` Updated `scripts/restore_db.sh` to stream-decrypt `EMAILSAAS_GCM_V1` on-the-fly directly to MySQL engine via Artisan.
  - `[x]` Updated `scripts/restore_vmail.sh` to stream-decrypt `EMAILSAAS_GCM_V1` directly to `tar -x` extraction.
  - `[x]` Updated `scripts/verify_backup.sh` to test authenticated AEAD stream integrity.
  - `[x]` Updated `backup:verify` and `backup:status` commands to display encryption envelope format and manifest HMAC validity.
- `[x]` Automated Testing & Verification:
  - `[x]` Created `tests/Feature/AuthenticatedBackupEncryptionTest.php` with 16 comprehensive security, tampering, streaming, and backward compatibility tests.
  - `[x]` Updated `tests/Feature/BackupAndDisasterRecoveryTest.php` for AEAD defaults.
  - `[x]` Full test suite execution: **259 tests / 1,132 assertions passing cleanly (0 failures, 0 errors, 0 regressions)**.

## Step 22 — Local Database Bootstrap, Seeder Audit & Safe Environment Configuration

**Phase:** Local Environment Setup, Seeder Idempotency & Installer Parameterization
**Verdict:** **`IMPLEMENTED — VERIFIED`**

- `[x]` Local Laragon MySQL Verification:
  - `[x]` Verified local MySQL 8.4.3 runtime listening on `127.0.0.1:3306`.
  - `[x]` Verified isolated local database `emailsaas_local` with zero external dependencies.
  - `[x]` Verified `laravel-panel/.env` configured with safe local database credentials (`127.0.0.1:3306`).
- `[x]` Migration Inventory & Status:
  - `[x]` Audited all 16 migrations across schema dependencies, foreign keys, and indexes.
  - `[x]` Confirmed `php artisan migrate:status` returns exit code 0 with all 16 migrations recorded in Batch 1.
- `[x]` Idempotent Plan Seeder (`Database\Seeders\PlanSeeder`):
  - `[x]` Created `laravel-panel/database/seeders/PlanSeeder.php` provisioning standard tiers (`starter`, `business`, `enterprise`).
  - `[x]` Configured complete domain, mailbox, storage, and outbound rate limits (`daily_outbound_recipients`, `mailbox_daily_outbound_recipients`).
  - `[x]` Implemented `Plan::firstOrCreate(['slug' => ...], [...])` ensuring 100% idempotent execution.
- `[x]` Seeder Hardening & RBAC Compliance (`Database\Seeders\DatabaseSeeder`):
  - `[x]` Hardened `DatabaseSeeder` with child seeder delegation to `PlanSeeder`.
  - `[x]` Enforced strict environment boundaries (`if (app()->environment('local', 'testing'))`).
  - `[x]` Local development seeds:
    - Tenant user `test@example.com` (starter plan, `is_admin = false`, 0 roles).
    - Admin user `admin@example.com` (Super Admin, `is_admin = true`, assigned `super_admin` role via `assignRole()`).
  - `[x]` Zero default accounts or hardcoded credentials created in `production` environment.
  - `[x]` Re-running `db:seed` produces zero duplicate rows and zero constraint errors.
- `[x]` Automated Seeder Testing:
  - `[x]` Created `tests/Feature/DatabaseSeederTest.php` verifying plan seeder idempotency, database seeder idempotency, and production environment safety guards.
- `[x]` Installer Parameterization & Safety Refactor (`scripts/install.sh`):
  - `[x]` Added CLI options: `--domain`, `--db-name`, `--db-user`, `--db-pass`, `--staging`, `--skip-ssl`, `--skip-ufw`, `--reset-ufw`, `--skip-hostname`, `--allow-placeholder`, `--dry-run`, `-h`/`--help`.
  - `[x]` Fail-closed domain guard: halts immediately on placeholder `mail.yourdomain.com` without explicit override.
  - `[x]` Idempotent `/etc/hosts` appending preventing duplicate entries.
  - `[x]` Preserves existing UFW firewall rules; requires explicit `--reset-ufw` before wiping.
  - `[x]` Dynamic config template substitution: replaces `mail.yourdomain.com` with `${MAIL_DOMAIN}` in Postfix `main.cf` and Dovecot `10-ssl.conf`.
  - `[x]` Non-destructive Certbot failure handling with explicit operational warning.
  - `[x]` Modernized post-installation instructions pointing to safe artisan migration, seeding, and emergency recovery commands.
- `[x]` Complete Verification & Regression Testing:
  - `[x]` Backend PHPUnit test suite: **262 tests / 1,151 assertions passing cleanly (100%)**.
  - `[x]` Frontend Next.js production build: **Compiled and static pages generated cleanly (100%)**.
  - `[x]` Git whitespace and format verification: **`git diff --check` clean (0 errors)**.

## Step 23 — Admin Audit Trail & Security Event Monitoring

**Phase:** Comprehensive Security Audit Trail, Sensitive Data Redaction, Server-Side Filtering & UI Observability
**Verdict:** **`IMPLEMENTED — VERIFIED`**

- `[x]` Unified Security & Mutation Event Ledger:
  - `[x]` Authentication events: `auth.login`, `auth.logout`, `auth.failed` (recording `attempted_email` and guard, strictly excluding submitted passwords/credentials).
  - `[x]` Perimeter authorization events: `security.authorization_denied` persisted to `audit_logs` via `AuditService::record()` with fail-safe error handling (authorization denials remain strictly fail-closed even if database logging fails).
  - `[x]` Tenant and mail operations: `tenant.register`, `domain.create`, `domain.delete`, `mailbox.create`, `mailbox.change_password`, `mailbox.toggle`, `mailbox.delete`, `admin.users.suspend`, `admin.users.activate`.
  - `[x]` Subscription plans: `plan.create`, `plan.update`, `plan.delete`.
  - `[x]` Super Admin governance: `admin.roles.assign`, `admin.roles.demote`, `admin.emergency_recovery`.
  - `[x]` Backup and DR operations: `backup.database.create`, `backup.vmail.create`, `backup.verify`, `backup.prune`.
  - `[x]` Abuse incident tracking: `admin.abuse_incident.resolve`, `admin.abuse_incident.dismiss` with `incident_uuid` correlation in after-state.
- `[x]` Centralized Sensitive Data Protection:
  - `[x]` Expanded `AuditService::SENSITIVE_KEYS` to 38+ keys including `db_password`, `encryption_key`, `backup_encryption_key`, `passphrase`, `authorization`, `bearer`, `cookie`, `session`, `x_csrf_token`, `card_number`.
  - `[x]` Content-based detection for cryptographic password hashes (`$6$`, `$2y$`, `$2a$`, `$argon2`) and JWT/Bearer tokens (`eyJ...`).
  - `[x]` Recursive state sanitization masking all sensitive values with `[REDACTED]`.
- `[x]` Server-Side Querying, Sorting & Bounded Pagination:
  - `[x]` Controller authorization enforced via Sanctum `web` guard and `admin.permission:admin.audit_logs.read`.
  - `[x]` Bounded pagination: `per_page` clamped between 1 and 50.
  - `[x]` Validated sorting: `sort_by` (`id`, `created_at`, `action`, `entity_type`) and `direction` (`asc`, `desc`).
  - `[x]` Validated filtering: `action`, `entity_type`, `entity_id`, `actor_user_id`, `search`, `date_from`, `date_to`, and `outcome` (`success`, `failure`, `denial`).
  - `[x]` Resource enhancement: `AdminAuditLogResource` computes and resolves `outcome` (`success`, `failure`, `denial`).
- `[x]` Frontend Admin UI & Observability (`AuditLogsTable.tsx`):
  - `[x]` Dedicated HTTP 403 Forbidden permission-denied UI alert card.
  - `[x]` Expanded Action Filter with categorized optgroups across Authentication, Mailboxes, Domains, Plans, Governance, Backups, and Abuse.
  - `[x]` Expanded Entity Filter (`mailbox`, `domain`, `user`, `plan`, `backup`, `AbuseIncident`).
  - `[x]` New Outcome Filter (`success`, `failure`, `denial`).
  - `[x]` Enhanced Action Badges and dedicated visual Outcome Badges (`CheckCircle2` for success, `XCircle` for failure, `ShieldAlert` for denial).
  - `[x]` Comprehensive Detail Inspection Modal showing resolved outcome, formatted actor details, IP, request ID, and before/after diffs.
- `[x]` Automated Testing & Verification:
  - `[x]` Authoring `tests/Feature/AdminAuditTrailCoverageTest.php` with 7 comprehensive feature tests (66 assertions).
  - `[x]` Existing `tests/Feature/AdminAuditLogTest.php`: 10 tests / 51 assertions passing (100%).
  - `[x]` Full regression test suite: **269 tests / 1,217 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend Next.js production build: **`npm run build` succeeds cleanly (0 errors, 100%)**.

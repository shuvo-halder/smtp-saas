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

## Domains & Mailboxes (Step 27 Completed)
- [x] Domain listing API with bounded pagination (1-50), search, and status filters
- [x] Domain creation API with case normalization (lowercase & trim) and plan quota limits
- [x] Domain DNS verification API with truthful reporting of propagation & activation
- [x] Domain deletion with transactional cascade soft-deletion of associated mailboxes and aliases
- [x] Tenant-wide mailbox listing API (`GET /api/mailboxes`) across all customer domains
- [x] Domain-specific mailbox listing API (`GET /api/domains/{id}/mailboxes`)
- [x] Mailbox creation strictly under verified domains (`status === 'active'`; 422 if unverified)
- [x] Mailbox creation enforcing plan storage cap (`storage_mb_per_mailbox`) and mailbox limits
- [x] Mailbox password generation and hashing (Dovecot SHA512-CRYPT), redacted from API responses
- [x] Mailbox display name update (`PUT /api/mailboxes/{id}`)
- [x] Mailbox password change API (`POST /api/mailboxes/{id}/change-password`)
- [x] Mailbox active toggle API enforcing domain active and subscription active invariants
- [x] Dedicated Mailbox Policy (`MailboxPolicy`) enforcing tenant boundary isolation
- [x] Frontend customer domain management (`/domains`, `/domains/new`, `/domains/[id]`, `/domains/[id]/setup`)
- [x] Frontend customer mailbox management (`/mailboxes` hub with search, domain filter, and status filter)
- [x] Frontend embedded domain mailbox table and modals (`CreateMailboxModal`, `EditMailboxModal`, `ChangePasswordModal`)

## Email Aliases & Forwarding (Step 29 Completed)
- [x] Dedicated `EmailAlias` PSR-4 model (`app/Models/EmailAlias.php`) with domain relationship, tenant scopes, search, and status filter
- [x] `EmailAliasPolicy` registered in `AuthServiceProvider` with strict cross-tenant isolation
- [x] Tenant-wide alias listing API (`GET /api/aliases`) with bounded pagination (1-50), domain filter, status filter, and keyword search
- [x] Domain-scoped alias listing API (`GET /api/domains/{domain}/aliases`)
- [x] Alias creation API (`POST /api/aliases`) with local-part or full source email resolution
- [x] Plan quota enforcement: strictly checks `max_aliases_per_domain` per domain
- [x] Domain and subscription active invariants: blocks alias creation/activation on pending domains or suspended tenants
- [x] Address collision prevention: rejects aliases conflicting with physical mailboxes (`mailboxes.email`)
- [x] Uniqueness per domain: prevents duplicate aliases on the same domain
- [x] Cycle detection: prevents self-forwarding direct loops (`source === destination`) and circular forwarding chains
- [x] Alias destination update API (`PUT /api/aliases/{alias}`) with loop detection and immutable source email
- [x] Alias active toggle API (`POST /api/aliases/{alias}/toggle`) allowing disable anytime and validating domain/subscription on enable
- [x] Alias deletion API (`DELETE /api/aliases/{alias}`) with cascade deletion on domain removal
- [x] Persistent audit logging: all alias actions (`alias.create`, `alias.update`, `alias.toggle`, `alias.delete`) recorded via `AuditService`
- [x] Truthful status reporting: API and UI label forwarding as `configured` (database/virtual map ready, live delivery pending live MTA)
- [x] Next.js customer frontend (`/aliases`): search, filters, pagination, create modal with loop warning and domain picker, edit destination modal, toggle, delete
- [x] Navigation sidebar integration: added Email Aliases link (`/aliases`)
- [x] Comprehensive automated test coverage (`tests/Feature/TenantAliasManagementTest.php` with 21 tests, 76 assertions, 100% passing)

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
- [x] Tenant Self-Service Billing & Subscription Management (Step 26 IMPLEMENTED & VERIFIED):
  - [x] `GET /api/billing/subscription` endpoint returning authoritative plan, status, days remaining, domain count, mailbox count, and today's outbound usage
  - [x] `GET /api/billing/invoices` endpoint with bounded pagination (1-50) and status filtering (`paid`, `pending`, `failed`, `cancelled`)
  - [x] `GET /api/billing/invoices/{invoice}` single invoice inspection endpoint with `InvoicePolicy::view` authorization
  - [x] `User::mailboxes()` HasManyThrough relationship for direct mailbox queries
  - [x] Next.js `/billing` dashboard with real-time countdown badge, warning banners (expired, expiring soon, suspended), usage progress bars (domains, mailboxes, daily outbound recipients, storage), and one-click renewal flow
  - [x] Next.js `InvoiceTable` with bounded pagination and status filtering
  - [x] Next.js `InvoiceDetailModal` with print-friendly layout and PDF download
  - [x] Next.js `/billing/plans` & `PlanCard` with monthly/yearly toggle, current plan indicator, feature list, and SSLCommerz redirect
  - [x] Gateway return feedback banners (`?status=success|failed|cancelled`)
  - [x] Comprehensive automated test coverage in `TenantBillingManagementTest.php` (12 passing tests, 46 assertions; suite total: 311 tests, 1,346 assertions)
  - [x] Next.js 14 production build verified (24/24 static pages compiled, zero errors)

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

## Step 24 — Tenant Usage, SMTP Quota & Billing Reconciliation

**Phase:** Accounting Invariant Hardening, Quota Enforcement, Subscription Activation Gate & Read-Only Reconciliation Subsystem
**Verdict:** **`IMPLEMENTED — VERIFIED`**

- `[x]` Outbound Quota Accounting Hardening (`OutboundQuotaService`):
  - `[x]` Fixed key omission: `$tenantKey` is always passed to Redis Lua evaluation whenever Redis is called, guaranteeing that tenants on unlimited plans (`-1`) with finite mailbox limits have their total recipient count captured in Redis and synced to `tenant_outbound_usage`.
  - `[x]` Preserved established `test_unlimited_quotas_bypasses_redis` contract when both tenant and mailbox limits are `-1`.
  - `[x]` Safe domain null checking (`$mailbox->domain?->user_id ?? 0`).
  - `[x]` Fail-closed plan check: tenants without an assigned plan fail closed (`REJECTED_QUOTA`).
- `[x]` Billing Invariants & IPN Security Hardening (`BillingService`):
  - `[x]` Strict rejection of IPN webhooks targeting `cancelled` invoices (returns `false`).
  - `[x]` Recording of `failed` and `cancelled` payment statuses in invoice ledger.
  - `[x]` Pessimistic concurrency locks (`lockForUpdate()`) on both `Invoice` and `User` records in `markInvoicePaid()`, ensuring idempotency under duplicate webhooks.
  - `[x]` Scoped domain recovery: reactivates strictly suspended domains (`where('status', 'suspended')`), preserving pending and unverified domains.
- `[x]` Subscription Validity Gate for Tenant Activation (`AdminApiController::activateUser`):
  - `[x]` Prevents reactivating customer tenants with expired subscriptions (`plan_expires_at->isPast()`), returning HTTP 422 with actionable guidance.
  - `[x]` Enforces administrator account segregation under `RBAC-DEC-04` (HTTP 403).
- `[x]` Read-Only Reconciliation Service (`TenantUsageReconciliationService`):
  - `[x]` Detects `USAGE_LEDGER_DESYNC` when Redis ephemeral counters lead MariaDB historical rows.
  - `[x]` Detects `MAILBOX_SUM_EXCEEDS_TENANT` when mailbox Redis counters exceed tenant totals.
  - `[x]` Detects `TENANT_OVER_QUOTA` and `MAILBOX_OVER_QUOTA`.
  - `[x]` Detects `ACTIVE_EXPIRED_SUBSCRIPTION` and `ACTIVE_WITHOUT_PLAN`.
  - `[x]` Detects `SUSPENDED_TENANT_ACTIVE_DOMAINS`.
  - `[x]` Detects `PAID_INVOICE_MISSING_PAID_AT` and `PAID_INVOICE_CURRENCY_MISMATCH`.
  - `[x]` Strictly read-only: performs zero database mutations and zero Redis modifications.
- `[x]` Operator Reconciliation Command (`TenantReconcileUsageCommand`):
  - `[x]` Artisan command `php artisan tenant:reconcile-usage` with `--tenant=`, `--date=`, `--detailed`, and `--json` options.
  - `[x]` Clean tabular formatting with summary metrics and itemized discrepancy reporting.
  - `[x]` Scheduled daily at 05:00 UTC with `withoutOverlapping(15)` mutex in `routes/console.php`.
- `[x]` Administrative API Endpoints (`AdminReconciliationApiController`):
  - `[x]` `GET /api/admin/reconciliation/report`: comprehensive platform reconciliation summary and discrepancies list.
  - `[x]` `GET /api/admin/reconciliation/tenants/{user}`: drill-down single-tenant reconciliation view.
  - `[x]` Protected by `EnsureAdmin` and `admin.permission:admin.stats.read`.
- `[x]` Enhanced Control Plane Quota & Usage Visibility:
  - `[x]` `AdminSmtpTenantResource`: returns `remaining_daily_quota`, `quota_usage_percent`, `is_over_quota`, and granular `subscription_state`.
  - `[x]` `AdminSmtpService::getOverview`: returns `tenants_over_quota_count` and `reconciliation_status`.
  - `[x]` Frontend Next.js UI (`SmtpTenantsTable.tsx`): displays remaining daily quota subtext, over-quota badges, and subscription state indicators.
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Implemented `tests/Feature/TenantUsageAndBillingReconciliationTest.php` with 16 comprehensive feature tests (57 assertions).
  - `[x]` Full backend test suite: **285 tests / 1,274 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend Next.js 14 production build: **Compiled all 24 static pages cleanly with zero errors (100%)**.

---

## Step 25 — Final Local Integration Audit, Dummy Data Seeding & Security Verification (`IMPLEMENTED — VERIFIED`)
- `[x]` Local Database & Environment Verification:
  - `[x]` Verified local runtime: PHP 8.3.33 (Laragon), MySQL 8.4.3 on `127.0.0.1:3306`, database `emailsaas_local`.
  - `[x]` Confirmed zero exposure to remote production IP `103.131.144.61`.
  - `[x]` Confirmed all 16 migrations applied (`[1] Ran`).
- `[x]` Dedicated Local Dummy Data Seeder Tooling (`SeedAuditDemoCommand`):
  - `[x]` Command `php artisan emailsaas:seed-audit-demo` with `--dry-run` and `--cleanup` options.
  - `[x]` Strict environment guard: refuses execution when `app()->environment()` is not `local`.
  - `[x]` Strict database guard: verifies host is `127.0.0.1` or `localhost`, port is `3306`, and database is `emailsaas_local`.
  - `[x]` Idempotency verified: re-running reuses existing records with 0 duplicate insertions.
  - `[x]` Controlled discrepancy scenarios seeded:
    - Active tenant with valid subscription (`audit-demo-active@example.test`)
    - Active tenant with expired subscription (`audit-demo-expired@example.test`)
    - Suspended tenant (`audit-demo-suspended@example.test`)
    - Pending tenant without assigned plan (`audit-demo-noplan@example.test`)
    - Over-quota tenant with 550 recipient usage on 500-recipient plan (`audit-demo-overquota@example.test`)
    - Active, pending, and suspended domains (`audit-demo-*.example.test`)
    - Active, inactive, restricted-quota, and bulk mailboxes
    - Paid, pending, failed, and cancelled invoice fixtures
    - Open and resolved abuse incidents with evidence snapshots
    - Authentic audit log entries with recursive secret scrubbing
  - `[x]` Safe cleanup verified: `php artisan emailsaas:seed-audit-demo --cleanup` cleanly removes all test-owned records while preserving baseline canonical records (`test@example.com`, `admin@example.com`, standard plans).
- `[x]` 13-Point Security & Authorization Matrix Verification:
  - `[x]` Unauthenticated access to admin endpoints returns HTTP 401.
  - `[x]` Ordinary tenants accessing admin endpoints return HTTP 403.
  - `[x]` Cross-tenant resource access returns HTTP 403.
  - `[x]` Suspended tenants are denied access across protected endpoints.
  - `[x]` Tenants without assigned plans fail closed on quota evaluation (`REJECTED_QUOTA`).
  - `[x]` Cancelled invoices reject payment callbacks without activating subscriptions.
  - `[x]` Duplicate payment callbacks are handled idempotently.
  - `[x]` Expired tenants cannot be activated via admin API (HTTP 422).
  - `[x]` Unauthorized administrators cannot manage plans (HTTP 403).
  - `[x]` Audit log mutation endpoints return HTTP 405 Method Not Allowed.
  - `[x]` Backup decryption with invalid key fails closed and cleans temporary output.
  - `[x]` Tampered backup manifest metadata fails HMAC-SHA256 verification.
  - `[x]` Passwords and credentials in failed logins and state snapshots are redacted to `[REDACTED]`.
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Implemented `tests/Feature/LocalIntegrationAndSecurityAuditTest.php` with 14 comprehensive tests (26 assertions).
  - `[x]` Full backend test suite: **299 passed (1,300 assertions)** cleanly with zero failures and zero regressions.
  - `[x]` Frontend Next.js 14 production build: **Compiled all 24 static pages cleanly with zero errors (100%)**.

---

## Step 28 — Tenant SMTP Access, Sending Activity & Delivery Logs (`IMPLEMENTED — VERIFIED`)
- `[x]` Backend API & Service Architecture:
  - `[x]` `TenantSmtpService`: Server connection settings resolution, real-time quota telemetry, credential management, one-time reveal password rotation with Dovecot SHA512-CRYPT, activity log querying, and account diagnostics.
  - `[x]` `TenantSmtpApiController`: 6 REST endpoints (`GET /api/smtp/settings`, `GET /api/smtp/usage`, `GET /api/smtp/credentials`, `POST /api/smtp/credentials/{mailbox}/rotate-password`, `GET /api/smtp/activity`, `GET /api/smtp/diagnostics`) protected by `auth:sanctum` and `EnsureActiveSubscription`.
  - `[x]` `DeliveryLog` Model & Migration (`2026_10_16_000001_create_delivery_logs_table.php`): Tracks outcomes (`delivered`, `deferred`, `bounced`, `rejected`), Postfix queue IDs, DSN codes, SMTP reply codes, and sanitized responses.
  - `[x]` Integrated Pipeline Ingestion:
    - Downstream Postfix syslog delivery events logged by `AbuseDetectionService::processEvent`.
    - Inbound quota and policy rejections logged by `PolicyDecisionService::recordQuotaAbuseIncident`.
  - `[x]` `DeliveryLogResource`: Centralized response text sanitization, stripping internal filesystem paths (`/var/vmail/...`, `/etc/...`) and truncating long responses.
- `[x]` Security & Tenant Isolation:
  - `[x]` Strict tenant scoping across all SMTP endpoints; cross-tenant access returns HTTP 403.
  - `[x]` Suspended tenants are denied access via `EnsureActiveSubscription`.
  - `[x]` Stored password hashes strictly excluded from API responses; rotated passwords revealed strictly once in the rotation response.
  - `[x]` Zero SSRF risk in diagnostics: checks operate solely on internal relational and Redis state with zero external socket or HTTP requests.
- `[x]` Frontend Next.js Hub (`/smtp`):
  - `[x]` Settings & Code tab: Hostname with copy button, port configuration table (587, 465, 25), TLS 1.2+ specification, code snippets (Node.js, Python, PHP, .env).
  - `[x]` Quota & Usage tab: Real-time sent today, remaining allowance, mailbox cap, progress bar, 14-day history, mailbox usage breakdown.
  - `[x]` Credentials tab: Authorized mailboxes table, status badges, and secure password rotation modal with one-time reveal notice.
  - `[x]` Activity Logs tab: Filterable delivery table (status, search, bounded pagination 1-50), formatted outcome badges, and sanitized MTA responses.
  - `[x]` Diagnostics & Troubleshooting tab: Automated self-service checks (subscription, domains, mailboxes, quota, deliverability) and RFC error code resolution reference guide.
  - `[x]` Sidebar navigation updated with `{ name: 'SMTP Access', href: '/smtp', icon: Send }`.
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Implemented `tests/Feature/TenantSmtpManagementTest.php` with 10 comprehensive tests (216 assertions).
  - `[x]` Full backend test suite: **342 tests / 1,648 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend Next.js 14 production build: **Compiled all 26 static pages cleanly with zero errors (100%)**.

---

## Step 29 — Tenant Email Aliases & Forwarding Management (`IMPLEMENTED — VERIFIED`)
- `[x]` Backend API & Service Architecture:
  - `[x]` Extracted standalone `EmailAlias` PSR-4 model (`app/Models/EmailAlias.php`) with domain relationship, tenant scoping, search, and status scopes.
  - `[x]` Implemented `EmailAliasPolicy` registered in `AuthServiceProvider` enforcing strict tenant domain ownership.
  - `[x]` `AliasApiController`: RESTful endpoints (`GET /api/aliases`, `GET /api/domains/{domain}/aliases`, `POST /api/aliases`, `GET /api/aliases/{alias}`, `PUT /api/aliases/{alias}`, `POST /api/aliases/{alias}/toggle`, `DELETE /api/aliases/{alias}`) protected by `auth:sanctum` and `EnsureActiveSubscription`.
  - `[x]` Invariant enforcement:
    - Rejects alias creation on inactive, unverified, or foreign domains.
    - Rejects alias creation if plan `max_aliases_per_domain` quota is reached.
    - Rejects alias collision with physical mailboxes (`mailboxes.email`).
    - Rejects direct self-forwarding (`source === destination`).
    - Rejects circular multi-hop forwarding loops (`A -> B -> C -> A`).
    - Disabling alias is always permitted; re-enabling strictly validates parent domain DNS verification state.
  - `[x]` Audit trail integration: `alias.create`, `alias.update`, `alias.toggle`, `alias.delete` recorded via `AuditService`.
- `[x]` Frontend Next.js Hub (`/aliases`):
  - `[x]` Bounded paginated alias management interface with keyword search, domain filter, and status filter.
  - `[x]` `CreateAliasModal` with active domain dropdown, `@domain` preview, destination validation, and loop warning.
  - `[x]` `EditAliasModal` with immutable source email notice and destination update.
  - `[x]` `AliasRow` with quick toggle switch, status pills, and delete confirmation dialog.
  - `[x]` Sidebar navigation updated with `{ name: 'Email Aliases', href: '/aliases', icon: ArrowRightLeft }`.
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Implemented `tests/Feature/TenantAliasManagementTest.php` with 21 tests (76 assertions).
  - `[x]` Full backend test suite passing cleanly with zero errors.

---

## Step 30 — Email Alias Routing Integration & Mail Flow Verification (`IMPLEMENTED — VERIFIED`)
- `[x]` Mail Pipeline Configuration & Schema Alignment:
  - `[x]` Updated `postfix-config/mysql-virtual-aliases.cf` and created `server-configs/mysql-virtual-alias-maps.cf` querying canonical `email_aliases` joined with `domains` with `status = 'active'` and `domains.deleted_at IS NULL`.
  - `[x]` Modernized `mysql-virtual-domains.cf`, `mysql-virtual-mailboxes.cf`, and `mysql-virtual-sender-login-maps.cf` with `status = 'active'` and `deleted_at IS NULL` for SoftDeletes compatibility.
  - `[x]` Enabled `virtual_alias_maps` in `server-configs/main-cf-snippet.txt`.
- `[x]` Postfix Routing Service Integration (`PostfixService.php`):
  - `[x]` Added `syncAlias(EmailAlias $alias): bool` and `removeAlias(EmailAlias $alias): bool`.
  - `[x]` Implemented `verifyAliasRouting(string $sourceEmail): array`:
    - Simulates real-time Postfix MySQL map lookup query.
    - Traces recursive expansion chains up to 30 hops.
    - Detects cycle loops (`cycle_detected => true`).
    - Verifies local terminal destinations exist in `mailboxes` (flags dangling local mailboxes with explicit warning).
    - Checks live MTA operational state via `isPostfixOperational()`.
  - `[x]` Injected `PostfixService` into `AliasApiController` across all lifecycle actions (`store`, `update`, `toggle`, `destroy`).
- `[x]` Telemetry & User Experience:
  - `[x]` `EmailAliasResource` returns dynamic `forwarding_status` (`synchronized`, `operational`, `configured`, `failed`, `unknown`) and complete `routing_details` payload.
  - `[x]` Frontend `AliasRow.tsx` displays color-coded badges, hop count chips (`(2 hops)`), and tooltip warnings for unroutable aliases.
  - `[x]` CLI tool `php artisan mail:verify-alias {source_email?} {--all}` with tabular diagnostics.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. Covered by `TenantAliasRoutingIntegrationTest.php` and `TenantAliasManagementTest.php`.
  - `[x]` **Level B (Configuration & SQL Maps):** `VERIFIED`. Exact `.cf` SQL queries and artisan command verified against database.
  - `[x]` **Level C (End-to-End Live Mail Flow):** `BLOCKED — REQUIRES INFRASTRUCTURE`. Local Windows + Laragon development environment lacks Linux Postfix/Dovecot services; requires dedicated Linux VPS for live TCP socket delivery.
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Implemented `tests/Feature/TenantAliasRoutingIntegrationTest.php` with 8 tests (44 assertions).
  - `[x]` Full backend test suite: **371 tests / 1,768 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend Next.js 14 production build: **Compiled all 27 static pages cleanly with zero errors (100%)**.

---

## Step 31 — Tenant Mailbox Storage & Quota Management (`IMPLEMENTED — VERIFIED`)
- `[x]` Backend Storage Service Architecture (`MailboxStorageService.php`):
  - `[x]` Mailbox disk usage measurement engine supporting `doveadm quota get -u <email>`, maildirsize file parsing, test fakes, and safe Windows environment fallbacks.
  - `[x]` Exact storage telemetry computation: `quota_mb`, `quota_bytes`, `usage_bytes`, `usage_mb`, `remaining_bytes`, `utilization_percent`, `status`, and `status_label`.
  - `[x]` Status threshold rules: `normal` (< 80%), `warning` (80% - 89.9%), `near_limit` (90% - 99.9%), `over_limit` ($\ge 100\%$), or `unknown` (unmeasured / Dovecot offline).
  - `[x]` Safe unlimited quota allocation handling (`quota_mb = -1` or `NULL`) avoiding division by zero.
  - `[x]` Tenant-wide summary aggregation: total mailboxes, measured count, unmeasured count, provisioned storage, plan storage limit, total usage, overall utilization, and status count distribution.
  - `[x]` Truthful telemetry guarantee: never substitutes `0` for unmeasured usage; reports `is_measured = false`, `usage_bytes = null`, and `status = 'unknown'`.
- `[x]` RESTful API & Serialization:
  - `[x]` `GET /api/mailboxes/storage`: Tenant overview and per-mailbox breakdown with optional `status`, `domain_id`, and `search` filters.
  - `[x]` `GET /api/mailboxes/{mailbox}/storage`: Single mailbox storage telemetry protected by `MailboxPolicy`.
  - `[x]` Embedded `storage` telemetry object inside `MailboxResource` (`/api/mailboxes`, `/api/mailboxes/{id}`).
- `[x]` Dovecot Quota Rule Configuration:
  - `[x]` Updated `server-configs/dovecot-sql.conf.ext` and `dovecot-config/dovecot-sql.conf.ext` to dynamically map `quota_rule` via `CONCAT('*:bytes=', (mailboxes.quota_mb * 1048576))`.
  - `[x]` Modernized Dovecot SQL queries to enforce `mailboxes.is_active = 1`, `domains.status = 'active'`, and `deleted_at IS NULL`.
- `[x]` Frontend Next.js Hub (`/mailboxes`):
  - `[x]` Top KPI cards displaying Provisioned Mailboxes, Provisioned Storage vs Plan Limit, and Storage Telemetry status.
  - `[x]` Storage status filter dropdown (`over_limit`, `near_limit`, `warning`, `normal`, `unknown`).
  - `[x]` Updated `MailboxRow.tsx` with storage progress bar, colored warning badges, or `Unmeasured` badge when Dovecot is offline.
  - `[x]` `MailboxStorageModal.tsx` provides detailed breakdown of usage, quota ceiling, remaining capacity, provenance source, and measured timestamp.
- `[x]` Verification Matrix:
  - `[x]` Feature test suite (`tests/Feature/TenantMailboxStorageTest.php`): **14 tests, 66 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **385 tests, 1,834 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 27 static pages cleanly with zero errors (100%)**.

---

## Step 32 — Tenant Catch-All Mailbox & Inbound Email Routing Rules (`IMPLEMENTED — VERIFIED`)
- `[x]` Database Schema & Domain Entity Integration:
  - `[x]` Added `catch_all_policy` (varchar(32), default `'disabled'`) and `catch_all_mailbox_id` (foreignId to `mailboxes`, nullable, `nullOnDelete()`) to `domains` table via migration `2026_10_17_000001_add_catch_all_fields_to_domains_table.php`.
  - `[x]` Updated `Domain` model with `$fillable`, `catchAllMailbox(): BelongsTo`, and `isCatchAllEnabled(): bool`.
  - `[x]` Updated `Mailbox` model with `isCatchAll(): bool`.
  - `[x]` Updated `DomainResource` to expose `catch_all_policy` and `catch_all_mailbox_id`.
  - `[x]` Updated `MailboxApiController::destroy()` to automatically reset catch-all configuration if the deleted mailbox was the designated catch-all destination.
- `[x]` Postfix Routing Configuration & SQL Mapping:
  - `[x]` Created `postfix-config/mysql-virtual-catchall.cf` and `server-configs/mysql-virtual-catchall-maps.cf`.
  - `[x]` Registered `mysql-virtual-catchall.cf` inside `virtual_alias_maps` in `postfix-config/main.cf` and `server-configs/main-cf-snippet.txt`.
  - `[x]` Strict Invariant Precedence: SQL map enforces `NOT EXISTS (SELECT 1 FROM mailboxes WHERE email = '%s' ...)` and `NOT EXISTS (SELECT 1 FROM email_aliases WHERE source_email = '%s' ...)` so physical mailboxes and explicit aliases are NEVER shadowed or redirected by catch-all rules.
  - `[x]` Discard Sink Mapping: When `catch_all_policy = 'discard'`, rewrites recipient to `devnull@localhost` or designated discard sink with user disclosure.
- `[x]` Postfix Service & CLI Verification Tool:
  - `[x]` Implemented `PostfixService::verifyCatchAllRouting(Domain $domain, ?string $probeLocalPart = null)` returning `status` (`synchronized`, `operational`, `configured`, `failed`), `is_routable`, `destination_mailbox`, `probe_email`, `probe_resolution`, and `mta_driver`.
  - `[x]` Implemented `PostfixService::syncCatchAll(Domain $domain)` and `disableCatchAll(Domain $domain)`.
  - `[x]` Implemented operator CLI command `php artisan mail:verify-catchall {domain?} {--all}` with detailed tabular diagnostics.
- `[x]` RESTful API & Security Authorization:
  - `[x]` Implemented `CatchAllApiController` with `show` and `update` actions.
  - `[x]` Protected by `auth:sanctum`, `DomainPolicy` (`update`), and `EnsureActiveSubscription` middleware.
  - `[x]` Enforces validation: policy must be in `['disabled', 'reject', 'mailbox', 'discard']`. When policy is `mailbox`, `mailbox_id` is required, must belong to the domain, and must be active.
  - `[x]` Unverified or pending domains cannot activate `mailbox` or `discard` policies.
  - `[x]` Suspended tenants cannot modify catch-all routing (blocked by `EnsureActiveSubscription`).
  - `[x]` Transactional synchronization of `mailboxes.is_catchall` flags.
  - `[x]` Persistent audit logging: Records `domain.catch_all_update` in `audit_logs` via `AuditService`.
- `[x]` Next.js Tenant Dashboard UI:
  - `[x]` Added TypeScript definitions in `types/index.ts` (`CatchAllPolicy`, `CatchAllMailbox`, `CatchAllRoutingDetails`, `DomainCatchAllDetails`, `CatchAllUpdateRequest`).
  - `[x]` Added `useDomainCatchAll` and `updateCatchAll` hooks in `hooks/useDomains.ts`.
  - `[x]` Created `CatchAllSettingsCard.tsx` with policy radios, destination mailbox selector, destructive discard alert, spam warning advisory, and status badges.
  - `[x]` Integrated `CatchAllSettingsCard` on `/domains/[id]` page.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantCatchAllManagementTest.php` passes with 19 tests (73 assertions).
  - `[x]` **Level B (Configuration & SQL Maps):** `VERIFIED`. Exact `.cf` SQL queries and artisan command verified against database.
  - `[x]` **Level C (End-to-End Live Mail Flow):** `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services; requires dedicated Linux VPS).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Feature test suite (`tests/Feature/TenantCatchAllManagementTest.php`): **19 tests, 73 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **404 tests, 1,907 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 27 static pages cleanly with zero errors (100%)**.

---

## Step 33 — Tenant Email Forwarding Rules & Auto-Reply Management (`IMPLEMENTED — VERIFIED`)
- `[x]` Database Schema & Mailbox Entity Integration:
  - `[x]` Added 8 columns to `mailboxes`: `is_forwarding_enabled` (boolean), `forwarding_destinations` (text), `forwarding_keep_copy` (boolean), `auto_reply_enabled` (boolean), `auto_reply_subject` (varchar 255), `auto_reply_body` (text), `auto_reply_start_at` (timestamp), `auto_reply_end_at` (timestamp) via migration `2026_10_17_000002_add_forwarding_and_auto_reply_to_mailboxes_table.php`.
  - `[x]` Updated `Mailbox` model with `$fillable`, `$casts`, `getForwardingDestinationsList()`, `setForwardingDestinationsList()`, `isAutoReplyActive()`, and `autoReplyScheduleStatus()`.
  - `[x]` Updated `MailboxResource` exposing `is_forwarding_enabled` and `auto_reply_enabled`.
- `[x]` MailboxRuleService & Dovecot Sieve Pigeonhole Compiler:
  - `[x]` `MailboxRuleService.php` implements RFC 5228 and RFC 5230 compliant Sieve vacation script generation with `:days 1` rate limiting.
  - `[x]` Sieve anti-loop suppression: filters automated messages matching `Precedence: bulk|list|junk`, `Auto-Submitted`, `List-Id`, and empty `Return-Path: <>`.
  - `[x]` Date window evaluation using Dovecot date extension (`date :value "currentdate" :zone "+0000" "iso8601"`).
  - `[x]` Multi-hop forwarding loop and cycle detection traversing both mailbox forwardings and virtual aliases up to 30 hops.
  - `[x]` Direct self-forwarding validation (source address cannot be in destinations).
  - `[x]` Safe Dovecot `.dovecot.sieve` synchronization with fallback simulation when mail storage is unmounted.
- `[x]` Postfix Forwarding Integration & SQL Maps:
  - `[x]` Created `postfix-config/mysql-virtual-forwardings.cf` and `server-configs/mysql-virtual-forwardings-maps.cf`.
  - `[x]` Integrated into `virtual_alias_maps` in `postfix-config/main.cf` and `server-configs/main-cf-snippet.txt` with evaluation precedence: `aliases -> forwardings -> catchall`.
  - `[x]` Postfix expansion: returns `CONCAT(email, ', ', forwarding_destinations)` when `forwarding_keep_copy = true` (delivering both locally and externally) or `forwarding_destinations` when `forwarding_keep_copy = false`.
- `[x]` RESTful API & Security Authorization:
  - `[x]` Created `MailboxRuleApiController.php` (`GET /mailboxes/{mailbox}/rules`, `PUT /mailboxes/{mailbox}/forwarding`, `PUT /mailboxes/{mailbox}/auto-reply`).
  - `[x]` Gated by `auth:sanctum`, `MailboxPolicy` (`view`/`update`), and `EnsureActiveSubscription`.
  - `[x]` Input sanitization (`strip_tags` on subject and body for XSS protection).
  - `[x]` Persistent audit logging: Records `mailbox.forwarding_update` and `mailbox.auto_reply_update` via `AuditService`.
- `[x]` Operator CLI Diagnostic Tool:
  - `[x]` `php artisan mail:verify-mailbox-rules {mailbox?} {--all}` with detailed tabular diagnostics.
- `[x]` Next.js Tenant UI:
  - `[x]` Added types in `types/index.ts` and hooks in `hooks/useMailboxes.ts`.
  - `[x]` Created `MailboxRulesModal.tsx` with Forwarding, Auto-Reply, and Sieve & MTA Diagnostics tabs.
  - `[x]` Updated `MailboxRow.tsx` with status badges (`Fwd`, `Auto-Reply`) and rules modal launcher.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantMailboxForwardingAndAutoReplyTest.php` passes with 20 tests (67 assertions).
  - `[x]` **Level B (Configuration & SQL Maps):** `VERIFIED`. Exact `.cf` queries and CLI command verified against database.
  - `[x]` **Level C (End-to-End Live Mail Flow):** `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services; requires dedicated Linux VPS).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Feature test suite (`tests/Feature/TenantMailboxForwardingAndAutoReplyTest.php`): **20 tests, 67 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **424 tests, 1,974 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 27 static pages cleanly with zero errors (100%)**.

---

## Step 34 — Domain DNS Records Wizard, Inbound Authentication & Deliverability Diagnostics (`IMPLEMENTED — VERIFIED`)
- `[x]` Database & Model Architecture:
  - `[x]` Zero schema modifications required; verified existing canonical columns (`mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `last_dns_check_at`, `dkim_public_key`).
  - `[x]` Upgraded `Domain::requiredDnsRecords()` returning structured array with record identifiers, types, hosts, values, priority, purpose, and verification status.
  - `[x]` Updated `DomainResource` exposing `last_dns_check_at`.
- `[x]` DnsVerificationService Multi-Record Engine:
  - `[x]` Implemented mockable resolver via `setResolver(?callable $resolver)` for offline, deterministic testing.
  - `[x]` RFC 7208 PermError handling: Flags multiple `v=spf1` TXT records as `MISMATCH` with consolidation advice.
  - `[x]` 2048-bit DKIM key chunking: Concatenates multi-chunk TXT records via `extractTxtContent()`.
  - `[x]` Resilient DKIM key extraction: Normalizes and isolates public key payload following `p=` up to semicolon.
  - `[x]` Truthful verification status taxonomy: `VERIFIED`, `MISMATCH`, `MISSING`, `CHECK_FAILED`, `NOT_CONFIGURED`, `EXPECTED`.
  - `[x]` Deliverability score algorithm: Weighted 0-100 score (MX 40%, SPF 25%, DKIM 25%, DMARC 10%) with ratings (`EXCELLENT`, `GOOD`, `FAIR`, `POOR`).
  - `[x]` Diagnostic separation: Decouples DNS publication, application configuration, and live MTA readiness.
  - `[x]` Actionable recommendations engine: Generates specific instructions for missing or mismatched records.
- `[x]` RESTful API & Security Boundaries:
  - `[x]` Implemented `dnsWizard()` action on `DomainApiController` (`GET /api/domains/{domain}/dns-wizard`).
  - `[x]` Implemented `verifyDns()` action on `DomainApiController` (`POST /api/domains/{domain}/verify-dns`).
  - `[x]` Preserved backwards compatibility: `POST /api/domains/{domain}/verify` routes to `verifyDns()`.
  - `[x]` Enforced rate limiting: `throttle:30,1` on both DNS endpoints.
  - `[x]` Authorization gates: `auth:sanctum`, `DomainPolicy` (`view`/`update`), and `EnsureActiveSubscription`.
  - `[x]` Persistent audit logging: Records `domain.dns_verify` in `audit_logs` via `AuditService::record()`.
  - `[x]` Zero sensitive information leakage: Redacts private keys; only exposes public records.
- `[x]` Next.js Tenant UI:
  - `[x]` Added TypeScript interfaces in `types/index.ts` (`DnsRecordType`, `DnsVerificationStatus`, `DnsRecord`, `DeliverabilityScore`, `InboundRoutingDiagnostics`, `OutboundAuthDiagnostics`, `InfrastructureDiagnostics`, `DomainDnsWizardData`, `DnsVerifyResponse`).
  - `[x]` Added `useDomainDnsWizard` and `verifyDns` hooks in `hooks/useDomains.ts`.
  - `[x]` Transformed `/domains/[id]/setup` (`DomainSetupPage.tsx`) into a full-featured DNS Wizard & Diagnostics dashboard with Deliverability Scorecard, Diagnostic Cards, Multi-layer environment notice, and Actionable Recommendations banner.
  - `[x]` Overhauled `DnsRecordTable.tsx` with copy buttons, priority, purpose, status badges, and expandable troubleshooting drawer showing expected vs current values.
  - `[x]` Updated `/domains/[id]` page with "DNS Wizard & Diagnostics" button.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantDomainDnsWizardTest.php` passes with 24 tests (211 assertions).
  - `[x]` **Level B (Configuration & Resolver Logic):** `VERIFIED`. Mockable resolver, RFC 7208 duplicate SPF, 2048-bit chunking, and key payload extraction verified.
  - `[x]` **Level C (End-to-End Live Mail Flow & Public DNS):** `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services and public DNS zone delegation; requires dedicated Linux VPS).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Feature test suite (`tests/Feature/TenantDomainDnsWizardTest.php`): **24 tests, 211 assertions passing (100%)**.
  - `[x]` Domain regression suite (`tests/Feature/TenantDomainAndMailboxManagementTest.php`): **21 tests, 86 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **448 tests, 2,185 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 27 static pages cleanly with zero errors (100%)**.






---

## Step 35 — Domain Email Authentication Hardening & Tenant Deliverability Reports (`IMPLEMENTED — VERIFIED`)
- `[x]` Authentication Hardening in `DnsVerificationService`:
  - `[x]` Hardened SPF syntax analysis (`analyzeSpfSyntax`): Enforces RFC 7208 prefix `v=spf1`, validates mechanism syntax, validates IPv4/IPv6 formats, warns against insecure `+all` open relays, tracks DNS lookup mechanisms (`include`, `a`, `mx`, `ptr`, `exists`, `redirect`) to warn on exceeding the 10-lookup limit (RFC 7208 section 4.6.4), and detects duplicate SPF records.
  - `[x]` Hardened DKIM key validation: Detects revoked keys (`p=` empty or missing), validates base64 key payloads, concatenates multi-chunk UDP DNS records, and warns when key length is under 2048-bit.
  - `[x]` Hardened DMARC syntax analysis (`analyzeDmarcSyntax`): Enforces `v=DMARC1;` as first tag, enforces mandatory `p=` tag (`none`, `quarantine`, `reject`), detects duplicate `_dmarc` TXT records per RFC 7489 section 6.6.3 PermError, extracts optional tags (`sp`, `pct`, `rua`, `ruf`, `adkim`, `aspf`), and provides policy explanations.
  - `[x]` Hardened MX verification: Prohibits bare IP addresses (RFC 5321 section 5.1) and warns on priority conflicts.
  - `[x]` DNS Drift Detection: Flags `dns_drift_detected: true` when an active domain loses required MX, SPF, or DKIM records.
  - `[x]` Verification History Extraction (`getVerificationHistory`): Queries immutable `audit_logs` where `action = 'domain.dns_verify'`, returning score trends and record statuses over time without schema churn.
  - `[x]` Deliverability Report Generator (`getDeliverabilityReport`): Computes 4-pillar score breakdown (MX 40%, SPF 25%, DKIM 25%, DMARC 10%), policy advisor matrix, drift indicator, and explicit diagnostic disclaimer.
- `[x]` DMARC Aggregate Report Parser (`DmarcReportParserService`):
  - `[x]` RFC 7489 XML Aggregate Report Parser: Parses `<feedback>` XML files up to 5MB.
  - `[x]` Protected against XXE vulnerabilities using `LIBXML_NONET` and proactively rejecting `<!DOCTYPE` or `<!ENTITY` blocks.
  - `[x]` Extracts report metadata, published policy, volume summaries, and source IP delivery records.
  - `[x]` Computes deterministic SHA-256 deduplication digest.
  - `[x]` Rejects cross-domain spoofing by validating `<policy_published><domain>` against tenant domain.
- `[x]` RESTful API & Security Boundaries:
  - `[x]` Added `deliverabilityReport()` on `DomainApiController` (`GET /api/domains/{domain}/deliverability-report`, supporting `?refresh=1`).
  - `[x]` Added `parseDmarcReport()` on `DomainApiController` (`POST /api/domains/{domain}/parse-dmarc-report`, throttled at `throttle:10,1`).
  - `[x]` Enhanced `verifyDns` and `verify` to log rich audit state (score, per-record statuses, drift detection) without breaking legacy Mockery tests.
  - `[x]` Zero private key exposure: Private keys strictly isolated from API responses, database tables, and audit logs.
- `[x]` Next.js Tenant UI:
  - `[x]` Added TypeScript interfaces in `types/index.ts` (`DeliverabilityScoreBreakdown`, `VerificationHistoryEntry`, `DmarcPolicyAdvisor`, `DeliverabilityReportData`, `DmarcReportParserResponse`).
  - `[x]` Added `useDomainDeliverabilityReport` and `parseDomainDmarcReport` in `hooks/useDomains.ts`.
  - `[x]` Enhanced `/domains/[id]/setup` (`DomainSetupPage.tsx`) with:
    - `[x]` DNS Drift warning alert banner.
    - `[x]` Deliverability Scorecard with 4-pillar breakdown (MX, SPF, DKIM, DMARC) and legal/diagnostic disclaimer.
    - `[x]` DMARC Policy Advisor card explaining `p=none` vs `p=quarantine` vs `p=reject` with escalation rules.
    - `[x]` Verification History audit table showing previous check timestamps, scores, and per-record statuses.
    - `[x]` DMARC Aggregate Report Inspector allowing tenants to upload or paste XML feedback reports and view detailed message counts, pass/fail rates, and source IP authenticity.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantDomainDeliverabilityReportTest.php` passes with 24 tests (105 assertions).
  - `[x]` **Level B (Configuration & Protocol Logic):** `VERIFIED`. Mockable resolver, RFC 7208 syntax analysis, RFC 7489 duplicate detection, RFC 5321 bare IP prohibition, and SHA-256 deduplication hashing verified.
  - `[x]` **Level C (End-to-End Live DNS & Inbound DMARC Collection):** `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services and public DNS zone delegation; requires dedicated Linux VPS).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Feature test suite (`tests/Feature/TenantDomainDeliverabilityReportTest.php`): **24 tests, 105 assertions passing (100%)**.
  - `[x]` DNS Wizard regression suite (`tests/Feature/TenantDomainDnsWizardTest.php`): **24 tests, 211 assertions passing (100%)**.
  - `[x]` Domain regression suite (`tests/Feature/TenantDomainAndMailboxManagementTest.php`): **21 tests, 86 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **472 tests, 2,290 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 27 static pages cleanly with zero errors (100%)**.

---

## Step 36 — Tenant Email Security & Deliverability Monitoring Dashboard (`IMPLEMENTED — VERIFIED`)
- `[x]` Database & Model Architecture:
  - `[x]` Zero schema modifications required; reuses existing `Domain`, `DeliveryLog`, `TenantOutboundUsage`, `AbuseIncident`, `Invoice`, and `Plan` models.
  - `[x]` Zero data mutation on render; read-only telemetry aggregation.
- `[x]` TenantSecurityDashboardService & Backend Orchestration:
  - `[x]` Implemented `getDashboardData(User $user, ?Request $request = null)` as unified dashboard entrypoint.
  - `[x]` `buildDomainSecuritySection`: Computes deliverability scores and detects DNS drift strictly from stored domain flags (`mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `dns_drift_detected_at`). Zero live DNS queries dispatched on render.
  - `[x]` `buildSmtpUsageSection`: Real-time daily quota from Redis via `TenantSmtpService::getUsage()`, 14-day history from `TenantOutboundUsage`, 30-day delivery event summary from `DeliveryLog`, quota near-limit (`>= 80%`) and exhaustion (`>= 100%`) warnings.
  - `[x]` `buildRecentActivitySection`: Bounded retrieval of latest 10 `DeliveryLog` records via `DeliveryLogResource`.
  - `[x]` `buildAbuseAndSecuritySection`: Scoped strictly to tenant's own incidents, formatted via `TenantAbuseIncidentResource`, synthesized warnings.
  - `[x]` `buildDmarcReportingSection`: Truthfully reports XML parser available and automated ingestion as `PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL`.
  - `[x]` `getTenantIncidents`: Bounded pagination (1..50) with status and severity filters.
  - `[x]` Per-section fault isolation: Wraps each section in independent try-catch returning explicit status contracts (`operational`, `warning`, `degraded`, `clean`, `empty`, `error`) and sanitized error messages.
- `[x]` TenantAbuseIncidentResource & Data Sanitization:
  - `[x]` Customer-facing projection of `AbuseIncident`.
  - `[x]` Strictly redacts internal traces, evidence blobs, internal paths, and administrative ID (`resolved_by`).
  - `[x]` Context-aware `suggested_remediation` synthesized per incident type (`consecutive_hard_bounces`, `daily_hard_bounce_threshold`, `high_hard_bounce_rate`, `quota_exhaustion`, `dns_drift`).
- `[x]` RESTful API & Security Boundaries:
  - `[x]` Extended `GET /api/dashboard` with backward compatibility (`user`, `domains_count`, `domain_count`, `mailboxes_count`, `mailbox_count`, `plan_info`, `recent_domains`, `recent_invoices`).
  - `[x]` Added `GET /api/security/incidents` with bounded pagination (1..50) and status/severity filters.
  - `[x]` Protected by `auth:sanctum` and `EnsureActiveSubscription`.
  - `[x]` Strict tenant isolation: Tenant A cannot observe or query Tenant B's domains, delivery logs, or abuse incidents.
  - `[x]` Administrative privileges preserved: Incident resolution strictly locked to admin control plane.
- `[x]` Next.js Tenant UI:
  - `[x]` Added TypeScript interfaces in `frontend/src/types/index.ts` (`DomainSecuritySummary`, `DomainSecurityItem`, `DomainSecuritySection`, `SmtpDeliverySummary`, `SmtpUsageHistoryItem`, `SmtpUsageSection`, `DeliveryLogEvent`, `RecentActivitySection`, `TenantAbuseIncident`, `AbuseAndSecuritySection`, `DmarcReportingSection`, `DashboardStats`).
  - `[x]` Added `useSecurityIncidents` hook and updated `useDashboard` in `frontend/src/hooks/useDashboard.ts`.
  - `[x]` Comprehensive monitoring dashboard in `frontend/src/app/(dashboard)/dashboard/page.tsx`:
    - `[x]` DNS drift, abuse incident, and quota exhaustion alert banners.
    - `[x]` 4 KPI cards (Deliverability Health, Sent Today / Quota, Authenticated Domains, Open Incidents).
    - `[x]` Domain Authentication table with setup links and health score progress bars.
    - `[x]` SMTP Daily Sending Quota & 14-Day Outbound Volume Trend cards.
    - `[x]` Recent Outbound Activity event ledger.
    - `[x]` Security Incidents Ledger with suggested remediation drawer.
    - `[x]` DMARC Reporting Posture card clarifying XML parser availability and pending automated inbox ingestion.
    - `[x]` Preserved billing invoices table.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantSecurityDeliverabilityDashboardTest.php` passes with 18 tests (144 assertions).
  - `[x]` **Level B (Fault-Tolerance & Data Protection):** `VERIFIED`. Redis degradation, zero live DNS queries on render, strict tenant isolation, and sensitive data redaction verified.
  - `[x]` **Level C (End-to-End Live Automated DMARC Inbound Collection):** `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL` (automated inbound IMAP/mail pipe requires dedicated Linux VPS infrastructure).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Feature test suite (`tests/Feature/TenantSecurityDeliverabilityDashboardTest.php`): **18 tests, 144 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **490 tests, 2,434 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 27 static pages cleanly with zero errors (100%)**.

---

## Step 37 — Tenant Notifications & Security Alert Center (`IMPLEMENTED — VERIFIED`)
- `[x]` Database & Schema Architecture:
  - `[x]` Dedicated `tenant_notifications` table created via non-destructive migration `2026_10_28_000001_create_tenant_notifications_table.php` on MySQL 8.4.3.
  - `[x]` Foreign key `tenant_id` references `users.id` with `cascadeOnDelete`.
  - `[x]` Structured columns: `category`, `severity`, `title`, `message`, `action_url`, `deduplication_key`, `data`, `read_at`, `dismissed_at`, timestamps.
  - `[x]` Composite indexes for performance: `idx_tenant_read_created`, `idx_tenant_cat_created`, `idx_tenant_dedup`.
- `[x]` Backend Models, Policies & Service Layer:
  - `[x]` Eloquent model `TenantNotification` (`app/Models/TenantNotification.php`) with scopes (`forTenant`, `unread`, `read`, `active`, `dismissed`, `category`, `severity`), helper methods, and `tenant()` / `User::tenantNotifications()` relationships.
  - `[x]` `TenantNotificationPolicy` (`app/Policies/TenantNotificationPolicy.php`) registered in `AuthServiceProvider` enforcing strict tenant ownership (`$user->id === $notification->tenant_id`).
  - `[x]` `TenantNotificationResource` (`app/Http/Resources/TenantNotificationResource.php`) providing XSS protection (`strip_tags`) and recursive sensitive data scrubbing.
  - `[x]` `TenantNotificationService` (`app/Services/TenantNotificationService.php`) orchestrator:
    - `[x]` `notify()` with atomic deduplication (`deduplication_key`) preventing alert fatigue during persistent conditions.
    - `[x]` Fail-safe database exception trapping so primary application workflows never abort on notification errors.
    - `[x]` Recursive sensitive credential scrubbing (`password`, `secret`, `token`, `key`, `private_key`, `api_key`, `hash`, `salt`, `trace`, `file`, `line`, `evidence`).
    - `[x]` Bounded `getNotifications()` (1..50 per page) with status, category, and severity filtering.
    - `[x]` Idempotent lifecycle mutations: `getUnreadCount()`, `markAsRead()`, `markAllAsRead()`, and `dismiss()`.
  - `[x]` `TenantNotificationApiController` (`app/Http/Controllers/Api/TenantNotificationApiController.php`) under `auth:sanctum` (exempt from subscription gating so expired/suspended tenants can inspect critical notices).
- `[x]` Application Event Trigger Integrations:
  - `[x]` Domain DNS drift alert (`dns_drift:{id}:active`) and drift recovery auto-resolution (`dns_recovered:{id}:{date}`) in `DomainApiController`.
  - `[x]` Mailbox forwarding and auto-reply configuration audit notifications in `MailboxRuleApiController`.
  - `[x]` Mailbox active/quarantine toggle notifications in `MailboxApiController`.
  - `[x]` Abuse incident detection alerts and admin resolution notices in `AbuseIncidentService`.
  - `[x]` Invoice payment confirmation notice (`invoice_paid:{id}`) in `BillingService`.
  - `[x]` Subscription expiration alert (`subscription_expired:{id}:{date}`) in `SuspendExpiredTenants.php`.
  - `[x]` Daily SMTP quota warning (>=80%) and exhaustion (>=100%) alerts in `OutboundUsageSyncService.php`.
- `[x]` Next.js Frontend Architecture:
  - `[x]` Added TypeScript interfaces in `frontend/src/types/index.ts` (`TenantNotificationCategory`, `TenantNotificationSeverity`, `TenantNotification`, `NotificationListResponse`, `UnreadCountResponse`).
  - `[x]` Implemented SWR hooks and mutations in `frontend/src/hooks/useNotifications.ts` (`useNotifications`, `useUnreadCount`, `markNotificationAsRead`, `markAllNotificationsAsRead`, `dismissNotification`).
  - `[x]` Interactive Notification Bell and Drawer in `frontend/src/components/notifications/NotificationDrawer.tsx` connected to `TopBar.tsx`.
  - `[x]` Navigation item with live unread counter badge in `frontend/src/components/layouts/Sidebar.tsx`.
  - `[x]` Dedicated, filterable Notification Center page at `frontend/src/app/(dashboard)/notifications/page.tsx`.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantNotificationTest.php` passes with 16 tests (92 assertions).
  - `[x]` **Level B (Isolation, Deduplication & Data Scrubbing):** `VERIFIED`. Cross-tenant isolation, idempotent mark-as-read/dismiss, atomic deduplication, issue recurrence, and recursive sensitive data scrubbing verified.
  - `[x]` **Level C (End-to-End Live Multi-Channel Push / Webhook Broadcast):** `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL` (external push notifications / SMS / external webhooks require external messaging gateways and dedicated architectural approval; local development implements in-app notification center).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Feature test suite (`tests/Feature/TenantNotificationTest.php`): **16 tests, 92 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **506 tests, 2,526 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 28 static pages cleanly with zero errors (100%)**.

---

## Step 38 — Tenant Notification Preferences & Alert Lifecycle Management (`IMPLEMENTED — VERIFIED`)
- `[x]` Database & Schema Architecture:
  - `[x]` Added `notification_preferences` (`json`, nullable) to `users` table via non-destructive migration `2026_10_28_000002_add_notification_preferences_to_users_table.php` on MySQL 8.4.3.
  - `[x]` Preserves strict backward compatibility: existing and new users default to all categories enabled (`domain_dns`, `security_abuse`, `smtp_quota`, `billing_subscription`, `mailbox_rule`, `system`).
- `[x]` Tenant Notification Preferences & Invariants:
  - `[x]` Preferences stored and managed directly per tenant user (`User::getNotificationPreferences()`, `User::isNotificationCategoryEnabled()`).
  - `[x]` Mutable categories: `domain_dns`, `smtp_quota`, `mailbox_rule`, `system`.
  - `[x]` Immutable categories: `security_abuse` and `billing_subscription` (cannot be muted; API validates and rejects muting with HTTP 422).
  - `[x]` **Critical Alert Invariant:** Any alert with `severity === 'critical'` (e.g. domain DNS drift or 100% quota exhaustion) automatically bypasses category preferences and is always delivered to the tenant.
  - `[x]` Multi-tenant isolation: Tenants can only view and update their own preferences. Cross-tenant access is impossible.
  - `[x]` Persistent audit logging: Every preference update is recorded in `audit_logs` via `AuditService::record()` with before and after state snapshots.
- `[x]` Alert Lifecycle & Archive Management:
  - `[x]` Distinct alert states: `unread`, `read`, `dismissed`, and `active` (`dismissed_at IS NULL`).
  - `[x]` Status filtering: `GET /api/notifications?status=dismissed` enables viewing archived notifications.
  - `[x]` Idempotent alert restoration: `POST /api/notifications/{id}/restore` resets `dismissed_at = null`, returning the alert to the active drawer and center.
  - `[x]` Aggregated category and severity counts: `GET /api/notifications/counts` providing unread/total breakdowns across all categories and severity levels.
- `[x]` Documented Retention Policy & Bounded Pruning:
  - `[x]` Read notifications older than 90 days are pruned.
  - `[x]` Dismissed notifications older than 30 days are pruned.
  - `[x]` Stale non-critical unread notifications older than 180 days are pruned.
  - `[x]` **Critical Alert Retention Invariant:** Unresolved critical alerts (`severity === 'critical' AND read_at IS NULL AND dismissed_at IS NULL`) are permanently exempted from automated deletion and NEVER deleted.
  - `[x]` **Anti-Data-Loss Invariant:** Pruning strictly targets `tenant_notifications`. Under no circumstances are `audit_logs` or `abuse_incidents` modified or pruned.
  - `[x]` Bounded Artisan command: `php artisan notifications:prune` with `--read-days=90`, `--dismissed-days=30`, `--batch-size=500`, `--dry-run`.
  - `[x]` Daily automated schedule in `routes/console.php` at 04:30 UTC with atomic cache lock `withoutOverlapping(15)`.
- `[x]` Next.js Frontend Implementation:
  - `[x]` TypeScript interfaces in `frontend/src/types/index.ts` (`NotificationPreferences`, `NotificationPreferencesResponse`, `CategoryCountItem`, `NotificationCountsResponse`).
  - `[x]` SWR hooks and mutations in `frontend/src/hooks/useNotifications.ts` (`useNotificationCounts`, `useNotificationPreferences`, `updateNotificationPreferences`, `restoreNotification`).
  - `[x]` Enhanced Notification Center in `frontend/src/app/(dashboard)/notifications/page.tsx`:
    - `[x]` View switcher between "Alerts & Activity" and "Preferences".
    - `[x]` Status filter tabs with live count badges: All, Unread, Read, and Dismissed.
    - `[x]` One-click "Restore" action on dismissed alert items.
    - `[x]` `NotificationPreferencesCard` with category switches, descriptions, immutable locked badges, and critical alert explanations.
  - `[x]` Settings page integration (`frontend/src/app/(dashboard)/settings/page.tsx`) linking directly to notification preferences.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantNotificationPreferencesTest.php` passes with 15 tests (75 assertions).
  - `[x]` **Level B (Preferences Isolation, Immutability & Retention Invariants):** `VERIFIED`. Category muting, critical severity bypass, immutable category enforcement, audit logging, dry-run simulation, and retention pruning invariants verified.
  - `[x]` **Level C (End-to-End Live External Notification Channels):** `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL` (external push / email digest / SMS delivery requires third-party delivery infrastructure).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Feature test suite (`tests/Feature/TenantNotificationPreferencesTest.php`): **15 tests, 75 assertions passing (100%)**.
  - `[x]` Notification feature suite (`tests/Feature/TenantNotificationTest.php`): **16 tests, 92 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **521 tests, 2,601 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 28 static pages cleanly with zero errors (100%)**.

---

## Step 39 — Tenant Notification Delivery Reliability & Operational Monitoring (`IMPLEMENTED — VERIFIED`)
- `[x]` Concurrency-Safe Deduplication & Delivery Reliability:
  - `[x]` Neutralized race conditions in `TenantNotificationService::notify()` via atomic mutex lock (`Cache::lock("tenant_notif_lock:{$tenantId}:" . md5($deduplicationKey), 5)->block(3)`).
  - `[x]` Verified under concurrency that duplicate unread alerts are never created; identical events return the existing active notification.
  - `[x]` Operational metrics tracking: records deduplication outcomes, creation successes, and structured failure diagnostics with 24-hour cache counters.
- `[x]` Complex & Malformed Metadata Sanitization:
  - `[x]` Enhanced `TenantNotificationService::scrubSensitiveData()` with recursion depth guard ($depth \le 10$) preventing stack exhaustion or circular reference panics.
  - `[x]` Stream and file resources safely converted to `'[Resource: type]'` preventing JSON serialization exceptions.
  - `[x]` Objects converted via `toArray()`, `JsonSerializable`, `__toString()`, or class descriptor.
  - `[x]` Strings normalized to valid UTF-8 via `mb_convert_encoding()`.
- `[x]` Pruning Command Hardening & Boundary Validation:
  - `[x]` Validated input boundaries in `PruneNotificationsCommand`: `--read-days >= 1`, `--dismissed-days >= 1`, `--stale-days >= 1`, `1 <= --batch-size <= 5000`. Exits with `Command::INVALID` (code 2) on failure.
  - `[x]` Caches execution telemetry in `notifications:prune:last_execution` (executed_at, status, counts, duration_ms, options).
  - `[x]` Overlap protection verified: scheduler `withoutOverlapping(15)` plus command-level `Cache::lock('notifications_prune_lock', 300)`.
  - `[x]` Stale 180-day unread non-critical rule verified while unresolved critical alerts remain 100% immune from deletion.
- `[x]` Operational Status & Admin Diagnostics:
  - `[x]` Added `getOperationalStatus()` to `TenantNotificationService` reporting aggregate metrics, unread counts, unresolved critical alerts, 24-hour counters, retention settings, and scheduler registration posture.
  - `[x]` Strict privacy: completely excludes tenant notification content, titles, messages, and email addresses.
  - `[x]` Integrated `checkNotifications()` into `SystemHealthService::getHealth()`, incorporating notification delivery posture into overall system health.
  - `[x]` Registered dedicated route `GET /api/admin/system/notifications` protected under `EnsureAdmin` and `admin.permission:admin.stats.read`.
- `[x]` API Query Validation Hardening:
  - `[x]` Hardened `TenantNotificationApiController::index` query validation: `category` validated against `VALID_CATEGORIES` and `severity` validated against `VALID_SEVERITIES`. Invalid queries return HTTP 422.
- `[x]` Next.js Admin UI Integration:
  - `[x]` Updated TypeScript interfaces in `frontend/src/types/index.ts` with `notifications?: ComponentHealth` and `NotificationOperationalStatus`.
  - `[x]` Added localization keys in `frontend/messages/en.json`.
  - `[x]` Rendered 8th component card in `frontend/src/app/(admin)/admin/system-health/page.tsx` with `Bell` icon displaying total alerts, active unread, unresolved critical count, and pruning posture.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `TenantNotificationReliabilityTest.php` passes with 17 tests (94 assertions).
  - `[x]` **Level B (Concurrency, Telemetry, Metadata & Invariants):** `VERIFIED`. Concurrency locking, failure tracking, resource/object sanitization, strict query validation, pruning boundaries, overlap prevention, and admin privacy verified.
  - `[x]` **Level C (End-to-End Live Multi-Channel Push / Webhooks):** `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL` (external push / email digest / SMS delivery requires third-party delivery infrastructure).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Reliability feature test suite (`tests/Feature/TenantNotificationReliabilityTest.php`): **17 tests, 94 assertions passing (100%)**.
  - `[x]` All notification feature test suites (3 suites): **48 tests, 261 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **538 tests, 2,695 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 28 static pages cleanly with zero errors (100%)**.

---

## Step 40 — Mail Delivery Queue Foundation & Retry Management (`IMPLEMENTED — VERIFIED`)
- `[x]` Mail Delivery Ledger Database Migration (`mail_deliveries`):
  - `[x]` Created and applied migration `2026_10_30_000001_create_mail_deliveries_table.php` on MySQL 8.4.3 (`emailsaas_local`).
  - `[x]` High-performance composite indexes: `[tenant_id, created_at]`, `[tenant_id, status, created_at]`, `[status, created_at]`, `[status, last_attempted_at]`, `[tenant_id, idempotency_key]`.
  - `[x]` Foreign key `tenant_id` references `users(id)` with cascade deletion; dedicated to application-level transactional emails (verification, reset, invoices, alerts) with zero interference with Postfix or `delivery_logs`.
- `[x]` Configuration & Domain Model Layer:
  - `[x]` Configuration in `config/mail_delivery.php` (dry-run mode default true, max attempts 3, base backoff 60s, max backoff 900s, stale threshold 900s).
  - `[x]` Eloquent model `App\Models\MailDeliveryRecord` with status constants (`STATUS_QUEUED`, `STATUS_PROCESSING`, `STATUS_SENT`, `STATUS_FAILED`, `STATUS_CANCELLED`), casts, scopes (`forTenant`, `queued`, `processing`, `sent`, `failed`, `cancelled`, `staleProcessing`), and domain helper methods (`canRetry`, `markFailedAttempt`, `isSent`, etc.).
  - `[x]` Exception hierarchy in `App\Exceptions\MailDelivery\` (`PermanentDeliveryException`, `TransientDeliveryException`, `InvalidRecipientException`, `InvalidPayloadException`, `UnauthorizedRecipientException`, `UnsupportedMessageTypeException`).
- `[x]` Core Mail Delivery Service Layer (`MailDeliveryService`):
  - `[x]` `enqueue()`: Validates recipient email, message type (`account_verification`, `password_reset`, `billing_invoice`, `security_alert`), required payload fields; recursively scrubs sensitive tokens (`password`, `token`, `secret`, `bearer`); deduplicates via `idempotency_key`; and dispatches `ProcessMailDeliveryJob`.
  - `[x]` `retry()`: Idempotent manual retry of failed/cancelled records, resets attempts to 0, and dispatches job.
  - `[x]` `cancel()`: Idempotent cancellation of pending queued records.
  - `[x]` `recoverStaleProcessing()`: Rescues interrupted/crashed worker records stuck in `processing`.
  - `[x]` `getOperationalStatus()`: Structured operational telemetry and queue health.
  - `[x]` `getTenantDeliveries()` & `getAdminDeliveries()`: Bounded paginated queries.
- `[x]` Queue Worker & Mail Transport Abstraction (`ProcessMailDeliveryJob`):
  - `[x]` Implements `ShouldQueue` with dynamic retry budget per delivery record.
  - `[x]` Dry-run safety invariant: `MAIL_DELIVERY_DRY_RUN=true` simulates deliveries locally and logs to Laravel log without outbound SMTP network traffic.
  - `[x]` Failure classification: Permanent errors fail immediately without retry; transient errors trigger exponential backoff with jitter (`min(900, (60 * 2^(attempts-1)) + jitter)`). Records exceeding `max_attempts` dead-letter to `failed`.
  - `[x]` Error message sanitizer redacting passwords, bearer tokens, and hashes from logs and database error columns.
  - `[x]` Responsive transactional Blade email templates in `resources/views/emails/transactional/` (`layout`, `account_verification`, `password_reset`, `billing_invoice`, `security_alert`) and `TransactionalMail` mailable.
- `[x]` Scheduled Stale Processing Recovery (`mail:recover-stale`):
  - `[x]` Artisan command `App\Console\Commands\RecoverStaleMailCommand` with `--stale-seconds=900` and `--dry-run`.
  - `[x]` Scheduled every 15 minutes in `routes/console.php` with `withoutOverlapping(10)` protection.
- `[x]` API Routing & Policy Isolation:
  - `[x]` Tenant endpoints: `GET /api/mail-deliveries` and `GET /api/mail-deliveries/{id}` protected by `MailDeliveryPolicy` and strictly tenant-scoped (404 on cross-tenant access).
  - `[x]` Admin endpoints: `GET /api/admin/system/mail-queue`, `GET /api/admin/system/mail-deliveries`, `POST /api/admin/system/mail-deliveries/{id}/retry`, `POST /api/admin/system/mail-deliveries/{id}/cancel` guarded by `EnsureAdmin` and `admin.permission:admin.stats.read`.
  - `[x]` System health integration: added `mail_delivery` to `SystemHealthService::getHealth()`.
- `[x]` Next.js Admin UI Integration:
  - `[x]` Added `mail_delivery?: ComponentHealth`, `MailQueueOperationalStatus`, `MailDeliveryItem` in `frontend/src/types/index.ts`.
  - `[x]` Added localization strings in `frontend/messages/en.json`.
  - `[x]` Rendered 9th posture card in `frontend/src/app/(admin)/admin/system-health/page.tsx` with `Mail` icon.
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `MailDeliveryQueueTest.php` passes with 15 tests (78 assertions).
  - `[x]` **Level B (Queue Invariants, Idempotency, Backoff & Redaction):** `VERIFIED`. Dry-run simulation, idempotency keys, permanent vs transient failure classification, exponential backoff with jitter, dead-lettering, sensitive token scrubbing, and stale recovery verified.
  - `[x]` **Level C (End-to-End Live External SMTP Transport):** `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL` (sending live external emails through Postfix or external relay is locked and disabled per Step 40 specification).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Dedicated queue feature test suite (`tests/Feature/MailDeliveryQueueTest.php`): **15 tests, 78 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **553 tests, 2,773 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.
  - `[x]` Frontend Next.js 14 production build (`npm run build`): **Compiled all 28 static pages cleanly with zero errors (100%)**.






---

## Step 41 — Transactional Mail Security, Queue Recovery & Delivery Consistency (`IMPLEMENTED — VERIFIED`)
- `[x]` Finite State Machine & Delivery Lifecycle Validation:
  - `[x]` Defined strict transition mapping (`ALLOWED_TRANSITIONS`) in `MailDeliveryRecord`:
    - `queued` -> `[processing, cancelled]`
    - `processing` -> `[sent, failed, queued]`
    - `failed` -> `[queued]`
    - `cancelled` -> `[queued]`
    - `sent` -> `[]`
  - `[x]` Added `canTransitionTo()` and `transitionTo()` methods throwing `InvalidDeliveryStateException` on illegal transitions.
  - `[x]` Sent immutability guaranteed: records in `sent` status cannot transition or be modified, retried, or cancelled.
- `[x]` Worker Execution Safety & Timing Hierarchy:
  - `[x]` Added explicit `$timeout = 60;` property on `ProcessMailDeliveryJob`, strictly below queue driver `retry_after = 90`.
  - `[x]` Added `worker_lease_seconds = 120` to `config/mail_delivery.php`.
  - `[x]` Implemented atomic conditional worker acquisition: claims `queued` or reclaims `processing` only when `last_attempted_at <= now() - worker_lease_seconds`. Sibling workers and recovery jobs will never stomp or interrupt an active running worker.
  - `[x]` Worker completion updates (`sent` or `failed`) conditioned strictly on `WHERE id = ? AND status = 'processing'`.
- `[x]` Atomic Concurrency & Safe State Recovery:
  - `[x]` Stale processing recovery (`recoverStaleProcessing()`): Conditional atomic update `WHERE id = ? AND status = 'processing' AND last_attempted_at <= cutoff`. If an active worker finishes in between, 0 rows are affected and no duplicate job is dispatched.
  - `[x]` Retry (`retry()`): Validates state (`isSent()` or `isProcessing()` throws `InvalidDeliveryStateException`), performs atomic conditional update `WHERE id = ? AND status IN ('failed', 'cancelled')`, and dispatches queue job only if affected rows > 0.
  - `[x]` Cancel (`cancel()`): Validates state (`isSent()` or `isProcessing()` throws `InvalidDeliveryStateException`), performs atomic conditional update `WHERE id = ? AND status = 'queued'`.
  - `[x]` Concurrency-safe idempotency locking via `Cache::lock("mail_delivery_idemp:{$tenantId}:{$key}", 5)->block(3)`.
  - `[x]` Two Generals' Problem Documentation: Acknowledged distributed systems constraint that true exactly-once email delivery across a worker crash post-SMTP acceptance (`250 OK`) is technically impossible without downstream MTA deduplication. Status `sent` strictly represents Transport Acceptance.
- `[x]` Per-Tenant Burst Rate Limiting:
  - `[x]` Integrated `RateLimiter::tooManyAttempts("mail_delivery_rate:{$tenantId}", $limit)` enforcing 60 dispatches/minute per tenant (configurable via `config('mail_delivery.tenant_rate_limit_per_minute')`).
  - `[x]` Throws `RateLimitExceededException` upon exceeding burst limit, protecting queue capacity.
- `[x]` Security & Content Hardening:
  - `[x]` URL scheme validation: `validateSafeUrl()` validates action URLs (`verification_url`, `reset_url`, `invoice_url`, `action_url`) strictly against `http` and `https` schemes with valid hostnames, rejecting `javascript:`, `data:`, and malformed URIs.
  - `[x]` Blade template contextual escaping: All transactional templates strictly utilize `{{ ... }}` escaping with zero unescaped `{!! ... !!}` directives.
  - `[x]` Enhanced error message scrubber: Regex scrubbing of URI passwords (`redis://user:pass@host` -> `redis://user:[REDACTED]@host`), bearer tokens, API keys, authorization headers, and hashes.
- `[x]` Administrative API Conflict Handling & Configuration Audit:
  - `[x]` `AdminSystemHealthApiController`: Catches `InvalidDeliveryStateException` on retry/cancel endpoints and returns semantic `HTTP 409 Conflict`.
  - `[x]` `MailDeliveryService::validateConfiguration()`: Validates timing hierarchy (`job_timeout < retry_after`, `retry_after <= stale_threshold`, `base_backoff <= max_backoff`, `worker_lease > timeout`), surfacing `configuration_health` inside administrative telemetry (`GET /api/admin/system/mail-queue`).
- `[x]` Three-Level Verification Matrix:
  - `[x]` **Level A (Application & API Layer):** `VERIFIED`. `MailDeliverySecurityAndConsistencyTest.php` passes with 15 tests (51 assertions).
  - `[x]` **Level B (State Machine, Concurrency, Leases, Redaction & Recovery):** `VERIFIED`. Valid/invalid transitions, 409 Conflict responses on in-flight retries/cancellations, concurrent retry deduplication, active worker preservation during stale recovery, dead-lettering, concurrent idempotency key locking, rate limiting, URL scheme validation, Blade XSS escaping, error secret redaction, config validation, and operational health verified.
  - `[x]` **Level C (End-to-End Live External SMTP Transport):** `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL` (sending live external emails through Postfix or external relay is locked and disabled per Step 41 specification).
- `[x]` Automated Verification & Regression Testing:
  - `[x]` Security feature test suite (`tests/Feature/MailDeliverySecurityAndConsistencyTest.php`): **15 tests, 51 assertions passing (100%)**.
  - `[x]` Queue foundation test suite (`tests/Feature/MailDeliveryQueueTest.php`): **15 tests, 78 assertions passing (100%)**.
  - `[x]` Full regression test suite (`php vendor/bin/phpunit`): **568 tests, 2,824 assertions passing cleanly (0 failures, 0 errors, 100%)**.
  - `[x]` Frontend typecheck (`npx tsc --noEmit`): **0 errors (100%)**.

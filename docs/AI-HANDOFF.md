# AI IMPLEMENTATION HANDOFF

This document is the operational starting point for any AI coding agent working on this repository.

## 1. Current Architecture
- **Frontend:** Next.js 14 App Router, React 18, Tailwind, shadcn/ui.
- **Backend:** Laravel 11, PHP 8.2.x, Sanctum, MariaDB, Redis.
- **Mail Stack:** Postfix, Dovecot, Roundcube.
- **Tenants:** Users act as Tenants.
- **Billing:** SSLCommerz integration, Prepaid subscription model.
- **Outbound SMTP Policy:** Laravel Policy Daemon (`php artisan policy:serve`), Redis atomic Lua enforcement, Postfix `smtpd_data_restrictions`.
- **Outbound Abuse & Bounce Tracking:** Log telemetry parser (`php artisan mail:process-log`), Redis atomic bounce counters, non-destructive threshold alerting (`storage/logs/abuse.log`).

## 2. Current Billing Lifecycle
`NEW` (pending) -> `CHECKOUT` (pending invoice) -> `PAYMENT` -> `VERIFICATION` (IPN Webhook) -> `ACTIVE` (User active, Invoice paid, plan_expires_at updated) -> `EXPIRED` (Scheduler suspends user and domains) -> `RENEWAL` (Invoice paid, domains reactivated, plan_expires_at extended).

## 3. Completed Security & Stability Patches (Step 8)
- **Mailbox Password Mass-Assignment:** Patched `Mailbox` model with `$fillable` and `$hidden` arrays so Dovecot passwords are encrypted via SHA512-CRYPT, persisted properly, and obscured from API output.
- **Postfix Cross-Tenant Spoofing:** Configured Postfix `smtpd_sender_login_maps` and `reject_sender_login_mismatch` in `postfix-config/main.cf` to strictly enforce that authenticated SASL users can only send mail matching their authorized local mailbox or alias addresses.

## 4. Completed Quota Foundations (Steps 11, 12, 13, 14)
- **Step 11 — MariaDB Schema:** Added `daily_outbound_recipients` and `mailbox_daily_outbound_recipients` to `plans` table (-1 = unlimited, 0 = disabled, positive integer = limit). Created `tenant_outbound_usage` historical ledger table with `UNIQUE(user_id, usage_date)`.
- **Step 12 — Redis Quota Service:** Implemented `OutboundQuotaService` providing atomic check+increment via Lua scripts in Redis over a 48h TTL on a fixed UTC daily window with fail-open behavior on Redis downtime.
- **Step 13 — Policy Daemon & Postfix Integration:**
  - Implemented `PostfixPolicyParser`, `PolicyRequest`, and `PolicyResponse` supporting the Postfix SMTP policy delegation protocol.
  - Implemented `PolicyDecisionService` resolving `sasl_username` to `Mailbox -> Domain -> Tenant -> Plan`, validating active tenant and domain states, checking transaction idempotency (`outbound:policy:tx:{instance}`), and delegating to `OutboundQuotaService`.
  - Implemented `PolicyDaemonCommand` (`php artisan policy:serve`) providing a persistent TCP socket server (`127.0.0.1:10031`) with Supervisor process configuration (`server-configs/mailsaas-policy.conf`).
  - Integrated into `postfix-config/main.cf` under `smtpd_data_restrictions` with `check_policy_service inet:127.0.0.1:10031` and fail-open default (`smtpd_policy_service_default_action = DUNNO`).
- **Step 14 — Redis → MariaDB Usage Synchronization:**
  - Implemented `TenantOutboundUsage` model.
  - Implemented `OutboundUsageSyncService` with bounded Redis `SCAN` (`outbound:tenant:*:recipients:daily:*`), key deduplication, regex validation, calendar date parsing, and monotonic ledger update (never decrements confirmed historical data).
  - Implemented `php artisan outbound:usage-sync` command (`SyncOutboundUsageCommand`) with atomic lock `Cache::lock('outbound_usage_sync_lock', 600)`.
  - Registered hourly schedule in `routes/console.php` with `withoutOverlapping(15)` mutex.
  - Full test suite: 72 tests, 263 assertions passing.
- **Pre-Step 15 Baseline Restoration:**
  - Removed duplicate boilerplate migration `0001_01_01_000000_create_users_table.php` which conflicted with canonical `2024_01_01_000002_create_users_table.php` on `users` table creation during `RefreshDatabase`.
  - Verified full test suite execution: 72 tests, 263 assertions passing (Unit: 8 tests/30 assertions, Feature: 64 tests/233 assertions).

- **Step 15 — Outbound Abuse Detection & Bounce Tracking (IMPLEMENTED WITH DEPLOYMENT REQUIREMENT - HARDENED):**
  - Implemented `PostfixLogParserService` streaming incremental log parser with rotation tail draining (`mail.log.1`), truncation handling, and transactional cursor state (`inode` + `offset`) in Redis (`outbound:abuse:parser:cursor`).
  - Implemented `BounceClassificationService` providing deterministic bounce classification based on Postfix delivery status, enhanced DSN codes (RFC 3463: `2.x.x` success, `5.x.x` hard bounce, `4.x.x` soft bounce), and SMTP status codes.
  - Implemented content filter discrimination (`NormalizedMailEvent::TYPE_INTERMEDIATE_FILTER_HANDOFF` on `smtp-amavis` / `127.0.0.1:10024`) preventing false success increments or consecutive hard bounce counter resets.
  - Implemented queue ID alias correlation (`outbound:abuse:qid_alias:{newQid} => oldQid`) to attribute messages reinjected by Amavis on port 10025 back to the original authenticated sender.
  - Implemented atomic soft bounce deduplication (`outbound:abuse:seen:{queueId}:{recipient}:{classification}` with 24h TTL) preventing duplicate counting of repeated Postfix deferrals.
  - Implemented `AbuseAttributionService` mapping envelope senders to `Mailbox -> Domain -> Tenant` with graceful fallback to domain-level or system-level ownership.
  - Implemented `AbuseDetectionService` with queue ID correlation (`outbound:abuse:qid:*`), atomic daily Redis bounce counters (`outbound:abuse:tenant:*`, `outbound:abuse:mailbox:*`), consecutive mailbox hard bounce tracking with success reset, and non-destructive threshold checks (10% hard bounce rate on $\ge 20$ accepted outbound attempts, 50 daily hard bounces, 15 consecutive hard bounces).
  - Implemented rate-safe alert cooldowns (`SET NX` 24h) and structured JSON logging to `storage/logs/abuse.log` (`config/logging.php` abuse channel). Zero automatic account/domain/mailbox suspensions.
  - Implemented `php artisan mail:process-log` command (`ProcessMailLogCommand`) with `--lines=1000`, `--dry-run` (zero Redis mutations), `--path=`, transactional cursor checkpointing, atomic concurrency locking (`Cache::lock('mail_process_log_lock', 300)`), and scheduled execution every 5 minutes in `routes/console.php`.
  - Deployment Requirement: `/var/log/mail.log` on Ubuntu must have read permissions granted to `www-data` (via `adm` group membership or POSIX ACL `setfacl -m u:www-data:r /var/log/mail.log`).
  - Full test suite: 118 tests, 458 assertions passing (46 Step 15 tests, 195 assertions; zero regressions on Step 13 and Step 14).

- **Step 16A — Admin SMTP Management & Deliverability Control Plane (IMPLEMENTED):**
  - Architecture-locked scope implemented with ZERO database migrations.
  - Implemented `AdminSmtpService` (`App\Services\Admin\AdminSmtpService`) providing cluster overview, fail-safe Redis telemetry detection with graceful degradation, bounded batch discovery (no unindexed `KEYS *`), parent hierarchy invariant validation on mailbox enable (`Domain active AND Tenant active AND Subscription valid`), SHA512-CRYPT mailbox password resets, consecutive hard bounce streak resets, and structured operational audit logging.
  - Implemented `AdminSmtpTenantResource` and `AdminSmtpMailboxResource`.
  - Implemented `AdminSmtpApiController` with 8 endpoints at `/api/admin/smtp/*` protected by `EnsureAdmin`.
  - Implemented dedicated daily logging channel `'admin_smtp'` logging to `storage/logs/admin-smtp.log`.
  - Implemented Next.js 14 Admin SMTP UI at `/(admin)/admin/smtp`: `SmtpOverview`, `SmtpTenantsTable`, `SmtpMailboxesTable`, and `SmtpAbuseTable`. Complete `next-intl` localization with zero hardcoded strings.
  - Full test suite: 136 tests, 555 assertions passing cleanly (18 new Step 16A tests, zero regressions).
  - Explicitly deferred items: Tenant-level manual suspension, persistent administrative `audit_logs` table, granular RBAC, and persistent `abuse_incidents` table.

- **Step 16B.1 — Persistent Administrative Audit Logging Foundation (IMPLEMENTED):**
  - Implemented MariaDB migration `2026_09_23_000001_create_audit_logs_table.php` provisioning relational `audit_logs` table (`actor_user_id` nullable FK -> `users.id` on delete set null, `actor_email`, `action`, `entity_type`, `entity_id`, `before_state`, `after_state`, `reason`, `ip_address`, `user_agent`, `request_id`, `created_at`).
  - Implemented application-level append-only `AuditLog` Eloquent model (`const UPDATED_AT = null`, JSON array casts, `actor()` relation; no update/delete mutations).
  - Implemented `AuditService` with centralized recursive secret redaction (`sanitizeState()` scrubbing passwords, hashes, tokens, keys, OTPs, signatures) and fail-safe database error trapping.
  - Integrated `AdminSmtpService` mutations (`toggleMailbox`, `resetPassword`, `resetConsecutiveBounces`) with single-point pre-logging secret redaction via `AuditService::sanitizeState()`, ensuring raw state never reaches `storage/logs/admin-smtp.log` while persisting sanitized records into `AuditLog`.
  - Implemented `AdminAuditLogApiController` (`GET /api/admin/audit-logs` bounded to max 50/page with search/filters, `GET /api/admin/audit-logs/{id}`) protected by `EnsureAdmin`.
  - Implemented Next.js 14 Admin Audit Log view at `/admin/audit-logs` with `AuditLogsTable`, search, action/entity filters, pagination, and detail inspection modal.
  - Added unit and feature tests (`tests/Unit/AuditServiceTest.php`, `tests/Feature/AdminAuditLogTest.php`).
  - Full test suite: 153 tests, 649 assertions passing cleanly. Clean Next.js production build (`npm run build`).

- **Step 16B.2 — Granular Administrative RBAC (Roles & Permissions) (IMPLEMENTED & VERIFIED):**
  - **Single Guard Namespace (`RBAC-DEC-01`):** Spatie Laravel Permission configured to use the existing `web` session/cookie guard (`config/permission.php`). `User` model specifies `protected string $guard_name = 'web';` and uses `HasRoles` trait.
  - **Explicit Super Admin Permissions (`RBAC-DEC-02`):** Zero universal `Gate::before` bypass. `RoleEnum::SUPER_ADMIN` is explicitly granted all 13 permissions defined in `PermissionEnum`, ensuring complete auditability and least-privilege predictability.
  - **Frontline Mailbox Password Reset (`RBAC-DEC-03`):** Mailbox password reset endpoint protected by `admin.permission:admin.smtp.mailbox.reset_password`, authorized for `RoleEnum::SUPER_ADMIN` and `RoleEnum::DELIVERABILITY_OPERATOR`. `RoleEnum::CUSTOMER_SUPPORT` is denied mutation endpoints.
  - **Tenant Controller Administrator Segregation (`RBAC-DEC-04`):** `AdminApiController::suspendUser()` and `activateUser()` strictly reject administrator targets (`is_admin = true` or `hasAnyRole()`) with HTTP 403 and operational denial logging, preventing cross-domain privilege corruption.
  - **Operational Denial Logging (`RBAC-DEC-05`):** Created `SecurityAuditLogger` logging all authorization rejections to `storage/logs/security.log` via daily `security` channel (`config/logging.php`). Includes recursive redaction of sensitive credentials, passwords, tokens, and hashes.
  - **Dedicated Concurrency Governance Mutex (`RBAC-DEC-06`):** Implemented `SuperAdminGovernanceService` and `governance_locks` table. Atomic `SELECT ... FOR UPDATE` mutex over `'super_admin_governance'` lock row protects the final active Super Admin from accidental demotion, de-escalation, deletion, or suspension. Includes MySQL 8.0 `performance_schema.data_lock_waits` diagnostics on contention.
  - **Emergency Recovery Status Preservation (`RBAC-DEC-07`):** Implemented `php artisan rbac:emergency-recovery {email} {--reactivate}` command (`RbacEmergencyRecoveryCommand`). Re-assigns Super Admin role and restores `is_admin = true` while preserving existing account status (`suspended` remains suspended unless explicit `--reactivate` flag is supplied).
  - **Explicit Test Factory States (`RBAC-DEC-08`):** `UserFactory` defaults to zero administrative roles (`is_admin = false`). Added explicit factory states `superAdmin()`, `deliverabilityOperator()`, `customerSupport()`, and `withoutRoles()`. Zero implicit observer role creation.
  - **Pure-DML Migration & Non-Destructive Rollback (`RBAC-DEC-09` & Supplementary):**
    - Migration `2026_10_03_000001_create_permission_tables.php`: Spatie tables with `provenance` column on `model_has_roles`.
    - Migration `2026_10_03_000002_create_governance_locks_table.php`: `governance_locks` table with seeded `'super_admin_governance'` lock row.
    - Migration `2026_10_03_000003_seed_rbac_and_backfill_legacy_admins.php`: Pure-DML seed of roles/permissions and backfill of existing `is_admin = 1` users with provenance `'migration_step_16b2_backfill'`. `down()` rollback strictly deletes only backfilled records, preserving subsequent legitimate role assignments.
  - **Fail-Closed Default Privileges (`RBAC-DEC-10`):** New administrators have zero default roles/permissions. `RequireAdminPermission` middleware denies access (HTTP 403) to any administrator lacking the required permission.
  - **Perimeter Guard Hardening (Step 16B.2A):** `EnsureAdmin` middleware checks authentication, verifies `$user->status !== 'suspended'`, and confirms `$user->is_admin || $user->hasAnyRole(...)`.
  - **Tenant Invoice Authorization Hardening (Finding 6 Remediation):** Replaced legacy `$user->is_admin` check in `InvoicePolicy::view()` with granular RBAC check (`admin.invoices.read`) on the `web` guard, ensuring zero-role administrators cannot bypass RBAC to access other tenants' invoices while preserving tenant ownership.
  - **Test Suite Execution:** Expanded test suite in `tests/Feature/RbacAuthorizationTest.php` to 28 tests (84 assertions). Full test suite: **189 passed (758 assertions)** cleanly with zero regressions.

- **Step 16B.3 — Tenant Suspension Redesign & Billing Lifecycle Integration (IMPLEMENTED & VERIFIED):**
  - **100% Administrative Exemption:** `SuspendExpiredTenants` command explicitly excludes administrators by checking both `where('is_admin', false)` AND `whereDoesntHave('roles', fn ($q) => $q->whereIn('name', RoleEnum::adminRoles()))`.
  - **Pessimistic Concurrency Locking & Race Condition Prevention:**
    - `SuspendExpiredTenants` executes per-tenant locked transactions with `lockForUpdate()`, re-checking `status === 'active'` and `plan_expires_at < now()` inside the lock to eliminate race conditions with concurrent renewal webhooks. Isolated error handling prevents one tenant exception from aborting others.
    - `BillingService::markInvoicePaid()` locks both `Invoice` and `User` rows via `lockForUpdate()`, enforcing lock-level idempotency (`if (! $lockedInvoice || $lockedInvoice->status === 'paid') return;`) to guarantee financial idempotency under duplicate/concurrent webhooks.
  - **Domain Invariant Scoping:** Auto-suspension and administrative suspension strictly target active domains (`where('status', 'active')`), preserving `pending` and unverified domains. Reactivation targets verified domains (`where('mx_verified', true)`).
  - **Independent Mailbox Disablement Preservation:** Reactivation re-enables verified parent domains without modifying mailbox `is_active` flags, guaranteeing that mailboxes independently disabled for abuse, security, or admin holds remain disabled upon billing renewal.
  - **Strict IPN Currency Validation:** `BillingService::handleIpn()` verifies that the incoming IPN currency matches the invoice currency (`BDT`), rejecting mismatches with logged warnings.
  - **Persistent Relational Audit Logging:** System auto-suspensions (`tenant.auto_suspend`), payment invoice activations (`billing.invoice_paid`), and admin user mutations (`admin.users.suspend`, `admin.users.activate`) are logged to `audit_logs` via `AuditService::record()` with sensitive credential scrubbing.
  - **Test Suite Execution:** Expanded `tests/Feature/BillingLifecycleTest.php` with 9 new feature regression tests (18 tests total, 67 assertions). Full test suite: **198 passed (807 assertions)** cleanly with zero regressions.

- **Step 16B.4 — Persistent Abuse Incident Ledger (IMPLEMENTED & VERIFIED):**
  - **Relational Ledger Schema:** Implemented `database/migrations/2026_10_06_000001_create_abuse_incidents_table.php` provisioning `abuse_incidents` table with auto-increment `id`, unique `uuid`, nullable `nullOnDelete()` foreign keys (`tenant_id`, `domain_id`, `mailbox_id`, `resolved_by`), unique `idempotency_key`, indexes on `incident_type`, `severity`, `status`, `occurred_at`, and composite indexes on `['tenant_id', 'created_at']`, `['incident_type', 'created_at']`, `['status', 'created_at']`.
  - **Durable Historical Snapshots:** Preserves immutable entity snapshots (`tenant_email`, `domain_name`, `mailbox_email`) ensuring compliance audit trails remain intact when tenant, domain, or mailbox entities are deleted.
  - **Eloquent Model & Scopes:** Implemented `AbuseIncident` model with auto-generated UUID, automatic `occurred_at` timestamps, query scopes (`open`, `resolved`, `forTenant`, `ofType`, `severity`), relationships, and dual `id`/`uuid` route model binding (`resolveRouteBinding`).
  - **Centralized Service Layer:** Implemented `AbuseIncidentService` with deduplication via `idempotency_key`, recursive evidence credential scrubbing via `AuditService::sanitizeState()` (zero passwords, tokens, hashes, or payload secrets), administrative resolution/dismissal, and fail-safe try/catch error trapping.
  - **Detector Integrations:**
    - `AbuseDetectionService::emitAlert()`: dispatches fail-safe incident creation with deterministic idempotency keys (`log_parser:{alertType}:{tenantId}:{mailboxId}:{date}`) without interrupting log parsing flow.
    - `PolicyDecisionService::evaluate()`: on `REJECTED_QUOTA`, records incident with atomic Redis `SET NX` daily cooldown (`outbound:abuse:incident:cooldown:quota:{tenantId}:{mailboxId}:{date}`) to protect MariaDB from high-frequency write amplification while fail-safely preserving the SMTP rejection decision (`REJECT 554 5.7.1`).
  - **Administrative API Endpoints:** Implemented `AdminAbuseIncidentApiController` and `AbuseIncidentResource` providing bounded pagination (1-50), search, and filters under `/api/admin/smtp/*`:
    - `GET /api/admin/smtp/incidents` (`admin.permission:admin.smtp.read`)
    - `GET /api/admin/smtp/incidents/{incident}` (`admin.permission:admin.smtp.read`)
    - `POST /api/admin/smtp/incidents/{incident}/resolve` (`admin.permission:admin.smtp.mailbox.toggle`)
  - **Audit Log Integration:** Administrative incident resolution and dismissal are persistently logged to `audit_logs` via `AuditService::record()`. Background daemon ingestion does not pollute `audit_logs`.
  - **Test Suite Execution:** Implemented `tests/Feature/AbuseIncidentLedgerTest.php` with 16 comprehensive feature tests (81 assertions). Full test suite: **214 passed (888 assertions)** cleanly with zero regressions.

- **Step 17 — Backup & Disaster Recovery (IMPLEMENTED & VERIFIED):**
  - **Logging & Configuration:** Dedicated `backup` channel in `config/logging.php` (`storage/logs/backup.log`); centralized configuration in `config/backup.php` defining storage paths, retention policies (7 daily, 4 weekly, 3 monthly), critical table lists, and offsite placeholders.
  - **Database Backup Service:** `DatabaseBackupService` generates transaction-safe compressed `.sql.gz` archives supporting MariaDB/MySQL (InnoDB non-blocking `--single-transaction --quick --routines --triggers --events --hex-blob`) and SQLite. Includes atomic `.tmp` promotion, SHA-256 calculation, companion `.manifest.json` generation, and collision-resistant filenames.
  - **Mail Storage Backup Service:** `MailStorageBackupService` archives `/var/vmail` into compressed `.tar.gz` preserving Maildir folder semantics (`cur`, `new`, `tmp`), ownership, and permissions.
  - **Integrity Verification Service:** `BackupVerificationService` enforces 3-tier validation (physical file check, gzip magic header `\x1f\x8b` and decompression stream integrity, cryptographic SHA-256 cross-reference against manifest) and performs isolated restore verification:
    - Isolated database restore test into a disposable SQLite database verifying schema tables and row counts.
    - Isolated mail storage restore test into a disposable temporary directory asserting Maildir structures.
  - **Retention Service:** `BackupRetentionService` prunes expired daily archives while enforcing strict invariants: index 0 (newest backup) is NEVER deleted, single remaining backup is NEVER deleted, and stale `.tmp` files older than 24h are reaped.
  - **Artisan Console Commands:** 6 dedicated commands in the `backup:` namespace: `backup:run` (master suite), `backup:database`, `backup:vmail`, `backup:verify`, `backup:status`, `backup:prune`.
  - **Native Linux Production Scripts:** `scripts/backup_db.sh`, `scripts/backup_vmail.sh`, `scripts/verify_backup.sh`, `scripts/restore_db.sh`, `scripts/restore_vmail.sh`, and `scripts/disaster_recovery.sh` with `flock` locking and `vmail:vmail` (5000:5000) ownership enforcement.
  - **Automated Scheduling:** Registered in `routes/console.php`: `backup:run --verify` daily at 02:00 UTC, `backup:prune` daily at 03:00 UTC.
  - **Disaster Recovery Runbook:** Authored `docs/DISASTER-RECOVERY-RUNBOOK.md` detailing operational procedures for Scenario A (DB loss), Scenario B (Mail loss), and Scenario C (Bare-metal VPS disaster recovery), RPO/RTO metrics, and verification steps.
  - **Offsite Posture:** Clearly designated as `OFFSITE BACKUP — PENDING INFRASTRUCTURE` pending remote storage provisioning.
  - **Test Suite Execution:** Implemented `tests/Feature/BackupAndDisasterRecoveryTest.php` with 9 passing tests (55 assertions). Full test suite expanded to **223 passed (943 assertions)** cleanly with zero regressions.

## 5. Next Recommended Implementation Phase
- **Step 18 — Next.js Admin UI Deliverability & Abuse Dashboard Expansion:**
  - Connect the Next.js control plane to the `/api/admin/smtp/incidents` endpoints for live abuse incident tracking and administrative resolution.
  - Expose backup status and disaster recovery health metrics in the Admin Control Plane.

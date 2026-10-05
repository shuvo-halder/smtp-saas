# Changelog

### Persistent Abuse Incident Ledger (Step 16B.4)
- **Implemented:** Database migration `database/migrations/2026_10_06_000001_create_abuse_incidents_table.php`:
  - Created `abuse_incidents` table with auto-increment `id` and unique `uuid`.
  - Configured nullable foreign keys with `nullOnDelete()` (`tenant_id`, `domain_id`, `mailbox_id`, `resolved_by`) and immutable historical snapshot columns (`tenant_email`, `domain_name`, `mailbox_email`) to guarantee data preservation when entities are purged.
  - Added unique `idempotency_key` index preventing duplicate entries from concurrent daemons or burst alerts.
  - Added indexes on `incident_type`, `severity`, `status`, `occurred_at`, and composite indexes on `['tenant_id', 'created_at']`, `['incident_type', 'created_at']`, `['status', 'created_at']`.
- **Implemented:** Eloquent model `App\Models\AbuseIncident`:
  - Defined fillable attributes, casts (JSON `evidence`, datetime `occurred_at`, `resolved_at`), auto-generated UUID and default `occurred_at` timestamp.
  - Defined relationships (`tenant`, `domain`, `mailbox`, `resolver`) and query scopes (`open`, `resolved`, `forTenant`, `ofType`, `severity`).
  - Added `resolveRouteBinding` supporting seamless API lookups by either integer `id` or UUID `uuid`.
- **Implemented:** Service layer `App\Services\Abuse\AbuseIncidentService`:
  - `record()`: Idempotency deduplication, entity snapshot extraction, recursive evidence sanitization via `AuditService::sanitizeState()` (zero plaintext credentials, passwords, tokens, hashes, or email contents), and fail-safe try/catch returning `null` on errors.
  - `resolve()` & `dismiss()`: Mutates incident status, records attribution, and creates immutable audit entries in `audit_logs` via `AuditService::record()`.
- **Integrated:** `AbuseDetectionService` (`App\Services\Abuse\AbuseDetectionService`):
  - In `emitAlert()`: In addition to operational logging to `storage/logs/abuse.log`, dispatches fail-safe incident creation with deterministic idempotency keys (`log_parser:{alertType}:{tenantId}:{mailboxId}:{date}`) without interrupting log parsing flow.
- **Integrated:** `PolicyDecisionService` (`App\Services\Policy\PolicyDecisionService`):
  - In `evaluate()`: On `REJECTED_QUOTA`, calls `recordQuotaAbuseIncident()` with atomic Redis `SET NX` daily cooldown (`outbound:abuse:incident:cooldown:quota:{tenantId}:{mailboxId}:{date}`) to protect MariaDB from high-frequency write storms.
  - Persistence errors are caught safely and never alter or delay the SMTP rejection decision (`REJECT 554 5.7.1`).
- **Implemented:** API layer:
  - Created `App\Http\Resources\AbuseIncidentResource` returning full incident context and snapshots.
  - Created `App\Http\Controllers\Api\AdminAbuseIncidentApiController` with bounded pagination (1-50), comprehensive query filters, and resolution handling.
  - Registered routes under `/api/admin/smtp/*`:
    - `GET /api/admin/smtp/incidents` (`admin.permission:admin.smtp.read`)
    - `GET /api/admin/smtp/incidents/{incident}` (`admin.permission:admin.smtp.read`)
    - `POST /api/admin/smtp/incidents/{incident}/resolve` (`admin.permission:admin.smtp.mailbox.toggle`)
- **Added:** Feature test suite in `tests/Feature/AbuseIncidentLedgerTest.php` (16 passing tests, 81 assertions):
  - Verified perimeter security: unauthenticated (401), ordinary tenant (403), suspended admin (403), zero-role admin (403).
  - Verified role segregation: Customer Support read-only (200 on index/show, 403 on resolve), Deliverability Operator read & resolve (200), Super Admin dismiss (200).
  - Verified persistence, deduplication, snapshot preservation upon entity deletion, evidence credential scrubbing, detector integrations, fail-safe SMTP decoupling, and audit logging.
- **Suite Verification:** Full test suite expanded to **214 passed, 888 assertions** with zero failures, zero errors, and zero regressions.

### Tenant Suspension Redesign & Billing Lifecycle Integration (Step 16B.3)
- **Hardened:** `SuspendExpiredTenants` command (`App\Console\Commands\SuspendExpiredTenants`):
  - Added Spatie administrative role check (`whereDoesntHave('roles', fn ($q) => $q->whereIn('name', RoleEnum::adminRoles()))`) alongside `where('is_admin', false)` to guarantee 100% administrative immunity from automated expiration regardless of role vs attribute configuration.
  - Implemented per-tenant isolated database transactions with `lockForUpdate()`, re-checking `status === 'active'` and `plan_expires_at < now()` inside the lock to eliminate race conditions with concurrent renewals.
  - Restricts domain suspension strictly to active domains (`$tenant->domains()->where('status', 'active')->update(['status' => 'suspended'])`), preserving `pending` and unverified domains.
  - Integrated persistent audit logging via `AuditService::record('tenant.auto_suspend', ...)` for every automated tenant suspension.
  - Isolated failure handling so an individual tenant failure logs an error without aborting execution for other tenants.
- **Hardened:** `BillingService` (`App\Services\BillingService`):
  - Added strict IPN currency verification (`$data['currency']` must match `$invoice->currency` [BDT]) in `handleIpn()`, rejecting mismatched currency payloads.
  - Enforced pessimistic row-locking on both `$invoice` and `$user` inside `markInvoicePaid()` (`lockForUpdate()`) to guarantee strict financial idempotency under concurrent webhook delivery.
  - Added locked-state idempotency check: `if (! $lockedInvoice || $lockedInvoice->status === 'paid') return;`.
  - Preserved independent mailbox disablement: reactivation updates verified parent domains to active, leaving disabled mailbox flags (`is_active = false`) completely untouched.
  - Integrated persistent audit logging via `AuditService::record('billing.invoice_paid', ...)` recording period dates, total amount, and gateway transaction IDs.
- **Hardened:** `AdminApiController` (`App\Http\Controllers\Api\AdminApiController`):
  - In `suspendUser()`: restricted domain suspension to active domains (`where('status', 'active')`), preventing accidental corruption of `pending` domain states.
  - In `suspendUser()` and `activateUser()`: added persistent audit logging via `AuditService::record('admin.users.suspend', ...)` and `AuditService::record('admin.users.activate', ...)`.
- **Added:** Feature test suite in `tests/Feature/BillingLifecycleTest.php` expanded with 9 new regression tests (18 tests total, 67 assertions):
  - `test_expired_administrator_with_rbac_role_is_exempt_from_tenant_suspension`
  - `test_free_plan_user_with_null_expiry_is_not_suspended`
  - `test_ipn_currency_mismatch_is_rejected`
  - `test_concurrent_duplicate_ipn_is_idempotent_under_pessimistic_lock`
  - `test_mailbox_disabled_state_is_preserved_across_billing_reactivation`
  - `test_auto_suspension_creates_audit_log`
  - `test_payment_activation_creates_audit_log`
  - `test_admin_suspend_and_activate_create_audit_logs`
  - `test_admin_suspend_only_suspends_active_domains_preserving_pending_domains`
- **Suite Verification:** Full test suite expanded to **198 passed, 807 assertions** with zero failures, zero errors, and zero regressions.

### Granular RBAC (Roles & Permissions) Implementation (Step 16B.2)
- **Implemented:** Granular Role-Based Access Control using Spatie Laravel Permission with `guard_name = 'web'` matching Sanctum SPA cookie authentication (`RBAC-DEC-01`).
- **Implemented:** `RoleEnum` and `PermissionEnum` defining 3 primary administrative roles (`super_admin`, `deliverability_operator`, `customer_support`) and 13 granular resource/action permissions.
- **Implemented:** Super Admin explicit permission assignments (`RBAC-DEC-02`): mapped all permissions directly to `super_admin` in `role_has_permissions`, strictly omitting any universal `Gate::before` bypass.
- **Implemented:** Mailbox password reset authorization (`RBAC-DEC-03`): authorized exclusively for Super Admin and Deliverability Operator roles via `admin.smtp.mailbox.reset_password`.
- **Implemented:** Tenant administrator segregation (`RBAC-DEC-04`): updated `AdminApiController::suspendUser` and `activateUser` to reject targets that are administrators or hold administrative roles with HTTP 403.
- **Implemented:** Operational security denial logging (`RBAC-DEC-05`): implemented `SecurityAuditLogger` logging structured 403 events to `storage/logs/security.log` with recursive redaction of sensitive credentials and session secrets.
- **Implemented:** Dedicated concurrency governance mutex (`RBAC-DEC-06`): created `governance_locks` table and `SuperAdminGovernanceService` guaranteeing that the final active Super Admin cannot be demoted, de-escalated, or deactivated.
- **Implemented:** Emergency recovery command (`RBAC-DEC-07`): created `php artisan rbac:emergency-recovery {email} {--reactivate}` preserving account status unless `--reactivate` is explicitly passed.
- **Implemented:** Explicit test factory states (`RBAC-DEC-08`): added `superAdmin()`, `deliverabilityOperator()`, `customerSupport()`, and `withoutRoles()` to `UserFactory.php`, enforcing zero default privileges for new accounts (`RBAC-DEC-10`).
- **Implemented:** Pure-DML backfill migration (`RBAC-DEC-09`, `Supplementary Decision 2`): created `2026_10_03_000003_seed_rbac_and_backfill_legacy_admins.php` with provenance tracking (`migration_step_16b2_backfill`), ensuring rollback preserves legitimate non-backfilled assignments.
- **Implemented:** Granular route middleware `RequireAdminPermission` (`admin.permission`) applied across all administrative routes under `/api/admin/*`.
- **Hardened:** `InvoicePolicy::view` (`App\Policies\InvoicePolicy`) updated to eliminate the legacy `$user->is_admin` bypass (Finding 6 Remediation). Cross-tenant invoice view/download access now requires explicit `admin.invoices.read` permission on the `web` guard and active account status (`status !== 'suspended'`). Normal customer ownership access is preserved.
- **Added:** Comprehensive test suite in `tests/Feature/RbacAuthorizationTest.php` (28 tests, 84 assertions). Full test suite expanded to **189 passed, 758 assertions** with zero regressions.


### Step 16B.2 — Canonical RBAC Architecture Decision Finalization (Documentation Approved Only)
- **Approved for Documentation:** Project owner approved the 10 Canonical RBAC Architecture Decisions and 4 Supplementary Decisions in `docs/DECISIONS.md`.
- **Implementation Authorization:** STRICTLY PROHIBITED / NOT GRANTED. No source code, migrations, tests, seeders, configuration, database records, or authorization behavior were modified or authorized to change.
- **Approved Direction Summary:**
  - `RBAC-DEC-01`: Guard Name -> Option A (`web`).
  - `RBAC-DEC-02`: Super Admin Authorization Model -> Option A (Explicit permissions, no global `Gate::before` bypass).
  - `RBAC-DEC-03`: Mailbox Password Reset -> Option B (Super Admin + Deliverability Operator).
  - `RBAC-DEC-04`: Tenant Controller Admin Segregation -> Option A (Reject administrator targets).
  - `RBAC-DEC-05`: Authorization Denial Logging -> Option A (Operational file/security log).
  - `RBAC-DEC-06`: Super Admin Concurrency Lock -> Option B (Dedicated `governance_locks` table).
  - `RBAC-DEC-07`: Emergency Recovery Account Status -> Option A (Preserve existing status and require `--reactivate`).
  - `RBAC-DEC-08`: Test Factory Role Assignment -> Option A (Explicit factory states).
  - `RBAC-DEC-09`: Legacy Administrator Backfill -> Option A (Pure-DML migration).
  - `RBAC-DEC-10`: New Administrator Default Privileges -> Option A (Zero default roles/permissions; fail-closed).
  - `Supplementary Decisions`: MySQL 8.0 `performance_schema.data_lock_waits` (Technical resolution), preserve role assignments with provenance on rollback (Option B), tenant expiry admin exemption (Implemented in Step 16B.2A), standard Laravel migration tracking (Option A).
- **Governance State:** `ARCHITECTURE DECISIONS APPROVED FOR DOCUMENTATION — IMPLEMENTATION STILL BLOCKED`.

### Legacy Admin Status Authorization Hardening (Step 16B.2A)
- **Hardened:** `EnsureAdmin` middleware (`App\Http\Middleware\EnsureAdmin`) updated to strictly deny access when an administrator account is suspended (`!$user || !$user->is_admin || $user->status === 'suspended'`), returning HTTP `403 Forbidden` (`{"message": "Admin access required."}`).
- **Remediated Security Gap:** Closes the vulnerability where an account with `is_admin = true` and `status = 'suspended'` could authenticate and access administrative control plane endpoints (`/api/admin/*`).
- **Added:** Focused regression tests in `tests/Feature/AdminUserLifecycleTest.php`:
  - `test_active_administrator_retains_authorized_admin_access`: Asserts that active administrators retain HTTP 200 access to admin endpoints.
  - `test_suspended_administrator_is_denied_admin_access`: Asserts that suspended administrators are denied with HTTP 403.
  - `test_ordinary_non_admin_user_is_denied_admin_access`: Asserts that regular tenants are denied with HTTP 403.
  - `test_unauthenticated_request_is_denied_with_unauthenticated_status`: Asserts that unauthenticated requests receive HTTP 401 via `auth:sanctum`.
- **Suite Verification:** Full test suite expanded to **161 passed, 674 assertions** with zero regressions.

### Verified Issue Remediation & Hardening (Step 16B.2 Phase A)
- **Hardened:** `SuspendExpiredTenants` command (`App\Console\Commands\SuspendExpiredTenants`) updated to explicitly restrict candidate selection with `where('is_admin', false)`. Guarantees administrative accounts are strictly excluded from automated customer subscription expiration processing and prevents accidental administrator suspension or domain locking.
- **Added:** Regression test in `tests/Feature/BillingLifecycleTest.php` (`test_expired_administrator_is_exempt_from_tenant_suspension`) asserting that an administrator holding an expired subscription timestamp remains active when `tenant:suspend-expired` executes, while normal expired tenant subscriptions are suspended as expected.
- **Added:** Feature test suite `tests/Feature/AdminUserLifecycleTest.php` verifying:
  - Tenant administrative suspension and reactivation via `POST /api/admin/users/{user}/suspend` and `POST /api/admin/users/{user}/activate`.
  - Immediate `403 Forbidden` (`Active subscription required.`) enforcement on subscription-protected endpoints (`/api/domains`) for suspended tenants.
  - Documented empirical behavior that `suspendUser` does not revoke database sessions, keeping `/api/auth/user` accessible until explicit session purge or re-authentication.
  - Legacy `EnsureAdmin` authorization verification demonstrating that suspended administrative accounts retain access under the legacy `$user->is_admin` binary check (verifying the critical pre-rollback de-escalation requirement).
- **Audit Verification:** Completed evidence classification audit across MySQL 8.0 `performance_schema` lock telemetry, verified absence of unapproved RBAC/concurrency fixtures, and preserved all 10 canonical owner architecture decisions as strictly PENDING.

### Persistent Administrative Audit Logging Foundation (Step 16B.1)
- **Added:** MariaDB database migration `database/migrations/2026_09_23_000001_create_audit_logs_table.php` provisioning persistent relational `audit_logs` table (`actor_user_id` nullable FK -> `users.id` on delete set null, `actor_email`, `action`, `entity_type`, `entity_id`, `before_state`, `after_state`, `reason`, `ip_address`, `user_agent`, `request_id`, `created_at`).
- **Added:** `AuditLog` Eloquent model (`App\Models\AuditLog`) enforcing application-level append-only ledger semantics (`const UPDATED_AT = null`), JSON array casting for state snapshots, and eager-loadable `actor()` relationship.
- **Added:** `AuditService` (`App\Services\AuditService`) providing centralized administrative audit logging with:
  - Recursive sensitive key and hash scrubbing (`sanitizeState()`) replacing password fields, tokens, secrets, API keys, OTPs, credentials, and cryptographic hash strings (`$6$`, `$2y$`, `$2a$`) with `'[REDACTED]'`.
  - Fail-safe database recording: catches database exceptions, logs errors to `storage/logs/admin-smtp.log` with safe metadata (error class/code, zero sensitive state), and returns `null` without throwing or aborting caller operations.
  - Correlation request ID derivation and client telemetry extraction (`ip`, `userAgent`, `request_id`).
- **Integrated:** `AdminSmtpService` mutations (`toggleMailbox`, `resetPassword`, `resetConsecutiveBounces`) with single-point pre-logging secret redaction via `AuditService::sanitizeState()`, ensuring raw state is strictly prohibited from entering `storage/logs/admin-smtp.log` while persisting sanitized records into `AuditLog`.
- **Added:** `AdminAuditLogResource` (`App\Http\Resources\AdminAuditLogResource`) formatting audit records for the frontend control plane.
- **Added:** `AdminAuditLogApiController` (`App\Http\Controllers\Api\AdminAuditLogApiController`) with endpoints protected by `EnsureAdmin`:
  - `GET /api/admin/audit-logs`: Bounded pagination (max 50 per page), full text search, action filter, entity type and ID filter, actor filter, and date range filters.
  - `GET /api/admin/audit-logs/{id}`: Detailed single event inspection with eager-loaded actor.
- **Added:** Next.js 14 Admin Audit Log view at `/admin/audit-logs`:
  - `AuditLogsTable`: Searchable datatable, action filter dropdown, entity filter dropdown, bounded pagination controls, action badges, and interactive detail modal with formatted JSON diff viewers for before and after states.
  - Page component at `src/app/(admin)/admin/audit-logs/page.tsx`.
  - Navigation item and `ScrollText` icon in `AdminSidebar.tsx`.
  - TypeScript interface `AuditLog` in `src/types/index.ts`.
  - Complete UI localization in `messages/en.json` under `Admin.audit_logs`.
- **Added:** Unit and Feature test suites:
  - `tests/Unit/AuditServiceTest.php`: Testing audit record creation, recursive secret key redaction, nested object conversion, cryptographic hash redaction, fail-safe exception handling, and request metadata capture (6 tests, 35 assertions).
  - `tests/Feature/AdminAuditLogTest.php`: Testing unauthenticated/non-admin 401/403 guards, read-only 405 route protection, paginated listing, max 50 per page bound, action and entity filtering, text search, single record detail, dual log secret redaction, and fail-safe degradation on DB errors (9 tests, 48 assertions).
- **Verified:** Clean Next.js production build (`npm run build` static generation 22/22 routes) and full Laravel test suite passing cleanly (153 tests, 649 assertions). Zero regressions across Steps 13, 14, 15, and 16A.

### Admin SMTP Management & Deliverability Control Plane (Step 16A)
- **Added:** `AdminSmtpService` (`App\Services\Admin\AdminSmtpService`) managing cluster-wide SMTP overview, safe Redis telemetry availability detection with graceful degradation, bounded batch discovery (prohibiting unindexed `KEYS *`), parent hierarchy invariant validation on mailbox enable, SHA512-CRYPT mailbox password resets, consecutive hard bounce streak resets, and structured operational audit logging.
- **Added:** `AdminSmtpTenantResource` (`App\Http\Resources\AdminSmtpTenantResource`) serializing tenant details, plan limits, today's outbound recipient consumption, bounce metrics, bounce rate, and abuse states.
- **Added:** `AdminSmtpMailboxResource` (`App\Http\Resources\AdminSmtpMailboxResource`) serializing mailbox details, domain/tenant names, parent hierarchy states, `can_be_enabled` invariant check, today's usage, and consecutive hard bounce counts.
- **Added:** `AdminSmtpApiController` (`App\Http\Controllers\Api\AdminSmtpApiController`) exposing 8 endpoints:
  - `GET /api/admin/smtp/overview`
  - `GET /api/admin/smtp/tenants`
  - `GET /api/admin/smtp/tenants/{user}`
  - `GET /api/admin/smtp/mailboxes`
  - `GET /api/admin/smtp/abuse`
  - `POST /api/admin/smtp/mailboxes/{mailbox}/toggle`
  - `POST /api/admin/smtp/mailboxes/{mailbox}/reset-bounces`
  - `POST /api/admin/smtp/mailboxes/{mailbox}/reset-password`
- **Added:** Dedicated daily logging channel `'admin_smtp'` in `config/logging.php` recording operational audit events to `storage/logs/admin-smtp.log` with actor ID, client IP, action, target, reason, and before/after states (credentials redacted).
- **Added:** Next.js 14 Admin SMTP UI at `/(admin)/admin/smtp`:
  - `SmtpOverview` component with real-time KPI cards, Redis status indicator, offline degradation warning, and denominator explanatory callout.
  - `SmtpTenantsTable` component with search, pagination, plan details, live usage progress bars, bounce counts, and abuse status badges.
  - `SmtpMailboxesTable` component with search, status filters, consecutive bounce tracking, and modals for status toggle (with parent constraint validation), bounce streak reset, and password reset (with copy-to-clipboard).
  - `SmtpAbuseTable` component displaying active daily threshold breaches with quick "Inspect Mailbox" navigation.
- **Added:** Frontend TypeScript interfaces in `frontend/src/types/index.ts` (`SmtpOverview`, `SmtpTenant`, `SmtpMailbox`, `AbuseWarning`, `AbuseResponse`).
- **Added:** Complete UI localization in `frontend/messages/en.json` under `Admin.smtp` and `Admin.sidebar.smtp`.
- **Added:** Navigation link and `MailCheck` icon in `AdminSidebar.tsx`.
- **Added:** Comprehensive test suite in `tests/Feature/AdminSmtpTest.php` covering authorization (401/403), overview metrics, zero-denominator bounce rate safety, Redis failure degradation, tenant pagination, 30-day historical usage, mailbox listing (passwords hidden), mailbox disable, mailbox enable parent invariants (domain, tenant, subscription), consecutive bounce streak reset, SHA512-CRYPT password reset, active abuse warnings, and Step 13/14/15 key isolation (18 tests, 97 assertions; entire suite: 136 tests, 555 assertions passing cleanly).
- **Verified:** Zero database migrations created or executed. Tenant-level suspension, persistent audit log tables, granular RBAC, and persistent abuse incident tables explicitly deferred.

### Outbound Abuse Detection & Bounce Tracking Production Hardening (Step 15 Hardening)
- **Hardened:** Intermediate filter hop discrimination in `PostfixLogParserService` and `NormalizedMailEvent`. Postfix delivery to local Amavis content filters (`postfix/smtp-amavis`, `amavis`, or `relay=127.0.0.1:10024`) emits `TYPE_INTERMEDIATE_FILTER_HANDOFF`. Handoffs record queue ID aliases but never increment delivery success or reset consecutive hard bounce metrics.
- **Hardened:** Queue ID alias correlation across content filters. Extracted `queued as <NEW_QID>` on Amavis handoffs and stored short-lived Redis alias mapping (`outbound:abuse:qid_alias:{newQid} => oldQid`, TTL 24h). Added fallback in `AbuseDetectionService::getQueueSender()` to resolve original sender attribution even when `postfix/qmgr` omits `from=<sender>` on reinjected mail.
- **Hardened:** Soft bounce deduplication and idempotency in `AbuseDetectionService`. Introduced atomic `SET NX` event locks (`outbound:abuse:seen:{queueId}:{recipientHash}:{classification}`, TTL 24h). Repeated `deferred` status lines for the same queued message are recorded exactly once, and log replays do not inflate daily bounce counters.
- **Hardened:** Log rotation tail draining in `PostfixLogParserService::parseFile()`. When an active file inode change is detected, unread trailing lines from `{$filePath}.1` (matching previous inode) are read to EOF before switching cursor offset to 0 on the new log file.
- **Hardened:** Transactional cursor checkpointing in `ProcessMailLogCommand`. Removed eager cursor persistence from `parseFile()`; cursor state is only persisted to Redis via `saveCursor()` after all events in the processed batch evaluate without error.
- **Hardened:** Zero Redis mutation in dry-run mode (`--dry-run`). In dry-run mode, no cursor updates, bounce counters, alert locks, idempotency locks, or queue aliases are written to Redis.
- **Hardened:** Clarified and verified bounce rate denominator semantics: `hard_bounce_rate = daily_hard_bounces / accepted_outbound_recipient_attempts` (where denominator is accepted outbound submissions at `DATA` stage from Step 13/14, not confirmed remote deliveries).
- **Tested:** Expanded test suite with 9 new unit and feature tests covering filter handoff isolation, queue ID alias fallback, soft bounce deduplication, rotation tail draining, and transactional cursor checkpointing (118 tests, 458 assertions passing cleanly).

### Outbound Bounce Tracking & SMTP Abuse Detection (Step 15)
- **Added:** `NormalizedMailEvent` immutable DTO (`App\Services\Abuse\NormalizedMailEvent`) capturing timestamp, queue ID, daemon, event type, sender, recipient, status, DSN code, SMTP code, and message.
- **Added:** `PostfixLogParserService` (`App\Services\Abuse\PostfixLogParserService`) streaming incremental parser with chunk-based file reading, max line capping (4096 bytes), line buffer management, inode and file offset cursor tracking in Redis (`outbound:abuse:parser:cursor`), log rotation / truncation detection, and regex tokenization for `qmgr`, `smtp`, `submission`, and `bounce`.
- **Added:** `BounceClassificationService` (`App\Services\Abuse\BounceClassificationService`) implementing deterministic RFC 3463 and RFC 5321 bounce classification (`SUCCESS`, `HARD_BOUNCE`, `SOFT_BOUNCE`, `UNKNOWN`).
- **Added:** `AbuseAttributionService` (`App\Services\Abuse\AbuseAttributionService`) resolving envelope senders to `Mailbox -> Domain -> Tenant (User)` models, with domain-only and unknown system fallbacks.
- **Added:** `AbuseDetectionService` (`App\Services\Abuse\AbuseDetectionService`) managing queue ID correlation (`outbound:abuse:qid:*`), atomic daily Redis counters (`outbound:abuse:tenant:{id}:bounces:daily:{date}`, `outbound:abuse:mailbox:{id}:bounces:daily:{date}`), consecutive mailbox hard bounces with success reset, non-destructive threshold checks (10% hard bounce rate on $\ge 20$ attempts, 50 daily hard bounces, 15 consecutive hard bounces), race-safe alert cooldowns (`SET NX` with 24h TTL), and structured logging.
- **Added:** `php artisan mail:process-log` command (`App\Console\Commands\ProcessMailLogCommand`) with `--lines=1000`, `--dry-run`, `--path=`, atomic lock (`Cache::lock('mail_process_log_lock', 300)`), and graceful permission-error reporting.
- **Added:** Dedicated daily logging channel `'abuse'` in `config/logging.php` writing structured JSON alerts to `storage/logs/abuse.log`.
- **Added:** Centralized configuration file `config/mail_abuse.php` for abuse thresholds, log path, and Redis TTL settings.
- **Added:** Console scheduling in `routes/console.php` executing `mail:process-log` every 5 minutes (`everyFiveMinutes()->withoutOverlapping(10)`).
- **Added:** Comprehensive test suites in `tests/Unit/PostfixLogParserTest.php`, `tests/Unit/BounceClassificationTest.php`, `tests/Feature/AbuseAttributionTest.php`, `tests/Feature/AbuseDetectionServiceTest.php`, and `tests/Feature/ProcessMailLogCommandTest.php` (37 new tests, 141 assertions; entire suite 109 tests, 404 assertions passing).
- **Deployment Requirement:** Production Ubuntu requires `/var/log/mail.log` read access granted to `www-data` (via `adm` group membership or POSIX ACL `setfacl -m u:www-data:r /var/log/mail.log`).

### Pre-Step 15 Test Suite Restoration & Migration Cleanup
- **Fixed:** Removed duplicate boilerplate migration `0001_01_01_000000_create_users_table.php` which had remained tracked in git, colliding with canonical `2024_01_01_000002_create_users_table.php` during `RefreshDatabase` in Feature tests.
- **Verified:** Restored clean test baseline: 72 tests, 263 assertions passing cleanly across all Unit and Feature suites (including full regression verification for Step 13 SMTP Policy Daemon and Step 14 Redis-MariaDB historical usage sync).

### Redis → MariaDB Historical Usage Synchronization (Step 14)
- **Added:** `TenantOutboundUsage` Eloquent model (`App\Models\TenantOutboundUsage`) bound to `tenant_outbound_usage` table.
- **Added:** `OutboundUsageSyncService` (`App\Services\OutboundUsageSyncService`) implementing bounded Redis `SCAN` (`outbound:tenant:*:recipients:daily:*`), regex key parsing, strict date and counter sanitization, tenant verification, and monotonic ledger upsert.
- **Added:** Monotonic reconciliation logic: MariaDB counts only update upward when Redis counts increase; never decrements historical ledger data even if Redis is flushed or restarted mid-day.
- **Added:** `php artisan outbound:usage-sync` command (`App\Console\Commands\SyncOutboundUsageCommand`) supporting `--date=`, `--days=`, `--dry-run`, and protected by atomic lock (`Cache::lock('outbound_usage_sync_lock', 600)`).
- **Added:** Hourly scheduling in `routes/console.php` with `withoutOverlapping(15)` mutex.
- **Added:** Comprehensive test suite in `tests/Feature/OutboundUsageSyncTest.php` covering basic sync, idempotency, monotonic update retention, unlimited plan usage recording, zero handling, Redis failure safety, malformed key skipping, invalid counter handling, missing tenant safety, sliding window filtering, specific date targeting, dry-run, and concurrency lock behavior (18 tests, 116 assertions; full suite 72 tests, 263 assertions).

### Laravel SMTP Policy Daemon & Postfix Quota Integration (Step 13)
- **Added:** `PolicyRequest` and `PolicyResponse` DTOs modeling the Postfix SMTP policy delegation protocol.
- **Added:** `PostfixPolicyParser` streaming parser with protection against buffer overflows (64KB max).
- **Added:** `PolicyDecisionService` resolving `sasl_username` to `Mailbox -> Domain -> Tenant -> Plan`, performing active domain/tenant status validation, transaction idempotency caching (`outbound:policy:tx:{instance}`), and delegating quota deduction to `OutboundQuotaService`.
- **Added:** `php artisan policy:serve` Artisan command (`PolicyDaemonCommand`) providing a persistent, non-blocking TCP server (`127.0.0.1:10031`) and UNIX domain socket support.
- **Added:** Supervisor worker configuration `server-configs/mailsaas-policy.conf`.
- **Added:** Postfix integration in `postfix-config/main.cf` under `smtpd_data_restrictions` with `check_policy_service inet:127.0.0.1:10031` and fail-open default (`smtpd_policy_service_default_action = DUNNO`).
- **Added:** Dedicated daily logging channel `policy` in `config/logging.php`.
- **Hardened:** Idempotency cache payload binding in `PolicyDecisionService` (verifying `sasl_username` and `recipient_count` match prior to replaying cached action).
- **Hardened:** Preserved raw non-positive/non-numeric `recipient_count` in `PolicyRequest` to explicitly reject invalid/zero/negative recipient requests rather than coercing to 1.
- **Tested:** Comprehensive unit and integration test suite (`PolicyParserTest`, `PolicyDecisionServiceTest`, `PolicyDaemonIntegrationTest`) verifying policy parsing, identity resolution, quota enforcement, fail-open resilience, idempotency context-matching, and socket communication (54 tests, 147 assertions).

### SMTP Outbound Quotas Redis Service (Step 12)
- **Added:** OutboundQuotaService providing an atomic, Lua-script based quota validation engine in Redis.
- **Added:** Support for tenant and mailbox dual-layer validation without partial consumption bugs.
- **Added:** Fail-open Redis error handling to ensure transient cache outages do not block valid email.
- **Tested:** Comprehensive test suite mapping unlimited, disabled, finite, and multi-layer quota permutations using mock Redis execution.


### SMTP Outbound Quotas Database Foundation (Step 11)
- **Added:** daily_outbound_recipients and mailbox_daily_outbound_recipients quota fields to plans table and AdminApiController. Default set to -1 (unlimited) for backward compatibility.
- **Added:** 	enant_outbound_usage table to serve as a durable historical reporting ledger for outbound SMTP quotas.
- **Tested:** Implemented test coverage ensuring idempotency and duplication prevention for usage ledger inserts.


### SMTP Outbound Quota & Abuse Prevention Architecture Audit
- **Architecture Audit:** Traced current Postfix/Dovecot implementation. Confirmed Postfix natively enforces IP connection rate limits via nvil but lacks outbound message volume quotas per tenant or mailbox.
- **Gap Analysis:** Verified the system requires an architectural change (database migrations for Plan quotas + a new Policy Daemon) to durably track and enforce outbound sending limits.
- **Reporting:** Created rtifacts/smtp_architecture_audit.md detailing the required architecture and implementation plan for billing-grade quotas.


## 2026-09-07

### SMTP Security & Mailbox Stability Patching
- **Mailbox Password Mass-Assignment:** Added 'password' to $fillable and $hidden arrays in Mailbox.php to correctly save and obscure Dovecot hashes.
- **Postfix Cross-Tenant Spoofing patched:** Added mysql-virtual-sender-login-maps.cf and updated main.cf with smtpd_sender_login_maps and 
eject_sender_login_mismatch to tie the SASL username to the authorized sender.
- **Mailbox Creation Tests:** Created 	ests/Feature/MailboxApiTest.php to securely verify password persistence, serialization, and tenant quota isolation.


## 2026-09-04

### SMTP Management Architecture & Production Gap Audit
- **Threat Model Audit:** Identified missing `smtpd_sender_login_maps` in Postfix config allowing cross-tenant sender spoofing.
- **Code Audit:** Identified missing `$fillable` for `'password'` in `Mailbox` model, preventing Mailbox creation from saving Dovecot credentials correctly. Identified missing `$hidden` array.
- **Quota & Metrics Gap:** Confirmed no DB migrations or logs exist for outbound SMTP tracking. Admin UI for SMTP configuration is missing.
- **Reporting:** Created `artifacts/smtp_audit_report.md` detailing all gaps and required next steps.

### Admin Plan CRUD & Subscription Management UI
- **Plan Management API:** Added `AdminApiController` methods to completely manage Plan lifecycle (`showPlan`, `updatePlan`, `destroyPlan`). Protected by `EnsureAdmin`.
- **Database Safety Guard:** Ensured destructive `DELETE` of a Plan is strictly blocked if historically referenced by any `User` or `Invoice`, maintaining historical data integrity.
- **Admin Plans Frontend:** Refactored `/admin/plans` into a fully functional CRUD interface utilizing Shadcn UI, React Hook Form, and `next-intl` (zero hardcoded strings). Included secure activation toggles.
- **Subscription Display Enhancement:** Cleaned up `/admin/tenants/[id]` tenant profile to accurately reflect Plan limits, explicit expiration times, Usage (domains/mailboxes), and associated billing invoices. Extracted all hardcoded strings into `next-intl`.
- **Tests Added:** Created `tests/Feature/AdminPlanTest.php` covering creation, updates, secure deletion protection, duplicate slug rejections, and Role-Based Access Control (RBAC).

### Billing Lifecycle Correction & Verification
- **Renewal Time Erasure Fixed:** Resolved a critical bug in `BillingService@markInvoicePaid` where early renewals erased remaining prepaid time. Future expirations are now properly extended by adding the new billing cycle to the existing expiration date.
- **Suspension Lockout Fixed:** Modified `SuspendExpiredTenants` to no longer destructively overwrite individual mailbox `is_active` states. Relying on `domain.status = suspended` inherently blocks mail routing in Postfix. Modified `BillingService` to safely reactivate suspended domains when an invoice is paid, ensuring suspended tenants instantly regain mail access upon payment.
- **Payment Idempotency:** Added a strict `status === 'paid'` guard inside `BillingService@handleIpn` to discard duplicate valid IPN webhooks, preventing multiple accidental subscription extensions from the same invoice.
- **Mailbox Creation Fatal Error Fixed:** Corrected a bug in `MailboxApiController@store` that called a non-existent method `$domain->canAddMailbox()`. It now correctly invokes `$request->user()->canAddMailbox($domain)`.
- **Database Readiness:** Safely deleted duplicate legacy `0001_01_01_000000_create_users_table.php` migration. Verified `php artisan migrate` creates a healthy schema matching the architecture requirements.
- **Comprehensive Lifecycle Testing:** Added `tests/Feature/BillingLifecycleTest.php` covering first payment, renewal (before/after expiry), suspension recovery, failed payment, duplicate IPN, and scheduled expiration. All tests pass (8 tests, 14 assertions).

### Legacy UI Cleanup & Build Fixes
- **Backend Runtime:** Successfully aligned dependency graph with PHP 8.2.x environment (the explicitly supported runtime). Downgraded `symfony/*` components from `v8.1.x` to `v7.4.x` and `laravel/pint` from `1.30.5` to `1.30.4` to remove PHP 8.3/8.4 platform requirements.
- **Backend Routing:** Re-registered API routes in `bootstrap/app.php`. Replaced legacy root `welcome` view route with a JSON status endpoint.
- **Backend Testing:** Verified `php artisan test` now passes under PHP 8.2.
- **Backend Configuration:** Restored missing `.env` configuration for `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, and `SSLCommerz`. Added missing `sslcommerz` block to `config/services.php`.
- **Backend Bug Fix:** Fixed a critical bug in `BillingApiController@checkout` where the frontend was incorrectly receiving an array instead of a scalar string for `redirect_url`.
- **Backend Runtime:** Confirmed local environment lacks PHP 8.4+ binary. Laravel runtime remains blocked locally by `composer.lock` dependencies requiring PHP `>= 8.4.1`.
- **Backend Verification:** Executed a comprehensive verification of Laravel runtime, routing, and Sanctum middleware. Discovered platform requirement block (PHP 8.2 vs 8.4) preventing artisan commands. Documented API contract mismatches for Mailboxes and Billing.
- **Frontend Build:** Fixed a TypeScript compilation error in `(dashboard)/billing/page.tsx` where the Auth provider's `loading` state was incorrectly referenced as `isLoading`.
- **Frontend Build:** Exposed `fetchUser` as `mutate` in `AuthContext` to fix type errors in `settings/page.tsx`.
- **Frontend Build:** Added missing `address` property to `User` interface in `types/index.ts`.
- **Frontend Build:** Fixed `asChild` prop errors on `Button` components in tenant billing success/fail pages by directly using `buttonVariants` on Next.js `Link` tags.
- **Frontend Build:** Fixed SSG prerender crash across all Admin pages by wrapping `RootLayout` with `NextIntlClientProvider`, satisfying `"use client"` translation requirements.
- **Laravel Views:** Removed all legacy web controllers and Blade files (`resources/views/*`, `DomainController`, `BillingController`, etc.) as they are obsolete in the Next.js API-driven architecture.
- **Routing:** Stripped `routes/web.php` of unused authenticated web routes, retaining only the `billing/ipn` SSLCommerz webhook, which now safely routes to `BillingApiController`.

### Architecture Audit & Fixes
- **Mail Infrastructure:** Stripped legacy `mysql` queries from `add_domain.sh`, `add_mailbox.sh`, `remove_domain.sh`, and `remove_mailbox.sh`. Migrated complete database truth to Laravel ORM (`domains` and `mailboxes` tables).
- **Filesystem:** Standardized shell scripts to output maildirs to `/var/vmail/` and `/var/vmail_archive/` to perfectly match Dovecot configs.
- **Reliability:** Wrapped `DomainApiController@store`, `MailboxApiController@store`, and `BillingService@markInvoicePaid` in `DB::transaction` to prevent ghost records if external processes (shell execution, email sending) fail.
- **Security:** Audited API controllers for multi-tenancy leaks. Confirmed `$request->user()->domains()` and Laravel Policies are correctly enforcing boundaries.
- **i18n:** Removed hardcoded English strings from `frontend/src/app/(tenant)/[subdomain]/dashboard/page.tsx` and moved them to `messages/en.json`.
- **Documentation:** Created comprehensive `docs/AUDIT-REPORT.md` and initialized the Master Implementation Documentation System.

## [Unreleased]
### Added
- **Admin Control Plane (P0/P1):** Implemented global dashboard, tenant list/drilldown, domains list, mailboxes list, plans list, and invoices list in Next.js using `shadcn/ui`.
- **Admin API Extensions:** Added `GET /admin/users/{user}`, `GET /admin/domains`, and `GET /admin/mailboxes` to Laravel `AdminApiController` for global resource observation.
- **Admin i18n:** Added full localization mapping for all Admin metrics and datatables in `messages/en.json`.

## 2026-09-02 (Prior)
- Implemented SSLCommerz Billing integration API and frontend.
- Implemented Roundcube Webmail SSO using Dovecot Master User pattern and Redis OTP caching.
- Created `SuspendExpiredTenants` automation cron.
- Implemented Next.js Tenant layout and Edge routing.
- Set up initial Laravel Sanctum auth and controllers.

# Modules

This document lists the primary modules within the EmailSaaS architecture. Detailed behaviors for sub-systems are linked.

## 1. Authentication & Tenancy
**Status:** IMPLEMENTED
**Purpose:** Authenticate users via stateful cookies and strictly bind API requests to tenant subdomains.
**Components:**
- `AuthController`: Manages sessions.
- `IdentifyTenant` (Middleware): Parses Origin/Host to verify and bind tenant context.
- `DomainPolicy`: Enforces tenant ownership for resource access.

## 2. Mail Infrastructure Integration
**Status:** IMPLEMENTED
**Purpose:** Manage Postfix and Dovecot interactions from the Laravel application.
**Components:**
- `PostfixService`: Executes privileged shell scripts (`add_domain.sh`, `add_mailbox.sh`, `remove_domain.sh`, `remove_mailbox.sh`, `setup_dkim.sh`) for directory generation and DKIM signing.
- Mail configurations directly querying Laravel's `domains` and `mailboxes` MariaDB tables.

## 3. Subscription & Billing (SSLCommerz)
**Status:** IMPLEMENTED
**Purpose:** Manage prepaid usage tiers via the SSLCommerz Bangladesh gateway.
**Components:**
- `BillingApiController`: Routes checkout initiation and webhooks.
- `BillingService`: Validates MD5 signatures, confirms payment amounts, and atomically extends user subscriptions using `DB::transaction`.

## 4. Webmail SSO
**Status:** IMPLEMENTED
**Purpose:** Allow one-click login from the Tenant Dashboard into Roundcube Webmail.
**Components:**
- `SsoController`: Generates a 32-char Redis OTP (60s TTL).
- Roundcube + Dovecot Master User integration.

## 5. Background Automation
**Status:** IMPLEMENTED
**Purpose:** Automatically suspend overdue accounts and process queues.
**Components:**
- `SuspendExpiredTenants`: Cron task.
- Redis-backed Queue.

## 6. Outbound Quotas & Policy Daemon
**Status:** IMPLEMENTED
**Purpose:** Enforce per-tenant and per-mailbox outbound recipient limits in real-time at SMTP submission.
**Components:**
- `OutboundQuotaService`: Atomic Redis check+increment via Lua scripts (48h TTL).
- `PolicyDaemonCommand` (`php artisan policy:serve`): High-throughput TCP/socket daemon on `127.0.0.1:10031`.
- `PolicyDecisionService`: Evaluates Postfix `DATA` restrictions with transaction idempotency caching.

## 7. Historical Usage Synchronization
**Status:** IMPLEMENTED
**Purpose:** Reconcile ephemeral runtime Redis counters into durable MariaDB reporting ledger (`tenant_outbound_usage`).
**Components:**
- `OutboundUsageSyncService`: Bounded Redis `SCAN` parser with monotonic upward reconciliation.
- `SyncOutboundUsageCommand` (`php artisan outbound:usage-sync`): Hourly scheduled command with atomic lock.

## 8. Outbound Bounce Tracking & Abuse Detection
**Status:** IMPLEMENTED WITH DEPLOYMENT REQUIREMENT (HARDENED)
**Purpose:** Ingest Postfix delivery logs, classify RFC 3463 bounces, and alert on deliverability threshold breaches without automatic suspension.
**Components:**
- `PostfixLogParserService`: Incremental streaming log parser with inode tracking and rotation tail draining.
- `BounceClassificationService`: Deterministic RFC 3463 / RFC 5321 bounce status mapping.
- `AbuseDetectionService`: Sender attribution, Redis daily counters, consecutive streak tracking, and alert cooldowns (`storage/logs/abuse.log`).
- `ProcessMailLogCommand` (`php artisan mail:process-log`): 5-minute scheduled daemon.

## 9. Admin SMTP Management & Deliverability Control Plane
**Status:** IMPLEMENTED (Step 16A)
**Purpose:** Administrative cluster-wide deliverability observability, tenant/mailbox quota inspection, and safe mailbox-level operational controls without schema migrations.
**Components:**
- `AdminSmtpService`: Aggregates cluster overview, handles fail-safe Redis degradation, enforces parent invariants on mailbox enable, performs SHA512-CRYPT password resets, resets bounce failure streaks, and writes operational audit logs to `storage/logs/admin-smtp.log`.
- `AdminSmtpApiController`: Exposes 8 RESTful endpoints protected by `EnsureAdmin`.
- Next.js Admin UI (`/admin/smtp`): Overview KPI cards, Tenants table with quota bars, Mailboxes table with control modals, and Abuse warnings table with inspection shortcuts.

## 10. Admin Audit Logging Foundation
**Status:** IMPLEMENTED (Step 16B.1)
**Purpose:** Persistent, append-only relational audit ledger for administrative operations and security state changes.
**Components:**
- `AuditLog` Model: Append-only Eloquent model (`const UPDATED_AT = null`).
- `AuditService`: Centralized recording engine with recursive secret redaction (`sanitizeState()`) scrubbing passwords, hashes, tokens, keys, and credentials.
- `AdminAuditLogApiController`: Exposes bounded paginated query endpoints (`/api/admin/audit-logs`) protected by `EnsureAdmin`.
- Next.js Admin UI (`/admin/audit-logs`): Searchable, filterable audit log viewer with detail inspection modal.

## 11. Granular Administrative RBAC & Governance
**Status:** IMPLEMENTED & VERIFIED (Step 16B.2)
**Purpose:** Multi-role administrative access control with fail-closed permissions, single-guard architecture, dedicated concurrency lock, and operational security logging.
**Components:**
- `RoleEnum` & `PermissionEnum`: Define 3 canonical roles (`Super Admin`, `Deliverability Operator`, `Customer Support`) and 13 resource permissions.
- `EnsureAdmin` & `RequireAdminPermission`: Perimeter authentication, suspended account rejection, and granular permission checking on the `web` guard.
- `SuperAdminGovernanceService`: Dedicated row-level mutex (`governance_locks` table) ensuring the platform's last active Super Admin cannot be de-escalated, with MySQL 8.0 lock-wait telemetry.
- `SecurityAuditLogger`: Structured JSON denial logging to `storage/logs/security.log` with credential sanitization.
- `RbacEmergencyRecoveryCommand` (`php artisan rbac:emergency-recovery`): Disaster recovery CLI tool restoring Super Admin privileges while preserving account suspension status unless `--reactivate` is passed.
- Pure-DML Migration Backfill & Non-Destructive Rollback: Backfills existing administrators with provenance tag (`migration_step_16b2_backfill`) ensuring safe schema rollback without dropping runtime role assignments.

## 12. Admin Audit Trail & Security Event Monitoring
**Status:** IMPLEMENTED & VERIFIED (Step 23)
**Purpose:** Centralized, append-only security audit ledger tracking authentication, authorization denials, tenant lifecycle, mail administration, plans, governance, backups, and abuse management with recursive secret scrubbing, server-side filtering, and frontend UI observability.
**Components:**
- `AuditService`: Comprehensive single-point recording engine with 38+ redacted sensitive keys, regex/prefix detection for password hashes and JWT tokens, and automated actor/request metadata resolution.
- `SecurityAuditLogger`: Intercepts authorization denials, logging to operational security logs and persisting `security.authorization_denied` events into `audit_logs` without breaking fail-closed perimeter defense.
- `AppServiceProvider`: Global auth event listeners for `Login` (`auth.login`), `Logout` (`auth.logout`), and `Failed` (`auth.failed` with email-only attribution and strict credential omission).
- `AdminAuditLogApiController`: Bounded (<= 50) paginated API protected by `admin.permission:admin.audit_logs.read` with validated filtering (`outcome`, `action`, `entity_type`, `search`, `dates`) and sorting (`id`, `created_at`, `action`, `entity_type`).
- Next.js Admin UI (`/admin/audit-logs`): Full-featured audit table with categorized action optgroups, outcome filters, visual outcome badges (`success`, `failure`, `denial`), 403 Forbidden permission-denied UI alert card, and JSON before/after state diff modal.

## 14. Tenant Usage, Quota & Billing Reconciliation (Step 24)
- `TenantUsageReconciliationService`: Central read-only reconciliation engine comparing real-time Redis telemetry, MariaDB `tenant_outbound_usage`, plan quota boundaries, subscription states, domain statuses, and billing invoices.
- `TenantReconcileUsageCommand` (`php artisan tenant:reconcile-usage`): Operator console command with tabular and `--json` outputs for auditing platform discrepancies.
- `AdminReconciliationApiController`: REST API endpoints (`GET /api/admin/reconciliation/report`, `GET /api/admin/reconciliation/tenants/{user}`) protected by Sanctum, `EnsureAdmin`, and `admin.permission:admin.stats.read`.
- Enhanced `OutboundQuotaService`: Preserves tenant recipient accounting in Redis Lua scripts even when tenant quota is unlimited (-1) with restricted mailbox limits.
- Enhanced `AdminSmtpTenantResource`: Provides live remaining quota, usage percentages, over-quota flags, and granular subscription states.
- Enhanced Next.js UI (`SmtpTenantsTable.tsx`): Displays remaining daily quota, over-quota alerts, and subscription states.

## 15. Tenant Self-Service Billing & Subscription Management (Step 26)
**Status:** IMPLEMENTED & VERIFIED (Step 26)
**Purpose:** End-to-end self-service billing, plan selection, renewal management, and invoice inspection for tenant accounts.
**Components:**
- `BillingApiController`: Exposes `/api/billing/subscription` (authoritative posture, limits, outbound usage, remaining days; accessible without active subscription), `/api/billing/plans`, `/api/billing/checkout`, `/api/billing/invoices` (bounded pagination 1-50, status filtering), and `/api/billing/invoices/{invoice}` (authorized via `InvoicePolicy::view`).
- `User` Model: Added `mailboxes(): HasManyThrough` relationship for direct mailbox aggregate queries.
- Next.js Dashboard UI (`/billing`): Real-time subscription posture, remaining days badge, quota progress indicators (domains, mailboxes, daily outbound emails, mailbox storage), renewal CTA, and payment gateway callback alerts (`?status=success|failed|cancelled`).
- Next.js Invoice Ledger (`InvoiceTable.tsx` & `InvoiceDetailModal.tsx`): Bounded pagination, status filtering (`paid`, `pending`, `failed`, `cancelled`), printable modal layout, and direct PDF download links.
- Next.js Plans & Checkout UI (`/billing/plans` & `PlanCard.tsx`): Monthly/yearly billing toggle, plan feature comparison, recommended tier badge, current plan indicator, and SSLCommerz checkout redirect.

## 16. Tenant Domain & Mailbox Management (Step 27)
**Status:** IMPLEMENTED & VERIFIED (Step 27)
**Purpose:** Self-service domain verification, lifecycle management, and mailbox creation/configuration under active tenant subscriptions.
**Components:**
- `DomainApiController`: Domain CRUD, casing normalization, DNS record verification reporting, and cascading transaction deletions.
- `MailboxApiController`: Mailbox CRUD, tenant-wide listing (`/api/mailboxes`), plan storage caps, Dovecot SHA512-CRYPT hashing, display name updates, and state toggles.
- Next.js UI (`/domains`, `/domains/[id]`, `/mailboxes`): Domain verification checklist, DNS wizard, mailbox table, create/update modals.

## 17. Tenant SMTP Access, Sending Activity & Delivery Logs (Step 28)
**Status:** IMPLEMENTED & VERIFIED (Step 28)
**Purpose:** Self-service outbound SMTP relay configuration, secure credentials lifecycle, real-time quota telemetry, persistent delivery activity ledger, and zero-SSRF account diagnostics.
**Components:**
- `TenantSmtpService`: Encapsulates SMTP configuration generation, Redis/MariaDB quota aggregation, one-time reveal password rotation with Dovecot SHA512-CRYPT, activity log queries, and account configuration diagnostics.
- `TenantSmtpApiController`: Exposes 6 RESTful endpoints protected by `auth:sanctum` and `EnsureActiveSubscription` (`/api/smtp/settings`, `/api/smtp/usage`, `/api/smtp/credentials`, `/api/smtp/credentials/{mailbox}/rotate-password`, `/api/smtp/activity`, `/api/smtp/diagnostics`).
- `DeliveryLog` Model & Migration: Relational storage for message outcomes (`delivered`, `deferred`, `bounced`, `rejected`), Postfix queue IDs, DSN codes, and sanitized MTA responses.
- `DeliveryLogResource`: Formats and sanitizes MTA response text, stripping internal server paths and sensitive fragments.
- Integrated Pipeline Ingestion: `AbuseDetectionService::processEvent` logs terminal delivery events; `PolicyDecisionService::recordQuotaAbuseIncident` logs outbound submission rejections.
- Next.js Frontend Hub (`/smtp`): Tabbed interface for Settings & Snippets (Node.js, Python, PHP, .env), Quota & Usage (progress bars, 14-day history, mailbox breakdown), Credentials (authorized mailboxes, password rotation dialog with one-time reveal), Activity Logs (search, status filters, bounded pagination), and Diagnostics & Troubleshooting (self-service health checks, common error resolutions).

## 18. Tenant Email Aliases & Forwarding Management (Step 29)
**Status:** IMPLEMENTED & VERIFIED (Step 29)
**Purpose:** Self-service inbound email alias and forwarding management under active domains and subscription plans.
**Components:**
- `EmailAlias` Model & Policy: Extracted standalone PSR-4 model (`app/Models/EmailAlias.php`) with domain relations, tenant ownership scopes, search, and status filters. Protected by `EmailAliasPolicy` registered in `AuthServiceProvider`.
- `AliasApiController`: Full RESTful lifecycle (`/api/aliases`, `/api/domains/{domain}/aliases`, show, store, update, toggle, destroy).
- Safety Invariants: Enforces active domain DNS status, active subscription, plan limit (`max_aliases_per_domain`), collision check against physical mailboxes, duplicate check per domain, and cycle detection for direct (`source === destination`) and circular forwarding chains. Disabling always permitted; enabling strictly validates domain and subscription active state.
- Audit Trail Integration: All lifecycle operations (`alias.create`, `alias.update`, `alias.toggle`, `alias.delete`) record immutable events via `AuditService`.
- Next.js Dashboard UI (`/aliases`): Full-featured alias management hub with search, domain and status filters, bounded pagination, creation dialog with domain selector and loop warning, edit destination modal, toggle switch, and delete confirmation.

## 19. Email Alias Routing Integration & Mail Flow Verification (Step 30)
**Status:** IMPLEMENTED & VERIFIED (Step 30)
**Purpose:** Bridge tenant-managed email aliases with Postfix mail delivery pipeline via dynamic MySQL virtual maps, multi-hop routing resolution, dangling destination detection, and truthful status telemetry.
**Components:**
- **Postfix MySQL Configuration Alignment:**
  - Modernized `postfix-config/mysql-virtual-aliases.cf` and created `server-configs/mysql-virtual-alias-maps.cf` querying canonical `email_aliases` table joined with `domains` where `is_active = 1`, `domains.status = 'active'`, and `domains.deleted_at IS NULL`.
  - Modernized `mysql-virtual-domains.cf`, `mysql-virtual-mailboxes.cf`, and `mysql-virtual-sender-login-maps.cf` to enforce `status = 'active'` and `deleted_at IS NULL` for SoftDeletes compatibility.
- **PostfixService Routing Engine (`app/Services/PostfixService.php`):**
  - `syncAlias(EmailAlias $alias): bool` and `removeAlias(EmailAlias $alias): bool`.
  - `verifyAliasRouting(string $sourceEmail): array`: Executes Postfix lookup, traces recursive expansion chains up to 30 hops, detects cycle loops, verifies terminal recipient mailbox existence for local domains, and returns comprehensive routing details.
  - `isPostfixOperational(): bool`: Checks if live Postfix daemon is responsive via socket or process list.
- **Controller Lifecycle Integration (`app/Http/Controllers/Api/AliasApiController.php`):**
  - Injected `PostfixService` across `store`, `update`, `toggle`, and `destroy` operations.
- **Resource & API Telemetry (`app/Http/Resources/EmailAliasResource.php`):**
  - Dynamically calculates `forwarding_status`: `synchronized` (map valid & routable), `operational` (live MTA active), `configured` (disabled or pending domain), `failed` (dangling destination or loop), `unknown` (missing record or deleted domain).
  - Supplies `routing_details` payload (`is_routable`, `source_email`, `destination`, `terminal_target`, `status`, `hops`, `hop_count`, `cycle_detected`, `warning`, `mta_driver`).
- **Next.js Dashboard Integration (`frontend/`):**
  - Enhanced `AliasRow.tsx` with dynamic status pills (Emerald for operational, Blue for synchronized, Amber for unknown, Gray for configured, Red for failed), hop count chips, and hover tooltips for routing warnings.
  - Updated `/aliases` header legend explaining routing verification statuses.
- **Operator CLI Diagnostic Tool:**
  - `php artisan mail:verify-alias {source_email?} {--all}`: Detailed tabular inspection of alias resolution, hop expansion chains, and routing integrity.

## 20. Tenant Mailbox Storage & Quota Management (Step 31)
**Status:** IMPLEMENTED & VERIFIED (Step 31)
**Purpose:** Tenant-authorized mailbox storage usage telemetry, plan limits, utilization calculations, quota alert badges, and Dovecot `quota_rule` SQL configuration alignment.
**Components:**
- **MailboxStorageService (`app/Services/MailboxStorageService.php`):**
  - Encapsulates mailbox disk usage measurement via `doveadm quota get -u <email>`, maildirsize file inspection, fake test resolvers, and safe Windows environment fallbacks.
  - Computes exact storage metrics: `quota_mb`, `quota_bytes`, `usage_bytes`, `usage_mb`, `remaining_bytes`, `utilization_percent`, `status`, and `status_label`.
  - Calculates status thresholds: `normal` (< 80%), `warning` (80% - 89.9%), `near_limit` (90% - 99.9%), `over_limit` ($\ge 100\%$), or `unknown` (unmeasured / Dovecot offline).
  - Handles unlimited quota allocations (`quota_mb = -1` or `NULL`) without division by zero.
  - Aggregates tenant-wide storage summary: total mailboxes, measured mailboxes, unmeasured mailboxes, provisioned storage, plan storage limit, total measured usage, overall utilization, and status count distribution.
  - Truthful telemetry guarantee: never substitutes `0` for unmeasured usage; reports `is_measured = false`, `usage_bytes = null`, and `status = 'unknown'`.
- **API Endpoints & Resource Integration (`MailboxApiController`, `MailboxResource`):**
  - `GET /api/mailboxes/storage`: Tenant overview and per-mailbox breakdown with optional `status`, `domain_id`, and `search` filters.
  - `GET /api/mailboxes/{mailbox}/storage`: Single mailbox storage telemetry protected by `MailboxPolicy`.
  - Automatically embeds detailed `storage` object inside `MailboxResource` (`/api/mailboxes`, `/api/mailboxes/{id}`).
- **Dovecot SQL Quota Configuration (`dovecot-sql.conf.ext`):**
  - Updated `user_query` in `server-configs/dovecot-sql.conf.ext` and `dovecot-config/dovecot-sql.conf.ext` to include `CONCAT('*:bytes=', (mailboxes.quota_mb * 1048576)) AS quota_rule`.
  - Dovecot LMTP/LDA dynamically enforces mailbox storage limits in real-time based on database values.
- **Next.js Dashboard Integration (`frontend/`):**
  - Storage Overview KPI cards at `/mailboxes` showing Provisioned Mailboxes, Provisioned Storage vs Plan Limit, and Storage Telemetry status.
  - Status filter dropdown (`over_limit`, `near_limit`, `warning`, `normal`, `unknown`) on `/mailboxes`.
  - Enhanced `MailboxRow.tsx` with storage progress bar, colored warning badges, or `Unmeasured` badge when Dovecot is offline.
  - `MailboxStorageModal.tsx` provides detailed breakdown of usage, quota ceiling, remaining capacity, provenance source, and measured timestamp.

## 21. Tenant Catch-All Mailbox & Inbound Email Routing Rules (Step 32)
**Status:** IMPLEMENTED & VERIFIED (Step 32)
**Purpose:** Provide secure, tenant-managed catch-all and inbound recipient fallback policies (`disabled`, `reject`, `mailbox`, `discard`) with strict precedence preservation for physical mailboxes and explicit aliases.
**Components:**
- **Database Schema & Domain Lifecycle:**
  - Added `catch_all_policy` (varchar(32), default `'disabled'`) and `catch_all_mailbox_id` (foreignId to `mailboxes`, nullable, `nullOnDelete()`) to `domains` table.
  - Eloquent relations `catchAllMailbox(): BelongsTo` and helper `isCatchAllEnabled(): bool` on `Domain`.
  - Helper `isCatchAll(): bool` on `Mailbox`.
  - Automatic catch-all reset on destination mailbox deletion in `MailboxApiController::destroy()`.
- **Postfix MySQL Configuration Alignment:**
  - Created `postfix-config/mysql-virtual-catchall.cf` and `server-configs/mysql-virtual-catchall-maps.cf`.
  - Postfix `main.cf` integrates `mysql-virtual-catchall.cf` inside `virtual_alias_maps` after `mysql-virtual-aliases.cf`.
  - Invariant Precedence Enforcement: Catch-all query strictly verifies `NOT EXISTS (SELECT 1 FROM mailboxes ...)` and `NOT EXISTS (SELECT 1 FROM email_aliases ...)` so physical mailboxes and explicit aliases are NEVER hijacked.
- **Postfix Routing Service & CLI Diagnostics:**
  - `verifyCatchAllRouting(Domain $domain, ?string $probeLocalPart = null)`: Simulates Postfix catch-all resolution, verifies destination mailbox validity, and detects live MTA socket state.
  - `syncCatchAll` and `disableCatchAll` lifecycle synchronization hooks.
  - Operator CLI command `php artisan mail:verify-catchall {domain?} {--all}` with tabular output of policy, destination mailbox, routing status, and live MTA readiness.
- **RESTful API & Policy Authorization:**
  - `GET /api/domains/{domain}/catch-all`: Retrieve catch-all policy, destination mailbox, available mailboxes, and routing details.
  - `PUT /api/domains/{domain}/catch-all`: Update catch-all policy and destination with strict validation (domain verified/active, active tenant subscription, destination mailbox must belong to the domain and be active).
  - Mutex and transactions: Enforces atomic updates across domain policy and `mailboxes.is_catchall` flags.
  - Audit logging: Records `domain.catch_all_update` in `audit_logs` via `AuditService`.
- **Next.js Dashboard Integration (`frontend/`):**
  - Added TypeScript interfaces in `types/index.ts` (`CatchAllPolicy`, `CatchAllMailbox`, `CatchAllRoutingDetails`, `DomainCatchAllDetails`, `CatchAllUpdateRequest`).
  - Added `useDomainCatchAll` and `updateCatchAll` SWR hooks in `hooks/useDomains.ts`.
  - Embedded `CatchAllSettingsCard.tsx` on the domain management page (`/domains/[id]`), providing policy radio selectors (`disabled`, `reject`, `mailbox`, `discard`), active mailbox dropdown picker, high-risk discard warning, spam flood advisory, and routing status badges (`operational`, `synchronized`, `configured`, `failed`).




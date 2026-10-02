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



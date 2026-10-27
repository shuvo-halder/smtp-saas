# Project Implementation Overview

This document describes the current implementation state of the EmailSaaS project.

| Feature | Status | Implementation | Notes |
|---|---|---|---|
| Frontend Framework | IMPLEMENTED | Next.js 14 App Router | Located in `frontend/` |
| Edge Subdomain Routing | IMPLEMENTED | Next.js Edge Middleware | `tenant.mailsaas.com` -> `/(tenant)/[subdomain]` |
| Strict UI Localization | IMPLEMENTED | `next-intl` (en.json) | Zero hardcoded strings |
| Backend Framework | IMPLEMENTED | Laravel 11 REST API | Located in `laravel-panel/` |
| Authentication | IMPLEMENTED | Laravel Sanctum | Stateful cookie-based authentication |
| Multi-tenancy | IMPLEMENTED | Hostname + tenant context | `IdentifyTenant` middleware enforces isolation |
| Mail Infrastructure | IMPLEMENTED | Postfix + Dovecot + MariaDB | Shell scripts refactored to ORM native DB logic. SQL maps securely isolate tenants. |
| Mailbox Provisioning | IMPLEMENTED | Laravel + Dovecot SHA512-CRYPT | Provisioning strictly in `DB::transaction` |
| Webmail SSO | IMPLEMENTED | Roundcube + Redis OTP | Dovecot Master User login via 60s OTP |
| Billing / SSLCommerz | IMPLEMENTED | SSLCommerz IPN | DB transactions protect payment vs. activation |
| Admin Control Plane | IMPLEMENTED | Next.js + Laravel | Global dashboard, tenant drilling, metrics |
| Scheduled Automation | IMPLEMENTED | Laravel Cron | `tenant:suspend-expired` suspends overdue accounts |
| Queue Workers | IMPLEMENTED | Redis + Supervisor | `mailsaas-worker.conf` manages 8 processes |
| Offsite Backups | PENDING | S3 sync script | Recommended architectural addition |
| Multi-server scaling | PENDING | Load Balancer + NFS | Currently single-node VPS architecture |

## 1. Billing & Subscriptions
- Payments are handled exclusively via the **SSLCommerz API**, integrated securely on the Laravel backend via a custom `BillingService`.
- Transactions rely strictly on the `ipn` webhook, eliminating "client-side success" trust vulnerabilities.
- Pre-paid lifecycle model.

## 2. Admin Control Plane
- Full global administrative dashboard available at `/(admin)/admin/*`.
- Granular visibility into individual **Tenants**, including their specific domains, mailboxes, and invoice ledgers.
- System health observability via `postqueue -p`, `doveadm`, and `df` execution from Laravel.
- Global domain, mailbox, and invoice search with pagination capabilities.

## 3. SMTP Outbound Quotas (Redis)
- **Real-Time Counters:** Handled atomically in Redis via `OutboundQuotaService` using Lua scripts to prevent concurrent over-allocation.
- **Fail-Open Policy:** The service traps Redis unavailability and returns a `FAIL_OPEN_REDIS` state to ensure legitimate business mail is not blocked during transient cache outages.
- **Limits:** Tenant and Mailbox limits are pulled from the `Plan` model (`-1` = unlimited, `0` = disabled). Keys use a strict UTC date format with a 48-hour TTL.

## 4. SMTP Policy Daemon & Postfix Integration
- **Daemon Implementation:** Artisan command `php artisan policy:serve` (`App\Console\Commands\PolicyDaemonCommand`) implementing native non-blocking TCP/UNIX socket server listening on `127.0.0.1:10031`.
- **Postfix Protocol:** Parses Postfix policy delegation requests (`PostfixPolicyParser`) at `smtpd_data_restrictions`.
- **Policy Decision Engine:** `PolicyDecisionService` resolves authenticated `sasl_username` to `Mailbox -> Domain -> Tenant -> Plan`, enforces active domain and tenant subscription states, and consumes quota via `OutboundQuotaService`.
- **Transaction Idempotency:** Caches policy decision per Postfix message `instance` (`outbound:policy:tx:{instance}`) for 300 seconds to prevent double-counting if a transaction is re-evaluated.
- **Postfix Hook:** Integrated in `postfix-config/main.cf` under `smtpd_data_restrictions` with `check_policy_service inet:127.0.0.1:10031`.
- **Fail-Open Resilience:** Configured with `smtpd_policy_service_default_action = DUNNO` so Postfix defaults to accepting mail if the policy daemon or Redis is temporarily unavailable.
- **Process Supervisor:** Managed by `server-configs/mailsaas-policy.conf`.

## 5. Historical Usage Synchronization (Redis → MariaDB)
- **Synchronization Engine:** `OutboundUsageSyncService` synchronizes runtime daily tenant recipient counters from Redis to the durable MariaDB `tenant_outbound_usage` table.
- **Key Discovery:** Uses bounded `Redis::scan()` (batch size 100, matching `outbound:tenant:*:recipients:daily:*`). Strictly avoids production-blocking `KEYS`.
- **Sliding Window Recovery:** Automatically scans recent UTC days (default 2-day lookback: today and yesterday, aligned with Redis 48-hour key TTL), guaranteeing automated recovery after scheduler or node outages without manual database repair.
- **Monotonic Ledger Semantics:** Preserves confirmed historical counts. If a Redis key counter increases, MariaDB is updated; if a Redis key reset occurs (counter lower than database), the higher MariaDB ledger value is preserved with a warning log.
- **Strict Idempotency:** Re-running the synchronization command multiple times produces identical recipient counts, preventing double-counting.
- **Fail-Safe Operation:** Redis unavailability throws an exception that safely aborts the sync without writing fabricated zeros or overwriting existing historical data. Missing Redis keys do not generate dummy zero rows.
- **Concurrency Protection:** Protected by an atomic lock (`Cache::lock('outbound_usage_sync_lock', 600)`) and scheduled hourly in `routes/console.php` with `withoutOverlapping(15)`.
- **Artisan Command:** `php artisan outbound:usage-sync {--date=} {--days=2} {--dry-run}`.

## 6. Outbound Bounce Tracking & Abuse Detection (Step 15)
- **Log Streaming Engine:** `PostfixLogParserService` parses Postfix mail logs (`/var/log/mail.log`) incrementally with regex tokenization for `qmgr`, `smtp`, `submission`, and `bounce` daemons.
- **State Checkpointing:** Cursor state (`inode` + `offset`) is saved to Redis (`outbound:abuse:parser:cursor`). Handles log rotation tail draining (`mail.log.1`) and truncation. Cursor updates are transactional (only persisted after batch evaluation succeeds).
- **Intermediate Filter Discrimination:** Distinguishes internal hops to Amavis (`127.0.0.1:10024`) from external delivery. Prevents internal handoffs from resetting consecutive hard bounce counts or fabricating delivery success.
- **Queue ID Alias Correlation:** Extracts `queued as <NEW_QID>` on Amavis reinjections and maps aliases in Redis (`outbound:abuse:qid_alias:{newQid} => oldQid`) to attribute reinjected delivery outcomes back to the authenticated sender.
- **Soft Bounce Deduplication:** Employs atomic `SET NX` locks (`outbound:abuse:seen:{queueId}:{recipient}:{classification}`, 24h TTL) to count deferred retries exactly once.
- **Deterministic Classification:** `BounceClassificationService` evaluates RFC 3463 and RFC 5321 status codes into `SUCCESS`, `HARD_BOUNCE`, `SOFT_BOUNCE`, or `UNKNOWN`.
- **Sender Attribution:** `AbuseAttributionService` maps envelope senders to `Mailbox -> Domain -> User` models.
- **Telemetry Counters:** Atomic daily Redis counters (`outbound:abuse:tenant:*`, `outbound:abuse:mailbox:*`, 48h TTL) track volumes and consecutive hard bounce failure streaks.
- **Non-Destructive Alerting:** `AbuseDetectionService` flags threshold breaches (10% hard bounce rate on $\ge 20$ accepted attempts, 50 daily hard bounces, 15 consecutive hard bounces), enforces 24h cooldown locks (`SET NX`), and emits structured JSON to `storage/logs/abuse.log`. Never triggers automated account/domain/mailbox suspensions.
- **Artisan Command:** `php artisan mail:process-log {--lines=1000} {--dry-run} {--path=}` scheduled every 5 minutes in `routes/console.php`.

## 7. Admin SMTP Management & Deliverability Control Plane (Step 16A)
- **Architecture Scope Boundary:** Delivers read-only cluster-wide deliverability observability, tenant/mailbox metrics, and safe mailbox-level administrative mutations without database schema migrations.
- **Service Layer:** `AdminSmtpService` integrates MariaDB models with runtime Redis telemetry keys:
  - Cluster Overview: Computes aggregated outbound attempts, hard/soft bounces, bounce rate, queue size via Postfix `postqueue -p`, total tenants, total mailboxes, and active abuse alert counts.
  - Fail-Safe Redis Telemetry: Detects Redis connectivity (`isRedisAvailable()`). When Redis is offline, gracefully degrades by returning `telemetry_available: false` and `null` metrics with an explanatory warning banner, preventing false confidence in deliverability health.
  - Bounded Batch Discovery: Prohibits unindexed production `KEYS *`; retrieves paginated MariaDB entities and issues bounded `Redis::mget()` calls.
  - Mailbox Status Invariant: Administrative enable (`is_active = true`) strictly validates that the parent Domain is active, the parent Tenant is active, and the Tenant subscription is currently valid. Returns HTTP 422 on violations. Disabling is always permitted.
  - Mailbox Password Reset: Securely hashes passwords using SHA512-CRYPT (`crypt($password, '$6$' . Str::random(16) . '$')`). Displays plaintext password once in the admin UI; zero plaintext persistence or logging.
  - Mailbox Bounce Reset: Deletes Redis consecutive hard bounce streak (`DEL outbound:abuse:mailbox:{id}:consecutive_hard`) back to 0 without altering historical daily bounce counts.
  - Operational Audit Logging: Writes administrative mutations with actor user ID, client IP, target, action, reason, and before/after states (passwords redacted) to `storage/logs/admin-smtp.log` (`admin_smtp` daily logging channel).
- **Controller & API Routes:** `AdminSmtpApiController` exposes 8 RESTful endpoints protected globally by `EnsureAdmin` middleware at `/api/admin/smtp/*`.
- **Frontend Control Plane:** Next.js 14 tabbed administrative interface at `/(admin)/admin/smtp`:
  - `SmtpOverview`: Real-time KPI stat cards, Redis telemetry status indicator with pulse animation, offline alert banner, and bounce rate denominator explanatory callout.
  - `SmtpTenantsTable`: Searchable datatable with daily quotas, today's usage progress bars, hard/soft bounce tallies, bounce rate percentage, and abuse state badges.
  - `SmtpMailboxesTable`: Searchable and filterable datatable displaying domain, tenant, active status, today's recipients, consecutive bounce badges, and interactive modals for status toggle (with parent invariant feedback), bounce streak reset, and password reset (with copy-to-clipboard).
  - `SmtpAbuseTable`: Active abuse alert dashboard displaying threshold breaches flagged by Step 15 telemetry for today, with quick "Inspect Mailbox" jump actions.
- **Explicitly Deferred Elements:**
  - Tenant-level manual suspension (`suspension_type`, `suspension_reason`, etc.) (deferred to Step 16B.3)
  - Granular RBAC / Spatie permissions (deferred to Step 16B.2)
  - Persistent abuse incident history (`abuse_incidents` table) (deferred to Step 16B.4)

## 8. Persistent Administrative Audit Logging Foundation (Step 16B.1)
- **Database Schema:** Persistent relational `audit_logs` table (`2026_09_23_000001_create_audit_logs_table.php`):
  - `id`: Big incremental primary key.
  - `actor_user_id`: Nullable unsigned big integer foreign key referencing `users.id` with `onDelete('set null')` for lifecycle safety.
  - `actor_email`: String recording the acting administrator's email at the exact time of action.
  - `action`: String identifying the discrete operation (e.g., `admin.smtp.mailbox.toggle`).
  - `entity_type`: Nullable string (`mailbox`, `tenant`, `domain`, etc.).
  - `entity_id`: Nullable unsigned big integer.
  - `before_state`: Nullable JSON capturing pre-mutation snapshot (recursively sanitized).
  - `after_state`: Nullable JSON capturing post-mutation snapshot (recursively sanitized).
  - `reason`: Nullable text capturing administrator justification.
  - `ip_address`: Nullable string (client IPv4/IPv6).
  - `user_agent`: Nullable text (client browser / API client agent).
  - `request_id`: Nullable string (UUID / correlation identifier).
  - `created_at`: Timestamp indexed for chronological sorting and date filtering.
  - Indexed combinations: `(entity_type, entity_id)`, `action`, `actor_user_id`, `created_at`.
- **Model Layer:** `App\Models\AuditLog`:
  - Application-level append-only audit records: `const UPDATED_AT = null;`. No update or delete endpoints or application routines exist.
  - JSON attribute casting: `before_state => 'array'`, `after_state => 'array'`.
  - Belongs-to relationship: `actor()` referencing `User`.
- **Service Layer:** `App\Services\AuditService`:
  - Reusable recording interface: `record(string $action, ?string $entityType, ?int $entityId, ?array $beforeState, ?array $afterState, ?string $reason, ?User $actor, ?Request $request)`.
  - Centralized Recursive Secret Redaction: `sanitizeState()` traverses multi-dimensional arrays, instantly replacing values for sensitive keys (`password`, `password_hash`, `new_password`, `token`, `secret`, `api_key`, `otp`, `private_key`, `signature`, `auth`, `credentials`) with `'[REDACTED]'`, and redacting cryptographic hash strings (`$6$`, `$2y$`, `$2a$`).
  - Fail-Safe Persistence: Catches any `Throwable` during database insertion, writes an error entry to `storage/logs/admin-smtp.log` (with zero sensitive state or queries in the error context), and returns `null` without throwing exceptions or rolling back legitimate administrative operations.
  - Dual Logging Architecture with Single-Point Sanitization: Works alongside operational logging channel `admin_smtp`. `AdminSmtpService` sanitizes state via `AuditService::sanitizeState()` prior to emitting to `Log::channel('admin_smtp')` and `AuditService::record()`. Raw state is strictly prohibited from reaching operational logs.
- **Controller & API Routes:** `AdminAuditLogApiController`:
  - Protected globally by `EnsureAdmin` and Sanctum authentication.
  - `GET /api/admin/audit-logs`: Paginated audit log retrieval, strictly bounded to max 50 records per page (`min(max((int) $request->input('per_page', 15), 1), 50)`). Supports filtering by `action`, `entity_type`, `entity_id`, `actor_user_id`, `date_from`, `date_to`, and broad text `search`.
  - `GET /api/admin/audit-logs/{id}`: Detailed single event retrieval with eager-loaded actor relationship.
  - Formatted output via `AdminAuditLogResource`.
- **Frontend Audit UI:** Next.js 14 control plane view at `/admin/audit-logs`:
  - Search input with live text filtering.
  - Action and Entity dropdown filters.
  - Full audit ledger table displaying UTC timestamp, acting administrator, action badge, target entity, reason, and IP.
  - Bounded pagination controls with total record count and page indicators.
  - Interactive Detail Modal with JSON diff viewers for `before_state` and `after_state` snapshots, actor identity, correlation request ID, and client user-agent.
  - All user-facing strings centralized in `frontend/messages/en.json` under `Admin.audit_logs`.
- **Retention Policy:** Undefined in Step 16B.1. Explicitly marked as `NOT YET DEFINED — REQUIRES ARCHITECTURE APPROVAL` to prevent premature automatic data loss.

## 13. Tenant SMTP Access, Sending Activity & Delivery Logs (Step 28)
- **Status:** IMPLEMENTED & FULLY VERIFIED
- **API & Service Layer:**
  - `TenantSmtpService`: Handles connection parameter resolution, live quota calculation from Redis (`outbound:tenant:{id}:recipients:daily:{date}`), 14-day history aggregation with MariaDB `tenant_outbound_usage`, mailbox credential queries, password rotation with Dovecot `SHA512-CRYPT` (`$6$`), and safe account configuration diagnostics.
  - `TenantSmtpApiController`: Exposes 6 RESTful endpoints (`/api/smtp/settings`, `/api/smtp/usage`, `/api/smtp/credentials`, `/api/smtp/credentials/{mailbox}/rotate-password`, `/api/smtp/activity`, `/api/smtp/diagnostics`) protected by Sanctum and `EnsureActiveSubscription`.
- **Database & Model:**
  - `2026_10_16_000001_create_delivery_logs_table.php`: Relational schema for message delivery outcomes, queue IDs, DSN codes, SMTP reply codes, and sanitized responses.
  - `DeliveryLog` Model: Tenant scoping (`scopeForTenant`), status filters, date range filters, search, and relationships (`tenant`, `domain`, `mailbox`).
  - Integrated Pipeline Ingestion: Terminal delivery outcomes (`delivered`, `deferred`, `bounced`) are logged automatically by `AbuseDetectionService::processEvent`; quota/policy rejections are logged by `PolicyDecisionService::recordQuotaAbuseIncident`.
- **Frontend Hub:** Next.js dashboard view at `frontend/src/app/(dashboard)/smtp/page.tsx`:
  - Settings & Code tab: Server hostname copy, supported port guide (587 STARTTLS, 465 SMTPS, 25), TLS 1.2+ policy, copyable code snippets (Node.js, Python, PHP, .env).
  - Quota & Usage tab: KPI cards for sent today, remaining allowance, mailbox limit, visual progress bar, 14-day sending volume trend, and individual mailbox breakdown table.
  - Credentials tab: Authorized mailboxes table, active status badges, and secure "Rotate Password" modal with Dovecot SHA512-CRYPT generation and strict one-time plaintext reveal dialog.
  - Activity Logs tab: Filterable delivery table (status, search, bounded pagination 1-50), formatted badges (`delivered`, `deferred`, `bounced`, `rejected`), sanitized response snippets, and timestamping.
  - Diagnostics & Troubleshooting tab: Zero-SSRF account diagnostics checking subscription, domains, mailboxes, quota headroom, and bounce health, plus common SMTP errors reference guide (535, 554, 451/452, 550).

## 14. Domain DNS Records Wizard, Inbound Authentication & Deliverability Diagnostics (Step 34)
- **Status:** IMPLEMENTED & FULLY VERIFIED
- **API & Service Layer:**
  - `DnsVerificationService`: Multi-record verification engine (`verifyMx`, `verifySpf`, `verifyDkim`, `verifyDmarc`, `verifyA`, `verifyCname`, `verifyAll`, `getDnsWizardData`).
  - Injected mock resolver architecture (`setResolver`) enables 100% deterministic, offline automated testing.
  - RFC 7208 PermError handling: Rejects multiple SPF records on a single domain with `MISMATCH` status.
  - 2048-bit DKIM DNS packet chunk concatenation via `extractTxtContent()`.
  - Resilient DKIM key payload isolation via `extractDkimKeyPayload()`.
  - Weighted Deliverability Score algorithm (MX 40%, SPF 25%, DKIM 25%, DMARC 10%) producing actionable ratings (`EXCELLENT`, `GOOD`, `FAIR`, `POOR`).
  - Multi-layer environment diagnostics reporting DNS publication, application configuration, and live Postfix MTA readiness.
  - `DomainApiController`: Exposes `GET /api/domains/{domain}/dns-wizard` and `POST /api/domains/{domain}/verify-dns` (plus backwards-compatible alias `POST /api/domains/{domain}/verify`), protected by `auth:sanctum`, `DomainPolicy`, `EnsureActiveSubscription`, and `throttle:30,1`.
  - Audit logging: Emits `domain.dns_verify` into `audit_logs` via `AuditService::record()`.
- **Database & Model:**
  - Zero schema migrations needed; leveraged canonical `domains` table columns (`mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `last_dns_check_at`, `dkim_public_key`).
  - `Domain::requiredDnsRecords()` generates structured record definitions (`id`, `type`, `host`, `value`, `priority`, `purpose`, `is_required`, `status`, `verified`).
  - `DomainResource` exposes `last_dns_check_at`.
- **Frontend Hub:** Next.js dashboard view at `frontend/src/app/(dashboard)/domains/[id]/setup/page.tsx`:
  - Deliverability Health Scorecard with progress bar and rating badge.
  - Multi-layer environment notice separating DNS publication from live MTA status.
  - Inbound Routing and Outbound Authentication diagnostic cards.
  - Actionable Recommendations alert with concrete remediation instructions.
  - Upgraded `DnsRecordTable.tsx` with copy buttons, priority, purpose, status badges (`VERIFIED`, `MISMATCH`, `MISSING`, `CHECK_FAILED`, `NOT_CONFIGURED`, `EXPECTED`), and expandable troubleshooting drawer showing expected vs current values.





## 15. Domain Email Authentication Hardening & Tenant Deliverability Reports (Step 35)
- **Status:** IMPLEMENTED & FULLY VERIFIED
- **API & Service Layer:**
  - `DnsVerificationService`:
    - Hardened SPF syntax analysis (`analyzeSpfSyntax`): Enforces RFC 7208 prefix `v=spf1`, validates mechanism syntax, validates IPv4/IPv6 formats, warns against insecure `+all` open relays, tracks DNS lookup mechanisms (`include`, `a`, `mx`, `ptr`, `exists`, `redirect`) to warn on exceeding the 10-lookup limit, and detects duplicate SPF records.
    - Hardened DKIM key validation: Detects revoked keys (`p=` empty or missing), validates base64 key payloads, concatenates multi-chunk UDP DNS records, and warns when key length is under 2048-bit.
    - Hardened DMARC syntax analysis (`analyzeDmarcSyntax`): Enforces `v=DMARC1;` as first tag, enforces mandatory `p=` tag, detects duplicate `_dmarc` TXT records per RFC 7489 section 6.6.3 PermError, extracts optional tags (`sp`, `pct`, `rua`, `ruf`, `adkim`, `aspf`), and provides policy explanations.
    - Hardened MX verification: Prohibits bare IP addresses (RFC 5321 section 5.1) and warns on priority conflicts.
    - DNS Drift Detection: Flags `dns_drift_detected: true` when an active domain loses required MX, SPF, or DKIM records.
    - Verification History Extraction (`getVerificationHistory`): Queries immutable `audit_logs` where `action = 'domain.dns_verify'`, returning score trends and record statuses over time without schema churn.
    - Deliverability Report Generator (`getDeliverabilityReport`): Computes 4-pillar score breakdown (MX 40%, SPF 25%, DKIM 25%, DMARC 10%), policy advisor matrix, drift indicator, and explicit diagnostic disclaimer.
  - `DmarcReportParserService`:
    - RFC 7489 XML Aggregate Report Parser: Parses `<feedback>` XML files up to 5MB.
    - Protected against XXE vulnerabilities using `LIBXML_NONET` and proactively rejecting `<!DOCTYPE` or `<!ENTITY` blocks.
    - Extracts report metadata, published policy, volume summaries, and source IP delivery records.
    - Computes deterministic SHA-256 deduplication digest.
    - Rejects cross-domain spoofing by validating `<policy_published><domain>` against tenant domain.
  - `DomainApiController`:
    - Added `GET /api/domains/{domain}/deliverability-report` (supports `?refresh=1`).
    - Added `POST /api/domains/{domain}/parse-dmarc-report` (file upload or raw XML, throttled at `throttle:10,1`).
    - Enhanced `verifyDns` and `verify` to log rich audit state (score, per-record statuses, drift detection) without breaking legacy Mockery tests.
- **Frontend Hub:** Next.js dashboard view at `frontend/src/app/(dashboard)/domains/[id]/setup/page.tsx`:
  - DNS Drift warning banner when active domain records disappear.
  - Deliverability Scorecard with 4-pillar breakdown (MX, SPF, DKIM, DMARC) and legal/diagnostic disclaimer.
  - DMARC Policy Advisor card explaining `p=none` vs `p=quarantine` vs `p=reject` with escalation rules.
  - Verification History audit table showing previous check timestamps, scores, and per-record statuses.
  - DMARC Aggregate Report Inspector allowing tenants to upload or paste XML feedback reports and view detailed message counts, pass/fail rates, and source IP authenticity.
  - All verified with `npx tsc --noEmit` (0 errors) and Next.js production build `npm run build` (27/27 pages compiled cleanly).

## 16. Tenant Email Security & Deliverability Monitoring Dashboard (Step 36)
- **Status:** IMPLEMENTED & FULLY VERIFIED
- **API & Service Layer:**
  - `TenantSecurityDashboardService` (`app/Services/TenantSecurityDashboardService.php`):
    - Single entrypoint `getDashboardData(User $user, ?Request $request = null)` returning an unified payload.
    - `buildDomainSecuritySection`: Computes deliverability scores and detects DNS drift strictly from persisted database flags (`mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `dns_drift_detected_at`). Zero live DNS queries dispatched on render.
    - `buildSmtpUsageSection`: Integrates with `TenantSmtpService::getUsage()`, fetches daily quota from Redis (UTC daily key), calculates remaining allowance and percent used, generates quota exhaustion (`>= 100%`) and near-limit (`>= 80%`) warnings, retrieves 14-day history trend from `TenantOutboundUsage`, and tallies 30-day delivery event summary (delivered, deferred, bounced, rejected) from `DeliveryLog`.
    - `buildRecentActivitySection`: Bounded retrieval of latest 10 `DeliveryLog` records via `DeliveryLogResource` with strict tenant isolation.
    - `buildAbuseAndSecuritySection`: Queries tenant-scoped `AbuseIncident` records, counts open incidents, formats records via `TenantAbuseIncidentResource`, and synthesizes actionable warnings.
    - `buildDmarcReportingSection`: Exposes RFC 7489 XML parser status and truthfully reports automated inbox ingestion posture as `PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL`.
    - `getTenantIncidents`: Bounded pagination (1..50) with status and severity filters.
    - Resilient partial-failure isolation: Each section wraps in an independent try-catch block and returns explicit status (`operational`, `warning`, `degraded`, `clean`, `empty`, `error`) and sanitized error messages.
  - `TenantAbuseIncidentResource` (`app/Http/Resources/TenantAbuseIncidentResource.php`):
    - Redacts administrative ID (`resolved_by`), internal server file paths, raw traces, and system evidence payloads.
    - Provides context-aware `suggested_remediation` per incident type (`consecutive_hard_bounces`, `daily_hard_bounce_threshold`, `high_hard_bounce_rate`, `quota_exhaustion`, `dns_drift`).
  - `DashboardApiController` (`app/Http/Controllers/Api/DashboardApiController.php`):
    - Refactored `index()` to inject `TenantSecurityDashboardService` and return the unified payload while preserving 100% backwards compatibility (`user`, `domains_count`, `domain_count`, `mailboxes_count`, `mailbox_count`, `plan_info`, `recent_domains`, `recent_invoices`).
    - Added `securityIncidents()` (`GET /api/security/incidents`) under `auth:sanctum` and `EnsureActiveSubscription`.
- **Frontend Dashboard (`frontend/src/app/(dashboard)/dashboard/page.tsx`):**
  - Responsive, enterprise-grade Next.js monitoring dashboard matching existing design system.
  - Prominent alert banners for DNS drift, active abuse incidents, and quota warnings.
  - KPI Stat Cards: Deliverability Health average, Sent Today / Quota, Authenticated Domains count, Open Incidents count.
  - Domain Authentication Table: MX, SPF, DKIM, and DMARC verification pills, deliverability health score progress bars, DNS drift badges, and direct links to `/domains/[id]/setup`.
  - SMTP Daily Sending Quota & 14-Day Outbound Volume Trend: Quota progress bar, delivery event breakdown (30-day window), and historical sending table.
  - Recent Outbound Activity: Delivery log event ledger showing sender, recipient, status, and bounce classification.
  - Security Incidents Ledger: Tenant-specific incident table with severity badges, status chips, and expandable suggested remediation steps.
  - DMARC Reporting Posture Card: Highlights XML report parser availability and transparently discloses pending automated inbox ingestion.
  - Preserved recent invoices billing section.
- **Verification & Testing:**
  - Dedicated feature test suite: `TenantSecurityDeliverabilityDashboardTest.php` (18/18 tests passing, 144 assertions, 100%).
  - Full PHPUnit regression suite: 490/490 tests passing (2434 assertions, 100%).
  - Frontend TypeScript validation: `npx tsc --noEmit` (0 errors).
  - Next.js production build: `npm run build` (27/27 pages compiled cleanly with 0 errors).

## 17. Tenant Notifications & Security Alert Center (Step 37)
- **Status:** IMPLEMENTED & FULLY VERIFIED
- **Database Architecture & Migration:**
  - Non-destructive migration `2026_10_28_000001_create_tenant_notifications_table.php` applied to `emailsaas_local` (MySQL 8.4.3).
  - Schema: `id`, `tenant_id` (FK -> `users.id` cascade on delete), `category` (varchar 50), `severity` (varchar 20 default 'info'), `title` (varchar 255), `message` (text), `action_url` (varchar 255 nullable), `deduplication_key` (varchar 255 nullable), `data` (json nullable), `read_at` (timestamp nullable), `dismissed_at` (timestamp nullable), `created_at`, `updated_at`.
  - Composite indexes: `idx_tenant_read_created` (`tenant_id`, `read_at`, `created_at`), `idx_tenant_cat_created` (`tenant_id`, `category`, `created_at`), `idx_tenant_dedup` (`tenant_id`, `deduplication_key`).
- **Backend Architecture & Service Layer:**
  - `TenantNotification` Model (`app/Models/TenantNotification.php`):
    - Scopes: `forTenant()`, `unread()`, `read()`, `active()`, `dismissed()`, `category()`, `severity()`.
    - Helper methods: `isRead()`, `isDismissed()`, `markAsRead()`, `markAsUnread()`, `dismiss()`.
    - Relationships: `tenant(): BelongsTo` with `User`, and `User::tenantNotifications(): HasMany`.
  - `TenantNotificationPolicy` (`app/Policies/TenantNotificationPolicy.php`):
    - Registered in `AuthServiceProvider`. Enforces strict tenant ownership (`$user->id === $notification->tenant_id`) across `view()`, `update()`, and `delete()`.
  - `TenantNotificationService` (`app/Services/TenantNotificationService.php`):
    - Centralized entrypoint `notify()` with atomic deduplication (`deduplication_key`), preventing duplicate active alerts.
    - Fail-safe non-blocking error handling: catches and logs database exceptions so primary operations never abort.
    - Recursive sensitive data scrubbing (`scrubSensitiveData`): strips passwords, secrets, tokens, api keys, private keys, hashes, salts, and system traces.
    - Bounded querying `getNotifications()` (1..50 per page) with status, category, and severity filtering.
    - Lifecycle operations: `getUnreadCount()`, `markAsRead()`, `markAllAsRead()`, and `dismiss()`.
  - `TenantNotificationResource` (`app/Http/Resources/TenantNotificationResource.php`):
    - Standardized API payload formatting with stored XSS prevention via `strip_tags()` and recursive data scrubbing.
  - `TenantNotificationApiController` (`app/Http/Controllers/Api/TenantNotificationApiController.php`):
    - RESTful API endpoints under `auth:sanctum` (exempt from subscription gating so expired/suspended tenants can inspect critical notices):
      - `GET /api/notifications`: paginated list.
      - `GET /api/notifications/unread-count`: live unread count.
      - `POST /api/notifications/{id}/read`: idempotent mark read.
      - `POST /api/notifications/mark-all-read`: idempotent bulk mark read.
      - `POST /api/notifications/{id}/dismiss`: idempotent archive/dismiss.
- **Event Trigger Integrations Across Codebase:**
  - Domain DNS Drift & Recovery: `DomainApiController::verifyDns()` emits critical alert on drift (`dns_drift:{id}:active`) and automatically marks drift read with success notice on recovery (`dns_recovered:{id}:{date}`).
  - Mailbox Forwarding & Auto-Reply: `MailboxRuleApiController::updateForwarding()` and `updateAutoReply()` emit informative configuration audit notices.
  - Mailbox Active/Disabled Toggle: `MailboxApiController::toggle()` emits notifications when mailboxes are enabled or quarantined.
  - Abuse Incidents & Resolutions: `AbuseIncidentService::record()` and `resolve()` dispatch security alerts on detection and resolution notices on remediation.
  - Billing & Invoices: `BillingService::markInvoicePaid()` dispatches payment confirmation notice (`invoice_paid:{id}`).
  - Subscription Expiration: `SuspendExpiredTenants.php` dispatches critical plan expiration alert (`subscription_expired:{id}:{date}`).
  - SMTP Quota Thresholds: `OutboundUsageSyncService.php` dispatches warning (>=80%) and critical exhaustion (>=100%) alerts during usage synchronization.
- **Next.js Frontend Architecture:**
  - TypeScript types (`frontend/src/types/index.ts`): full type definitions for notification categories, severities, responses, and unread counts.
  - SWR Hooks (`frontend/src/hooks/useNotifications.ts`): reactive notifications fetcher, live polling unread counter, and optimistic mutation helpers.
  - TopBar Notification Bell & Drawer (`frontend/src/components/notifications/NotificationDrawer.tsx` & `TopBar.tsx`): interactive slide-out drawer with unread counter badge, category icons, severity badges, relative timestamps, and one-click action links.
  - Sidebar Navigation (`frontend/src/components/layouts/Sidebar.tsx`): dedicated `Notifications` nav item with live unread badge.
  - Notification Center Page (`frontend/src/app/(dashboard)/notifications/page.tsx`): responsive, filterable alert center with category selector, status tabs (All, Unread, Read), bulk mark-all-read, pagination, and dismissal controls.
- **Verification & Testing Results:**
  - Dedicated feature test suite: `TenantNotificationTest.php` (16/16 tests passing, 92 assertions, 100%).
  - Full PHPUnit regression suite: 506/506 tests passing (2526 assertions, 100%).
  - Frontend TypeScript validation: `npx tsc --noEmit` (0 errors).
  - Next.js production build: `npm run build` (28/28 static pages compiled cleanly with 0 errors).

## 18. Tenant Notification Preferences & Alert Lifecycle Management (Step 38)
- **Status:** IMPLEMENTED & FULLY VERIFIED
- **Database Architecture & Migration:**
  - Non-destructive migration `2026_10_28_000002_add_notification_preferences_to_users_table.php` applied to `emailsaas_local` (MySQL 8.4.3).
  - Schema extension: added `notification_preferences` (`json`, nullable) to `users` table after `remember_token`.
- **Backend Architecture & Service Layer:**
  - `User` Model (`app/Models/User.php`):
    - Added `notification_preferences` to `$fillable` and `casts()`.
    - Added `getNotificationPreferences(): array` returning tenant preferences with sensible defaults and locking mandatory categories.
    - Added `isNotificationCategoryEnabled(string $category): bool` helper for category routing checks.
  - `TenantNotification` Model (`app/Models/TenantNotification.php`):
    - Added `restore(): bool` for idempotent alert restoration (`dismissed_at = null`).
    - Added `created_at` to `$fillable` to allow precise historical timestamp assertions and bounded retention testing.
  - `TenantNotificationService` (`app/Services/TenantNotificationService.php`):
    - Updated `notify()` to evaluate tenant category preferences prior to database storage, enforcing the **Critical Alert Invariant**: notifications with `severity === 'critical'` (DNS drift, 100% quota exhaustion) bypass category filters and are always delivered.
    - Added `getPreferences(User $user): array` returning category settings, immutable definitions, labels, and compliance notices.
    - Added `updatePreferences(User $user, array $preferences): array` rejecting attempts to mute immutable categories with HTTP 422 and recording an append-only audit entry via `AuditService::record()`.
    - Added `getCounts(User $user): array` returning aggregated unread, read, dismissed, active, and breakdowns by category and severity.
    - Added `restore(User $user, int $notificationId): ?TenantNotification` for un-dismissing alerts.
    - Added `pruneExpiredNotifications(int $readDays, int $dismissedDays, int $batchSize, bool $dryRun): array` implementing bounded retention cleanup while strictly exempting unresolved critical alerts and preserving `audit_logs` and `abuse_incidents`.
  - `TenantNotificationApiController` (`app/Http/Controllers/Api/TenantNotificationApiController.php`):
    - Endpoints added: `GET /api/notifications/preferences`, `PUT /api/notifications/preferences`, `GET /api/notifications/counts`, `POST /api/notifications/{id}/restore`.
    - Enhanced `GET /api/notifications` with `status=dismissed` filter to allow viewing archived alerts.
  - `PruneNotificationsCommand` (`app/Console/Commands/PruneNotificationsCommand.php`):
    - Artisan command `notifications:prune` with `--read-days=90`, `--dismissed-days=30`, `--batch-size=500`, `--dry-run`.
    - Scheduled daily at 04:30 UTC in `routes/console.php` with 15-minute overlapping lock (`withoutOverlapping(15)`).
- **Next.js Frontend Architecture:**
  - TypeScript types (`frontend/src/types/index.ts`): added `NotificationPreferences`, `NotificationPreferencesResponse`, `CategoryCountItem`, `NotificationCountsResponse`.
  - SWR Hooks (`frontend/src/hooks/useNotifications.ts`): added `useNotificationCounts()`, `useNotificationPreferences()`, `updateNotificationPreferences()`, `restoreNotification()`, and updated `useNotifications` options for `status=dismissed`.
  - Notification Center Page (`frontend/src/app/(dashboard)/notifications/page.tsx`):
    - Added view switcher between "Alerts & Activity" and "Preferences".
    - Added status filter tabs with real-time count badges: All, Unread, Read, and Dismissed.
    - Added one-click "Restore" action on dismissed alert items.
    - Created `NotificationPreferencesCard` with category switches, descriptions, immutable locked badges, and critical alert explanations.
  - Settings Page Integration (`frontend/src/app/(dashboard)/settings/page.tsx`): added Notification Preferences discovery card linking to `/notifications`.
- **Verification & Testing Results:**
  - Dedicated feature test suite: `TenantNotificationPreferencesTest.php` (15/15 tests passing, 75 assertions, 100%).
  - Notifications feature suite: `TenantNotificationTest.php` (16/16 tests passing, 92 assertions, 100%).
  - Full PHPUnit regression suite: 521/521 tests passing (2601 assertions, 100%).
  - Frontend TypeScript validation: `npx tsc --noEmit` (0 errors).
  - Next.js production build: `npm run build` (28/28 static pages compiled cleanly with 0 errors).

## 19. Tenant Notification Delivery Reliability & Operational Monitoring (Step 39)
- **Status:** IMPLEMENTED & FULLY VERIFIED
- **Concurrency-Safe Deduplication & Service Telemetry (`TenantNotificationService`):**
  - Concurrency serialization via atomic mutex lock (`Cache::lock("tenant_notif_lock:{$tenantId}:" . md5($deduplicationKey), 5)->block(3)`). Prevents concurrent race conditions when two simultaneous processes attempt to emit identical unread alerts.
  - Fail-safe non-blocking execution with structured telemetry:
    - On deduplication: logs info with structured metadata (`deduplicated: true`) and increments `Cache::increment('notifications:deduplicated_24h_count')`.
    - On creation: increments `Cache::increment('notifications:created_24h_count')`.
    - On failure: increments `Cache::increment('notifications:failures_24h_count')`, caches error details in `notifications:last_failure`, and logs structured error.
  - Complex metadata sanitization (`scrubSensitiveData()`):
    - Caps recursion depth at 10 levels, returning `['_truncated' => 'Max metadata nesting depth exceeded']`.
    - Sanitizes PHP stream/file resources (`is_resource`) to `'[Resource: type]'` preventing JSON serialization crashes.
    - Serializes objects via `toArray()`, `JsonSerializable`, `__toString()`, or class descriptor.
    - Normalizes strings to valid UTF-8 via `mb_convert_encoding()`.
  - Operational status engine (`getOperationalStatus()`): returns numerical summary of total notifications, unread count, unresolved critical alerts, 24-hour counters, retention settings, and scheduler registration status. Exposes zero tenant private content or credentials.
- **Hardened Retention Pruning (`PruneNotificationsCommand`):**
  - Added `--stale-days=180` option to signature and service method.
  - Strict input validation: `--read-days >= 1`, `--dismissed-days >= 1`, `--stale-days >= 1`, `1 <= --batch-size <= 5000`. Exits with `Command::INVALID` (code 2) on violation.
  - Caches execution summary in `notifications:prune:last_execution` with duration, pruned counts, eligible counts, and exit status.
  - Double overlap protection: scheduler `withoutOverlapping(15)` plus command-level `Cache::lock('notifications_prune_lock', 300)`.
- **System Health & Admin Monitoring Integration:**
  - Added `checkNotifications(): array` to `SystemHealthService::getHealth()`, incorporating notification delivery posture into overall system health.
  - Added `GET /api/admin/system/notifications` route in `routes/api.php` routed to `AdminSystemHealthApiController::notifications`, guarded by `EnsureAdmin` and `admin.permission:admin.stats.read`.
- **API Query Validation Hardening:**
  - `TenantNotificationApiController::index` strictly validates `category` against `VALID_CATEGORIES` and `severity` against `VALID_SEVERITIES`, returning HTTP 422 on invalid values.
- **Next.js Admin UI Integration:**
  - Added `notifications?: ComponentHealth` and `NotificationOperationalStatus` to `frontend/src/types/index.ts`.
  - Added localization keys in `frontend/messages/en.json` under `Admin.system_health.components` and `labels`.
  - Rendered 8th component posture card with `Bell` icon in `frontend/src/app/(admin)/admin/system-health/page.tsx`.
- **Verification & Testing Results:**
  - Dedicated reliability test suite: `TenantNotificationReliabilityTest.php` (17/17 tests passing, 94 assertions, 100%).
  - Notification feature suites: 48/48 tests passing (261 assertions, 100%).
  - Full PHPUnit regression suite: 538/538 tests passing (2,695 assertions, 100%).
  - Frontend TypeScript validation: `npx tsc --noEmit` (0 errors).
  - Next.js production build: `npm run build` (28/28 static pages compiled cleanly with 0 errors).





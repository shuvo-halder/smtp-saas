# Changelog

### Email Delivery Production Readiness Audit & Linux Deployment Runbook (Step 42)
- **Implemented:** Comprehensive Linux Deployment & Verification Runbook (`docs/MAIL-DELIVERY-RUNBOOK.md`):
  - Documented OS prerequisites (Ubuntu 22.04 LTS), PHP 8.3 CLI extensions (`pcntl`, `posix`, `pdo_mysql`, `redis`), Supervisor daemon configuration, and crontab/systemd timer setups.
  - Formulated log rotation policy (`/etc/logrotate.d/emailsaas`) with `0640 www-data www-data` permissions.
- **Implemented:** Production-Critical Failure Analysis Matrix:
  - Documented 10 failure scenarios covering worker crashes after SMTP acceptance (Two Generals' Problem), active worker lease collision, stale recovery race conditions, repeated admin retries, cache outages, idempotency race conditions, database disconnections, worker restarts, URL attacks, and credential leaks.
- **Implemented:** Operational Audit Logging for Mail Mutations:
  - Added `AuditService::record()` logging on `AdminSystemHealthApiController::retryMailDelivery` (`admin.mail_delivery.retry`) and `cancelMailDelivery` (`admin.mail_delivery.cancel`).
- **Implemented:** Transport-Level RFC 5322 Message-ID Deduplication:
  - Added `TransactionalMail::headers()` defining deterministic `Message-ID: <uuid@domain>` and `X-Delivery-UUID` headers to enable destination MTAs and ESPs to deduplicate transmissions following post-crash worker recovery.
- **Implemented:** Disaster Recovery Runbook Extension:
  - Added Section 10 to `docs/DISASTER-RECOVERY-RUNBOOK.md` detailing database snapshot restoration impacts on in-flight mail, post-restore queue re-synchronization (`mail:recover-stale`, `queue:flush`), and dead-letter triage.
- **Automated Verification & Three-Level Matrix:**
  - Expanded `MailDeliveryQueueTest.php` with 16 tests (83 assertions) verifying deterministic headers and administrative audit logging.
  - Full backend regression suite: **569 tests, 2,829 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.

### Transactional Mail Security, Queue Recovery & Delivery Consistency (Step 41)
- **Implemented:** Finite State Machine & Delivery Lifecycle Validation:
  - Formalized valid transition mapping (`ALLOWED_TRANSITIONS`) in `MailDeliveryRecord`: `queued` -> `[processing, cancelled]`, `processing` -> `[sent, failed, queued]`, `failed` -> `[queued]`, `cancelled` -> `[queued]`, `sent` -> `[]`.
  - Added `canTransitionTo(string $targetStatus): bool` and `transitionTo(string $targetStatus, array $attributes = []): bool` throwing `InvalidDeliveryStateException` on illegal transitions.
  - Immutability guarantee: `sent` status is permanent; any mutation attempt on a sent delivery is rejected.
- **Implemented:** Worker Execution Safety & Timing Hierarchy:
  - Added explicit `$timeout = 60;` property on `ProcessMailDeliveryJob`, strictly below queue driver `retry_after = 90`.
  - Added `worker_lease_seconds = 120` to `config/mail_delivery.php`.
  - Worker acquisition uses conditional atomic updates claiming `queued` or reclaiming `processing` only when `last_attempted_at <= now() - worker_lease_seconds`, preventing active workers from being stomped.
  - Worker completion updates (`sent` or `failed`) conditioned strictly on `WHERE id = ? AND status = 'processing'`.
- **Implemented:** Atomic Concurrency & Safe State Recovery:
  - Stale processing recovery (`recoverStaleProcessing()`): Conditional atomic update `WHERE id = ? AND status = 'processing' AND last_attempted_at <= cutoff`. If an active worker finishes in between, 0 rows are affected and no duplicate job is dispatched.
  - Retry (`retry()`): Validates state, performs atomic conditional update `WHERE id = ? AND status IN ('failed', 'cancelled')`, and dispatches queue job only if affected rows > 0.
  - Cancel (`cancel()`): Validates state, performs atomic conditional update `WHERE id = ? AND status = 'queued'`.
  - Concurrency-safe idempotency locking via `Cache::lock("mail_delivery_idemp:{$tenantId}:{$key}", 5)->block(3)`.
  - Two Generals' Problem Documentation: Acknowledged distributed systems constraint that true exactly-once email delivery across a worker crash post-SMTP acceptance (`250 OK`) is technically impossible without downstream MTA deduplication. Status `sent` strictly represents Transport Acceptance.
- **Implemented:** Per-Tenant Burst Rate Limiting:
  - Integrated `RateLimiter::tooManyAttempts("mail_delivery_rate:{$tenantId}", $limit)` enforcing 60 dispatches/minute per tenant (configurable via `config('mail_delivery.tenant_rate_limit_per_minute')`).
  - Throws `RateLimitExceededException` upon exceeding burst limit, protecting queue capacity.
- **Implemented:** Security & Content Hardening:
  - URL scheme validation: `validateSafeUrl()` validates action URLs (`verification_url`, `reset_url`, `invoice_url`, `action_url`) strictly against `http` and `https` schemes with valid hostnames, rejecting `javascript:`, `data:`, and malformed URIs.
  - Blade template contextual escaping: All transactional templates strictly utilize `{{ ... }}` escaping with zero unescaped `{!! ... !!}` directives.
  - Enhanced error message scrubber: Regex scrubbing of URI passwords (`redis://user:pass@host` -> `redis://user:[REDACTED]@host`), bearer tokens, API keys, authorization headers, and hashes.
- **Implemented:** Administrative API Conflict Handling & Configuration Audit:
  - `AdminSystemHealthApiController`: Catches `InvalidDeliveryStateException` on retry/cancel endpoints and returns semantic `HTTP 409 Conflict`.
  - `MailDeliveryService::validateConfiguration()`: Validates timing hierarchy (`job_timeout < retry_after`, `retry_after <= stale_threshold`, `base_backoff <= max_backoff`, `worker_lease > timeout`), surfacing `configuration_health` inside administrative telemetry (`GET /api/admin/system/mail-queue`).
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/MailDeliverySecurityAndConsistencyTest.php` with 15 tests (51 assertions) covering state transitions, 409 conflicts, concurrent retry deduplication, active worker preservation during stale recovery, dead-lettering, idempotency key locking, rate limiting, URL scheme validation, Blade XSS escaping, error secret redaction, and configuration validation.
  - Full backend regression suite: **568 tests, 2,824 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.

### Mail Delivery Queue Foundation & Retry Management (Step 40)
- **Implemented:** Dedicated Mail Delivery Database Ledger (`mail_deliveries`):
  - Created and applied `2026_10_30_000001_create_mail_deliveries_table.php` on MySQL 8.4.3 (`emailsaas_local`).
  - Columns: `id`, `uuid` (unique), `tenant_id` (foreign key to `users.id` cascade on delete), `recipient_email`, `message_type`, `template_id`, `subject`, `payload` (JSON), `status` (`queued`, `processing`, `sent`, `failed`, `cancelled`), `attempts`, `max_attempts`, `idempotency_key`, `last_attempted_at`, `sent_at`, `failed_at`, `cancelled_at`, `last_error`, `is_dry_run`, timestamps.
  - High-performance composite indexes: `[tenant_id, created_at]`, `[tenant_id, status, created_at]`, `[status, created_at]`, `[status, last_attempted_at]`, `[tenant_id, idempotency_key]`.
  - Zero interference with Postfix or `delivery_logs`.
- **Implemented:** Configuration & Domain Exception Hierarchy:
  - Created `config/mail_delivery.php` configuring dry-run mode (default `true`), default max attempts (3), base backoff (60s), max backoff (900s), and stale threshold (900s).
  - Created exception hierarchy under `App\Exceptions\MailDelivery\`: `PermanentDeliveryException`, `TransientDeliveryException`, `InvalidRecipientException`, `InvalidPayloadException`, `UnauthorizedRecipientException`, `UnsupportedMessageTypeException`.
- **Implemented:** Mail Delivery Service Layer (`MailDeliveryService`):
  - `enqueue()`: Validates recipient email, message type (`account_verification`, `password_reset`, `billing_invoice`, `security_alert`), required payload fields; recursively scrubs sensitive tokens (`password`, `token`, `secret`, `bearer`); deduplicates via `idempotency_key`; and dispatches `ProcessMailDeliveryJob`.
  - `retry()`: Idempotent manual retry of failed/cancelled records, resets attempts to 0, and dispatches job.
  - `cancel()`: Idempotent cancellation of pending queued records.
  - `recoverStaleProcessing()`: Rescues interrupted/crashed worker records stuck in `processing`.
  - `getOperationalStatus()`: Aggregates operational telemetry for administrative diagnostics.
  - `getTenantDeliveries()` & `getAdminDeliveries()`: Bounded paginated queries.
- **Implemented:** Queue Worker & Mail Transport Abstraction (`ProcessMailDeliveryJob`):
  - Implements `ShouldQueue` with dynamic retry budget per delivery record.
  - Dry-run safety invariant: `MAIL_DELIVERY_DRY_RUN=true` simulates deliveries locally and logs to Laravel log without outbound SMTP network traffic.
  - Failure classification: Permanent errors fail immediately without retry; transient errors trigger exponential backoff with jitter (`min(900, (60 * 2^(attempts-1)) + jitter)`). Records exceeding `max_attempts` dead-letter to `failed`.
  - Error message sanitizer redacting passwords, bearer tokens, and hashes from logs and database error columns.
  - Created responsive transactional Blade email templates in `resources/views/emails/transactional/` (`layout`, `account_verification`, `password_reset`, `billing_invoice`, `security_alert`) and `TransactionalMail` mailable.
- **Implemented:** Scheduled Stale Processing Recovery (`mail:recover-stale`):
  - Created Artisan command `App\Console\Commands\RecoverStaleMailCommand` with `--stale-seconds=900` and `--dry-run`.
  - Scheduled every 15 minutes in `routes/console.php` with `withoutOverlapping(10)` protection.
- **Implemented:** API Routing & Policy Isolation:
  - Tenant endpoints: `GET /api/mail-deliveries` and `GET /api/mail-deliveries/{id}` protected by `MailDeliveryPolicy` and strictly tenant-scoped (404 on cross-tenant access).
  - Admin endpoints: `GET /api/admin/system/mail-queue`, `GET /api/admin/system/mail-deliveries`, `POST /api/admin/system/mail-deliveries/{id}/retry`, `POST /api/admin/system/mail-deliveries/{id}/cancel` guarded by `EnsureAdmin` and `admin.permission:admin.stats.read`.
  - System health integration: added `mail_delivery` to `SystemHealthService::getHealth()`.
- **Implemented:** Next.js Admin UI Integration:
  - Added `mail_delivery?: ComponentHealth`, `MailQueueOperationalStatus`, `MailDeliveryItem` in `frontend/src/types/index.ts`.
  - Added localization strings in `frontend/messages/en.json`.
  - Rendered 9th posture card in `frontend/src/app/(admin)/admin/system-health/page.tsx` with `Mail` icon displaying total deliveries, queued in-flight count, dead-letter count, and dry-run mode indicator.
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/MailDeliveryQueueTest.php` with 15 tests (78 assertions) covering dry-run mode, idempotency keys, permanent vs transient failures, exponential backoff with jitter, dead-lettering, sensitive token scrubbing, stale recovery, tenant isolation, and administrative telemetry.
  - Full backend regression suite: **553 tests, 2,773 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 28 static pages cleanly with zero errors**.

### Tenant Notification Delivery Reliability & Operational Monitoring (Step 39)
- **Implemented:** Concurrency-Safe Deduplication & Service Telemetry:
  - Neutralized duplicate unread alert race conditions in `TenantNotificationService::notify()` via atomic mutex lock (`Cache::lock("tenant_notif_lock:{$tenantId}:" . md5($deduplicationKey), 5)->block(3)`).
  - Added operational tracking methods `recordDeduplicationOutcome()`, `recordCreationOutcome()`, and `recordFailureOutcome()`.
  - Added `notifications:deduplicated_24h_count`, `notifications:created_24h_count`, and `notifications:failures_24h_count` cache counters.
  - Enhanced `scrubSensitiveData()` with recursion depth limit ($depth \le 10$), safe stream/file resource transformation (`'[Resource: type]'`), object-to-array serialization, and UTF-8 byte normalization.
  - Added `getOperationalStatus()` returning aggregated numerical counters, unread counts, unresolved critical alerts, 24-hour counters, retention settings, and scheduler registration status. Exposes zero tenant content, titles, messages, or emails.
- **Implemented:** Retention Pruning Hardening & Input Validation:
  - Added `--stale-days=180` option to `PruneNotificationsCommand` and `TenantNotificationService::pruneExpiredNotifications()`.
  - Implemented boundary validation for `--read-days >= 1`, `--dismissed-days >= 1`, `--stale-days >= 1`, and `1 <= --batch-size <= 5000`, rejecting invalid options with `Command::INVALID` (code 2).
  - Captured execution telemetry in `notifications:prune:last_execution` with duration, pruned counts, eligible counts, and exit status.
  - Verified double overlap protection: scheduler `withoutOverlapping(15)` plus command-level `Cache::lock('notifications_prune_lock', 300)`.
- **Implemented:** System Health & Administrative Monitoring:
  - Integrated `checkNotifications(): array` into core `SystemHealthService::getHealth()`, incorporating notification delivery posture into overall system health.
  - Registered dedicated route `GET /api/admin/system/notifications` protected under `EnsureAdmin` and `admin.permission:admin.stats.read`.
  - Hardened `TenantNotificationApiController::index` query validation: `category` validated against `VALID_CATEGORIES` and `severity` against `VALID_SEVERITIES`. Invalid queries return HTTP 422.
- **Implemented:** Next.js Admin UI Integration:
  - Updated TypeScript interfaces in `frontend/src/types/index.ts` with `notifications?: ComponentHealth` and `NotificationOperationalStatus`.
  - Added localization keys in `frontend/messages/en.json`.
  - Rendered 8th component posture card in `frontend/src/app/(admin)/admin/system-health/page.tsx` with `Bell` icon displaying total alerts, active unread, unresolved critical count, and pruning posture.
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantNotificationReliabilityTest.php` with 17 tests (94 assertions) covering concurrency deduplication, failure tracking, resource/object sanitization, strict query validation, pruning boundaries, overlap prevention, and admin privacy.
  - All 3 notification test suites passing: **48 tests, 261 assertions passing (100%)**.
  - Full backend regression suite: **538 tests, 2,695 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 28 static pages cleanly with zero errors**.


### Tenant Notification Preferences & Alert Lifecycle Management (Step 38)
- **Implemented:** Database Migration & User Preferences:
  - Created and applied `2026_10_28_000002_add_notification_preferences_to_users_table.php` on MySQL 8.4.3.
  - Added `notification_preferences` (`json`, nullable) to `users` table with default enablements across all 6 categories: `domain_dns`, `security_abuse`, `smtp_quota`, `billing_subscription`, `mailbox_rule`, `system`.
  - Added `getNotificationPreferences()` and `isNotificationCategoryEnabled()` helpers to `User` model.
- **Implemented:** Alert Lifecycle Management & Preferences Enforcement:
  - Updated `TenantNotificationService::notify()` to evaluate category preferences before dispatching.
  - Enforced the **Critical Alert Invariant**: notifications with `severity === 'critical'` (DNS drift, quota exhaustion) automatically bypass category mute filters and are always delivered.
  - Enforced immutable categories: `security_abuse` and `billing_subscription` cannot be disabled; attempts to mute are rejected with HTTP 422 Unprocessable Entity.
  - Added `TenantNotificationService::getPreferences()` and `updatePreferences()` with persistent audit logging via `AuditService::record()`.
  - Added `TenantNotification::restore()` and `TenantNotificationService::restore()` for idempotent alert un-dismissing.
  - Added `TenantNotificationService::getCounts()` aggregating unread, read, dismissed, active, and breakdowns by category and severity.
- **Implemented:** Automated Retention Policy & Bounded Pruning:
  - Documented retention schedule: read alerts retained 90 days, dismissed alerts retained 30 days, non-critical unread alerts retained 180 days.
  - Guaranteed invariant: unresolved critical alerts (`severity === 'critical' AND read_at IS NULL AND dismissed_at IS NULL`) are never deleted.
  - Anti-data-loss boundary: pruning strictly touches `tenant_notifications` only; `audit_logs` and `abuse_incidents` are completely immune.
  - Implemented Artisan command `php artisan notifications:prune` (`PruneNotificationsCommand.php`) with `--read-days`, `--dismissed-days`, `--batch-size`, `--dry-run`.
  - Scheduled daily run in `routes/console.php` at 04:30 UTC with atomic cache lock `withoutOverlapping(15)`.
- **Implemented:** Next.js Frontend Notification Preferences & Archive UI:
  - Updated TypeScript interfaces in `frontend/src/types/index.ts`.
  - Added hooks in `frontend/src/hooks/useNotifications.ts` (`useNotificationCounts`, `useNotificationPreferences`, `updateNotificationPreferences`, `restoreNotification`).
  - Added dual view switcher ("Alerts & Activity" vs "Preferences") in `frontend/src/app/(dashboard)/notifications/page.tsx`.
  - Added status tabs with real-time count badges (All, Unread, Read, Dismissed) and one-click "Restore" action on dismissed alerts.
  - Created `NotificationPreferencesCard` with category toggles, locked badges for mandatory categories, and compliance explanations.
  - Added Notification Preferences discovery card to `frontend/src/app/(dashboard)/settings/page.tsx`.
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantNotificationPreferencesTest.php` with 15 tests (75 assertions) covering category preferences, immutable category validation, audit log persistence, tenant isolation, critical alert bypass, category/severity counts, dismiss view, restore action, cross-tenant restore isolation, prune command deletion, critical alert retention immunity, audit log/abuse incident immunity, and dry-run mode.
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Preferences Isolation, Immutability & Retention Invariants): `VERIFIED`.
  - Level C (Multi-Channel Live Push / Webhooks): `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL`.
  - Backend feature tests: **15 tests, 75 assertions passing (100%)**.
  - Notification feature tests: **16 tests, 92 assertions passing (100%)**.
  - Full backend regression suite: **521 tests, 2,601 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 28 static pages cleanly with zero errors**.


### Tenant Notifications & Security Alert Center (Step 37)
- **Implemented:** Database Schema & Non-Destructive Migration:
  - Created and applied `2026_10_28_000001_create_tenant_notifications_table.php` on MySQL 8.4.3.
  - Dedicated `tenant_notifications` table with columns: `id`, `tenant_id` (foreign key -> `users.id` cascade on delete), `category`, `severity`, `title`, `message`, `action_url`, `deduplication_key`, `data`, `read_at`, `dismissed_at`, timestamps.
  - Composite indexes: `idx_tenant_read_created`, `idx_tenant_cat_created`, and `idx_tenant_dedup`.
- **Implemented:** Backend Models, Policy & Service Layer:
  - Created `TenantNotification` Eloquent model (`app/Models/TenantNotification.php`) with scopes (`forTenant`, `unread`, `read`, `active`, `dismissed`, `category`, `severity`), helper methods (`isRead`, `isDismissed`, `markAsRead`, `markAsUnread`, `dismiss`), and relations (`tenant()`, `User::tenantNotifications()`).
  - Implemented `TenantNotificationPolicy` (`app/Policies/TenantNotificationPolicy.php`) registered in `AuthServiceProvider` enforcing strict tenant ownership (`$user->id === $notification->tenant_id`).
  - Created `TenantNotificationResource` (`app/Http/Resources/TenantNotificationResource.php`) ensuring stored XSS mitigation (`strip_tags`) and recursive data scrubbing.
  - Implemented `TenantNotificationService` (`app/Services/TenantNotificationService.php`):
    - `notify()` with atomic deduplication (`deduplication_key`) preventing alert fatigue during persistent conditions.
    - Fail-safe database exception trapping so primary application workflows never abort on notification errors.
    - Recursive sensitive credential scrubbing (`password`, `secret`, `token`, `key`, `private_key`, `api_key`, `hash`, `salt`, `trace`, `file`, `line`, `evidence`).
    - Bounded `getNotifications()` (1..50 per page) with status, category, and severity filtering.
    - Idempotent lifecycle mutations: `getUnreadCount()`, `markAsRead()`, `markAllAsRead()`, and `dismiss()`.
  - Created `TenantNotificationApiController` (`app/Http/Controllers/Api/TenantNotificationApiController.php`) under `auth:sanctum` (accessible to expired/suspended tenants for renewal and suspension alerts).
- **Implemented:** Application Event Trigger Integrations:
  - Domain DNS drift alert (`dns_drift:{id}:active`) and drift recovery auto-resolution (`dns_recovered:{id}:{date}`) in `DomainApiController::verifyDns()`.
  - Mailbox forwarding and auto-reply configuration audit notifications in `MailboxRuleApiController::updateForwarding()` & `updateAutoReply()`.
  - Mailbox active/quarantine toggle notifications in `MailboxApiController::toggle()`.
  - Abuse incident detection alerts and admin resolution notices in `AbuseIncidentService::record()` & `resolve()`.
  - Invoice payment confirmation notice (`invoice_paid:{id}`) in `BillingService::markInvoicePaid()`.
  - Subscription expiration alert (`subscription_expired:{id}:{date}`) in `SuspendExpiredTenants.php`.
  - Daily SMTP quota warning (>=80%) and exhaustion (>=100%) alerts in `OutboundUsageSyncService.php`.
- **Implemented:** Responsive Next.js Frontend Notification UI & SWR Integration:
  - Added TypeScript interfaces in `frontend/src/types/index.ts` (`TenantNotificationCategory`, `TenantNotificationSeverity`, `TenantNotification`, `NotificationListResponse`, `UnreadCountResponse`).
  - Implemented SWR hooks and mutations in `frontend/src/hooks/useNotifications.ts` (`useNotifications`, `useUnreadCount`, `markNotificationAsRead`, `markAllNotificationsAsRead`, `dismissNotification`).
  - Interactive Notification Bell and Drawer in `frontend/src/components/notifications/NotificationDrawer.tsx` connected to `TopBar.tsx`.
  - Navigation item with live unread counter badge in `frontend/src/components/layouts/Sidebar.tsx`.
  - Dedicated, filterable Notification Center page at `frontend/src/app/(dashboard)/notifications/page.tsx`.
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantNotificationTest.php` with 16 tests (92 assertions) covering authentication gates, listing & unread count, cross-tenant isolation, idempotent mark-as-read, mark-all-read, dismiss/archive, pagination & filter validation, alert deduplication, issue recurrence, sensitive data redaction, billing payment trigger, expired tenant suspension trigger, abuse incident creation trigger, DNS drift & recovery triggers, mailbox rule triggers, and mailbox toggle trigger.
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Isolation, Deduplication & Data Scrubbing): `VERIFIED`.
  - Level C (Multi-Channel Live Push / Webhooks): `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL`.
  - Full backend test suite passing: **506 tests, 2,526 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 28 static pages cleanly with zero errors**.

### Tenant Email Security & Deliverability Monitoring Dashboard (Step 36)
- **Implemented:** Unified Tenant Security & Deliverability Dashboard Service (`TenantSecurityDashboardService`):
  - Created centralized dashboard aggregation engine implementing `getDashboardData(User $user, ?Request $request = null)`.
  - Implemented `buildDomainSecuritySection`: Calculates deliverability scores and detects DNS drift strictly from persisted database flags (`mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `dns_drift_detected_at`). Zero live DNS queries dispatched on render.
  - Implemented `buildSmtpUsageSection`: Aggregates real-time daily quota from Redis via `TenantSmtpService::getUsage()`, fetches 14-day history trend from `TenantOutboundUsage`, computes 30-day delivery event summary (delivered, deferred, bounced, rejected) from `DeliveryLog`, and assesses quota thresholds (near-limit >= 80%, exhausted >= 100%).
  - Implemented `buildRecentActivitySection`: Bounded retrieval of latest 10 `DeliveryLog` records via `DeliveryLogResource` with strict tenant isolation.
  - Implemented `buildAbuseAndSecuritySection`: Queries tenant-scoped `AbuseIncident` records, counts open incidents, formats records via `TenantAbuseIncidentResource`, and synthesizes actionable warnings.
  - Implemented `buildDmarcReportingSection`: Exposes RFC 7489 XML parser status and truthfully reports automated inbox ingestion posture as `PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL`.
  - Implemented `getTenantIncidents`: Bounded pagination (1..50) with status and severity filters.
  - Per-section fault isolation: Wraps each section in independent try-catch returning explicit status contracts (`operational`, `warning`, `degraded`, `clean`, `empty`, `error`) and sanitized error messages.
- **Implemented:** Customer-Facing Incident Data Sanitization (`TenantAbuseIncidentResource`):
  - Strictly redacts internal traces, evidence blobs, internal file system paths, and administrative staff ID (`resolved_by`).
  - Synthesizes context-aware `suggested_remediation` per incident type (`consecutive_hard_bounces`, `daily_hard_bounce_threshold`, `high_hard_bounce_rate`, `quota_exhaustion`, `dns_drift`).
- **Implemented:** RESTful API Endpoints (`DashboardApiController` & `routes/api.php`):
  - Updated `GET /api/dashboard` returning the unified security, deliverability, quota, activity, and abuse telemetry while preserving 100% backwards compatibility (`user`, `domains_count`, `domain_count`, `mailboxes_count`, `mailbox_count`, `plan_info`, `recent_domains`, `recent_invoices`).
  - Added `GET /api/security/incidents` with bounded pagination (1..50), status, and severity filters.
  - Protected by `auth:sanctum` and `EnsureActiveSubscription`.
- **Implemented:** Responsive Next.js Tenant Monitoring Dashboard (`frontend/`):
  - Added TypeScript definitions in `types/index.ts` (`DomainSecuritySummary`, `DomainSecurityItem`, `DomainSecuritySection`, `SmtpDeliverySummary`, `SmtpUsageHistoryItem`, `SmtpUsageSection`, `DeliveryLogEvent`, `RecentActivitySection`, `TenantAbuseIncident`, `AbuseAndSecuritySection`, `DmarcReportingSection`, `DashboardStats`).
  - Added `useSecurityIncidents` hook and updated `useDashboard` with on-demand refresh in `hooks/useDashboard.ts`.
  - Transformed `/dashboard` (`page.tsx`) into a responsive, enterprise-grade security and deliverability monitoring dashboard:
    - Prominent alert banners for DNS drift, open abuse incidents, and quota warnings.
    - 4 KPI Stat Cards: Deliverability Health average, Sent Today / Quota, Authenticated Domains count, Open Incidents count.
    - Domain Authentication Table: MX, SPF, DKIM, and DMARC verification pills, deliverability health score progress bars, DNS drift badges, and direct links to `/domains/[id]/setup`.
    - SMTP Daily Sending Quota & 14-Day Outbound Volume Trend: Quota progress bar, delivery event breakdown (30-day window), and historical sending table.
    - Recent Outbound Activity: Delivery log event ledger showing sender, recipient, status, and bounce classification.
    - Security Incidents Ledger: Tenant-specific incident table with severity badges, status chips, and expandable suggested remediation steps.
    - DMARC Reporting Posture Card: Highlights XML report parser availability and transparently discloses pending automated inbox ingestion.
    - Preserved recent invoices billing section.
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantSecurityDeliverabilityDashboardTest.php` with 18 tests (144 assertions) covering authorization, subscription expiration gates, response structure, per-section statuses, zero live DNS queries on render, DNS drift detection, empty domains state, quota telemetry & 14-day history, quota near-limit & exhaustion warnings, graceful Redis degradation, delivery log tenant isolation & status discrimination, abuse incident tenant isolation, secret & path redaction, suggested remediation, DMARC reporting posture truthfulness, paginated tenant incidents, and backwards compatibility.
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Fault-Tolerance & Data Protection): `VERIFIED`.
  - Level C (Live Automated DMARC Inbound Collection): `BLOCKED — REQUIRES INFRASTRUCTURE & ARCHITECTURAL APPROVAL` (automated inbound IMAP/mail pipe requires dedicated Linux VPS infrastructure).
  - Full backend test suite passing: **490 tests, 2,434 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 27 static pages cleanly with zero errors**.


- **Implemented:** Inbound & Outbound Authentication Hardening in `DnsVerificationService`:
  - Enforced RFC 7208 SPF syntax analysis: prefix validation (`v=spf1`), valid mechanism checks, IPv4/IPv6 syntax checks, open-relay warning on `+all`, and DNS lookup mechanism count tracking to warn on exceeding the 10-lookup limit (RFC 7208 section 4.6.4).
  - Enforced RFC 6376 DKIM key checks: detected revoked keys (`p=` empty or missing), validated base64 key payloads, concatenated multi-chunk DNS packets, and added warning when key length is under 2048-bit.
  - Enforced RFC 7489 DMARC syntax & policy analysis: verified `v=DMARC1;` first tag, verified mandatory `p=` tag (`none`, `quarantine`, `reject`), detected duplicate `_dmarc` TXT records per RFC 7489 section 6.6.3 PermError, extracted optional tags (`sp`, `pct`, `rua`, `ruf`, `adkim`, `aspf`), and provided policy explanations.
  - Enforced RFC 5321 MX standards: prohibited bare IP addresses (RFC 5321 section 5.1) and warned on priority conflicts.
  - Implemented DNS Drift Detection: flags `dns_drift_detected: true` when an active domain loses required MX, SPF, or DKIM records.
  - Implemented Verification History Extraction (`getVerificationHistory`): queries immutable `audit_logs` where `action = 'domain.dns_verify'`, tracking score trends and record statuses over time without schema churn.
  - Implemented Deliverability Report Generator (`getDeliverabilityReport`): computes 4-pillar score breakdown (MX 40%, SPF 25%, DKIM 25%, DMARC 10%), policy advisor matrix, drift indicator, and explicit diagnostic disclaimer.
- **Implemented:** RFC 7489 DMARC XML Aggregate Report Parser (`DmarcReportParserService`):
  - Robust XML aggregate parser for `<feedback>` documents up to 5MB.
  - Neutralized XXE vulnerabilities using `LIBXML_NONET` and proactively rejecting `<!DOCTYPE` and `<!ENTITY` blocks before parsing.
  - Validates that report domain matches the tenant domain to prevent cross-tenant spoofing.
  - Computes deterministic SHA-256 deduplication digest over metadata and record contents.
  - Extracts org metadata, published policies, message counts, pass/fail rates, and source IP authenticity records.
  - Inbound collection posture: truthfully documents that automated ingestion via an IMAP poller or local mail pipe is `PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL`.
- **Implemented:** RESTful API & Security Boundaries (`DomainApiController`):
  - Added `GET /api/domains/{domain}/deliverability-report` (supports `?refresh=1`).
  - Added `POST /api/domains/{domain}/parse-dmarc-report` (supports file upload or raw XML string, throttled at `throttle:10,1`).
  - Zero private key exposure: verified that DKIM private keys are never stored in database tables, returned in API payloads, rendered in frontend code, or logged in `audit_logs`.
- **Implemented:** Next.js Tenant Dashboard UI (`frontend/`):
  - Added TypeScript definitions in `types/index.ts` (`DeliverabilityScoreBreakdown`, `VerificationHistoryEntry`, `DmarcPolicyAdvisor`, `DeliverabilityReportData`, `DmarcReportParserResponse`).
  - Added `useDomainDeliverabilityReport` and `parseDomainDmarcReport` in `hooks/useDomains.ts`.
  - Transformed `/domains/[id]/setup` (`DomainSetupPage.tsx`) into an enhanced authentication hardening hub:
    - DNS Drift warning alert banner.
    - Deliverability Health Scorecard with 4-pillar breakdown (MX, SPF, DKIM, DMARC) and legal/diagnostic disclaimer.
    - DMARC Policy Advisor card explaining `p=none` vs `p=quarantine` vs `p=reject` with escalation rules.
    - Verification History audit table showing previous check timestamps, scores, and per-record statuses.
    - DMARC Aggregate Report Inspector allowing tenants to upload or paste XML feedback reports and view detailed message counts, pass/fail rates, and source IP authenticity.
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantDomainDeliverabilityReportTest.php` with 24 tests (105 assertions) covering authorization, verification logic, SPF syntax & duplicates, DKIM revocation & key size, DMARC duplicates & tag parsing, DMARC policy advisor, MX bare IP prohibition, DNS drift, verification history extraction, DMARC XML parser (valid, XXE, oversized, domain mismatch), and secret redaction.
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Configuration & Protocol Logic): `VERIFIED`.
  - Level C (End-to-End Live DNS & Inbound DMARC Collection): `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services and public DNS zone delegation; requires dedicated Linux VPS).
  - Full backend test suite passing: **472 tests, 2,290 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 27 static pages cleanly with zero errors**.


### Domain DNS Records Wizard, Inbound Authentication & Deliverability Diagnostics (Step 34)
- **Implemented:** DnsVerificationService Multi-Record Verification Engine (`app/Services/DnsVerificationService.php`):
  - Injected mock resolver architecture (`setResolver(?callable $resolver)`) for 100% deterministic, offline automated testing.
  - Multi-record verification routines: `verifyMx()`, `verifySpf()`, `verifyDkim()`, `verifyDmarc()`, `verifyA()`, `verifyCname()`, `verifyAll()`, and `getDnsWizardData()`.
  - RFC 7208 section 3.2 PermError detection: Flags multiple `v=spf1` TXT records as `MISMATCH` and generates consolidation recommendations.
  - 2048-bit DKIM DNS packet chunk concatenation via `extractTxtContent()`, merging string chunks from PHP `$record['entries']`.
  - Resilient DKIM key payload isolation via `extractDkimKeyPayload()`, normalizing keys and stripping whitespace/semicolons.
  - Weighted Deliverability Health Score algorithm (MX 40%, SPF 25%, DKIM 25%, DMARC 10%) producing actionable ratings (`EXCELLENT`, `GOOD`, `FAIR`, `POOR`).
  - Three-layer diagnostics telemetry: Reports DNS publication, application database configuration, and live Postfix MTA readiness independently.
  - Actionable recommendations generator: Computes human-friendly remediation guidance for missing or misconfigured records.
- **Implemented:** RESTful Domain DNS Wizard & Verification API (`app/Http/Controllers/Api/DomainApiController.php`):
  - `GET /api/domains/{domain}/dns-wizard`: Returns all required/recommended DNS records, verification statuses, deliverability score, diagnostic breakdown, and actionable recommendations.
  - `POST /api/domains/{domain}/verify-dns`: Performs on-demand multi-record DNS query, updates verification flags on `domains`, updates `last_dns_check_at`, activates pending domains when core records pass, and emits `domain.dns_verify` audit log.
  - `POST /api/domains/{domain}/verify`: Preserved as a backwards-compatible alias to `verifyDns()`.
  - Enforced rate limiting: Protected by `throttle:30,1` against abuse and external resolver exhaustion.
  - Gated by `auth:sanctum`, `DomainPolicy` (`view`/`update`), and `EnsureActiveSubscription`.
- **Implemented:** Domain Model & Resource Enhancements:
  - `Domain::requiredDnsRecords()` generates structured record definitions (`id`, `type`, `host`, `value`, `priority`, `purpose`, `is_required`, `status`, `verified`).
  - `DomainResource` exposes `last_dns_check_at`.
- **Implemented:** Next.js Tenant Dashboard UI (`frontend/`):
  - Added TypeScript definitions in `types/index.ts` (`DnsRecordType`, `DnsVerificationStatus`, `DnsRecord`, `DeliverabilityScore`, `InboundRoutingDiagnostics`, `OutboundAuthDiagnostics`, `InfrastructureDiagnostics`, `DomainDnsWizardData`, `DnsVerifyResponse`).
  - Added `useDomainDnsWizard` and `verifyDns` hooks in `hooks/useDomains.ts`.
  - Transformed `/domains/[id]/setup` (`DomainSetupPage.tsx`) into a full-featured DNS Wizard & Deliverability Diagnostics dashboard:
    - Deliverability Health Scorecard with visual progress bar, status badges, and sub-score metrics.
    - Multi-layer environment notice separating DNS publication from live MTA state.
    - Diagnostics cards for Inbound MX Routing and Outbound SPF/DKIM/DMARC Authentication.
    - Actionable Recommendations alert with concrete troubleshooting steps.
    - Upgraded `DnsRecordTable.tsx` with copy buttons, record purpose, priority columns, status badges (`VERIFIED`, `MISMATCH`, `MISSING`, `CHECK_FAILED`, `NOT_CONFIGURED`, `EXPECTED`), and expandable troubleshooting drawer showing expected vs current values.
  - Updated `/domains/[id]` domain details page with "DNS Wizard & Diagnostics" button.
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantDomainDnsWizardTest.php` with 24 tests (211 assertions) covering authorization, verification logic, RFC 7208 PermError handling, DKIM chunking, deliverability scoring, rate limiting, and audit logging.
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Configuration & Resolver Logic): `VERIFIED`.
  - Level C (End-to-End Live Mail Flow & Public DNS): `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services and public DNS zone delegation; requires dedicated Linux VPS).
  - Full backend test suite passing: **448 tests, 2,185 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 27 static pages cleanly with zero errors**.

### Tenant Email Forwarding Rules & Auto-Reply Management (Step 33)
- **Implemented:** Database Schema & Mailbox Entity Integration:
  - Created migration `2026_10_17_000002_add_forwarding_and_auto_reply_to_mailboxes_table.php` adding 8 columns to `mailboxes`: `is_forwarding_enabled` (boolean), `forwarding_destinations` (text), `forwarding_keep_copy` (boolean), `auto_reply_enabled` (boolean), `auto_reply_subject` (varchar 255), `auto_reply_body` (text), `auto_reply_start_at` (timestamp), `auto_reply_end_at` (timestamp).
  - Updated `Mailbox` model with `$fillable`, `$casts`, `getForwardingDestinationsList()`, `setForwardingDestinationsList()`, `isAutoReplyActive()`, and `autoReplyScheduleStatus()`.
  - Updated `MailboxResource` exposing `is_forwarding_enabled` and `auto_reply_enabled`.
- **Implemented:** Mailbox Rule Service & Dovecot Sieve Pigeonhole Script Generation (`app/Services/MailboxRuleService.php`):
  - Compliant RFC 5228 and RFC 5230 Sieve vacation script generation with `:days 1` rate limiting per sender.
  - Sieve loop suppression: automatically skips automated responses for headers matching `Precedence: bulk|list|junk`, `Auto-Submitted`, `List-Id`, and empty `Return-Path: <>`.
  - Date window evaluation using Dovecot date extension (`date :value "currentdate" :zone "+0000" "iso8601"`).
  - Direct self-forwarding validation and recursive cycle detection traversing both mailbox forwardings and virtual aliases up to 30 hops.
  - Safe `.dovecot.sieve` script synchronization to mail storage with local simulation fallback.
- **Implemented:** Postfix Forwarding Integration & SQL Mapping:
  - Created `postfix-config/mysql-virtual-forwardings.cf` and `server-configs/mysql-virtual-forwardings-maps.cf`.
  - Registered `mysql-virtual-forwardings.cf` in `virtual_alias_maps` inside `postfix-config/main.cf` and `server-configs/main-cf-snippet.txt` with precedence order: `mysql-virtual-aliases.cf` -> `mysql-virtual-forwardings.cf` -> `mysql-virtual-catchall.cf`.
  - Query dynamically expands comma-separated destinations and prepends local address when `forwarding_keep_copy = 1`.
- **Implemented:** RESTful Mailbox Rules API (`app/Http/Controllers/Api/MailboxRuleApiController.php`):
  - `GET /api/mailboxes/{mailbox}/rules`: Retrieves forwarding settings, auto-reply settings, compiled Sieve preview, and MTA routing telemetry.
  - `PUT /api/mailboxes/{mailbox}/forwarding`: Updates forwarding state, destinations (up to 10), and keep-copy option with self-forwarding and loop validation.
  - `PUT /api/mailboxes/{mailbox}/auto-reply`: Updates vacation auto-responder with HTML sanitization (`strip_tags`) and recompiles Sieve script.
  - Protected by `auth:sanctum`, `MailboxPolicy` (`view`/`update`), active parent domain checks, and `EnsureActiveSubscription`.
  - Persistent audit logging: Records `mailbox.forwarding_update` and `mailbox.auto_reply_update` via `AuditService::record()`.
- **Implemented:** Operator CLI Command (`app/Console/Commands/VerifyMailboxRulesCommand.php`):
  - `php artisan mail:verify-mailbox-rules {mailbox?} {--all}` with detailed tabular diagnostics on forwarding, auto-reply, Sieve, and MTA status.
- **Implemented:** Next.js Tenant Dashboard UI (`frontend/`):
  - Added TypeScript definitions in `types/index.ts` (`MailboxForwardingSettings`, `MailboxAutoReplySettings`, `MailboxSieveSettings`, `MailboxMtaSettings`, `MailboxRulesDetails`, `ForwardingUpdateRequest`, `AutoReplyUpdateRequest`).
  - Added `useMailboxRules`, `updateMailboxForwarding`, and `updateMailboxAutoReply` hooks in `hooks/useMailboxes.ts`.
  - Created `MailboxRulesModal.tsx` with Forwarding tab, Auto-Reply tab, and Sieve & MTA Diagnostics tab.
  - Enhanced `MailboxRow.tsx` with `Fwd` and `Auto-Reply` status badges and settings trigger button (`SlidersHorizontal`).
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantMailboxForwardingAndAutoReplyTest.php` with 20 tests (67 assertions) covering authorization, validation, loop detection, keep copy, Sieve compilation, schedule states, XSS sanitization, and audit logs.
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Configuration & SQL Maps): `VERIFIED`.
  - Level C (End-to-End Live Mail Flow): `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services; requires dedicated Linux VPS).
  - Full backend test suite passing: **424 tests, 1,974 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 27 static pages cleanly with zero errors**.

### Tenant Catch-All Mailbox & Inbound Email Routing Rules (Step 32)
- **Implemented:** Database Schema & Domain Entity Integration:
  - Created migration `2026_10_17_000001_add_catch_all_fields_to_domains_table.php` adding `catch_all_policy` (varchar(32), default `'disabled'`) and `catch_all_mailbox_id` (foreignId to `mailboxes`, nullable, `nullOnDelete()`) to `domains` table.
  - Updated `Domain` model with `$fillable`, `catchAllMailbox(): BelongsTo`, and `isCatchAllEnabled(): bool`.
  - Updated `Mailbox` model with `isCatchAll(): bool`.
  - Updated `DomainResource` to expose `catch_all_policy` and `catch_all_mailbox_id`.
  - Updated `MailboxApiController::destroy()` to automatically clear domain catch-all settings if the designated destination mailbox is deleted.
- **Implemented:** Postfix Routing Configuration & Precedence Mapping:
  - Created `postfix-config/mysql-virtual-catchall.cf` and `server-configs/mysql-virtual-catchall-maps.cf`.
  - Registered `mysql-virtual-catchall.cf` in `virtual_alias_maps` inside `postfix-config/main.cf` and `server-configs/main-cf-snippet.txt`.
  - Strictly enforced SQL precedence: Query ensures `NOT EXISTS (SELECT 1 FROM mailboxes WHERE email = '%s' ...)` and `NOT EXISTS (SELECT 1 FROM email_aliases WHERE source_email = '%s' ...)`, guaranteeing physical mailboxes and explicit aliases are never intercepted or overridden.
  - Implemented discard-sink routing to `devnull@localhost` when `catch_all_policy = 'discard'`.
- **Implemented:** Postfix Routing Service & CLI Diagnostics:
  - Added `verifyCatchAllRouting(Domain $domain, ?string $probeLocalPart = null)` in `app/Services/PostfixService.php`, computing operational status (`synchronized`, `operational`, `configured`, `failed`), probe email address, probe resolution, and MTA driver status.
  - Added `syncCatchAll(Domain $domain)` and `disableCatchAll(Domain $domain)` lifecycle synchronization hooks.
  - Created `php artisan mail:verify-catchall {domain?} {--all}` command with comprehensive tabular diagnostics.
- **Implemented:** RESTful Catch-All API (`app/Http/Controllers/Api/CatchAllApiController.php`):
  - `GET /api/domains/{domain}/catch-all`: Retrieves policy, destination mailbox, eligible destination mailboxes, and live routing details.
  - `PUT /api/domains/{domain}/catch-all`: Atomically updates domain catch-all policy and destination mailbox with strict validation (policy allowlist, verified/active domain check, active tenant subscription check via `EnsureActiveSubscription`, destination mailbox ownership and active check).
  - Mutex & transactional synchronization of `mailboxes.is_catchall` flags.
  - Immutable audit trail: Emits `domain.catch_all_update` events via `AuditService::record()`.
- **Implemented:** Next.js Tenant Experience (`frontend/`):
  - Added TypeScript definitions in `types/index.ts` (`CatchAllPolicy`, `CatchAllMailbox`, `CatchAllRoutingDetails`, `DomainCatchAllDetails`, `CatchAllUpdateRequest`).
  - Created `useDomainCatchAll` and `updateCatchAll` SWR hooks in `hooks/useDomains.ts`.
  - Created `CatchAllSettingsCard.tsx` with policy radio options (`disabled`, `reject`, `mailbox`, `discard`), active mailbox dropdown selector, high-risk discard alert, spam surge warning, and live routing status badges.
  - Integrated `CatchAllSettingsCard` directly on the domain details page (`/domains/[id]`).
- **Automated Verification & Three-Level Matrix:**
  - Created `tests/Feature/TenantCatchAllManagementTest.php` with 19 comprehensive tests (73 assertions) covering authentication, subscription gating, domain ownership, policy validations, mailbox assignment, alias precedence, probe routing simulation, and cascade resets.
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Configuration & SQL Maps): `VERIFIED`.
  - Level C (End-to-End Live Mail Flow): `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services; requires dedicated Linux VPS).
  - Full backend test suite passing: **404 tests, 1,907 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 27 static pages cleanly with zero errors or warnings**.

### Tenant Mailbox Storage & Quota Management (Step 31)
- **Implemented:** Mailbox Storage Service Architecture (`app/Services/MailboxStorageService.php`):
  - Encapsulated disk usage measurement with support for `doveadm quota get -u <email>`, physical `maildirsize` header parsing, mock test resolvers, and safe Windows environment fallbacks.
  - Calculated exact telemetry attributes: `quota_mb`, `quota_bytes`, `usage_bytes`, `usage_mb`, `remaining_bytes`, `utilization_percent`, `status`, and `status_label`.
  - Implemented quota status thresholds: `normal` (< 80%), `warning` (80% - 89.9%), `near_limit` (90% - 99.9%), `over_limit` ($\ge 100\%$), or `unknown` (unmeasured / Dovecot offline).
  - Built unlimited quota support (`quota_mb = -1` or `NULL`) avoiding division by zero.
  - Implemented tenant-wide summary aggregation: total mailboxes, measured mailboxes, unmeasured mailboxes, provisioned storage, plan storage limit, total measured usage, overall utilization, and status count distribution.
  - Enforced truthful telemetry invariant: unmeasured storage is never reported as 0 bytes or 0%; always reports `is_measured = false`, `usage_bytes = null`, and `status = 'unknown'`.
- **Implemented:** RESTful Endpoints & Resource Integration (`MailboxApiController`, `MailboxResource`):
  - `GET /api/mailboxes/storage`: Tenant overview and per-mailbox breakdown with optional `status`, `domain_id`, and `search` filters.
  - `GET /api/mailboxes/{mailbox}/storage`: Single mailbox storage telemetry protected by `MailboxPolicy`.
  - Automatically embedded `storage` telemetry object inside `MailboxResource` (`/api/mailboxes`, `/api/mailboxes/{id}`).
- **Implemented:** Dovecot Dynamic SQL Quota Rule Configuration:
  - Updated `server-configs/dovecot-sql.conf.ext` and `dovecot-config/dovecot-sql.conf.ext` to include `CONCAT('*:bytes=', (mailboxes.quota_mb * 1048576)) AS quota_rule` in `user_query`.
  - Modernized Dovecot SQL queries to enforce `mailboxes.is_active = 1`, `domains.status = 'active'`, and `deleted_at IS NULL` for SoftDeletes compatibility.
- **Implemented:** Next.js Tenant Dashboard UI Enhancements (`frontend/`):
  - Added `MailboxStorageStatus`, `MailboxStorageDetails`, `TenantStorageSummary`, `TenantStorageOverviewResponse` types in `types/index.ts`.
  - Added `useMailboxStorageOverview(filters)` and `useMailboxStorage(mailboxId)` SWR hooks in `hooks/useMailboxes.ts`.
  - Added Storage Overview KPI cards at `/mailboxes` (Provisioned Mailboxes, Provisioned Storage vs Plan Limit, and Storage Telemetry status).
  - Added storage status filter options (`over_limit`, `near_limit`, `warning`, `normal`, `unknown`) to the Mailbox filter bar.
  - Enhanced `MailboxRow.tsx` with storage progress bar, colored warning badges, or `Unmeasured` badge when Dovecot is offline, plus trigger to open modal.
  - Built `MailboxStorageModal.tsx` providing a detailed breakdown of usage, quota ceiling, remaining capacity, provenance source, and measured timestamp.
- **Automated Verification & Three-Level Matrix**:
  - Created `tests/Feature/TenantMailboxStorageTest.php` with 14 comprehensive tests (66 assertions).
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Configuration & SQL Maps): `VERIFIED`.
  - Level C (End-to-End Live Mail Flow): `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Dovecot daemon and `/var/vmail` filesystem; requires dedicated Linux VPS).
  - Full backend test suite passing: **385 tests, 1,834 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Frontend TypeScript typecheck: **`npx tsc --noEmit` passed with 0 errors**.
  - Frontend production build: **Next.js 14 compiles and optimizes all 27 static pages with zero errors or warnings**.

### Email Alias Routing Integration & Mail Flow Verification (Step 30)
- **Implemented:** Postfix Dynamic MySQL Map Configuration Alignment:
  - Modernized `postfix-config/mysql-virtual-aliases.cf` and created canonical `server-configs/mysql-virtual-alias-maps.cf` querying `email_aliases` joined with `domains` with `email_aliases.is_active = 1`, `domains.status = 'active'`, and `domains.deleted_at IS NULL`.
  - Modernized `postfix-config/mysql-virtual-domains.cf`, `postfix-config/mysql-virtual-mailboxes.cf`, and `postfix-config/mysql-virtual-sender-login-maps.cf` to enforce `status = 'active'` and `deleted_at IS NULL` for SoftDeletes compatibility.
  - Uncommented `virtual_alias_maps` in `server-configs/main-cf-snippet.txt`.
- **Implemented:** Postfix Routing Service Engine (`app/Services/PostfixService.php`):
  - Added `syncAlias(EmailAlias $alias): bool` and `removeAlias(EmailAlias $alias): bool`.
  - Added `verifyAliasRouting(string $sourceEmail): array`: evaluates real-time Postfix MySQL map query, traces recursive expansion chains up to 30 hops, detects cycle loops, verifies terminal recipient mailbox existence for local domains, and returns comprehensive routing details.
  - Added `isPostfixOperational(): bool`: detects live Postfix MTA socket or process state.
- **Implemented:** Controller Lifecycle Integration (`app/Http/Controllers/Api/AliasApiController.php`):
  - Injected `PostfixService` into `store`, `update`, `toggle`, and `destroy` operations.
- **Implemented:** Dynamic API Resource Telemetry (`app/Http/Resources/EmailAliasResource.php`):
  - Evaluates dynamic `forwarding_status`: `synchronized` (map valid & routable), `operational` (live MTA active), `configured` (disabled or pending domain), `failed` (dangling destination or loop), `unknown` (missing record or deleted domain).
  - Supplies `routing_details` payload (`is_routable`, `source_email`, `destination`, `terminal_target`, `status`, `hops`, `hop_count`, `cycle_detected`, `warning`, `mta_driver`).
- **Implemented:** Next.js Tenant UI Enhancements (`frontend/`):
  - Updated `types/index.ts` with `EmailAliasRoutingDetails` and updated `forwarding_status` union type.
  - Enhanced `AliasRow.tsx` with color-coded status badges (Emerald for operational, Blue for synchronized, Amber for unknown, Gray for configured, Red for failed), hop count chips, and hover tooltips for routing warnings.
  - Updated `/aliases` header with routing verification status legend.
- **Implemented:** Operator CLI Tooling:
  - `php artisan mail:verify-alias {source_email?} {--all}` with detailed tabular output of alias resolution, hop chains, and routing integrity.
- **Automated Verification & Three-Level Matrix**:
  - Created `tests/Feature/TenantAliasRoutingIntegrationTest.php` with 8 comprehensive tests (44 assertions).
  - Level A (Application & API Layer): `VERIFIED`.
  - Level B (Configuration & SQL Maps): `VERIFIED`.
  - Level C (End-to-End Live Mail Flow): `BLOCKED — REQUIRES INFRASTRUCTURE` (local Windows + Laragon development environment lacks Linux Postfix/Dovecot services; requires dedicated Linux VPS).
  - Full backend test suite passing: **371 tests, 1,768 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Full frontend production build passing: **Next.js 14 compiles and optimizes all 27 static pages with zero errors or warnings**.

### Tenant Email Aliases & Forwarding Management (Step 29)
- **Implemented:** Standalone `EmailAlias` PSR-4 Model & Authorization Policy:
  - Extracted `App\Models\EmailAlias` into its own model file (`app/Models/EmailAlias.php`) with domain relationship, tenant scoping (`scopeForTenant`), search scope (`scopeSearch`), and status scope (`scopeByStatus`).
  - Created `App\Policies\EmailAliasPolicy` and registered in `AuthServiceProvider` to strictly enforce tenant boundary isolation across `view`, `create`, `update`, and `delete`.
  - Added `aliases(): HasManyThrough` and `canAddAlias(Domain $domain): bool` methods to `User` model, enforcing plan `max_aliases_per_domain` constraint.
  - Added `maxAliasesLabel(): string` helper to `Plan` model.
- **Implemented:** RESTful Alias API (`App\Http\Controllers\Api\AliasApiController`):
  - `GET /api/aliases`: Tenant-wide paginated alias list (bounded 1-50) with search and status/domain filters.
  - `GET /api/domains/{domain}/aliases`: Domain-scoped alias list with policy authorization.
  - `POST /api/aliases`: Create alias with local-part or full source email.
    - Strictly enforces active verified domain (`status === 'active'`; 422 if pending).
    - Strictly enforces active tenant subscription (422 if inactive/suspended).
    - Strictly enforces plan limit `max_aliases_per_domain` (403 if exceeded).
    - Prevents collision with existing physical mailboxes (`mailboxes.email`; 422).
    - Prevents duplicate aliases on the same domain (422).
    - Cycle detection: rejects direct self-forwarding (`source === destination`; 422) and circular forwarding chains (422).
  - `PUT /api/aliases/{alias}`: Update forwarding destination with cycle detection and immutable source email.
  - `POST /api/aliases/{alias}/toggle`: Toggle active/disabled status. Disabling always permitted; enabling strictly requires active domain and active subscription.
  - `DELETE /api/aliases/{alias}`: Permanently delete alias; cascades cleanly on domain deletion.
  - `App\Http\Resources\EmailAliasResource`: Serializes alias details with truthful `forwarding_status: "configured"` attribute.
- **Implemented:** Audit Trail Integration:
  - All alias lifecycle events (`alias.create`, `alias.update`, `alias.toggle`, `alias.delete`) are recorded in append-only `audit_logs` via `AuditService`.
- **Implemented:** Next.js Tenant Email Aliases Experience (`frontend/`):
  - **Sidebar**: Added `Email Aliases` navigation link (`/aliases`) with `ArrowRightLeft` icon.
  - **Types**: Added `EmailAlias` interface in `types/index.ts`.
  - **Hooks**: Created `useAliases.ts` with SWR-powered `useTenantAliases`, `useDomainAliases`, and async action helpers (`createAlias`, `updateAlias`, `toggleAlias`, `deleteAlias`).
  - **UI Page (`/aliases`)**: Search bar, domain selector, status filter, zero-overhead explanation banner, empty state guidance, responsive table, and bounded pagination.
  - **Modals**:
    - `CreateAliasModal`: Active verified domain picker, source local-part input with live `@domain` suffix preview, destination email input, active toggle, loop warning, and Postfix status disclosure.
    - `EditAliasModal`: Edit destination email with loop detection and immutable source address notice.
    - `AliasRow`: Displays source email, destination target, status badge (`Active`/`Disabled`), forwarding pill (`Configured`), quick toggle button, edit modal trigger, and delete confirmation.
- **Automated Verification & Test Coverage**:
  - Created `tests/Feature/TenantAliasManagementTest.php` with 21 comprehensive tests (76 assertions) covering unauthenticated blocking, suspended tenant gating, tenant-wide & domain-scoped listing, local-part vs full email source parsing, domain mismatch rejections, unverified domain rejection, cross-tenant isolation, plan limits, mailbox address collisions, duplicate aliases, direct and circular forwarding loop detection, destination update, active toggle invariants, and domain cascade deletion.
  - Full backend test suite passing: **363 tests, 1,724 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Full frontend production build passing: **Next.js 14 compiles and optimizes all 27 static pages with zero errors or warnings**.
  - Git working tree verified: Zero git staging, zero commits, zero pushes.

### Tenant SMTP Access, Sending Activity & Delivery Logs (Step 28)
- **Implemented:** Backend Service Layer & REST API Endpoints:
  - Created `App\Services\TenantSmtpService` managing connection parameters, live Redis/MariaDB quota telemetry, credentials lifecycle, password rotation, activity logs, and account diagnostics.
  - Created `App\Http\Controllers\Api\TenantSmtpApiController` exposing 6 RESTful endpoints protected by `auth:sanctum` and `EnsureActiveSubscription`:
    - `GET /api/smtp/settings`: Hostname, supported ports (587, 465, 25), TLS 1.2+ policy, Dovecot SASL specification, copyable code snippets (Node.js, Python, PHP, .env).
    - `GET /api/smtp/usage`: Live daily recipient usage from Redis, remaining allowance, plan quota ceiling, 14-day history, and individual mailbox breakdown.
    - `GET /api/smtp/credentials`: List authorized SMTP sending identities (mailboxes) under active domains, with zero hash or password exposure.
    - `POST /api/smtp/credentials/{mailbox}/rotate-password`: Generates cryptographically secure 16-character password, hashes with Dovecot SHA512-CRYPT, audit logs the event, and returns generated password strictly once in response.
    - `GET /api/smtp/activity`: Bounded paginated delivery activity logs (1-50 per page) with status filtering (`delivered`, `deferred`, `bounced`, `rejected`), recipient/sender search, and date filters.
    - `GET /api/smtp/diagnostics`: Account-level self-service health checks (subscription, domains, mailboxes, quota headroom, deliverability) with zero SSRF risk.
- **Implemented:** Database Schema & Pipeline Ingestion:
  - Created migration `2026_10_16_000001_create_delivery_logs_table.php` and `App\Models\DeliveryLog`.
  - Created `App\Http\Resources\DeliveryLogResource` with centralized response text sanitization (stripping internal server paths and sensitive fragments).
  - Integrated with `AbuseDetectionService::processEvent`: downstream delivery events parsed by `mail:process-log` daemon are automatically recorded in `delivery_logs`.
  - Integrated with `PolicyDecisionService::recordQuotaAbuseIncident`: outbound quota and policy rejections are automatically recorded in `delivery_logs`.
- **Implemented:** Next.js Tenant SMTP Access Hub (`frontend/src/app/(dashboard)/smtp/page.tsx`):
  - Added "SMTP Access" link with `Send` icon in `Sidebar.tsx`.
  - Built tabbed interface:
    - **Settings & Code**: Copyable hostname, port reference table, security requirements, multi-language code snippets with language selector and one-click copy.
    - **Quota & Usage**: Real-time KPI stat cards, dynamic utilization progress bar, 14-day sending trend table, and mailbox-level usage breakdown table.
    - **Credentials**: Table of authorized mailboxes under active domains, active status badges, and secure "Rotate Password" modal with Dovecot SHA512-CRYPT generation and strict one-time reveal notice.
    - **Activity Logs**: Filterable delivery table (search, status dropdown, pagination), colored outcome badges (`delivered`, `deferred`, `bounced`, `rejected`), and sanitized response snippets.
    - **Diagnostics & Troubleshooting**: Automated diagnostic checks with pass/warning/fail badges and remediation instructions, plus an RFC error resolution table (535, 554, 451/452, 550).
- **Automated Verification & Test Coverage**:
  - Created `tests/Feature/TenantSmtpManagementTest.php` with 10 comprehensive tests (216 assertions) covering unauthenticated blocking, suspended tenant gating, settings resolution, quota telemetry, credential listing without hash exposure, password rotation with one-time reveal and SHA512-CRYPT verification, cross-tenant isolation, activity log filtering, and safe diagnostics.
  - Full backend test suite passing: **342 tests, 1,648 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Full frontend production build passing: **Next.js 14 compiles and optimizes all 26 static pages with zero errors or warnings**.
  - Git working tree verified: Zero git staging, zero commits, zero pushes.

### Tenant Domain & Mailbox Self-Service Management (Step 27)
- **Implemented:** Dedicated Mailbox Policy & RBAC Ownership Verification:
  - Created `App\Policies\MailboxPolicy` and registered in `AuthServiceProvider`. Enforces strict tenant isolation across view, create, update, and delete actions.
  - Implemented `GET /api/mailboxes` for tenant-wide mailbox listing across all customer domains with bounded pagination (1-50), search, status, and domain filtering.
  - Implemented `GET /api/mailboxes/{mailbox}` and `PUT /api/mailboxes/{mailbox}` (safe fields only: `display_name`).
- **Hardened:** Domain & Mailbox Creation and Lifecycle Invariants:
  - Domain creation (`POST /api/domains`): automatic domain case normalization (lowercase & trim) before validation, uniqueness check, plan domain limit enforcement.
  - Domain verification (`POST /api/domains/{id}/verify`): truthful reporting of verification status (`verified: boolean`, descriptive message, record results, refreshed domain object).
  - Domain deletion (`DELETE /api/domains/{id}`): transactional cascade deletion of associated mailboxes and aliases before domain removal.
  - Mailbox creation (`POST /api/domains/{id}/mailboxes`): strictly requires verified domain (`domain.status === 'active'`; returns HTTP 422 if unverified/pending), enforces plan storage cap (`quota_mb <= storage_mb_per_mailbox`), hashes passwords with SHA512-CRYPT for Dovecot, and redacts passwords from API responses.
  - Mailbox toggle (`POST /api/mailboxes/{id}/toggle`): disabling is always permitted for security/quarantine; enabling strictly requires parent domain is active and tenant subscription is active (HTTP 422 if violated).
  - PostfixService script execution: simulated fallback in local and testing environments when `/opt/emailsaas/scripts` is absent, preventing local Windows test failures while maintaining Linux daemon execution.
- **Implemented:** Next.js Tenant Domain & Mailbox Self-Service Experience (`frontend/`):
  - **Sidebar Navigation**: Added Mailboxes navigation link (`/mailboxes`) for customer tenants.
  - **Tenant Mailboxes Hub (`/mailboxes`)**: Search bar, domain selector filter, active/disabled filter, mailboxes table, Webmail SSO links, and pagination.
  - **Domain Mailbox Management (`/domains/[id]`)**: Embedded active mailbox management under domain detail view when verified; clear guidance and setup link when unverified.
  - **Modals**: Added `CreateMailboxModal` (with multi-domain selector, plan quota hints, password validation), `EditMailboxModal` (display name editing), and `ChangePasswordModal` (min 8 chars, SHA512-CRYPT hashed).
  - **DNS Verification Feedback (`/domains/[id]/setup`)**: Truthful UI feedback distinguishing verified active domains from pending DNS propagation.
- **Automated Verification & Test Coverage**:
  - Created `tests/Feature/TenantDomainAndMailboxManagementTest.php` with 21 feature tests (86 assertions) covering domain listing, normalization, limits, DNS verification reporting, cascade deletion, mailbox listing, verified domain gating, plan storage limits, password hashing & redaction, display name updates, toggle invariants, and cross-tenant isolation.
  - Full backend test suite passing: **332 tests, 1,432 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Full frontend production build passing: **Next.js 14 compiles and optimizes all 25 static pages with zero errors or warnings**.
  - Git working tree verified: Zero git staging, zero commits, zero pushes.

### Tenant Self-Service Billing & Subscription Management (Step 26)
- **Implemented:** Dedicated Tenant Subscription API (`GET /api/billing/subscription`):
  - Returns authoritative plan configuration, lifecycle state (`active`, `expired`, `suspended`, `inactive`), countdown remaining days, `is_expiring_soon` flag (<= 7 days), and `can_renew` capability.
  - Returns live quota consumption metrics: domains used/limit, mailboxes used/limit, today's outbound recipient usage from `tenant_outbound_usage`, daily outbound limit, and per-mailbox storage ceiling.
  - Placed outside `EnsureActiveSubscription` middleware so expired or suspended tenants can view their subscription status and initiate renewal.
- **Enhanced:** Invoice APIs (`BillingApiController` & `routes/api.php`):
  - Enhanced `GET /api/billing/invoices` with bounded pagination (`per_page` clamped between 1 and 50) and validated status filtering (`paid`, `pending`, `failed`, `cancelled`).
  - Implemented `GET /api/billing/invoices/{invoice}` for single invoice inspection, governed by `InvoicePolicy::view` (tenant owner or administrator with `admin.invoices.read` permission).
  - Added `mailboxes(): HasManyThrough` relationship to `User` model for efficient mailbox queries.
- **Implemented:** Next.js Tenant Self-Service Billing Experience (`frontend/`):
  - **Billing Dashboard (`/billing`)**: Displays real-time subscription posture, warning banners for expired/expiring/suspended subscriptions, resource usage progress bars (domains, mailboxes, daily outbound emails, storage), one-click renewal flow, plan change action, and payment gateway callback feedback alerts (`?status=success|failed|cancelled`).
  - **Invoice Ledger (`InvoiceTable.tsx` & `InvoiceDetailModal.tsx`)**: Bounded pagination controls, status filtering, printable invoice detail modal layout, and direct PDF download links.
  - **Plans & Pricing (`/billing/plans` & `PlanCard.tsx`)**: Monthly/yearly billing switcher, clear feature matrices with daily outbound recipient limits and currency formatting (`BDT`), current plan badges, and SSLCommerz checkout redirect with active loading state.
- **Automated Verification & Test Coverage**:
  - Added `tests/Feature/TenantBillingManagementTest.php` with 12 feature tests covering subscription states, expiring-soon alerts, expired/suspended access, bounded invoice pagination, status filters, ownership policy enforcement, and SSLCommerz payment initiation.
  - Full backend test suite passing: **311 tests, 1,346 assertions passed (100% clean, 0 failures, 0 errors)**.
  - Full frontend production build passing: **Next.js 14 compiles and optimizes all 24 static pages with zero errors or warnings**.
  - Git working tree verified: Zero git staging, zero commits, zero pushes.

### Final Local Integration Audit, Dummy Data Seeding & Security Verification (Step 25)
- **Implemented:** Dedicated Local Dummy Data Seeder Tooling (`SeedAuditDemoCommand`):
  - Created `App\Console\Commands\SeedAuditDemoCommand` (`php artisan emailsaas:seed-audit-demo`) supporting `--dry-run` and `--cleanup`.
  - Enforced strict local-only execution guard: refuses execution when `app()->environment()` is not `local`.
  - Enforced strict local database host guard: verifies host is `127.0.0.1` or `localhost`, port is `3306`, and database is `emailsaas_local`. Strictly halts on remote production IP `103.131.144.61`.
  - Guaranteed idempotency: re-running reuses existing records with 0 duplicate insertions.
  - Seeded controlled discrepancy scenarios:
    - Active tenant with valid subscription (`audit-demo-active@example.test`)
    - Active tenant with expired subscription (`audit-demo-expired@example.test`)
    - Suspended tenant (`audit-demo-suspended@example.test`)
    - Pending tenant without assigned plan (`audit-demo-noplan@example.test`)
    - Over-quota tenant with 550 recipient usage on 500-recipient starter plan (`audit-demo-overquota@example.test`)
    - Active, pending, and suspended domains (`audit-demo-*.example.test`)
    - Active, inactive, restricted-quota, and bulk mailboxes
    - Paid, pending, failed, and cancelled invoice records
    - Open and resolved abuse incidents with evidence snapshots
    - Authentic audit log entries with recursive secret scrubbing
  - Implemented safe cleanup (`--cleanup`) strictly purging records in the explicit synthetic registry (`audit-demo-` prefix, `@example.test` domain) in safe foreign key sequence. Preserves baseline canonical records (`test@example.com`, `admin@example.com`, standard plans).
- **Verified:** 13-Point Security & Authorization Matrix:
  - Unauthenticated access returns HTTP 401.
  - Ordinary tenants accessing admin endpoints return HTTP 403.
  - Cross-tenant resource access returns HTTP 403.
  - Suspended tenants are blocked across protected endpoints.
  - Tenants without plans fail closed on quota evaluation (`REJECTED_QUOTA`).
  - Cancelled invoices reject payment callbacks without activating subscriptions.
  - Duplicate payment callbacks are handled idempotently.
  - Expired tenants cannot be activated via admin API (HTTP 422).
  - Unauthorized administrators cannot manage plans (HTTP 403).
  - Audit log mutation endpoints return HTTP 405 Method Not Allowed.
  - Backup decryption with invalid key fails closed and cleans temporary output.
  - Tampered backup manifest metadata fails HMAC-SHA256 verification.
  - Passwords and credentials in failed logins and state snapshots are redacted to `[REDACTED]`.
- **Added:** Automated Test Suite (`tests/Feature/LocalIntegrationAndSecurityAuditTest.php`):
  - 14 comprehensive feature tests (26 assertions) validating all security matrix points, fail-closed gates, and backup encryption.
- **Verified:** Complete application health:
  - Backend PHPUnit test suite: **299 tests / 1,300 assertions passed cleanly (0 failures, 0 errors, 100%)**.
  - Frontend production build: **Next.js 14 compiles and builds all 24 pages cleanly with zero errors**.
  - Reconciliation command verified: correctly detects synthetic expired subscriptions and over-quota breaches.
  - Git state: Zero staged changes, zero commits, zero pushes.

### Tenant Usage, SMTP Quota & Billing Reconciliation (Step 24)
- **Hardened:** Outbound Quota Accounting (`OutboundQuotaService`):
  - Fixed recipient tracking omission: `$tenantKey` is now always supplied to Redis Lua evaluation whenever Redis is evaluated, guaranteeing tenant daily recipient consumption is captured and synced to `tenant_outbound_usage` even when tenant-level limit is unlimited (`-1`) with finite mailbox limits.
  - Retained strict bypass of Redis when both tenant and mailbox limits are `-1`, preserving established architectural test contracts (`test_unlimited_quotas_bypasses_redis`).
  - Added safe domain null handling (`$mailbox->domain?->user_id ?? 0`).
  - Implemented fail-closed quota check: tenants without an assigned plan fail closed (`REJECTED_QUOTA`).
- **Hardened:** Billing Gateway & Lifecycle Invariants (`BillingService`):
  - Strictly reject IPN webhooks targeting `cancelled` invoices (`status = 'cancelled'`).
  - Persist `failed` and `cancelled` payment statuses in the invoice ledger.
  - Enforce pessimistic concurrency locking (`lockForUpdate()`) on both `Invoice` and `User` records in `markInvoicePaid()`, ensuring idempotency and preventing race conditions with expiration scheduler.
  - Restrict suspended tenant domain recovery to strictly suspended domains (`where('status', 'suspended')`), guaranteeing unverified and pending domains remain pending.
- **Implemented:** Subscription Validity Gate for Tenant Activation (`AdminApiController::activateUser`):
  - Rejects activation of customer tenants with expired subscriptions (`plan_expires_at->isPast()`) with HTTP 422 Unprocessable Entity, preventing inconsistent active states.
  - Enforces administrator account segregation under `RBAC-DEC-04` (HTTP 403).
- **Implemented:** Unified Read-Only Reconciliation Subsystem:
  - Created `App\Services\Reconciliation\TenantUsageReconciliationService` auditing Redis vs MariaDB usage desync, mailbox sums vs tenant totals, over-quota states, subscription state consistency, domain state invariants, and invoice payment metadata without state mutations.
  - Created `App\Console\Commands\TenantReconcileUsageCommand` (`php artisan tenant:reconcile-usage`) with tabular formatting, `--tenant=`, `--date=`, `--detailed`, and `--json` outputs.
  - Scheduled daily reconciliation check at 05:00 UTC with `withoutOverlapping(15)` mutex in `routes/console.php`.
- **Implemented:** Administrative Reconciliation API Endpoints (`AdminReconciliationApiController`):
  - `GET /api/admin/reconciliation/report`: comprehensive platform reconciliation summary and itemized discrepancies.
  - `GET /api/admin/reconciliation/tenants/{user}`: drill-down single tenant usage, quota, and billing reconciliation view.
  - Protected by `EnsureAdmin` and `admin.permission:admin.stats.read`.
- **Enhanced:** Control Plane Observability:
  - `AdminSmtpTenantResource`: returns `remaining_daily_quota`, `quota_usage_percent`, `is_over_quota`, and granular `subscription_state`.
  - `AdminSmtpService::getOverview`: returns `tenants_over_quota_count` and `reconciliation_status`.
  - Frontend Next.js UI (`SmtpTenantsTable.tsx`): displays remaining daily quota subtext, over-quota warning badges, and granular subscription state badges.
- **Added:** Automated Test Suite (`tests/Feature/TenantUsageAndBillingReconciliationTest.php`):
  - 16 comprehensive feature tests (57 assertions) covering boundary enforcement, multi-recipient accounting, unlimited tenant tracking, IPN validation and cancellation rejection, admin activation expiry gate, reconciliation desync detection, over-quota detection, CLI command, and API authorization.
- **Verified:** Complete application health:
  - Backend PHPUnit test suite: **285 tests / 1,274 assertions passed cleanly (0 failures, 0 errors, 100%)**.
  - Frontend production build: **Next.js 14 compiles and builds all 24 pages cleanly with zero TypeScript errors**.
  - Git state: Zero staged changes, zero commits, zero pushes.

### Admin Audit Trail & Security Event Monitoring (Step 23)
- **Implemented:** Comprehensive Security Event & Mutation Taxonomy across the platform:
  - Authentication lifecycle: `auth.login`, `auth.logout`, `auth.failed` (recording `attempted_email` and guard; passwords/credentials strictly omitted from payload).
  - Perimeter security: `security.authorization_denied` persisted to `audit_logs` with fail-safe error isolation ensuring fail-closed authorization.
  - Tenant & mail mutations: `tenant.register`, `admin.users.suspend`, `admin.users.activate`, `domain.create`, `domain.delete`, `mailbox.create`, `mailbox.change_password`, `mailbox.toggle`, `mailbox.delete`.
  - Subscription plans: `plan.create`, `plan.update`, `plan.delete`.
  - Admin governance: `admin.roles.assign`, `admin.roles.demote`, `admin.emergency_recovery`.
  - Backup & Disaster Recovery: `backup.database.create`, `backup.vmail.create`, `backup.verify`, `backup.prune`.
  - Abuse management: `admin.abuse_incident.resolve`, `admin.abuse_incident.dismiss` with `incident_uuid` correlation.
- **Enhanced:** Centralized Sensitive Data Sanitizer (`AuditService::sanitizeState()`):
  - Expanded `SENSITIVE_KEYS` to 38+ keys including `db_password`, `encryption_key`, `backup_encryption_key`, `passphrase`, `authorization`, `bearer`, `cookie`, `session`, `x_csrf_token`, `card_number`.
  - Added recursive masking replacing sensitive keys with `[REDACTED]`.
  - Added pattern matching for cryptographic password hashes (`$6$`, `$2y$`, `$2a$`, `$argon2`) and JWT/Bearer tokens (`eyJ...`).
- **Enhanced:** Server-Side Querying & API Resource (`AdminAuditLogApiController` & `AdminAuditLogResource`):
  - Enforced `admin.permission:admin.audit_logs.read` and bounded limit (max 50 records).
  - Added `outcome` filter (`success`, `failure`, `denial`).
  - Added validated `sort_by` (`id`, `created_at`, `action`, `entity_type`) and `direction` (`asc`, `desc`).
  - Enhanced API resource with computed `outcome` attribute.
- **Enhanced:** Frontend Admin UI Observability (`AuditLogsTable.tsx`):
  - Added dedicated HTTP 403 Forbidden permission-denied UI alert card.
  - Expanded Action Filter with categorized optgroups.
  - Added Outcome Filter (`success`, `failure`, `denial`) and visual outcome badges (`CheckCircle2`, `XCircle`, `ShieldAlert`).
  - Added Outcome display to detail inspection modal.
- **Added:** Automated Test Suite (`tests/Feature/AdminAuditTrailCoverageTest.php`):
  - 7 comprehensive feature tests covering auth logging, password scrubbing, authorization denial fail-closed behavior, tenant/mailbox mutations, governance role changes, recursive secret scrubbing, outcome filtering, and sorting.
- **Verified:** Complete application health:
  - Backend PHPUnit test suite: **269 tests / 1,217 assertions passed cleanly (0 failures, 0 errors, 100%)**.
  - Frontend production build: **Next.js 14 compiles and builds all 24 pages cleanly with zero TypeScript errors**.
  - Git state: Zero staged changes, zero commits, zero pushes.

### Local Database Bootstrap, Seeder Audit & Safe Environment Configuration (Step 22)
- **Verified:** Local Laragon MySQL 8.4.3 connectivity (`127.0.0.1:3306`) with dedicated database `emailsaas_local`.
- **Audited:** All 16 database migrations:
  - Verified dependency ordering, foreign keys, and indexes.
  - Confirmed all migrations recorded as `[1] Ran` under `php artisan migrate:status`.
- **Implemented:** Idempotent `PlanSeeder` (`database/seeders/PlanSeeder.php`):
  - Seeds 3 standard plans: `starter`, `business`, `enterprise`.
  - Configures full quotas including `daily_outbound_recipients` and `mailbox_daily_outbound_recipients`.
  - Implements `Plan::firstOrCreate(['slug' => ...], [...])` guaranteeing zero duplicate key errors on re-execution.
- **Hardened:** `DatabaseSeeder` (`database/seeders/DatabaseSeeder.php`):
  - Calls `PlanSeeder`.
  - Gated under `app()->environment('local', 'testing')` to strictly prevent creating default accounts in `production`.
  - Idempotently provisions local tenant (`test@example.com`, starter plan, 0 roles) and local Super Admin (`admin@example.com`, `super_admin` role).
- **Implemented:** Automated seeder test suite (`tests/Feature/DatabaseSeederTest.php`):
  - Validates `PlanSeeder` idempotency.
  - Validates `DatabaseSeeder` idempotency in local environment.
  - Validates `DatabaseSeeder` environment safety guard (zero default users seeded in production).
- **Refactored & Hardened:** Linux server installation script (`scripts/install.sh`):
  - Added CLI flags: `--domain`, `--db-name`, `--db-user`, `--db-pass`, `--staging`, `--skip-ssl`, `--skip-ufw`, `--reset-ufw`, `--skip-hostname`, `--allow-placeholder`, `--dry-run`, `-h`/`--help`.
  - Implemented fail-closed guard preventing installation with placeholder domain `mail.yourdomain.com`.
  - Made `/etc/hosts` modifications idempotent.
  - Protected UFW firewall from unconditional resets (`--reset-ufw` now required to reset; default preserves rules).
  - Parameterized Postfix `main.cf` (`myhostname`, TLS certs) and Dovecot `10-ssl.conf` (`ssl_cert`, `ssl_key`) with `${MAIL_DOMAIN}`.
  - Handled Certbot failures gracefully with actionable warnings.
  - Modernized post-installation instructions.
- **Verified:** Complete application health:
  - Backend PHPUnit test suite: **262 tests / 1,151 assertions passed cleanly (0 failures, 0 errors, 0 regressions)**.
  - Frontend production build: **Next.js 14 compiles and builds all 24 pages cleanly**.
  - Local database seeding executed and re-executed cleanly on live local Laragon MySQL.
  - Git state: Zero staged changes, zero commits, zero pushes.

### Authenticated Backup Encryption & Cryptographic Integrity (Step 21)
- **Implemented:** Authenticated Encryption with Associated Data (AEAD) across the backup subsystem using **AES-256-GCM** (`aes-256-gcm`).
- **Designed & Implemented:** Versioned, constant-memory chunked streaming envelope (`EMAILSAAS_GCM_V1`):
  - 40-byte binary header: 16-byte magic (`EMAILSAAS_GCM_V1`) + 16-byte random salt + 8-byte nonce prefix.
  - Chunk framing: 4-byte length + 1-byte terminal flag (`isLast`) + 16-byte GCM authentication tag + ciphertext chunk.
  - Per-chunk unique nonce: 8-byte prefix + 4-byte big-endian chunk counter (12 bytes total).
  - AAD binding: `EMAILSAAS_GCM_V1 || chunkCounter (4B BE) || isLast (1B)`.
  - Constant memory footprint ($O(1)$, ~64 KB buffers) enabling reliable streaming of multi-gigabyte archives.
- **Implemented:** Cryptographic tamper resistance and fail-closed security:
  - Immediate tag verification before releasing decrypted plaintext.
  - Bit-flip, tag corruption, chunk reordering, dropped terminal chunk, and truncation detection.
  - Automatic purging of `.tmp` partial files on any failure.
- **Implemented:** Companion Manifest HMAC-SHA256 metadata signing (`computeManifestHmac`, `verifyManifestHmac`):
  - Manifests cryptographically signed using the backup encryption key (`manifest_hmac`).
  - Tampering with recorded digests, sizes, or metadata is detected and rejected during verification.
  - Automated HMAC re-signing during legitimate lifecycle state updates (e.g. `verified: true`).
- **Implemented:** Isolated backward compatibility for legacy OpenSSL AES-256-CBC (`Salted__`) archives:
  - Dedicated legacy fallback path with explicit security warning: `LEGACY — AUTHENTICITY NOT GUARANTEED` (`authenticated: false`).
- **Implemented:** CLI Decryption Tool and shell script modernization:
  - New Artisan command `backup:decrypt {archive} {target?} {--key=} {--stdout}` supporting direct binary pipe streaming.
  - Updated `scripts/restore_db.sh` to stream-decrypt `EMAILSAAS_GCM_V1` on-the-fly to MySQL engine.
  - Updated `scripts/restore_vmail.sh` to stream-decrypt `EMAILSAAS_GCM_V1` directly to `tar -x`.
  - Updated `scripts/verify_backup.sh` to test authenticated AEAD stream integrity.
  - Enhanced `backup:verify` and `backup:status` commands with encryption envelope and manifest HMAC status reporting.
- **Created:** Comprehensive test suite in `tests/Feature/AuthenticatedBackupEncryptionTest.php` with 16 security, tampering, streaming, and backward compatibility tests.
- **Verified:** Complete test suite execution: **259 passed / 1,132 assertions (0 failures, 0 errors, 0 regressions)**.
- **Git State Maintained:** Zero staging, zero commits, zero pushes.

### Staging Deployment Review, Live Infrastructure Acceptance & Disaster Recovery Drill (Step 20)
- **Audited:** `scripts/install.sh` installation routine:
  - Identified hardcoded domain `MAIL_DOMAIN="mail.yourdomain.com"`, unconditional `ufw --force reset`, and automated standalone Let's Encrypt challenges.
  - Documented installer parameterization requirements (`--domain`, `--staging`, `--skip-ssl`, `--skip-ufw`) before running on shared or non-production staging environments.
- **Audited & Secured:** Database isolation boundary:
  - Identified remote database IP `DB_HOST=103.131.144.61` in `.env.example`.
  - Enforced strict isolation safeguards prohibiting execution of migrations, drops, or `restore_db.sh` against unverified or remote database hosts.
- **Analyzed:** Backup encryption security architecture:
  - Documented that OpenSSL AES-256-CBC is an unauthenticated cipher (confidentiality only, non-AEAD) and integrity relies on unauthenticated companion `.manifest.json` SHA-256 hashes.
  - Recorded formal security recommendation to upgrade to authenticated encryption (AEAD like AES-GCM or HMAC-signed manifests) prior to production release.
- **Hardened:** `docs/DISASTER-RECOVERY-RUNBOOK.md`:
  - Added Section 8: Staging Rollback Runbook detailing preconditions, target database/directory verification, service state, restore sequence, validation commands, stop conditions, and strict prohibition on automatic `migrate --force`.
  - Added Section 9: 15-point Acceptance Evidence Matrix.
- **Verified:** Complete baseline stability:
  - Backend regression suite: **243 passed (1064 assertions)**, 0 failures, 0 errors, 0 skipped.
  - Frontend: TypeScript typecheck 0 errors, `npm run test:step19` 100% passed, Next.js 14 production build (`npm run build`) generates all 24 static pages cleanly.
  - Git tree: Zero staged changes, zero commits.
- **Infrastructure Status Recorded:**
  - Live MariaDB Restore: `PENDING INFRASTRUCTURE` (Requires dedicated Linux VPS with active MariaDB daemon).
  - Live Dovecot Restore: `PENDING INFRASTRUCTURE` (Requires dedicated Linux VPS with active Dovecot daemon).
  - Point-in-Time Recovery (PITR): `NOT IMPLEMENTED` (Option B accepted).
  - Standalone Backup HTTP API: `REQUIRES ARCHITECTURE APPROVAL` (Maintained 404).

### Operational Reliability, Scheduled Jobs & System Health (Step 19)
- **Implemented:** Scheduled task execution tracking ledger (`scheduled_task_runs` table, migration `2026_10_15_000001_create_scheduled_task_runs_table.php`, and `ScheduledTaskRun` Eloquent model):
  - Records canonical task identifier, cron expression, start time, completion time, duration in milliseconds, result state (`running`, `success`, `failed`, `skipped`), and sanitized failure summaries.
  - Fail-safe operational tracking via `App\Services\Scheduling\ScheduledTaskTrackerService`.
  - Single-point failure sanitization stripping DSNs, passwords, Bearer tokens, hashes, and secrets.
  - Automated 7-day retention pruning via daily scheduled task (`scheduled-tasks:prune`) at 04:00 UTC using `withoutOverlapping(15)`.
- **Integrated:** Laravel 11 Console Scheduling Events wired in `AppServiceProvider`:
  - `ScheduledTaskStarting` -> `recordStart()`
  - `ScheduledTaskFinished` -> `recordFinish()`
  - `ScheduledTaskFailed` -> `recordFailure()`
  - `ScheduledTaskSkipped` -> `recordSkipped()`
- **Audited & Hardened:** Scheduler reliability in `laravel-panel/routes/console.php`:
  - Added `withoutOverlapping(10)` mutex to `tenant:suspend-expired` to prevent concurrent collisions under high queue latency.
  - Verified mutexes across all 6 scheduled tasks (`tenant:suspend-expired`, `outbound:usage-sync`, `mail:process-log`, `backup:run`, `backup:prune`, `scheduled-tasks:prune`).
  - Confirmed UTC timezone consistency across all routines.
- **Implemented:** Comprehensive `App\Services\Health\SystemHealthService`:
  - Application core diagnostics (PHP version, Laravel version, environment, timezone, debug mode).
  - Database connectivity and latency measurement (`SELECT 1`) with fail-closed error trapping.
  - Redis connectivity and latency measurement with graceful degradation (`fail-open` mode detection).
  - Scheduler freshness calculation based on execution heartbeat (`fresh` <= 5m, `delayed` 5-15m, `stale` > 15m).
  - 24-hour task failure reliability monitoring.
  - Queue subsystem health (driver, pending jobs, failed jobs in 24h).
  - Backup posture inspection from trusted local companion manifests (respecting Step 18 governance; zero unapproved HTTP endpoints).
  - Composite system status resolution (`HEALTHY`, `DEGRADED`, `UNHEALTHY`, `UNKNOWN`, `NOT_CONFIGURED`).
- **Implemented:** Public and administrative health API endpoints:
  - Minimal public liveness probe (`GET /api/health` and `/up`), exposing zero internal topology or credentials.
  - Authenticated admin system health diagnostics (`GET /api/admin/system/health`), protected by `EnsureAdmin` and `admin.stats.read`.
  - Authenticated admin task runs history (`GET /api/admin/system/tasks`), bounded max 50/page.
- **Implemented:** Admin System Health Dashboard UI (`frontend/src/app/(admin)/admin/system-health/page.tsx`):
  - Responsive Next.js 14 page featuring overall status banner with live heartbeat timestamps and manual refresh.
  - 7 Component diagnostic posture cards with latency metrics and status pills.
  - Recent scheduled task execution ledger table with status badges, runtimes, and failure inspection modal.
  - RBAC 403 Forbidden handling and error retry states.
  - Admin navigation sidebar updated with `System Health` (`Activity` icon).
  - Strict zero-hardcoded-English policy enforced via `next-intl` dictionaries in `frontend/messages/en.json`.
- **Verified:** Frontend verification suite (`npm run test:step19`) passing 100%; TypeScript typecheck (`npx tsc --noEmit`) 0 errors; Next.js production build (`npm run build`) passing 100% with 24/24 static pages generated.
- **Verified:** Feature test suite (`tests/Feature/SystemHealthAndSchedulerTest.php`) 15 tests, 84 assertions passing; full backend regression suite passing with **243 passed / 1064 assertions / 0 failures / 0 regressions**.

### Admin Control Plane Deliverability, Abuse & DR Health Visibility (Step 18)
- **Implemented:** Persistent Abuse Incident Ledger UI (`frontend/src/components/admin/smtp/SmtpIncidentsTable.tsx`):
  - Consumes `/api/admin/smtp/incidents`, displaying real-time paginated abuse records with summary cards (Open, Critical, High, Resolved, Total in view).
  - Multi-criteria server-side filtering: search query, status (`open`, `resolved`, `dismissed`), severity (`critical`, `high`, `medium`, `low`), incident type, detection source, and date ranges.
  - Severity visual hierarchy badges with accessible iconography and distinct color coding.
  - Detailed Incident Investigation Modal with UUID, full timestamps, immutable entity snapshots (tenant, domain, mailbox), metric observation breaches, resolution details, and HTML-safe JSON evidence viewer.
  - Incident Resolution & Dismissal Modal with status selection (`resolved` vs `dismissed`), operational notes field (up to 1000 characters), duplicate submission prevention, and RBAC error handling (`admin.smtp.mailbox.toggle` required).
- **Integrated:** SMTP Administration Navigation (`frontend/src/app/(admin)/admin/smtp/page.tsx`):
  - Added dedicated `incidents` tab alongside `overview`, `tenants`, `mailboxes`, and `abuse`.
  - Added URL search parameter synchronization (`?tab=incidents`) wrapped in React `Suspense` for Next.js 14 CSR bailout compatibility.
- **Implemented:** Disaster Recovery & Backup Health Dashboard (`frontend/src/app/(admin)/admin/backup-dr/page.tsx`):
  - Comprehensive operational health visibility into verified backup pipelines (MariaDB dumps, /var/vmail tar archives, GFS calendar retention).
  - Cryptographic protection at rest display (OpenSSL AES-256-CBC PBKDF2 with fail-closed key validation).
  - Provider-neutral offsite replication status (rsync/scp/local/custom with pre-flight check and fail-safe local preservation).
  - Complete Restore Verification Matrix detailing Verified, Partially Verified, and Not Implemented tiers (Option B PITR).
  - Operational runbook quick reference for Scenarios A, B, and C.
- **Documented:** DR Health HTTP API Gap (Section 5 & 29):
  - Documented missing REST API contract for backup status as `REQUIRES ARCHITECTURE APPROVAL`.
  - Specified proposed endpoint `GET /api/admin/system/backup-status`, required permissions (`admin.stats.read`), JSON response structure, and strict security prohibitions on shell execution over web.
- **Updated:** Admin Sidebar Navigation (`frontend/src/components/admin/AdminSidebar.tsx`):
  - Added `Backup & DR` item with `ShieldCheck` icon.
- **Enforced:** Strict Zero-Hardcoded-English Policy:
  - Added comprehensive localization dictionaries in `frontend/messages/en.json` under `Admin.smtp.incidents` and `Admin.backup_dr`.
  - Corrected legacy unlocalized strings in `SmtpOverview.tsx` and `SmtpAbuseTable.tsx`.
- **Verified:** Frontend test suite (`frontend/scripts/verify-step18.mjs`) passing 100%; Next.js production build (`npm run build`) passing 100% with all 23 static routes generated.
- **Verified:** Zero regressions in backend test suite (`228 passed / 980 assertions`).

### Backup & Disaster Recovery Production Hardening + Restore Verification (Step 17.2)
- **Implemented:** `App\Services\Backup\BackupEncryptionService`:
  - System-level OpenSSL AES-256-CBC with PBKDF2 (10,000 iterations, SHA-256, 8-byte random salt).
  - OpenSSL envelope format (`Salted__` + salt + ciphertext) cross-compatible between PHP OpenSSL and OpenSSL 3.x CLI.
  - Fail-closed security: missing encryption key when encryption is enabled immediately halts execution with an exception.
  - Keys stored strictly outside backup directories and omitted from manifests, logs, and git.
- **Implemented:** Provider-neutral offsite replication subsystem:
  - `App\Services\Backup\BackupOffsiteService`: supports `rsync`, `scp`, `local`, and `custom` transports.
  - Pre-flight local integrity verification before remote replication.
  - Fail-safe invariant: remote transfer failure never deletes, mutates, or invalidates local backup copies.
  - `scripts/sync_offsite.sh`: companion bash offsite replication script with credential redaction.
- **Implemented:** Calendar-aware Grandfather-Father-Son (GFS) retention in `BackupRetentionService`:
  - Distinct calendar bucket grouping: retains 7 daily, 4 weekly (ISO `o-W`), and 3 monthly (`Y-m`) snapshots based on backup timestamps.
  - Non-destructive safety invariants: newest valid backup strictly protected, single remaining backup never pruned, stale `.tmp` reaped after 24h.
- **Hardened:** Disaster recovery orchestrator in `scripts/disaster_recovery.sh`:
  - Removed silent migration failure (`migrate --force || true`).
  - Added `php artisan migrate:status` inspection and explicit `--run-migrations` flag.
  - Fail-closed behavior: migration errors abort script with non-zero exit code.
- **Updated:** Server installer `scripts/install.sh`:
  - Added Step 19: Idempotent Laravel scheduler cron job installation (`* * * * * cd /var/www/email-saas/laravel-panel && php artisan schedule:run >> /dev/null 2>&1`) without wiping or duplicating crontab.
- **Updated:** All companion scripts and Artisan commands:
  - `scripts/backup_db.sh` & `scripts/backup_vmail.sh`: added on-the-fly OpenSSL AES-256-CBC PBKDF2 encryption.
  - `scripts/restore_db.sh` & `scripts/restore_vmail.sh`: added stream decryption directly into database and tar extraction without plaintext disk footprint.
  - `scripts/verify_backup.sh`: added envelope checking and decryption testing.
  - `backup:run`: added `--sync-offsite` flag.
  - `backup:status`: added encryption posture and offsite status reporting.
  - `backup:verify`: added decryption support and isolated restore options.
- **Updated:** Manifest consistency: companion `.manifest.json` immutably tracks `encrypted`, `cipher`, `unencrypted_sha256`, `verified`, `verified_at`, `restore_tested`, `restore_tested_at`, `offsite_copied`, and `offsite_status`.
- **Architectural Decision Recorded:** Option B for Point-in-Time Recovery (`PITR: NOT IMPLEMENTED`). System provides consistent point-of-backup snapshots.
- **Expanded Test Suite:** Added 5 new feature tests in `tests/Feature/BackupAndDisasterRecoveryTest.php` covering AES-256 encryption at rest, decryption verification, fail-closed key validation, calendar GFS retention, and offsite fail-safe semantics (14 tests, 92 assertions; total suite: **228 passed, 980 assertions**).

### Backup & Disaster Recovery Implementation (Step 17)
- **Configured:** Added dedicated `backup` daily logging channel in `config/logging.php` routing to `storage/logs/backup.log`.
- **Created:** Enterprise backup configuration in `config/backup.php` specifying storage paths, retention policies (7 daily, 4 weekly, 3 monthly), critical tables for verification, and offsite configuration placeholders.
- **Implemented:** `App\Services\Backup\DatabaseBackupService`:
  - Transaction-safe compressed database dumps supporting both MariaDB/MySQL (InnoDB non-blocking `--single-transaction --quick --routines --triggers --events --hex-blob`) and SQLite.
  - Direct gzip stream compression into atomic `.tmp` files with atomic promotion.
  - Cryptographic SHA-256 calculation and atomic companion `.manifest.json` generation.
  - Collision guards handling same-second multiple executions with UUID suffixing.
- **Implemented:** `App\Services\Backup\MailStorageBackupService`:
  - Complete `/var/vmail` directory archiving preserving Maildir subdirectories (`cur`, `new`, `tmp`), permissions, and timestamps.
  - Streaming compression into `.tar.gz` with atomic promotion and SHA-256 manifest generation.
- **Implemented:** `App\Services\Backup\BackupVerificationService`:
  - Physical file existence and non-zero byte size verification.
  - Gzip magic header (`\x1f\x8b`) and stream decompression integrity check.
  - Cryptographic SHA-256 checksum validation against companion manifests.
  - Isolated database restore verification into disposable SQLite database, asserting critical tables and row counts without altering production state.
  - Isolated mail storage restore verification into disposable temporary directory, asserting Maildir folder structures.
- **Implemented:** `App\Services\Backup\BackupRetentionService`:
  - Enforced retention rules with non-negotiable safety invariants:
    - Never delete newest valid backup (index 0).
    - Never delete only remaining backup (count <= 1).
    - Stale temporary `.tmp` files older than 24 hours are reaped.
- **Implemented:** 6 Dedicated Artisan Console Commands:
  - `backup:run`: Master execution suite with `--only-db`, `--only-vmail`, `--verify`, and `--no-prune`.
  - `backup:database`: Generates transaction-safe database backup with `--path=`/`--dir=`, `--connection=`, and `--verify`.
  - `backup:vmail`: Archives mail storage with `--source=`, `--path=`/`--dir=`, and `--verify`.
  - `backup:verify`: Validates physical and cryptographic integrity with optional `--dry-run-restore`.
  - `backup:status`: Displays tabular status of archives, ages, storage footprint, and offsite state.
  - `backup:prune`: Prunes expired archives according to retention limits with `--keep=`.
- **Created:** Production Linux Shell Scripts in `scripts/`:
  - `scripts/backup_db.sh`: Native `mariadb-dump` / `mysqldump` with `--single-transaction`, gzip, SHA-256 manifest, and `flock`.
  - `scripts/backup_vmail.sh`: Native tar archive of `/var/vmail` with `--numeric-owner --preserve-permissions`, gzip, manifest, and `flock`.
  - `scripts/verify_backup.sh`: Verifies archive readability, `gzip -t`, and SHA-256 match.
  - `scripts/restore_db.sh`: Interactive database restoration with explicit safety confirmation.
  - `scripts/restore_vmail.sh`: Mail storage restoration enforcing `vmail:vmail` (5000:5000) ownership and 700/600 permissions.
  - `scripts/disaster_recovery.sh`: Bare-metal VPS disaster recovery orchestrator.
- **Scheduled:** Registered scheduled tasks in `routes/console.php`:
  - `backup:run --verify`: Scheduled daily at `02:00 UTC` with `withoutOverlapping(30)`.
  - `backup:prune`: Scheduled daily at `03:00 UTC` with `withoutOverlapping(15)`.
- **Created:** Comprehensive Operator Disaster Recovery Runbook (`docs/DISASTER-RECOVERY-RUNBOOK.md`) covering Scenario A (database loss), Scenario B (mail storage loss), and Scenario C (complete VPS destruction).
- **Added:** Feature test suite in `tests/Feature/BackupAndDisasterRecoveryTest.php` (9 passing tests, 55 assertions).
- **Suite Verification:** Full test suite expanded from 214 tests / 888 assertions to **223 passed, 943 assertions** with zero failures, zero errors, and zero regressions.

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

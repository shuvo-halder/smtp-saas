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
  - Tenant-level manual suspension (`suspension_type`, `suspension_reason`, etc.)
  - Persistent administrative `audit_logs` database table and framework
  - Granular RBAC / Spatie permissions
  - Persistent abuse incident history (`abuse_incidents` table)


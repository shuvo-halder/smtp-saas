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

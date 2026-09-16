# AI IMPLEMENTATION HANDOFF

This document is the operational starting point for any AI coding agent working on this repository.

## 1. Current Architecture
- **Frontend:** Next.js 14 App Router, React 18, Tailwind, shadcn/ui.
- **Backend:** Laravel 11, PHP 8.2.x, Sanctum, MariaDB, Redis.
- **Mail Stack:** Postfix, Dovecot, Roundcube.
- **Tenants:** Users act as Tenants.
- **Billing:** SSLCommerz integration, Prepaid subscription model.
- **Outbound SMTP Policy:** Laravel Policy Daemon (`php artisan policy:serve`), Redis atomic Lua enforcement, Postfix `smtpd_data_restrictions`.

## 2. Current Billing Lifecycle
`NEW` (pending) -> `CHECKOUT` (pending invoice) -> `PAYMENT` -> `VERIFICATION` (IPN Webhook) -> `ACTIVE` (User active, Invoice paid, plan_expires_at updated) -> `EXPIRED` (Scheduler suspends user and domains) -> `RENEWAL` (Invoice paid, domains reactivated, plan_expires_at extended).

## 3. Completed Security & Stability Patches (Step 8)
- **Mailbox Password Mass-Assignment:** Patched `Mailbox` model with `$fillable` and `$hidden` arrays so Dovecot passwords are encrypted via SHA512-CRYPT, persisted properly, and obscured from API output.
- **Postfix Cross-Tenant Spoofing:** Configured Postfix `smtpd_sender_login_maps` and `reject_sender_login_mismatch` in `postfix-config/main.cf` to strictly enforce that authenticated SASL users can only send mail matching their authorized local mailbox or alias addresses.

## 4. Completed Quota Foundations (Steps 11, 12, 13)
- **Step 11 — MariaDB Schema:** Added `daily_outbound_recipients` and `mailbox_daily_outbound_recipients` to `plans` table (-1 = unlimited, 0 = disabled, positive integer = limit). Created `tenant_outbound_usage` historical ledger table with `UNIQUE(user_id, usage_date)`.
- **Step 12 — Redis Quota Service:** Implemented `OutboundQuotaService` providing atomic check+increment via Lua scripts in Redis over a 48h TTL on a fixed UTC daily window with fail-open behavior on Redis downtime.
- **Step 13 — Policy Daemon & Postfix Integration:**
  - Implemented `PostfixPolicyParser`, `PolicyRequest`, and `PolicyResponse` supporting the Postfix SMTP policy delegation protocol.
  - Implemented `PolicyDecisionService` resolving `sasl_username` to `Mailbox -> Domain -> Tenant -> Plan`, validating active tenant and domain states, checking transaction idempotency (`outbound:policy:tx:{instance}`), and delegating to `OutboundQuotaService`.
  - Implemented `PolicyDaemonCommand` (`php artisan policy:serve`) providing a persistent TCP socket server (`127.0.0.1:10031`) with Supervisor process configuration (`server-configs/mailsaas-policy.conf`).
  - Integrated into `postfix-config/main.cf` under `smtpd_data_restrictions` with `check_policy_service inet:127.0.0.1:10031` and fail-open default (`smtpd_policy_service_default_action = DUNNO`).

## 5. Next Recommended Implementation Phase
- **Step 14 — MariaDB Usage Synchronization & Historical Ledger Aggregation:** Implement Laravel scheduled task / cron job to aggregate daily Redis counters into `tenant_outbound_usage` table.
- **Step 15 — Outbound Abuse & Bounce Detection:** Implement asynchronous log tailing / bounce queue parsing for spam classification and high bounce threshold mitigation.
- **Step 16 — Admin SMTP Management UI:** Add frontend dashboards for quota usage metrics and SMTP credential management.

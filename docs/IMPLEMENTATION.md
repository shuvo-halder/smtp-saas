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

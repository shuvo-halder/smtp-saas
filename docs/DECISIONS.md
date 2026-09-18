# Technical Decision Records

## Decision: Native ORM over Legacy Virtual Tables
### Status
ACCEPTED
### Date
2026-09-04
### Decision
Postfix and Dovecot `mysql-virtual-*.cf` configurations are bound directly to Laravel's native `domains` and `mailboxes` tables instead of delegating to legacy `virtual_domains` tables via shell scripts.
### Reason
Prevents split-brain desynchronization between Laravel state and Mail Server state.
### Consequences
Shell scripts (`add_domain.sh`, `add_mailbox.sh`) were stripped of all `mysql` logic and are now strictly responsible for physical filesystem generation (`mkdir /var/vmail/...`) and DKIM key generation. 
### DO NOT CHANGE WITHOUT APPROVAL
Yes

---

## Decision: Tenant Isolation Strategy
### Status
ACCEPTED
### Date
2026-09-01
### Decision
Tenant context is resolved from the wildcard subdomain (`tenant.mailsaas.com`), passed through Next.js Edge Middleware, and bound to the Laravel `User` context via the `IdentifyTenant` middleware.
### Reason
Provides seamless, URL-based tenant isolation ensuring that users cannot access other tenants' data even if they share the same physical application instance.
### DO NOT CHANGE WITHOUT APPROVAL
Yes

---

## Decision: SHA512-CRYPT for Mailboxes
### Status
ACCEPTED
### Date
2026-09-01
### Decision
Virtual Mailbox passwords must be hashed in Laravel using `SHA512-CRYPT` instead of Laravel's default `bcrypt`.
### Reason
Dovecot requires a natively supported hash algorithm to authenticate IMAP/POP3 logins directly from the database without invoking a Laravel API.
### DO NOT CHANGE WITHOUT APPROVAL
Yes

---

## Decision: SSLCommerz Pre-paid Model
### Status
ACCEPTED
### Date
2026-09-01
### Decision
The SaaS uses a manual pre-paid billing model (Monthly/Yearly) instead of automated recurring credit card charges via Stripe.
### Reason
Local market limitations in Bangladesh make recurring card tokenization difficult. Pre-paid manual renewals via MFS (bKash/Nagad) via SSLCommerz provides higher conversion.
### DO NOT CHANGE WITHOUT APPROVAL
Yes

---

## Decision: Redis Atomic Quota Enforcement & Postfix Policy Daemon
### Status
ACCEPTED
### Date
2026-09-07 (Step 12 & Step 13)
### Decision
Outbound SMTP volume limits are enforced in real time using Redis atomic counters (`OutboundQuotaService` via Lua script) connected to Postfix via a native Laravel Policy Daemon (`policy:serve`) at `smtpd_data_restrictions`.
### Reason
Guarantees sub-millisecond evaluation without database locking bottlenecks or race conditions under high concurrent SMTP connections. Fails open (`DUNNO`) to protect legitimate mail flow during cache outages.
### DO NOT CHANGE WITHOUT APPROVAL
Yes

---

## Decision: Monotonic Historical Usage Synchronization (Redis → MariaDB)
### Status
ACCEPTED
### Date
2026-09-17 (Step 14)
### Decision
Historical outbound recipient metrics are decoupled from runtime enforcement. An hourly scheduled task (`php artisan outbound:usage-sync`) scans Redis keys via bounded `SCAN` and idempotently syncs them to MariaDB `tenant_outbound_usage`. Updates are monotonic (MariaDB counts never decrease even if Redis is flushed or restarted mid-day). Missing Redis keys do not fabricate zero rows.
### Reason
MariaDB provides durable auditability and billing reports without burdening real-time SMTP delivery. Monotonic updates prevent data loss if ephemeral Redis state resets.
### DO NOT CHANGE WITHOUT APPROVAL
Yes

---

## Decision: Admin SMTP Management & Deliverability Control Plane (Step 16A Scope Lock)
### Status
ACCEPTED
### Date
2026-09-18 (Step 16A)
### Decision
Step 16 is implemented in a scoped, architecture-safe phase (Step 16A) restricted to:
1. SMTP cluster observability (overview, daily quota usage, 30-day historical usage, bounce metrics, queue size, active abuse warnings).
2. Mailbox-level administrative controls (toggle active/disabled with strict parent domain/tenant/subscription invariant checks, reset consecutive hard bounce failure streak, reset password with SHA512-CRYPT hashing and one-time reveal).
3. Zero database migrations: MariaDB schema remains 100% untouched.
4. Tenant-level manual suspension, persistent administrative audit log tables, granular RBAC, and persistent abuse incident tables are explicitly DEFERRED to future architectural phases.
5. All administrative mutations are recorded to a dedicated operational JSON log (`storage/logs/admin-smtp.log`).
### Reason
Guarantees production safety, prevents unapproved database schema changes, prevents race conditions with Step 13/14/15 SMTP subsystems, and delivers rich deliverability management while deferring destructive tenant suspension mechanics until proper schema models are established.
### DO NOT CHANGE WITHOUT APPROVAL
Yes


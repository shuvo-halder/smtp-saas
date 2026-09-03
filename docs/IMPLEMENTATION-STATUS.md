# Implementation Status

## Authentication
- [x] Login API
- [x] Registration API
- [x] Sanctum authentication & CSRF
- [x] Logout
- [x] User endpoint for current context
- [x] Authorization middleware (`EnsureAdmin`, `EnsureActiveSubscription`)

## Multi-Tenancy
- [x] Next.js Edge wildcard routing
- [x] Laravel Tenant identification (`IdentifyTenant` middleware)
- [x] Tenant context binding (`app('tenant')`)
- [x] Tenant-aware queries (Route model binding + `$request->user()->domains()`)
- [x] Cross-tenant access protection (Eloquent Policies)

## Mail Infrastructure (Postfix / Dovecot)
- [x] MariaDB native SQL queries configured
- [x] Postfix MTA mapped to `domains` and `mailboxes` tables
- [x] Dovecot IMAP mapped to `domains` and `mailboxes` tables
- [x] Mailbox directory creation decoupled from DB writing in shell scripts
- [x] `/var/vmail` and `/var/vmail_archive` standardization
- [x] DKIM generation (`setup_dkim.sh`)

## Domains & Mailboxes
- [x] Domain CRUD API
- [x] Domain DNS verification API
- [x] Mailbox creation API (with `DB::transaction`)
- [x] Mailbox password generation and hashing (SHA512-CRYPT)
- [x] Mailbox suspend/activate toggle API
- [x] Soft-deletion and archival (`remove_mailbox.sh`)

## Webmail
- [x] Roundcube configuration
- [x] Dovecot Master User logic
- [x] OTP Generation API
- [x] Redis caching of 60s OTP keys
- [x] Frontend one-click SSO redirect

## Billing & Subscription
- [x] Plans CRUD (DB)
- [x] Checkout Initiation API
- [x] SSLCommerz Gateway redirect
- [x] IPN / Webhook handler
- [x] Signature and amount validation
- [x] Invoice and User atomic updates (`DB::transaction`)
- [x] Payment Success/Fail/Cancel routes

## Background Automation
- [x] System Cron configured
- [x] `tenant:suspend-expired` command
- [x] Supervisor queue worker (`mailsaas-worker.conf`)

## Backup & Disaster Recovery
- `[ ]` Database backup pipeline
- `[ ]` `/var/vmail` offsite sync pipeline

## Admin Control Plane
- `[x]` Admin Dashboard (Stats, System Health, Revenue)
- `[x]` Tenants Datatable & Toggle (Suspend/Activate)
- `[x]` Tenant Details (Drilldown into Domains/Mailboxes/Invoices)
- `[x]` Global Domains Datatable
- `[x]` Global Mailboxes Datatable
- `[/]` Plans Datatable (Listing implemented, CRUD missing)
- `[x]` Invoices Datatable
- `[ ]` Subscription Management (Admin override/upgrade)
- `[ ]` SMTP Management (Credential listing/revocation)
- `[ ]` Admin Audit Logs
- `[ ]` Granular RBAC (Roles/Permissions)

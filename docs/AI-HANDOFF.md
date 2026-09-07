# AI IMPLEMENTATION HANDOFF

This document is the operational starting point for any AI coding agent working on this repository.

## 1. Current Architecture
- Frontend: Next.js 14 App Router, React 18, Tailwind, shadcn/ui.
- Backend: Laravel 11, PHP 8.2.x, Sanctum, MariaDB, Redis.
- Mail Stack: Postfix, Dovecot, Roundcube.
- Tenants: Users act as Tenants.
- Billing: SSLCommerz integration, Prepaid subscription model.

## 2. Current Billing Lifecycle
`NEW` (pending) -> `CHECKOUT` (pending invoice) -> `PAYMENT` -> `VERIFICATION` (IPN Webhook) -> `ACTIVE` (User active, Invoice paid, plan_expires_at updated) -> `EXPIRED` (Scheduler suspends user and domains) -> `RENEWAL` (Invoice paid, domains reactivated, plan_expires_at extended).

## 3. Completed Fixes (Step 6)
- **Renewal Time Erasure:** Early renewals now correctly append the new billing cycle duration to the existing future expiration date.
- **Suspension Lockout:** Suspended tenants correctly regain active domain access upon payment.
- **Payment Idempotency:** Added a strict `status === `'paid`' guard inside IPN webhook handler.
- **Mailbox Fatal Error:** Fixed `$domain->canAddMailbox()` crash to use `$user->canAddMailbox($domain)`.
- **Database Migrations:** Safely removed duplicate `users` migration. Ran migrations cleanly.

## 4. Verified Behavior
- Database migrates correctly from zero to full schema.
- Billing state machine safely transitions between pending, active, and suspended states.
- Idempotency guards prevent duplicate payment applications.
- Next.js frontend builds cleanly and zero hardcoded English strings exist in new UIs.
- Admin Plan CRUD is safely implemented (prevents deletion of Plans historically used by tenants).

## 5. Tests Passed
- `php artisan test --filter BillingLifecycleTest` (8 tests, 14 assertions) passes cleanly.
- `php artisan test --filter AdminPlanTest` (7 tests, 17 assertions) passes cleanly.

## 6. Tests Blocked
- Mailbox Creation (`MailboxApiController@store`) is fundamentally untested and currently broken due to an Eloquent mass-assignment omission.

## 7. Known Limitations & Vulnerabilities
- Deleting a `Plan` via Database/CRUD is safely blocked if historical `invoices` or `users` reference it, due to strict data integrity design.
- The Admin interface does not allow manual updating of a tenant's Plan because the platform operates strictly on a pre-paid SSLCommerz model without proration logic. Display-only Subscription logic is strictly enforced.
- **SECURITY VULNERABILITY:** Postfix is missing `smtpd_sender_login_maps`, allowing an authenticated mailbox to spoof ANY sender address across the platform.
- **CRITICAL BUG:** `Mailbox` model is missing `'password'` in `$fillable` and `$hidden`, causing Mailbox creation to silently drop the generated Dovecot hashed password.

## 8. Next Recommended Implementation Phase
- **Security & Stability Patching:** Fix the critical `Mailbox` mass-assignment bug and patch the Postfix `main.cf` spoofing vulnerability BEFORE proceeding to build any new SMTP Management features or Admin UI.

## 5. Security Patches (Step 8)
- **Mailbox Password Mass-Assignment:** Fixed Mailbox model so Dovecot passwords are saved properly and hidden from API output.
- **Postfix Cross-Tenant Spoofing:** Configured Postfix smtpd_sender_login_maps and 
eject_sender_login_mismatch to prevent authenticated users from spoofing other tenants' addresses.


## 6. Architecture Audits (Step 9)
- **SMTP Quotas & Abuse:** Completed architecture audit (rtifacts/smtp_architecture_audit.md). Implementing outbound quotas requires database migrations (plans table) and a new Policy Daemon integrating Postfix with Redis. Marked as REQUIRES ARCHITECTURE APPROVAL.


## 7. Outbound Quota Database Schema (Step 11)
- **Plan Quotas:** Added daily_outbound_recipients and mailbox_daily_outbound_recipients to the plans table and Admin API. Semantics: -1 (unlimited), 0 (disabled), positive integer (finite).
- **Usage Ledger:** Created 	enant_outbound_usage table to durably store historical daily recipient counts synced from Redis.
- **Status:** Database layer is IMPLEMENTED. Real-time Redis enforcement and Policy Daemon are PENDING.


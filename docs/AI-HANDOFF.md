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
- Next.js frontend builds without regression.

## 5. Tests Passed
- `php artisan test --filter BillingLifecycleTest` (8 tests, 14 assertions) passes cleanly.

## 6. Tests Blocked
- None.

## 7. Known Limitations
- Deleting a `Plan` via Database/CRUD is physically blocked if historical `invoices` reference it due to restrictive foreign keys.
- Changing a plan does not prorate costs. The system strictly extends expiration by exactly 1 month/year using the newly paid plan.

## 8. Next Recommended Implementation Phase
- **Admin Plan & Subscription Management UI:** Implement frontend CRUD for Plans and an interface to view Subscriptions.

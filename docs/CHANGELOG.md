# Changelog

## 2026-09-04

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

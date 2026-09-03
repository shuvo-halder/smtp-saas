# Changelog

## 2026-09-04

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

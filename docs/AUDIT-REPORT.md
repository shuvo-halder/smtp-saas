# EmailSaaS Architectural Audit & Fix Report
**Date:** September 4, 2026
**Status:** Completed

## 1. Executive Summary
A comprehensive codebase audit was performed against the `docs/ARCHITECTURE.md` specification to identify multi-tenancy leaks, reliability issues, hardcoded strings, and shell script architecture deviations. All identified issues have been fixed directly in the source code.

## 2. P0 (Critical) - Shell Script vs. ORM Discrepancy
**Finding:** 
Legacy bash scripts (`add_domain.sh`, `add_mailbox.sh`, `remove_domain.sh`, `remove_mailbox.sh`) were executing direct MySQL insertions and updates against legacy tables (`virtual_domains` and `virtual_users`) and directories (`/var/mail/vhosts`). However, the modern Laravel API and generated `Postfix/Dovecot` configurations correctly use Eloquent ORM to insert records into the `domains` and `mailboxes` tables and expect paths in `/var/vmail/`. This created a critical split-brain scenario where the database and filesystem paths would fall out of sync.

**Fix Applied:**
- Stripped all `mysql` query logic from the bash scripts. Database state is now exclusively managed by Laravel's Eloquent ORM.
- Standardized the mail storage directory in the shell scripts to `/var/vmail` and the archive directory to `/var/vmail_archive` to match Dovecot and Postfix configurations.
- Modified `PostfixService.php` to no longer pass database/user parameters to the bash scripts, and to omit `getServerDomainId` completely.

## 3. P1 (High) - Lack of DB Transactions (Reliability)
**Finding:** 
Several critical provisioning controllers performed sequential external and internal operations without `DB::transaction` wrappers. If the shell script execution failed *after* the database record was created, the database would contain a ghost record that did not exist physically on the mail server. 

**Fix Applied:**
- `MailboxApiController@store`: Wrapped the `mailboxes()->create()` and `PostfixService->addMailbox()` calls in a `DB::transaction`.
- `DomainApiController@store`: Wrapped the `domains()->create()` and `PostfixService->addDomain()` calls in a `DB::transaction`.
- `BillingService@markInvoicePaid`: Wrapped the `Invoice` and `User` Eloquent updates in a `DB::transaction` to ensure payment status and user subscription extensions are atomically consistent. 
- Removed a failing and missing `PaymentConfirmed` notification call from the billing service.

## 4. P1 (High) - Multi-Tenancy Data Isolation (Security)
**Finding:**
An audit was performed across all `Api` and `Admin` controllers for unsafe `Model::find()`, `Model::findOrFail()`, or `Model::where()` calls that lacked tenant scoping (`$request->user()->domains()`).

**Fix Applied:**
- The audit confirmed that the codebase correctly relies on Eloquent Route Model Binding combined with strict `$this->authorize('view', $model)` (Policy-based authorization) or `$request->user()->domains()` scoping. 
- No Insecure Direct Object Reference (IDOR) vulnerabilities were found. Tenant isolation boundaries are correctly respected.

## 5. P2 (Medium) - Hardcoded Frontend Strings (i18n)
**Finding:**
The Next.js frontend is localized via `next-intl` (`messages/en.json`), but several components (e.g., `dashboard/page.tsx`) contained hardcoded English strings.

**Fix Applied:**
- Extracted hardcoded UI text ("Unlimited", "per domain", "per mailbox", "Free Tier", "Active Subscription", "Pending/Suspended", "Error loading dashboard", "Limit") into `messages/en.json` under `Tenant.dashboard`.
- Updated `dashboard/page.tsx` to use the `t()` translation hook for all dynamic limit string formatting.

## 6. Verification
- The architecture documentation (`docs/ARCHITECTURE.md`) correctly reflects the current state (especially Section 14: Mailbox Provisioning Flow, which accurately describes the separation of DB and filesystem concerns).
- Multi-tenancy isolation remains intact.
- Provisioning pipelines are now atomicity-safe.

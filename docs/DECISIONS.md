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

---

## Step 16B.2 — Canonical RBAC Architecture Decision Registry (PREPARATION — PENDING OWNER APPROVAL)

The following 10 canonical decisions define the security boundaries and architecture for Step 16B.2 Granular RBAC. Implementation is strictly prohibited until the project owner explicitly selects an option for each decision.

### Decision 1: Spatie Guard Name
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: `web` (Single-guard architecture aligned with Sanctum stateful SPA cookie authentication).
  * Option B: `sanctum` (Explicit token guard architecture requiring Sanctum guard definition in `config/auth.php`).
* **Implementation Consequence:** Option A leverages the existing authenticated session guard without configuration divergence; Option B requires dual-guard configuration and explicit guard specification on all permission queries.

### Decision 2: Super Admin Authorization Strategy
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Explicit permission assignments (All permissions explicitly mapped to `super_admin` in `role_has_permissions`).
  * Option B: Global bypass via `Gate::before` (Super Admin bypasses all individual permission checks).
* **Implementation Consequence:** Option A enforces strict least privilege and complete auditability; Option B introduces global privilege bypass that can circumvent domain invariant policies unless guarded by defensive conditionals.

### Decision 3: Mailbox Password Reset Authorization
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: `super_admin` only.
  * Option B: `super_admin` plus `deliverability_operator`.
* **Implementation Consequence:** Option A strictly shields private tenant mailbox communications from support staff; Option B allows frontline deliverability staff to resolve tenant access lockouts.

### Decision 4: Administrator Segregation in Tenant Controllers
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Enforce administrator segregation (Tenant suspend/activate endpoints reject target users where `is_admin = true` or user holds an administrative role).
  * Option B: Allow tenant controllers to modify any user account.
* **Implementation Consequence:** Option A prevents cross-boundary privilege escalation or accidental administrative suspension via tenant endpoints; Option B leaves administrative accounts vulnerable to tenant lifecycle workflows.

### Decision 5: Authorization Denial Logging Destination
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Operational application log channel (`storage/logs/laravel.log` or dedicated `auth_denials` channel).
  * Option B: Persistent database administrative ledger (`audit_logs` table).
* **Implementation Consequence:** Option A isolates denial telemetry from database failures and prevents table flooding under automated scanning; Option B provides direct queryability and UI audit visibility at the cost of database write load.

### Decision 6: Universal Super Admin Concurrency Lock Strategy
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Pessimistic row lock on existing `roles` row (`SELECT * FROM roles WHERE name = 'super_admin' FOR UPDATE`).
  * Option B: Dedicated governance lock table (`governance_locks`).
* **Implementation Consequence:** Option A avoids additional database schema migrations; Option B cleanly decouples concurrency locking from Spatie table schema and eliminates risk of gap-lock contention on Spatie tables.

### Decision 7: Emergency Recovery Account Status Preservation
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Preserve account status by default and require an explicit `--reactivate` flag to restore a suspended recovery admin.
  * Option B: Automatically reactivate the recovery account upon password reset.
* **Implementation Consequence:** Option A prevents unintentional reactivation of compromised accounts; Option B prioritizes guaranteed break-glass access restoration.

### Decision 8: Test Factory Role Assignment Strategy
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Explicit factory states (`User::factory()->superAdmin()`, defaulting to zero roles).
  * Option B: Automatic model observer assigning default roles.
* **Implementation Consequence:** Option A guarantees test isolation and prevents unintended privilege leaks in test cases; Option B reduces boilerplate in tests but risks implicit privilege escalation.

### Decision 9: Legacy Administrator Backfill Execution Strategy
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Pure-DML database migration (`seed_rbac_and_backfill_legacy_admins.php`).
  * Option B: One-time manual CLI command (`php artisan rbac:backfill`).
* **Implementation Consequence:** Option A guarantees zero-gap synchronization during deployment; Option B decouples data migration from schema deployment but introduces risk of operator omission.

### Decision 10: New Administrator Default Privileges
* **Status:** PENDING OWNER APPROVAL
* **Options:**
  * Option A: Zero roles/permissions by default (fail-closed, requiring explicit role grant).
  * Option B: Predefined base role assignment (`customer_support` by default).
* **Implementation Consequence:** Option A strictly enforces default-deny least privilege; Option B speeds administrator onboarding at the expense of automated privilege grant.

---

## Supplementary Architecture Decisions (PENDING OWNER APPROVAL)

1. **Database Engine Telemetry Schema:** Standardize lock telemetry on MySQL 8.0 `performance_schema` (`data_lock_waits`) rather than MariaDB 10.x `information_schema.innodb_lock_waits` (which does not exist in MySQL 8.0).
2. **Migration Rollback Data Preservation:** Mandate that Migration 2 `down()` preserves `model_has_roles` user assignments rather than executing cascading deletes, reserving full table deletion for Migration 1 `down()`.
3. **Tenant-Expiration Job Exemption:** Status: **RESOLVED & IMPLEMENTED** in Step 16B.2 Phase A (`where('is_admin', false)`).
4. **Migration-Completion Sentinel Design:** Implement a dedicated versioned sentinel table (`rbac_migration_sentinels`) to guarantee idempotent, single-execution legacy backfills.

---

## Step 16B.2 — Canonical RBAC Architecture Decision Registry

### OWNER DECISION RECORD — APPROVED FOR DOCUMENTATION ONLY

**Project:** EmailSaaS (`smtp-saas`)  
**Step:** 16B.2 — Granular RBAC Architecture  
**Decision Status:** **OWNER-APPROVED FOR DOCUMENTATION**  
**Implementation Authorization:** **NOT GRANTED**  
**Implementation Status:** **BLOCKED / PENDING SEPARATE OWNER AUTHORIZATION**  

This record preserves the previously documented Step 16B.2 architecture-decision history and records the owner's explicit selections. No source code, migrations, tests, seeders, configuration, database records, or authorization behavior are authorized to change as a consequence of this record alone.

---

### Canonical Owner Decisions

#### RBAC-DEC-01 — Spatie Guard Name

**Owner Selection:** **Option A — `web`**

**Decision:** Spatie Permission will use the existing Laravel `web` authentication guard namespace.

**Architectural rationale:** The current application uses Laravel's `web` session guard for authentication, while Sanctum SPA authentication operates through the stateful session layer rather than requiring a separate `sanctum` guard definition.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-02 — Super Admin Authorization Model

**Owner Selection:** **Option A — Explicit permissions**

**Decision:** Super Admin capabilities will be represented through explicit RBAC permission assignments rather than a global `Gate::before` authorization bypass.

**Architectural consequence:** Super Admin authorization must remain visible within the RBAC permission model and must not silently bypass permission checks through a universal Gate override.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-03 — Mailbox Password Reset Authorization

**Owner Selection:** **Option B — Super Admin + Deliverability Operator**

**Decision:** Mailbox password reset functionality will be available to both the Super Admin role and the designated Deliverability Operator role, subject to the final permission definitions and policy enforcement implemented in the authorized RBAC phase.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-04 — Tenant Controller Administrator Segregation

**Owner Selection:** **Option A — Reject administrator targets**

**Decision:** Tenant-scoped suspend/activate controller operations must reject targets that are administrators.

**Architectural consequence:** Administrator lifecycle operations must remain separated from ordinary tenant-user lifecycle operations. Tenant endpoints must not be permitted to change administrator status through the ordinary tenant suspension/activation path.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-05 — Authorization Denial Logging

**Owner Selection:** **Option A — Operational file/security log**

**Decision:** Authorization-denial events will be recorded through an operational security log rather than the persistent administrative `audit_logs` database ledger.

**Architectural rationale:** Authorization-denial traffic may be high-volume or externally generated. File-based operational logging avoids making authorization-denial handling dependent on the database and reduces the risk of database audit-log flooding.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-06 — Super Admin Concurrency Lock

**Owner Selection:** **Option B — Dedicated `governance_locks` table**

**Decision:** Universal Super Admin governance concurrency control will use a dedicated governance lock table rather than relying on a row lock on the Spatie `roles` table.

**Architectural consequence:** Governance-level synchronization will have an isolated persistence boundary instead of coupling concurrency control to the RBAC role record itself.

**Required future implementation consideration:** The authorized implementation must define the lock-row lifecycle, transaction boundaries, contention behavior, timeout/failure behavior, and concurrency tests before this decision can be considered implemented.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-07 — Emergency Recovery Account Status

**Owner Selection:** **Option A — Preserve existing status and require `--reactivate`**

**Decision:** Emergency recovery tooling must preserve the existing account status by default. Explicit `--reactivate` authorization/intent will be required before the recovery mechanism changes a suspended account back to an active state.

**Architectural consequence:** Emergency recovery must not silently alter account lifecycle state as a side effect of restoring administrative access.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-08 — Test Factory Role Assignment

**Owner Selection:** **Option A — Explicit factory states**

**Decision:** Test factories will use explicit role-assignment states rather than an automatic observer that implicitly assigns roles.

**Architectural rationale:** Tests should declare their required authorization state explicitly and avoid hidden role mutations caused by model observers.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-09 — Legacy Administrator Backfill

**Owner Selection:** **Option A — Pure-DML migration**

**Decision:** Existing legacy administrators will be migrated into the new RBAC representation through a database migration using controlled DML rather than a separate one-time CLI command.

**Required future implementation consideration:** The migration must explicitly define eligibility, idempotency, transaction behavior, assignment provenance, rollback behavior, and protection against accidental privilege expansion.

**Implementation authorization:** Not granted by this record.

---

#### RBAC-DEC-10 — New Administrator Default Privileges

**Owner Selection:** **Option A — Zero default roles/permissions**

**Decision:** Newly created administrator accounts will receive no implicit RBAC roles or permissions by default.

**Architectural consequence:** Administrative privileges must be explicitly assigned. The system must fail closed rather than silently granting a default administrative permission set.

**Implementation authorization:** Not granted by this record.

---

### Supplementary Architecture Decisions

#### Supplementary Decision 1 — MySQL 8.0 Lock Telemetry

**Status:** **Resolved — Technical Compatibility Decision**

The production database environment uses MySQL 8.0. Lock-contention diagnostics must use the MySQL 8-compatible `performance_schema` mechanisms, including `performance_schema.data_lock_waits` and related thread/transaction metadata, rather than obsolete `information_schema.innodb_lock_waits` queries.

This is a technical compatibility resolution and does not require a new owner business decision.

---

#### Supplementary Decision 2 — Migration Rollback Role-Assignment Preservation

**Owner Selection:** **Option B — Preserve role assignments with provenance**

**Decision:** RBAC migration rollback must preserve legitimate role assignments rather than blindly deleting `model_has_roles` records.

**Required future implementation consideration:** Any migration-created role assignments that may require cleanup during rollback must have sufficient provenance to distinguish migration-created assignments from legitimate pre-existing or subsequently modified assignments.

**Implementation authorization:** Not granted by this record.

---

#### Supplementary Decision 3 — Tenant-Expiration Job Administrator Exemption

**Status:** **Resolved & Implemented in Step 16B.2A**

The tenant-expiration process was hardened so administrators are excluded from automatic tenant suspension.

The existing Step 16B.2A implementation and regression coverage remain part of the accepted baseline and are not reopened by the RBAC decision process.

---

#### Supplementary Decision 4 — Migration-Completion Sentinel Design

**Owner Selection:** **Option A — Standard Laravel migration tracking**

**Decision:** RBAC migration completion will rely on Laravel's standard migration tracking mechanism rather than introducing a separate `rbac_migration_sentinels` table.

**Architectural consequence:** No dedicated sentinel table is authorized by this decision.

**Implementation authorization:** Not granted by this record.

---

### Decision Governance

These decisions constitute the owner's selected **Step 16B.2 RBAC architecture direction**.

They do **not** constitute implementation authorization.

The following remain explicitly prohibited until a separate owner authorization is provided:

* Creating or modifying RBAC migrations.
* Creating roles or permissions.
* Assigning roles to production users.
* Enabling Spatie `HasRoles`.
* Adding RBAC middleware or policies.
* Replacing or bypassing the existing `EnsureAdmin` boundary.
* Modifying administrator creation/update behavior.
* Creating `governance_locks`.
* Implementing legacy administrator backfill.
* Implementing emergency recovery behavior.
* Modifying authorization-denial logging.
* Changing database schema or production database records.
* Claiming Step 16B.2 RBAC implementation is complete.

The canonical decision history preceding this record must remain preserved. Earlier `PENDING OWNER APPROVAL` preparation entries must not be overwritten or deleted; this record is an explicit subsequent owner-resolution entry.

**Current state after this decision record:**

> **ARCHITECTURE DECISIONS APPROVED FOR DOCUMENTATION — IMPLEMENTATION STILL BLOCKED**

A separate explicit owner instruction is required before implementation may begin.

# API Contract

The EmailSaaS backend utilizes a RESTful API powered by Laravel 11. All API routes are protected by Laravel Sanctum (except public webhooks/auth endpoints).

## Base Path
`https://panel.mailsaas.com/api`

## Authentication API
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| POST | `/auth/register` | Register a new tenant. | `IdentifyTenant` |
| POST | `/auth/login` | Login and establish Sanctum session. | `IdentifyTenant` |
| POST | `/auth/logout` | Terminate session. | `IdentifyTenant`, `auth:sanctum` |
| GET | `/auth/user` | Get current authenticated user + plan. | `IdentifyTenant`, `auth:sanctum` |

## Tenant API (Requires active subscription)
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/dashboard` | Tenant statistics (domains, mailboxes). | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/domains` | Paginated domains list. | `auth:sanctum`, `EnsureActiveSubscription` |
| POST | `/domains` | Add a new domain. Triggers DKIM script. | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/domains/{id}` | Domain details, DNS records, aliases. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/domains/{id}/verify`| Trigger DNS verification. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| DELETE| `/domains/{id}` | Soft delete domain & archive maildir. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |

## Mailbox API
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/domains/{id}/mailboxes`| Paginated mailboxes for a domain. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/domains/{id}/mailboxes`| Create mailbox (SHA512-CRYPT). | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/mailboxes/{id}/change-password`| Change mailbox password. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/mailboxes/{id}/toggle` | Activate/Deactivate mailbox. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| DELETE| `/mailboxes/{id}` | Soft delete mailbox & archive maildir.| `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |

## Billing & SSO API
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/billing/plans` | List available subscription plans. | `IdentifyTenant`, `auth:sanctum` |
| POST | `/billing/checkout` | Initiate SSLCommerz checkout session. | `IdentifyTenant`, `auth:sanctum` |
| POST | `/billing/ipn` | SSLCommerz Server-to-Server webhook. | `IdentifyTenant` (No CSRF) |
| POST | `/billing/success` | SSLCommerz Payment Success webhook. | `IdentifyTenant` (No CSRF) |
| POST | `/billing/fail` | SSLCommerz Payment Fail webhook. | `IdentifyTenant` (No CSRF) |
| POST | `/billing/cancel` | SSLCommerz Payment Cancel webhook. | `IdentifyTenant` (No CSRF) |
| POST | `/webmail/sso` | Generate 60s Redis OTP for Roundcube. | `IdentifyTenant`, `auth:sanctum` |

## Admin API (Step 16B.2 Granular RBAC)
| Method | Endpoint | Description | Middleware | Required Permission |
|---|---|---|---|---|
| GET | `/admin/stats` | Global platform metrics and charts data. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.stats.view` |
| GET | `/admin/charts` | Revenue and signup timeseries data. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.stats.view` |
| GET | `/admin/server-stats`| Mail queue, IMAP connections, disk usage. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.stats.view` |
| GET | `/admin/users` | Paginated list of all tenants. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.users.view` |
| GET | `/admin/users/{id}` | Single tenant drill-down (with relations). | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.users.view` |
| POST | `/admin/users/{id}/suspend`| Suspend a tenant and their domains (Rejects admin targets). | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.users.manage` |
| POST | `/admin/users/{id}/activate`| Reactivate a tenant and their domains (Rejects admin targets). | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.users.manage` |
| GET | `/admin/domains` | Paginated list of all domains. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.domains.view` |
| GET | `/admin/mailboxes` | Paginated list of all mailboxes. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.mailboxes.view` |
| GET | `/admin/plans` | List available subscription plans. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.plans.view` |
| POST | `/admin/plans` | Create a new subscription plan. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.plans.manage` |
| GET | `/admin/plans/{plan}` | Retrieve specific plan details. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.plans.view` |
| PUT | `/admin/plans/{plan}` | Update a specific plan. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.plans.manage` |
| DELETE | `/admin/plans/{plan}` | Delete an unused plan. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.plans.manage` |
| GET | `/admin/invoices` | Paginated list of all invoices. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.invoices.view` |

## Admin SMTP Management API (Step 16A / 16B.2 Granular RBAC)
| Method | Endpoint | Description | Middleware | Required Permission |
|---|---|---|---|---|
| GET | `/admin/smtp/overview` | Cluster-wide SMTP metrics, queue size, totals, and active abuse count. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.view` |
| GET | `/admin/smtp/tenants` | Paginated tenants with live daily quotas, today's usage, and bounce metrics. Supports `?search=&page=`. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.view` |
| GET | `/admin/smtp/tenants/{user}` | Detailed tenant profile, domains, live telemetry, and 30-day historical usage ledger. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.view` |
| GET | `/admin/smtp/mailboxes` | Paginated mailboxes with domain, tenant, active state, and consecutive bounce counts. Supports `?search=&is_active=&domain_id=&page=`. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.view` |
| GET | `/admin/smtp/abuse` | Active threshold warnings for today (high bounce rate, consecutive bounces, daily spike). | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.view` |
| POST | `/admin/smtp/mailboxes/{mailbox}/toggle` | Toggle mailbox active/disabled status with optional `{ reason }`. On enable, strictly enforces active domain, active tenant, and active subscription (HTTP 422 if violated). | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.mailbox.toggle` |
| POST | `/admin/smtp/mailboxes/{mailbox}/reset-bounces` | Reset consecutive hard bounce streak to 0 in Redis with optional `{ reason }`. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.mailbox.reset_bounces` |
| POST | `/admin/smtp/mailboxes/{mailbox}/reset-password` | Reset password with optional `{ password, reason }`. Authorized for Super Admin and Deliverability Operator. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.mailbox.reset_password` |
| GET | `/admin/smtp/incidents` | Bounded, paginated list of persistent abuse incidents. Supports `?page=&per_page=&status=&severity=&incident_type=&detection_source=&tenant_id=&mailbox_id=&domain_id=&search=&date_from=&date_to=`. Max `per_page` bounded to 50. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.read` |
| GET | `/admin/smtp/incidents/{incident}` | Detailed single incident record with immutable identity snapshots, metric observations, and sanitized evidence. Supports lookup by integer ID or UUID. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.read` |
| POST | `/admin/smtp/incidents/{incident}/resolve` | Resolve or dismiss an abuse incident with optional `{ notes, status }` (`status: resolved|dismissed`). Creates persistent audit log entry. Authorized for Super Admin and Deliverability Operator. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.smtp.mailbox.toggle` |

## Admin Audit Log API (Step 16B.1 / Step 23 Enhanced)
| Method | Endpoint | Description | Middleware | Required Permission |
|---|---|---|---|---|
| GET | `/admin/audit-logs` | Paginated list of persistent audit records. Supports `?page=&per_page=&search=&action=&entity_type=&entity_id=&actor_user_id=&date_from=&date_to=&outcome=&sort_by=&direction=`. Max `per_page` bounded to 50. `outcome` supports `success`, `failure`, `denial`. `sort_by` supports `id`, `created_at`, `action`, `entity_type`. `direction` supports `asc`, `desc`. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.audit_logs.read` |
| GET | `/admin/audit-logs/{id}` | Detailed single audit event record with eager-loaded actor relationship, before/after state diff, and resolved `outcome` (`success`, `failure`, `denial`). | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.audit_logs.read` |

## RBAC Error Responses & Contracts (Step 16B.2)
- **Granular Permission Denial (HTTP 403 Forbidden):**
  When an authenticated administrator lacks the required granular permission, `RequireAdminPermission` denies access with:
  ```json
  {
    "message": "User does not have the right permissions.",
    "required_permission": "admin.plans.manage"
  }
  ```
- **Tenant Management Administrator Segregation (HTTP 403 Forbidden - `RBAC-DEC-04`):**
  When attempting to suspend or activate a user account that is an administrator (`is_admin = true` or assigned any administrative role) via `/api/admin/users/{id}/suspend` or `/api/admin/users/{id}/activate`:
  ```json
  {
    "message": "Cannot manage administrator accounts via tenant management endpoints."
  }
  ```
- **Unauthenticated / Non-Admin Perimeter Rejection (HTTP 401 / 403):**
  Unauthenticated requests receive HTTP 401 (`Unauthenticated.`). Non-admin or suspended administrators attempting to access `/api/admin/*` receive HTTP 403 (`Admin access required.`).

## Security & Tenant Isolation
- **Tenant Isolation:** Explicitly enforced via Eloquent Route Model Binding intersecting with `DomainPolicy` and `InvoicePolicy`.
- **Tenant Invoice Download Authorization (Finding 6 Remediation):** GET `/api/billing/invoices/{invoice}/download` is governed by `InvoicePolicy::view`. Access is granted only if the authenticated user is the invoice owner (`user.id === invoice.user_id`), or is an active non-suspended user possessing explicit `admin.invoices.read` permission under the `web` guard. Legacy `$user->is_admin` bypass has been eliminated.
- **Ghost Record Protection:** Endpoints modifying databases and file systems simultaneously (`DomainApiController@store`, `MailboxApiController@store`) are wrapped in `DB::transaction`.
- **Admin Perimeter & RBAC Guard:** All `/api/admin/*` routes require authenticated administrator sessions via `EnsureAdmin` and granular permission verification via `RequireAdminPermission`.

## Tenant Suspension & Billing Lifecycle Contracts (Step 16B.3)
- **Automated Tenant Suspension:**
  - Automated scheduler command `tenant:suspend-expired` runs daily.
  - Targets expired active customer tenants (`status = 'active'`, `plan_expires_at < now()`, `is_admin = false`, and zero Spatie administrative roles).
  - Transition: Tenant status moves `active` -> `suspended`. Only active domains move `active` -> `suspended`. Pending domains remain `pending`. Mailboxes retain their exact `is_active` state.
  - Emits persistent audit log with action `'tenant.auto_suspend'`.
- **Payment Renewal & Reactivation:**
  - On valid payment IPN (`/api/billing/ipn`), payload is verified for signature, total amount, and currency (`BDT`).
  - Invoice moves to `paid` and tenant moves to `active`.
  - Subscription validity is extended: if renewal is prior to expiry, extends by plan cycle from existing expiry; if renewing after expiry/suspension, extends from current timestamp.
  - Verified domains with `mx_verified = true` are restored to `active`. Pending domains remain `pending`.
  - Mailboxes independently disabled for security or abuse retain `is_active = false`.
  - Emits persistent audit log with action `'billing.invoice_paid'`.
- **Administrative Manual Tenant Suspension/Activation:**
  - `POST /api/admin/users/{id}/suspend` and `POST /api/admin/users/{id}/activate`.
  - Rejects administrator targets with HTTP 403.
  - Suspending updates user to `suspended` and only active domains to `suspended`.
  - Activating updates user to `active` and only verified domains to `active`.
  - Emits persistent audit logs with actions `'admin.users.suspend'` and `'admin.users.activate'`.

## Abuse Incident Ledger Contracts (Step 16B.4)
- **Incident Model & Identifier Resolution:**
  - `GET /api/admin/smtp/incidents/{incident}` supports transparent lookup by either auto-increment `id` or standard UUID `uuid`.
  - Bounded pagination defaults to 15 per page, bounded between 1 and 50.
  - Snapshot attributes (`tenant_email`, `domain_name`, `mailbox_email`) are immutably preserved in the response even if the underlying tenant or mailbox has been deleted.
- **Incident Resolution & Attribution:**
  - `POST /api/admin/smtp/incidents/{incident}/resolve` accepts `{ notes?: string, status?: "resolved" | "dismissed" }` (defaults to `"resolved"`).
  - Mutates incident status to `resolved` or `dismissed`, records `resolved_at = now()`, and binds `resolved_by = auth.user.id`.
  - Emits persistent audit log entries with actions `'admin.abuse_incident.resolve'` or `'admin.abuse_incident.dismiss'`.
  - Protected by `admin.smtp.mailbox.toggle` permission (Super Admin and Deliverability Operator authorized; Customer Support denied HTTP 403).

## Admin Control Plane Deliverability, Abuse & DR Health Visibility (Step 18)
- **Frontend Dashboard Integration:**
  - The Next.js Admin Control Plane consumes `GET /api/admin/smtp/incidents`, `GET /api/admin/smtp/incidents/{incident}`, and `POST /api/admin/smtp/incidents/{incident}/resolve` via SWR and Axios with CSRF cookie binding.
  - Multi-criteria server-side filtering is supported: `status` (`open`, `resolved`, `dismissed`), `severity` (`critical`, `high`, `medium`, `low`), `incident_type`, `detection_source`, `search`, `date_from`, `date_to`.
  - Standard Laravel bounded pagination (`current_page`, `last_page`, `per_page`, `total`) is respected with query persistence across page transitions.
- **Incident Action Security Boundaries:**
  - `POST /api/admin/smtp/incidents/{incident}/resolve` strictly enforces backend authorization via `RequireAdminPermission:admin.smtp.mailbox.toggle`.
  - The UI protects against duplicate submission with asynchronous request state locking (`isSubmitting`) and provides clear error feedback when permission is denied (HTTP 403).
- **Disaster Recovery Health API Gap (Section 5 & 29):**
  - **Status:** `REQUIRES ARCHITECTURE APPROVAL`
  - **Context:** The backend backup CLI engine (`backup:run`, `backup:status`, `backup:verify`, `backup:prune`) and shell scripts (`scripts/backup_db.sh`, `scripts/backup_vmail.sh`, `scripts/restore_db.sh`, `scripts/restore_vmail.sh`, `scripts/disaster_recovery.sh`) are operational (Step 17.2). However, no HTTP endpoint currently exposes backup health to web clients.
  - **Proposed Future Endpoint:** `GET /api/admin/system/backup-status`
  - **Proposed Middleware:** `auth:sanctum`, `EnsureAdmin`, `admin.permission:admin.stats.read`
  - **Security Mandate:** The web API must be strictly read-only. It must NEVER execute shell commands or Artisan processes on request, and must NEVER expose encryption keys (`BACKUP_ENCRYPTION_KEY`), SSH private keys, database passwords, or secret credentials.

## Operational Reliability, Scheduled Jobs & System Health API (Step 19)

### 1. Minimal Public Liveness Probe
| Method | Endpoint | Description | Middleware | Required Permission |
|---|---|---|---|---|
| GET | `/api/health` | Minimal public liveness probe. Fast 200 OK. Exposes zero internal topology or secrets. | `IdentifyTenant` | None (Public) |

- **Response Payload (Minimal Public):**
  ```json
  {
    "status": "HEALTHY",
    "timestamp": "2026-10-15T01:30:00+00:00"
  }
  ```

### 2. Authenticated Admin System Health Diagnostics
| Method | Endpoint | Description | Middleware | Required Permission |
|---|---|---|---|---|
| GET | `/api/admin/system/health` | Comprehensive multi-component diagnostics, scheduler heartbeat, latencies, and recent task executions. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.stats.read` |
| GET | `/api/admin/system/tasks` | Bounded paginated historical ledger of scheduled task executions. Supports `?status=&search=&date_from=&date_to=&page=&per_page=`. Max `per_page` bounded to 50. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.stats.read` |

- **Response Payload (`GET /api/admin/system/health`):**
  ```json
  {
    "status": "HEALTHY",
    "timestamp": "2026-10-15T01:30:00+00:00",
    "environment": "production",
    "components": {
      "app": {
        "status": "HEALTHY",
        "details": {
          "php_version": "8.2.20",
          "framework": "Laravel 11.0",
          "environment": "production",
          "timezone": "UTC",
          "debug_mode": false
        }
      },
      "database": {
        "status": "HEALTHY",
        "details": {
          "connection": "mysql",
          "latency_ms": 1.45
        }
      },
      "redis": {
        "status": "HEALTHY",
        "details": {
          "latency_ms": 0.82,
          "client": "phpredis"
        }
      },
      "scheduler": {
        "status": "HEALTHY",
        "details": {
          "freshness": "fresh",
          "last_run_at": "2026-10-15T01:29:00+00:00",
          "seconds_since_last_run": 60,
          "registered_tasks_count": 6
        }
      },
      "task_failures": {
        "status": "HEALTHY",
        "details": {
          "failures_24h": 0,
          "last_failure_at": null,
          "last_failed_command": null,
          "last_failure_summary": null
        }
      },
      "queue": {
        "status": "HEALTHY",
        "details": {
          "driver": "database",
          "pending_jobs": 0,
          "failed_jobs_24h": 0
        }
      },
      "backup": {
        "status": "HEALTHY",
        "details": {
          "latest_db_backup_at": "2026-10-15T02:00:00Z",
          "latest_vmail_backup_at": "2026-10-15T02:00:00Z",
          "db_verified": true,
          "encryption_enabled": true,
          "note": "Posture verified from local companion manifests; HTTP API requires approval"
        }
      }
    },
    "recent_tasks": [
      {
        "id": 1,
        "uuid": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
        "task_name": "tenant:suspend-expired",
        "expression": "* * * * *",
        "status": "success",
        "started_at": "2026-10-15T01:29:00+00:00",
        "finished_at": "2026-10-15T01:29:01+00:00",
        "duration_ms": 450,
        "failure_summary": null
      }
    ]
  }
  ```
- **Classification Invariants:**
  - `status` values across system and components: `HEALTHY`, `DEGRADED`, `UNHEALTHY`, `UNKNOWN`, `NOT_CONFIGURED`.
  - Database or Application Core failure triggers overall `UNHEALTHY`.
  - Component failure/degradation (e.g. Redis disconnect or recent task failures) triggers overall `DEGRADED`.
  - Sensitive connection parameters, passwords, encryption keys, DSNs, and stack traces are strictly scrubbed prior to output.

---

### Step 20 API Acceptance & Boundary Audit
- Confirmed minimal public liveness probe `GET /api/health` and `/up` returns HTTP 200 `{ "status": "HEALTHY", "timestamp": "..." }` with zero server topology or credential disclosure.
- Confirmed authenticated administrative system health `GET /api/admin/system/health` enforces strict RBAC (unauthenticated 401, non-admin/zero-role 403, superAdmin/operator/support 200).
- Confirmed `GET /api/admin/system/backup-status` remains **unimplemented** (`REQUIRES ARCHITECTURE APPROVAL`, HTTP 404). No ad-hoc HTTP endpoints were created.

---

## Tenant Usage, SMTP Quota & Billing Reconciliation API (Step 24)

### 1. Administrative Reconciliation Endpoints
| Method | Endpoint | Description | Middleware | Required Permission |
|---|---|---|---|---|
| GET | `/admin/reconciliation/report` | Comprehensive read-only audit report across tenant daily recipient usage, Redis vs MariaDB desync, over-quota states, subscription invariants, and billing integrity. Supports `?date=YYYY-MM-DD&tenant_id=INT`. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.stats.read` |
| GET | `/admin/reconciliation/tenants/{user}` | Drill-down single tenant usage, quota, and billing reconciliation view with detailed discrepancies. Supports `?date=YYYY-MM-DD`. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.stats.read` |

### 2. Enhanced Admin SMTP Tenant Resource Attributes (`GET /api/admin/smtp/tenants`)
- `remaining_daily_quota`: Integer remaining daily recipient quota (`null` when unlimited or telemetry unavailable).
- `quota_usage_percent`: Float percentage of daily recipient quota consumed (`null` when unlimited).
- `is_over_quota`: Boolean flag indicating recipient consumption exceeded daily quota limit.
- `subscription_state`: Categorized state: `'valid'`, `'expired'`, `'suspended'`, `'pending'`, `'no_plan'`, `'admin_exempt'`.

### 3. Tenant Activation Gate (`POST /api/admin/users/{user}/activate`)
- Returns `HTTP 422 Unprocessable Entity` when attempting to activate a customer tenant with an expired subscription (`plan_expires_at->isPast()`):
  ```json
  {
    "message": "Cannot activate tenant with an expired subscription. Tenant must renew subscription or have plan expiration extended."
  }
  ```
- Protects administrative accounts via `RBAC-DEC-04` (HTTP 403).

### 4. Billing IPN Security Boundaries (`POST /api/billing/ipn`)
- Rejects callbacks targeting `cancelled` invoices (returns `false`).
- Rejects currency mismatches (expected `BDT`, returns `false`).
- Rejects amount mismatches outside 0.01 tolerance (returns `false`).
- Idempotent under duplicate concurrent invocations via pessimistic `lockForUpdate()`.
- Domain recovery under suspended tenant reactivates strictly suspended domains (`where('status', 'suspended')`), preserving pending and unverified domains.

---

## Step 25 — Integration Verification & CLI Contracts

### 1. Local Audit Seeder Command (`php artisan emailsaas:seed-audit-demo`)
```bash
php artisan emailsaas:seed-audit-demo [--dry-run] [--cleanup]
```
- **Environment Gating:** Strictly executes when `app()->environment() === 'local'` and DB is `127.0.0.1:3306/emailsaas_local`. Halts on unapproved host or remote production IP `103.131.144.61`.
- **Idempotent Mode (default):** Safely seeds synthetic tenants, domains, mailboxes, invoices, usage records, abuse incidents, and audit logs. Re-running reuses existing records without duplicates.
- **Dry-Run Mode (`--dry-run`):** Inspects database state and reports counts of records that would be created or removed without writing database mutations.
- **Cleanup Mode (`--cleanup`):** Safely purges strictly records registered under the explicit synthetic registry (`audit-demo-` prefix, `@example.test` domain). Preserves canonical users and plans.

### 2. Standardized Security Matrix Error Responses
- **Unauthenticated Endpoint Access:** `HTTP 401 Unauthorized` (`{"message": "Unauthenticated."}`).
- **Unauthorized Tenant Admin Access:** `HTTP 403 Forbidden` (`{"message": "Admin access required."}`).
- **Missing Administrative Permission:** `HTTP 403 Forbidden` (`{"message": "Forbidden: missing required permission [admin.permission.name]."}`).
- **Cross-Tenant Resource Access:** `HTTP 403 Forbidden` (`{"message": "This action is unauthorized."}`).
- **Audit Log Ledger Mutation:** `HTTP 405 Method Not Allowed` across `POST`, `PUT`, `PATCH`, `DELETE`.

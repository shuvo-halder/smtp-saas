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
| GET | `/domains` | Paginated domains list. Supports `per_page` (clamped 1-50), `search` (domain name search), and `status` filter (`active`, `pending`). | `auth:sanctum`, `EnsureActiveSubscription` |
| POST | `/domains` | Add a new domain. Normalizes input to lowercase and trimmed before validation. Enforces plan `max_domains` cap. Triggers DKIM key generation. | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/domains/{id}` | Domain details, DNS records, aliases. Governed by `DomainPolicy::view`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/domains/{id}/verify`| Trigger DNS verification. Returns truthful verification status (`verified: boolean`, `results`, `message`, `domain`). | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| DELETE| `/domains/{id}` | Soft delete domain & cascade soft-deletes associated mailboxes and aliases in transaction. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |

## Mailbox API (Requires active subscription)
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/mailboxes` | Tenant-wide mailbox listing across all tenant domains. Supports `per_page` (clamped 1-50), `domain_id`, `status` (`active`/`disabled`), and `search` (email, local part, display name). | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/domains/{id}/mailboxes`| Paginated mailboxes for a specific domain. Supports `per_page` (clamped 1-50), `status`, `search`. Governed by `DomainPolicy::view`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/domains/{id}/mailboxes`| Create mailbox under verified domain. Strictly requires `domain.status === 'active'` (HTTP 422 if unverified/pending). Enforces plan storage cap (`storage_mb_per_mailbox`) and mailbox limits. Hashes password with SHA512-CRYPT for Dovecot. Redacts password from response. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| GET | `/mailboxes/{id}` | Single mailbox detail with associated domain. Governed by `MailboxPolicy::view`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| PUT | `/mailboxes/{id}` | Update mailbox display name (safe fields only). Governed by `MailboxPolicy::update`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/mailboxes/{id}/change-password`| Change mailbox password (min 8 chars). Hashes with SHA512-CRYPT. Governed by `MailboxPolicy::update`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/mailboxes/{id}/toggle` | Toggle active/disabled status. Disabling is always allowed for security/quarantine. Enabling strictly checks parent domain is active and tenant subscription is active (HTTP 422 if violated). Governed by `MailboxPolicy::update`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| DELETE| `/mailboxes/{id}` | Soft delete mailbox and remove virtual config. Governed by `MailboxPolicy::delete`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| GET | `/mailboxes/{mailbox}/rules` | Retrieve mailbox forwarding rules, auto-reply vacation configuration, compiled Sieve script preview, and MTA routing telemetry. Governed by `MailboxPolicy::view`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| PUT | `/mailboxes/{mailbox}/forwarding` | Update mailbox forwarding state, multi-destination list (up to 10), and local delivery copy retention (`forwarding_keep_copy`). Strictly detects self-forwarding and multi-hop forwarding loops (HTTP 422). Governed by `MailboxPolicy::update`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| PUT | `/mailboxes/{mailbox}/auto-reply` | Update mailbox vacation out-of-office auto-reply settings, date range schedule, subject, and body. Sanitizes HTML tags for XSS protection. Automatically compiles and syncs Dovecot Sieve Pigeonhole script with loop-suppression headers. Governed by `MailboxPolicy::update`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |

## Tenant Email Aliases & Forwarding API (Step 29, Requires active subscription)
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/aliases` | Tenant-wide alias listing across all tenant domains. Supports `per_page` (clamped 1-50), `domain_id`, `status` (`active`/`disabled`), and `search` (source or destination address). | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/domains/{domain}/aliases` | Paginated aliases for a specific domain. Supports `per_page` (clamped 1-50), `status`, `search`. Governed by `DomainPolicy::view`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/aliases` | Create email alias with forwarding destination under active verified domain. Strictly requires active domain (HTTP 422 if pending), active subscription, plan limit (`max_aliases_per_domain`), collision check with existing mailboxes (HTTP 422), duplicate check on domain (HTTP 422), and cycle detection for direct and circular forwarding loops (HTTP 422). | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/aliases/{alias}` | Single alias details with associated domain. Governed by `EmailAliasPolicy::view`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| PUT | `/aliases/{alias}` | Update alias forwarding destination and active status. Performs direct and circular forwarding loop detection (HTTP 422). Preserves source email. Governed by `EmailAliasPolicy::update`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| POST | `/aliases/{alias}/toggle` | Toggle active/disabled status. Disabling is always permitted. Enabling strictly requires active domain and active subscription (HTTP 422 if violated). Governed by `EmailAliasPolicy::update`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| DELETE| `/aliases/{alias}` | Permanently delete alias. Inbound forwarding stops immediately. Governed by `EmailAliasPolicy::delete`. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |

## Billing & SSO API
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/billing/subscription` | Authoritative tenant subscription posture, quota limits, today's outbound count, and remaining days. Accessible even if expired or suspended. | `IdentifyTenant`, `auth:sanctum` |
| GET | `/billing/plans` | List available subscription plans. | `IdentifyTenant`, `auth:sanctum` |
| POST | `/billing/checkout` | Initiate SSLCommerz checkout session. | `IdentifyTenant`, `auth:sanctum` |
| GET | `/billing/invoices` | Tenant's own invoices. Supports `per_page` (clamped 1-50), `page`, and `status` filter (`paid`, `pending`, `failed`, `cancelled`). | `IdentifyTenant`, `auth:sanctum` |
| GET | `/billing/invoices/{invoice}` | Single invoice details. Governed by `InvoicePolicy::view` (tenant owner or `admin.invoices.read`). | `IdentifyTenant`, `auth:sanctum` |
| GET | `/billing/invoices/{invoice}/download` | Download invoice PDF. Governed by `InvoicePolicy::view` (tenant owner or `admin.invoices.read`). | `IdentifyTenant`, `auth:sanctum` |
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

---

## Step 28 — Tenant SMTP Access, Sending Activity & Delivery Logs API Contracts

All endpoints in this group require authenticated Sanctum session and active subscription (`EnsureActiveSubscription`).

| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/api/smtp/settings` | Server connection parameters (hostname, ports 587 STARTTLS / 465 SMTPS / 25, TLS 1.2+, Dovecot SASL specification, code snippets for Node.js, Python, PHP, .env). | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/api/smtp/usage` | Real-time quota telemetry, daily recipient consumption from Redis, remaining allowance, 14-day history, and mailbox breakdown. | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/api/smtp/credentials` | List tenant's SMTP sending identities (mailboxes) under active domains. Passwords and hashes strictly excluded. | `auth:sanctum`, `EnsureActiveSubscription` |
| POST | `/api/smtp/credentials/{mailbox}/rotate-password` | Securely rotate mailbox password. Hashes with Dovecot SHA512-CRYPT, audit logs the event, and returns generated password strictly once in the response. | `auth:sanctum`, `EnsureActiveSubscription` (Scoped) |
| GET | `/api/smtp/activity` | Bounded paginated delivery activity logs (`per_page` clamped 1-50). Supports `?status=delivered\|deferred\|bounced\|rejected`, `search`, `mailbox_id`, `date_from`, `date_to`. | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/api/smtp/diagnostics` | Safe account-level configuration diagnostics (Zero SSRF risk, evaluates subscription, domains, mailboxes, quota, and bounce health). | `auth:sanctum`, `EnsureActiveSubscription` |

### Password Rotation Response (`POST /api/smtp/credentials/{mailbox}/rotate-password`)
```json
{
  "message": "Mailbox password rotated successfully.",
  "email": "user@yourdomain.com",
  "generated_password": "GeneratedPlaintextPassword16Chars",
  "warning": "This password is displayed strictly once. Copy and store it securely."
}
```

### Delivery Activity Log Resource (`GET /api/smtp/activity`)
```json
{
  "data": [
    {
      "id": 1,
      "domain_id": 2,
      "mailbox_id": 5,
      "sender_email": "sales@acme.com",
      "recipient_email": "customer@example.com",
      "queue_id": "4X9Vp30...",
      "status": "delivered",
      "dsn_code": "2.0.0",
      "smtp_code": 250,
      "response_text": "250 2.0.0 Ok: queued as 4X9Vp30...",
      "occurred_at": "2026-10-16T12:00:00Z",
      "created_at": "2026-10-16T12:00:01Z"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

---

## Step 29 & Step 30 — Tenant Email Aliases & Forwarding Routing Contracts

All alias management endpoints require an authenticated Sanctum session and an active tenant subscription (`EnsureActiveSubscription`). Authorization is strictly gated per-tenant via `EmailAliasPolicy`.

| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/api/aliases` | List tenant aliases across all owned domains with search, domain, and status filters. Bounded pagination. | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/api/domains/{domain}/aliases` | List aliases strictly belonging to the specified domain. | `auth:sanctum`, `EnsureActiveSubscription` |
| POST | `/api/aliases` | Create new alias (`domain_id`, `local_part`, `destination_email`). Enforces domain ownership, active status, plan quota, mailbox collision, and loop/cycle detection. | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/api/aliases/{alias}` | Inspect alias details, forwarding status, and live routing resolution chain. | `auth:sanctum`, `EnsureActiveSubscription` |
| PUT | `/api/aliases/{alias}` | Update alias destination email. Source email is immutable. Re-validates cycle detection. | `auth:sanctum`, `EnsureActiveSubscription` |
| POST | `/api/aliases/{alias}/toggle` | Enable or disable alias forwarding in real-time. Blocked if parent domain is inactive. | `auth:sanctum`, `EnsureActiveSubscription` |
| DELETE | `/api/aliases/{alias}` | Delete alias. Logs `alias.delete` in immutable audit trail. | `auth:sanctum`, `EnsureActiveSubscription` |

### Email Alias Resource with Routing Details (`EmailAliasResource`)
```json
{
  "data": {
    "id": 1,
    "domain_id": 2,
    "domain_name": "company.com",
    "domain_status": "active",
    "source_email": "sales@company.com",
    "destination_email": "lead-team@gmail.com",
    "is_active": true,
    "forwarding_status": "synchronized",
    "routing_details": {
      "is_routable": true,
      "source_email": "sales@company.com",
      "destination": "lead-team@gmail.com",
      "terminal_target": "lead-team@gmail.com",
      "status": "synchronized",
      "hops": [
        "sales@company.com",
        "lead-team@gmail.com"
      ],
      "hop_count": 2,
      "cycle_detected": false,
      "warning": null,
      "mta_driver": "postfix_mysql_map"
    },
    "created_at": "2026-10-16T12:00:00Z",
    "updated_at": "2026-10-16T12:00:00Z"
  }
}
```

### Forwarding Status Semantics
- **`synchronized`**: Alias is active, domain is active and verified, and database lookup for Postfix `virtual_alias_maps` is valid and routable.
- **`operational`**: Live Postfix MTA daemon verified running and processing recipient resolution socket queries.
- **`configured`**: Stored in database but inactive (e.g. disabled by tenant, or parent domain is pending/unverified).
- **`failed`**: Routing resolution failed (e.g., local destination points to nonexistent mailbox or expansion cycle detected).
- **`unknown`**: Alias record not found or parent domain deleted.

### CLI Diagnostics Tool (`php artisan mail:verify-alias`)
```bash
# Verify single alias routing chain
php artisan mail:verify-alias sales@company.com

# Audit all aliases across all domains in the database
php artisan mail:verify-alias --all
```

---

## Step 31 — Tenant Mailbox Storage & Quota Management Contracts

All mailbox storage endpoints require an authenticated Sanctum session and an active tenant subscription (`EnsureActiveSubscription`). Mailbox authorization is strictly gated per-tenant via `MailboxPolicy`.

| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/api/mailboxes/storage` | Tenant-wide mailbox storage overview and aggregated telemetry (total provisioned storage, plan storage limit, total measured usage, high-utilization mailboxes, status breakdown, and per-mailbox storage breakdown with optional `status`, `domain_id`, and `search` filters). | `auth:sanctum`, `EnsureActiveSubscription` |
| GET | `/api/mailboxes/{mailbox}/storage` | Detailed storage telemetry for a single owned mailbox (quota, usage bytes/MB, remaining bytes, utilization percentage, status, status label, measurement source, and measurement timestamp). | `auth:sanctum`, `EnsureActiveSubscription` |

### Embedded Storage in `MailboxResource` (`GET /api/mailboxes`, `GET /api/mailboxes/{id}`)
Every mailbox listed or retrieved automatically contains an embedded `storage` object:
```json
{
  "id": 1,
  "domain_id": 2,
  "email": "user@company.com",
  "name": "Jane Doe",
  "quota_mb": 1024,
  "is_active": true,
  "storage": {
    "mailbox_id": 1,
    "email": "user@company.com",
    "quota_mb": 1024,
    "quota_bytes": 1073741824,
    "quota_formatted": "1 GB",
    "is_measured": true,
    "usage_bytes": 209715200,
    "usage_mb": 200,
    "usage_formatted": "200 MB",
    "remaining_bytes": 864026624,
    "remaining_formatted": "824 MB",
    "utilization_percent": 19.5,
    "status": "normal",
    "status_label": "Normal",
    "measured_at": "2026-10-16T12:00:00Z",
    "measurement_source": "doveadm"
  }
}
```

### Tenant Storage Overview Contract (`GET /api/mailboxes/storage`)
```json
{
  "data": {
    "summary": {
      "total_mailboxes": 12,
      "measured_mailboxes": 12,
      "unmeasured_mailboxes": 0,
      "total_quota_mb": 12288,
      "total_quota_formatted": "12 GB",
      "plan_storage_limit_mb": 51200,
      "plan_storage_limit_formatted": "50 GB",
      "total_usage_bytes": 2411724800,
      "total_usage_mb": 2300,
      "total_usage_formatted": "2.25 GB",
      "overall_utilization_percent": 18.7,
      "status_counts": {
        "normal": 10,
        "warning": 1,
        "near_limit": 1,
        "over_limit": 0,
        "unknown": 0
      }
    },
    "mailboxes": [
      {
        "mailbox_id": 1,
        "email": "user@company.com",
        "domain_id": 2,
        "domain_name": "company.com",
        "quota_mb": 1024,
        "quota_bytes": 1073741824,
        "quota_formatted": "1 GB",
        "is_measured": true,
        "usage_bytes": 209715200,
        "usage_mb": 200,
        "usage_formatted": "200 MB",
        "remaining_bytes": 864026624,
        "remaining_formatted": "824 MB",
        "utilization_percent": 19.5,
        "status": "normal",
        "status_label": "Normal",
        "measured_at": "2026-10-16T12:00:00Z",
        "measurement_source": "doveadm"
      }
    ]
  }
}
```

### Storage Status Semantics & Thresholds
- **`normal`**: Utilization is under 80% ($0.0\% \le \text{usage} < 80.0\%$).
- **`warning`**: Utilization is between 80% and 89.9% ($80.0\% \le \text{usage} < 90.0\%$). Quota alert badge rendered in amber.
- **`near_limit`**: Utilization is between 90% and 99.9% ($90.0\% \le \text{usage} < 100.0\%$). Critical warning rendered in orange/red.
- **`over_limit`**: Mailbox storage has reached or exceeded 100% quota ($\text{usage} \ge 100.0\%$). Dovecot rejects or defers inbound messages under `quota_rule`; UI displays destructive badge and disables remaining capacity.
- **`unknown`**: Storage cannot be probed or Dovecot service is unavailable (e.g. local Windows development or unmounted mail storage). `is_measured` is explicitly `false`, `usage_bytes` is `null`, and UI displays an informational `Unmeasured` badge. **Unmeasured storage is NEVER reported as 0% or 0 bytes.**

---

## Step 32 — Tenant Catch-All Mailbox & Inbound Email Routing Rules Contracts

All catch-all endpoints require an authenticated Sanctum session and an active tenant subscription (`EnsureActiveSubscription`). Authorization is strictly gated per-tenant via `DomainPolicy`.

| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/api/domains/{domain}/catch-all` | Inspect domain catch-all policy, designated destination mailbox, Postfix routing diagnostics, and eligible mailboxes on the domain. | `auth:sanctum`, `EnsureActiveSubscription` |
| PUT | `/api/domains/{domain}/catch-all` | Update domain catch-all policy (`disabled`, `reject`, `mailbox`, `discard`) and optional destination mailbox. Synchronizes Postfix routing maps and emits audit event. | `auth:sanctum`, `EnsureActiveSubscription` |

### Catch-All Details Resource (`GET /api/domains/{domain}/catch-all`)
```json
{
  "data": {
    "domain_id": 1,
    "domain_name": "company.com",
    "domain_status": "active",
    "is_verified": true,
    "policy": "mailbox",
    "is_enabled": true,
    "mailbox": {
      "id": 5,
      "email": "inbox@company.com",
      "local_part": "inbox",
      "display_name": "General Inbox",
      "is_active": true
    },
    "routing": {
      "domain_name": "company.com",
      "policy": "mailbox",
      "is_routable": true,
      "action": "mailbox",
      "probe_email": "catchall_probe_a1b2c3@company.com",
      "destination": "inbox@company.com",
      "terminal_target": "inbox@company.com",
      "status": "synchronized",
      "warning": null,
      "mta_driver": "postfix_mysql_map"
    },
    "available_mailboxes": [
      {
        "id": 5,
        "email": "inbox@company.com",
        "local_part": "inbox",
        "display_name": "General Inbox",
        "is_active": true
      }
    ],
    "updated_at": "2026-10-17T00:00:00Z"
  }
}
```

### Catch-All Update Request (`PUT /api/domains/{domain}/catch-all`)
```json
{
  "policy": "mailbox",
  "mailbox_id": 5
}
```

### Policy Allowlist & Semantics
- **`disabled` (Default)**: Catch-all is inactive. Inbound mail sent to unknown recipients falls through to native Postfix recipient validation and is rejected during the SMTP dialog (`550 5.1.1 User unknown in virtual mailbox table`). This is the secure default.
- **`reject`**: Explicitly rejects unknown recipients with RFC 550. Functionally equivalent to `disabled` in MTA routing.
- **`mailbox`**: Routes unknown recipients to an active designated mailbox on the same domain (`catch_all_mailbox_id`).
  - Precedence Guarantee: Physical mailboxes (`mailboxes`) and explicit aliases (`email_aliases`) take strict precedence over catch-all via `NOT EXISTS` query clauses.
  - Requires `mailbox_id` pointing to an active, non-deleted mailbox belonging to the same domain and tenant.
- **`discard`**: Acknowledges delivery with SMTP 250 OK and silently drops messages to `devnull@localhost` without generating a bounce.
  - Requires explicit confirmation in the UI; displays destructive discard warning alert.

---

## Step 33 — Tenant Mailbox Forwarding Rules & Auto-Reply Management Contracts

All mailbox forwarding and auto-reply endpoints require an authenticated Sanctum session, an active tenant subscription (`EnsureActiveSubscription`), and an active verified parent domain. Authorization is strictly governed per-tenant via `MailboxPolicy`.

| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/api/mailboxes/{mailbox}/rules` | Inspect mailbox forwarding rules, auto-reply vacation response settings, Sieve script preview, and Postfix MTA forwarding diagnostics. | `auth:sanctum`, `EnsureActiveSubscription` |
| PUT | `/api/mailboxes/{mailbox}/forwarding` | Update mailbox forwarding configuration, multi-destination list, and local copy retention. Validates against self-forwarding and multi-hop forwarding cycles. | `auth:sanctum`, `EnsureActiveSubscription` |
| PUT | `/api/mailboxes/{mailbox}/auto-reply` | Update mailbox auto-reply (vacation responder) settings, active window, subject, and body. Sanitizes HTML tags and recompiles Dovecot Sieve script. | `auth:sanctum`, `EnsureActiveSubscription` |

### Mailbox Rules Details Resource (`GET /api/mailboxes/{mailbox}/rules`)
```json
{
  "data": {
    "mailbox_id": 12,
    "email": "user@example.com",
    "domain_name": "example.com",
    "is_active": true,
    "forwarding": {
      "is_enabled": true,
      "destinations": [
        "external@gmail.com",
        "backup@otherdomain.com"
      ],
      "keep_copy": true,
      "destinations_count": 2
    },
    "auto_reply": {
      "is_enabled": true,
      "subject": "Out of Office: Annual Leave",
      "body": "Hello, I am out of the office until next Monday. For urgent matters, contact support@example.com.",
      "start_at": "2026-10-18T00:00:00.000000Z",
      "end_at": "2026-10-25T23:59:59.000000Z",
      "is_active_now": false,
      "schedule_status": "scheduled"
    },
    "sieve": {
      "compiled_script": "require [\"vacation\"];\n\n# Dovecot Sieve Vacation Script\n...",
      "is_compiled": true,
      "storage_driver": "dovecot_sieve_pigeonhole",
      "rate_limit_days": 1
    },
    "mta": {
      "is_routable": true,
      "forwarding_action": "forward_and_deliver_local",
      "effective_destinations": [
        "user@example.com",
        "external@gmail.com",
        "backup@otherdomain.com"
      ],
      "driver": "postfix_mysql_forwarding_map"
    }
  }
}
```

### Forwarding Update Request (`PUT /api/mailboxes/{mailbox}/forwarding`)
```json
{
  "is_forwarding_enabled": true,
  "forwarding_destinations": [
    "manager@externalcorp.com",
    "personal@gmail.com"
  ],
  "forwarding_keep_copy": true
}
```

### Auto-Reply Update Request (`PUT /api/mailboxes/{mailbox}/auto-reply`)
```json
{
  "auto_reply_enabled": true,
  "auto_reply_subject": "Out of Office: Vacation",
  "auto_reply_body": "I am traveling with limited email access. Please contact support for urgent issues.",
  "auto_reply_start_at": "2026-10-20T00:00:00Z",
  "auto_reply_end_at": "2026-10-27T23:59:59Z"
}
```

---

## Step 34 — Domain DNS Records Wizard, Inbound Authentication & Deliverability Diagnostics Contracts

All DNS wizard and verification endpoints require an authenticated Sanctum session and an active tenant subscription (`EnsureActiveSubscription`). Authorization is strictly gated per-tenant via `DomainPolicy`. Endpoints are protected by rate limiting (`throttle:30,1`) to prevent external DNS resolver exhaustion.

| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/api/domains/{domain}/dns-wizard` | Retrieve required/recommended DNS records, verification statuses, deliverability health score, diagnostic layer breakdown, and actionable recommendations. | `auth:sanctum`, `EnsureActiveSubscription`, `throttle:30,1` |
| POST | `/api/domains/{domain}/verify-dns` | Perform live multi-record DNS query for MX, SPF, DKIM, DMARC, A, and CNAME. Updates domain verification flags and emits audit event. | `auth:sanctum`, `EnsureActiveSubscription`, `throttle:30,1` |
| POST | `/api/domains/{domain}/verify` | Backwards-compatible alias for `POST /api/domains/{domain}/verify-dns`. | `auth:sanctum`, `EnsureActiveSubscription`, `throttle:30,1` |

### DNS Wizard Data Resource (`GET /api/domains/{domain}/dns-wizard`)
```json
{
  "data": {
    "domain": {
      "id": 1,
      "name": "company.com",
      "status": "active",
      "is_active": true,
      "is_verified": true,
      "last_dns_check_at": "2026-10-17T02:00:00.000000Z"
    },
    "score": {
      "score": 100,
      "rating": "EXCELLENT",
      "status": "EXCELLENT",
      "breakdown": {
        "mx": { "weight": 40, "earned": 40, "verified": true, "label": "Inbound MX Routing" },
        "spf": { "weight": 25, "earned": 25, "verified": true, "label": "Sender Policy Framework (SPF)" },
        "dkim": { "weight": 25, "earned": 25, "verified": true, "label": "DKIM Signature Record" },
        "dmarc": { "weight": 10, "earned": 10, "verified": true, "label": "DMARC Policy Record" }
      }
    },
    "records": [
      {
        "id": "mx",
        "type": "MX",
        "host": "@",
        "value": "mail.company.com",
        "priority": 10,
        "purpose": "Inbound mail routing",
        "is_required": true,
        "verified": true,
        "status": "VERIFIED",
        "current_values": ["mail.company.com"],
        "error": null
      },
      {
        "id": "spf",
        "type": "TXT",
        "host": "@",
        "value": "v=spf1 mx ~all",
        "priority": null,
        "purpose": "SPF sender authentication",
        "is_required": true,
        "verified": true,
        "status": "VERIFIED",
        "current_values": ["v=spf1 mx ~all"],
        "error": null
      },
      {
        "id": "dkim",
        "type": "TXT",
        "host": "default._domainkey",
        "value": "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...",
        "priority": null,
        "purpose": "DKIM cryptographic signature",
        "is_required": true,
        "verified": true,
        "status": "VERIFIED",
        "current_values": ["v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA..."],
        "error": null
      },
      {
        "id": "dmarc",
        "type": "TXT",
        "host": "_dmarc",
        "value": "v=DMARC1; p=quarantine; pct=100",
        "priority": null,
        "purpose": "DMARC deliverability protection policy",
        "is_required": false,
        "verified": true,
        "status": "VERIFIED",
        "current_values": ["v=DMARC1; p=quarantine; pct=100"],
        "error": null
      },
      {
        "id": "mail_host",
        "type": "A",
        "host": "mail",
        "value": "127.0.0.1",
        "priority": null,
        "purpose": "Mail server IP address",
        "is_required": true,
        "verified": true,
        "status": "VERIFIED",
        "current_values": ["127.0.0.1"],
        "error": null
      },
      {
        "id": "autodiscover",
        "type": "CNAME",
        "host": "autodiscover",
        "value": "mail.company.com",
        "priority": null,
        "purpose": "Automatic client email configuration",
        "is_required": false,
        "verified": true,
        "status": "VERIFIED",
        "current_values": ["mail.company.com"],
        "error": null
      }
    ],
    "diagnostics": {
      "inbound_routing": {
        "status": "VERIFIED",
        "primary_mx": "mail.company.com",
        "resolved_hosts": ["mail.company.com"],
        "points_to_us": true
      },
      "outbound_authentication": {
        "spf": { "status": "VERIFIED", "multiple_records": false, "raw_record": "v=spf1 mx ~all" },
        "dkim": { "status": "VERIFIED", "selector": "default", "key_configured": true },
        "dmarc": { "status": "VERIFIED", "policy": "quarantine", "syntax_valid": true }
      },
      "infrastructure": {
        "dns_published": true,
        "application_configured": true,
        "mta_operational": false,
        "mta_status": "local_development",
        "summary": "DNS records are published. Mail server MTA operational check is not available in local development."
      }
    },
    "recommendations": []
  }
}
```

### DNS Verification Response (`POST /api/domains/{domain}/verify-dns`)
```json
{
  "message": "DNS records verified successfully",
  "verified": true,
  "status": "active",
  "results": {
    "mx": { "verified": true, "status": "VERIFIED", "expected": "mail.company.com", "found": ["mail.company.com"], "error": null },
    "spf": { "verified": true, "status": "VERIFIED", "expected": "v=spf1 mx ~all", "found": ["v=spf1 mx ~all"], "error": null },
    "dkim": { "verified": true, "status": "VERIFIED", "expected": "v=DKIM1; k=rsa; p=...", "found": ["v=DKIM1; k=rsa; p=..."], "error": null },
    "dmarc": { "verified": true, "status": "VERIFIED", "expected": "v=DMARC1; p=quarantine; pct=100", "found": ["v=DMARC1; p=quarantine; pct=100"], "error": null }
  },
  "deliverability_score": {
    "score": 100,
    "rating": "EXCELLENT",
    "status": "EXCELLENT",
    "breakdown": {
      "mx": { "weight": 40, "earned": 40, "verified": true, "label": "Inbound MX Routing" },
      "spf": { "weight": 25, "earned": 25, "verified": true, "label": "Sender Policy Framework (SPF)" },
      "dkim": { "weight": 25, "earned": 25, "verified": true, "label": "DKIM Signature Record" },
      "dmarc": { "weight": 10, "earned": 10, "verified": true, "label": "DMARC Policy Record" }
    }
  }
}
```

### DNS Status Semantics & Error Codes
- **`VERIFIED`**: Record is resolved and matches expected configuration.
- **`MISMATCH`**: Record is resolved but value differs from expected (e.g. multiple SPF records violating RFC 7208, non-matching DKIM public key, MX priority conflict).
- **`MISSING`**: Query completed successfully with zero records found in DNS.
- **`CHECK_FAILED`**: DNS lookup failed due to network timeout or lookup error.
- **`NOT_CONFIGURED`**: DKIM key or server configuration is missing in the database.
- **`EXPECTED`**: Baseline unverified state awaiting lookup.

---

## Step 35 — Domain Email Authentication Hardening & Tenant Deliverability Reports

All Step 35 endpoints require an authenticated Sanctum session and an active tenant subscription (`EnsureActiveSubscription`). Authorization is strictly gated per-tenant via `DomainPolicy`.

| Method | Endpoint | Description | Middleware | Rate Limit |
|---|---|---|---|---|
| GET | `/api/domains/{domain}/deliverability-report` | Full deliverability report including domain status, DNS drift indicator, weighted 4-pillar deliverability score (MX 40%, SPF 25%, DKIM 25%, DMARC 10%), detailed diagnostic breakdown, DMARC policy advisor with escalation matrix, and historical audit log timeline. Supports optional `?refresh=1` query parameter to force fresh authoritative DNS resolution. | `auth:sanctum`, `EnsureActiveSubscription` | Standard |
| POST | `/api/domains/{domain}/parse-dmarc-report` | Securely parses an RFC 7489 DMARC XML aggregate feedback report uploaded as a multipart file (`report_file`, max 5MB) or raw string payload (`xml_content`). Protected against XXE (`LIBXML_NONET`), validates domain ownership match, extracts aggregate metrics, and returns a deterministic SHA-256 deduplication digest. | `auth:sanctum`, `EnsureActiveSubscription` | `throttle:10,1` (10 per minute) |

### Deliverability Report Response (`GET /api/domains/{domain}/deliverability-report`)
```json
{
  "data": {
    "domain": {
      "id": 1,
      "domain_name": "company.com",
      "status": "active",
      "is_verified": true,
      "activated_at": "2026-10-16T12:00:00Z",
      "last_dns_check_at": "2026-10-27T17:00:00Z",
      "dns_drift_detected": false
    },
    "deliverability_score": {
      "score_percent": 100,
      "status": "ready",
      "status_label": "Ready for Production",
      "summary": "All required DNS and authentication records are verified. Excellent deliverability posture.",
      "breakdown": {
        "mx": { "weight": 40, "earned": 40, "status": "VERIFIED" },
        "spf": { "weight": 25, "earned": 25, "status": "VERIFIED" },
        "dkim": { "weight": 25, "earned": 25, "status": "VERIFIED" },
        "dmarc": { "weight": 10, "earned": 10, "status": "VERIFIED" }
      },
      "disclaimer": "Notice: DNS verification confirms cryptographic publication and routing records on authoritative nameservers. It does NOT guarantee external inbox placement, third-party spam filter exemption, or sender reputation."
    },
    "inbound_routing": {
      "status": "operational",
      "mx_target": "mail.company.com",
      "mx_priority": 10,
      "details": "Inbound MX records are operational."
    },
    "outbound_authentication": {
      "spf_status": "VERIFIED",
      "dkim_status": "VERIFIED",
      "dmarc_status": "VERIFIED",
      "spf_policy": "~all",
      "dmarc_policy": "quarantine",
      "details": "Outbound authentication fully aligned."
    },
    "infrastructure_status": {
      "dns_publication": "published",
      "application_config": "active",
      "mail_server_mta": "local_development",
      "mta_driver": "postfix_mysql_map",
      "mta_note": "Local development mode. External DNS resolution uses system resolvers."
    },
    "dmarc_policy_advisor": {
      "current_policy": "quarantine",
      "current_policy_explanation": "Current policy is quarantine: Failing emails are directed to spam folders.",
      "policies": {
        "none": {
          "name": "Monitoring (p=none)",
          "status": "available",
          "description": "Mail is delivered normally without rejection. Feedback reports are collected to identify sending sources.",
          "implication": "Zero risk to legitimate mail, but provides no spoofing protection.",
          "recommended_when": "First 2-4 weeks of domain configuration."
        },
        "quarantine": {
          "name": "Spam Quarantine (p=quarantine)",
          "status": "active",
          "description": "Suspicious or unauthenticated mail is redirected to spam/junk folders.",
          "implication": "Protects recipients from phishing while allowing inspection of false positives.",
          "recommended_when": "All legitimate sending sources have been identified and configured."
        },
        "reject": {
          "name": "Strict Rejection (p=reject)",
          "status": "recommended",
          "description": "Unauthenticated mail is blocked outright by recipient mail transfer agents.",
          "implication": "Maximum security against impersonation. Any unaligned legitimate mail will bounce.",
          "recommended_when": "All senders pass SPF or DKIM alignment with zero legitimate failures in reports."
        }
      },
      "policy_escalation_rule": "Never escalate directly to p=reject without first observing 0% legitimate delivery failures under p=none or p=quarantine for at least two weeks."
    },
    "verification_history": [
      {
        "id": 45,
        "checked_at": "2026-10-27T17:00:00Z",
        "score": 100,
        "status": "active",
        "mx_status": "VERIFIED",
        "spf_status": "VERIFIED",
        "dkim_status": "VERIFIED",
        "dmarc_status": "VERIFIED",
        "dns_drift_detected": false,
        "actor_email": "tenant@company.com"
      }
    ],
    "recommendations": []
  }
}
```

### Parse DMARC Report Response (`POST /api/domains/{domain}/parse-dmarc-report`)
```json
{
  "message": "DMARC report parsed successfully.",
  "data": {
    "report_metadata": {
      "org_name": "google.com",
      "email": "noreply-dmarc-support@google.com",
      "report_id": "9876543210987654321",
      "date_range_begin": "2026-10-26 00:00:00 UTC",
      "date_range_end": "2026-10-26 23:59:59 UTC"
    },
    "policy_published": {
      "domain": "company.com",
      "policy": "quarantine",
      "subdomain_policy": "quarantine",
      "percentage": 100,
      "dkim_alignment": "r",
      "spf_alignment": "r"
    },
    "summary": {
      "total_messages": 125,
      "passed_messages": 120,
      "failed_messages": 5,
      "unique_source_ips": 3
    },
    "records": [
      {
        "source_ip": "198.51.100.1",
        "count": 120,
        "disposition": "none",
        "dkim_result": "pass",
        "spf_result": "pass"
      },
      {
        "source_ip": "203.0.113.5",
        "count": 5,
        "disposition": "quarantine",
        "dkim_result": "fail",
        "spf_result": "fail"
      }
    ],
    "deduplication_digest": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
  }
}
```







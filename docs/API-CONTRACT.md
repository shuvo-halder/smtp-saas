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

## Admin Audit Log API (Step 16B.1 / 16B.2 Granular RBAC)
| Method | Endpoint | Description | Middleware | Required Permission |
|---|---|---|---|---|
| GET | `/admin/audit-logs` | Paginated list of persistent audit records. Supports `?page=&per_page=&search=&action=&entity_type=&entity_id=&actor_user_id=&date_from=&date_to=`. Max `per_page` bounded to 50. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.audit_logs.view` |
| GET | `/admin/audit-logs/{id}` | Detailed single audit event record with eager-loaded actor relationship. | `auth:sanctum`, `EnsureAdmin`, `admin.permission` | `admin.audit_logs.view` |

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

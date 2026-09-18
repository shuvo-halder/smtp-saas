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

## Admin API
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/admin/stats` | Global platform metrics and charts data. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/charts` | Revenue and signup timeseries data. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/server-stats`| Mail queue, IMAP connections, disk usage. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/users` | Paginated list of all tenants. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/users/{id}` | Single tenant drill-down (with relations). | `auth:sanctum`, `EnsureAdmin` |
| POST | `/admin/users/{id}/suspend`| Suspend a tenant and their domains. | `auth:sanctum`, `EnsureAdmin` |
| POST | `/admin/users/{id}/activate`| Reactivate a tenant and their domains. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/domains` | Paginated list of all domains. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/mailboxes` | Paginated list of all mailboxes. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/plans` | List available subscription plans. | `auth:sanctum`, `EnsureAdmin` |
| POST | `/admin/plans` | Create a new subscription plan. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/plans/{plan}` | Retrieve specific plan details. | `auth:sanctum`, `EnsureAdmin` |
| PUT | `/admin/plans/{plan}` | Update a specific plan. | `auth:sanctum`, `EnsureAdmin` |
| DELETE | `/admin/plans/{plan}` | Delete an unused plan. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/invoices` | Paginated list of all invoices. | `auth:sanctum`, `EnsureAdmin` |

## Admin SMTP Management API (Step 16A)
| Method | Endpoint | Description | Middleware |
|---|---|---|---|
| GET | `/admin/smtp/overview` | Cluster-wide SMTP metrics, queue size, totals, and active abuse count. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/smtp/tenants` | Paginated tenants with live daily quotas, today's usage, and bounce metrics. Supports `?search=&page=`. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/smtp/tenants/{user}` | Detailed tenant profile, domains, live telemetry, and 30-day historical usage ledger. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/smtp/mailboxes` | Paginated mailboxes with domain, tenant, active state, and consecutive bounce counts. Supports `?search=&is_active=&domain_id=&page=`. | `auth:sanctum`, `EnsureAdmin` |
| GET | `/admin/smtp/abuse` | Active threshold warnings for today (high bounce rate, consecutive bounces, daily spike). | `auth:sanctum`, `EnsureAdmin` |
| POST | `/admin/smtp/mailboxes/{mailbox}/toggle` | Toggle mailbox active/disabled status with optional `{ reason }`. On enable, strictly enforces active domain, active tenant, and active subscription (HTTP 422 if violated). | `auth:sanctum`, `EnsureAdmin` |
| POST | `/admin/smtp/mailboxes/{mailbox}/reset-bounces` | Reset consecutive hard bounce streak to 0 in Redis with optional `{ reason }`. | `auth:sanctum`, `EnsureAdmin` |
| POST | `/admin/smtp/mailboxes/{mailbox}/reset-password` | Reset password with optional `{ password, reason }`. If blank, generates secure 16-char password. Returns plaintext once in response; hashes with SHA512-CRYPT. | `auth:sanctum`, `EnsureAdmin` |

## Security & Tenant Isolation
- **Tenant Isolation:** Explicitly enforced via Eloquent Route Model Binding intersecting with `DomainPolicy` and `InvoicePolicy`.
- **Ghost Record Protection:** Endpoints modifying databases and file systems simultaneously (`DomainApiController@store`, `MailboxApiController@store`) are wrapped in `DB::transaction`.
- **Admin SMTP Guard:** All `/api/admin/smtp/*` routes require authenticated administrator sessions via `EnsureAdmin`. Plaintext passwords are never stored in databases, never written to log files, and masked in Eloquent serialization via `$hidden`.


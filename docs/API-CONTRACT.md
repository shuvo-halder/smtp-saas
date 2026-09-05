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
| GET | `/admin/invoices` | Paginated list of all invoices. | `auth:sanctum`, `EnsureAdmin` |

## Security & Tenant Isolation
- **Tenant Isolation:** Explicitly enforced via Eloquent Route Model Binding intersecting with `DomainPolicy` and `InvoicePolicy`.
- **Ghost Record Protection:** Endpoints modifying databases and file systems simultaneously (`DomainApiController@store`, `MailboxApiController@store`) are wrapped in `DB::transaction`.

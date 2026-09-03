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

# Database Architecture

EmailSaaS utilizes a single, shared MariaDB relational database (`email_saas_db`). It acts as the ultimate source of truth for both the Laravel Application and the physical Mail Daemons (Postfix & Dovecot).

## Core Tables

### `users` (Tenants)
- **Primary Key:** `id`
- **Fields:** `name`, `email`, `password`, `status` (`active`, `suspended`, `pending`), `plan_id`, `plan_expires_at`
- **Role:** Core tenant identity. `IdentifyTenant` middleware binds requests to a `User` instance.

### `plans`
- **Primary Key:** `id`
- **Fields:** `name`, `max_domains`, `max_mailboxes_per_domain`, `storage_mb_per_mailbox`, `price_monthly`, `price_yearly`, `daily_outbound_recipients`, `mailbox_daily_outbound_recipients`
- **Role:** Defines subscription constraints.
- **Quota Semantics:** `-1` denotes unlimited. `0` denotes disabled. Positive integers denote the finite daily UTC window limit.

### `tenant_outbound_usage`
- **Primary Key:** `id`
- **Foreign Key:** `user_id` (Belongs to `User`)
- **Fields:** `usage_date`, `recipient_count`
- **Unique Constraint:** `(user_id, usage_date)`
- **Role:** Durable historical reporting ledger for outbound SMTP quota usage. 
- **Note:** Real-time quota enforcement runs in Redis. This table is strictly for historical ledger/billing visibility synced via Laravel Scheduler.

### `domains` (Virtual Domains)
- **Primary Key:** `id`
- **Foreign Key:** `user_id` (Belongs to `User`)
- **Fields:** `domain_name` (UNIQUE), `status` (`pending`, `active`, `suspended`), `mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `dkim_public_key`
- **Integration:** Postfix (`mysql-virtual-mailbox-domains.cf`) queries this table directly to determine if it should accept mail for a domain. `status` must be `active`.

### `mailboxes` (Virtual Users)
- **Primary Key:** `id`
- **Foreign Key:** `domain_id` (Belongs to `Domain`)
- **Fields:** `local_part`, `email` (UNIQUE), `password` (SHA512-CRYPT), `quota_mb`, `is_active`
- **Integration:** 
    - Dovecot (`dovecot-sql.conf.ext`) authenticates IMAP/POP3 logins directly against the `password` column using `SHA512-CRYPT`.
    - Postfix (`mysql-virtual-mailbox-maps.cf`) queries this table to resolve the physical `/var/vmail/` delivery path.
- **Tenant Security:** Mutated exclusively through Laravel controllers using `DB::transaction`. Bash scripts do NOT insert records here.

### `invoices`
- **Primary Key:** `id`
- **Foreign Key:** `user_id`, `plan_id`
- **Fields:** `invoice_number`, `total`, `status` (`pending`, `paid`, `failed`, `cancelled`), `transaction_id`
- **Role:** Payment ledger for SSLCommerz IPN verification.

## Cross-System Coupling
> **CRITICAL RULE:** Do NOT alter the schemas of `domains` or `mailboxes` without simultaneously verifying and updating `/etc/postfix/mysql-virtual-mailbox-*.cf` and `/etc/dovecot/dovecot-sql.conf.ext`. The mail stack relies precisely on the current table names and column structures.

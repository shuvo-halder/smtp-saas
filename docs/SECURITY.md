# Security Boundaries

## 1. Multi-Tenancy Data Isolation
Tenant data (domains, mailboxes, invoices) is isolated natively within Laravel.
- **Enforcement:** `IdentifyTenant` middleware binds the HTTP request origin/host to a `User`.
- **Controllers:** Resource endpoints explicitly use Eloquent Route Model Binding intersecting with Laravel Policies (e.g., `$this->authorize('view', $domain)`). 
- **Rule:** Never use `Model::find($id)` without a surrounding tenant scope. Always use `$request->user()->domains()->findOrFail($id)`.

## 2. API Authentication
- **Sanctum:** Uses cookie-based, stateful authentication for the dashboard.
- **CSRF:** Next.js requests must fetch the Sanctum CSRF cookie and pass the `X-XSRF-TOKEN` header. 
- **Exceptions:** Webhooks (`/api/billing/ipn`) are exempt from CSRF and authenticated via MD5 hash signature verification.

## 3. Privilege Escalation Protection (Shell Scripts)
Laravel runs as `www-data`, but creating `/var/vmail/` directories and DKIM keys requires `root` privileges.
- **Enforcement:** `www-data` is strictly granted `sudo NOPASSWD` access **only** to specific, parameterized shell scripts inside `scripts/`.
- **Parameter Validation:** Laravel strictly validates domain names and email prefixes via Regex before passing them as CLI arguments to prevent arbitrary command injection.

## 4. Mailbox Password Security
- **Hashing:** Mailbox passwords are NEVER stored in plaintext or standard Laravel `bcrypt`. They are hashed using `SHA512-CRYPT` via PHP's `crypt()` function.
- **Reason:** Dovecot natively supports `SHA512-CRYPT` for SQL authentication lookups.

## 5. Webmail SSO Security
- **OTP Generation:** When a user clicks "Webmail", Laravel generates a 32-character random OTP and stores it in Redis.
- **TTL:** The Redis key expires in exactly 60 seconds.
- **Validation:** Once Roundcube consumes the OTP via Dovecot Master User, the OTP is destroyed.

## 6. Payment Security (SSLCommerz)
- **Hash Validation:** The IPN webhook calculates an MD5 hash of the incoming payload using the `store_passwd` secret and compares it against the gateway's signature.
- **Amount Validation:** The system strictly verifies that `paid_amount == invoice_total`. If they mismatch, the invoice is NOT marked paid.

## 7. Admin Control Plane
- **Enforcement:** Admin routes (`/api/admin/*`) are globally isolated behind `EnsureAdmin` middleware.
- **Metrics Safety:** Server metrics (like `postqueue -p`) execute safe, static string queries via `shell_exec`. Command injection is impossible by design, as no user input is passed to the shell.

## 8. SMTP & Mail Delivery Threat Model
- **Cross-Tenant Sender Spoofing:** (SECURE) Postfix utilizes `smtpd_sender_login_maps` mapped to MySQL (`mailboxes` and `email_aliases`) and `reject_sender_login_mismatch`, preventing authenticated SASL users from spoofing other tenants' sender addresses.
- **Outbound Quota Enforcement:** (SECURE) Postfix enforces daily recipient limits via policy delegation to `127.0.0.1:10031` (`smtpd_data_restrictions`). Quotas are tracked atomically in Redis (`OutboundQuotaService`) per Tenant and per Mailbox. Over-quota submissions are permanently rejected (`554 5.7.1`).
- **Policy Socket Isolation:** The Policy Daemon socket binds strictly to `127.0.0.1:10031` (or local UNIX domain socket with `0660` permissions). It is never exposed publicly or accessible outside the local host.
- **Fail-Open Policy:** If the policy daemon or Redis encounters an outage, Postfix and the Policy Daemon fail-open (`DUNNO`) to avoid blocking mission-critical business email, while emitting structured alerts to `storage/logs/policy.log`.
- **Suspension Enforcement:** (SECURE) Dovecot's `user_query` strictly enforces `domains.status = 'active'`, and `PolicyDecisionService` independently verifies active domain and tenant subscription status before quota evaluation.
- **Input Sanitization:** Policy protocol requests are bounded to 64KB buffers to prevent memory exhaustion; fields are strongly typed, and no shell commands are executed based on SMTP input.

## 9. Historical Usage Ledger Security & Data Integrity
- **Tenant Isolation during Sync:** `OutboundUsageSyncService` validates that every discovered tenant ID exists in the `users` table before attempting to persist records into `tenant_outbound_usage`. Orphan or arbitrary Redis tenant IDs are rejected without crashing execution.
- **Strict Data Sanitization:** Redis counters are validated with strict regex (`/^[0-9]+$/`) and integer range bounds (`0` to `2147483647`). Non-numeric, negative, or overflow values are discarded with warning logs.
- **Fail-Safe Persistence:** Redis connection drops or timeouts abort synchronization immediately with a non-zero exit code, guaranteeing zero fabricated or guessed rows in MariaDB.
- **Monotonic Ledger Protection:** The database ledger never decrements existing recipient counts on sync, protecting durable billing metrics against unexpected Redis cache flushes or service restarts.
- **Log Privacy:** Synchronization logs record operational summaries, key counts, and tenant IDs without recording any credentials, message content, passwords, or tokens.

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
- **Outbound Spam Abuse:** (GAP) There are no per-mailbox or per-tenant daily sending quotas. Only global IP connection limits exist (`smtpd_client_message_rate_limit = 30`).
- **Suspension Enforcement:** (SECURE) Dovecot's `user_query` strictly enforces `domains.status = 'active'`, guaranteeing that suspended tenants instantly lose SASL/SMTP sending capabilities without requiring background daemon reloading.

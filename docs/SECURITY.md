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

## 10. Outbound Bounce Tracking & Abuse Detection Security (Step 15)
- **Privacy & Content Secrecy:** The log parser processes only delivery envelope metadata generated by Postfix daemons (`qmgr`, `smtp`, `submission`, `bounce`). No message headers, subject lines, body contents, or attachment data are ever inspected, parsed, or stored.
- **Strict Key Expirations (TTL):** All Redis keys generated by abuse tracking have explicit, bounded lifespans:
  - Queue-ID correlation records (`outbound:abuse:qid:{queueId}`): 24 hours (86,400s).
  - Queue-ID alias mapping records (`outbound:abuse:qid_alias:{newQid}`): 24 hours (86,400s).
  - Event idempotency deduplication keys (`outbound:abuse:seen:{queueId}:{recipientHash}:{classification}`): 24 hours (86,400s).
  - Daily abuse bounce counters (`outbound:abuse:tenant:*`, `outbound:abuse:mailbox:*`): 48 hours (172,800s).
  - Alert notification cooldown locks (`outbound:abuse:alert:*`): 24 hours (86,400s).
  - Key space is isolated with `outbound:abuse:*` prefix and will never collide with Step 12/13 quota keys (`outbound:tenant:*`, `outbound:policy:*`).
- **Intermediate Filter Boundary:** Postfix content filter handoffs (`postfix/smtp-amavis`, `amavis`, or relaying to `127.0.0.1:10024`) represent internal hops rather than final delivery to remote MX servers. The parser strictly classifies these as `TYPE_INTERMEDIATE_FILTER_HANDOFF`. They are forbidden from incrementing delivery success counters or resetting consecutive hard bounce counters, preventing adversarial filter bypasses.
- **Delivery Event Idempotency & Replay Protection:** Postfix logs repeated `deferred` status lines for soft bounces as delivery retries occur over time. Abuse tracking enforces atomic Redis `SET NX` event locks (`outbound:abuse:seen:{queueId}:{recipientHash}:{classification}`) with a 24-hour TTL, ensuring that repeated deferrals for the same queued message are recorded exactly once and replay of identical log entries cannot fabricate abuse metrics.
- **Denominator Definition & Operational Integrity:** Abuse bounce rate calculations strictly use `accepted_outbound_recipient_attempts` from Step 13/14 policy submissions at `DATA` stage as the denominator (`hard_bounces / accepted_outbound_recipient_attempts`), not confirmed remote deliveries. This prevents synthetic amplification of bounce ratios during mail volume fluctuations or deferral delays.
- **Non-Destructive Alerting Boundary:** Abuse detection operates strictly as an observability and alert signal engine. In accordance with platform security contracts, abuse detection will NEVER automatically suspend accounts (`users.status`), deactivate domains (`domains.status`), disable mailboxes (`mailboxes.is_active`), or modify database tables. Violations trigger structured JSON alerts to `storage/logs/abuse.log` for human operator / administrator review and manual action.
- **Tenant Attribution Isolation:** Envelope senders are attributed to tenants strictly via indexed database relationships (`mailboxes.username -> domains.domain -> users.id`). In the event of unauthenticated or unresolvable sender addresses, events fall back safely to domain-level or `unknown_system` attribution without leaking data across tenants.
- **System Privilege Boundary:** The application code adheres strictly to the principle of least privilege. The console command never executes `sudo`, never changes file permissions on `/var/log`, and never attempts to modify system group memberships. On production Linux hosts, `/var/log/mail.log` read permissions must be provisioned by the system administrator (e.g. `usermod -aG adm www-data` or POSIX ACL `setfacl -m u:www-data:r /var/log/mail.log`). If permissions are missing, the command fails gracefully with an informative error log and non-zero exit code without terminating server services.

## 11. Admin SMTP Management & Mailbox Control Security (Step 16A)
- **Role-Based Isolation:** All administrative SMTP routes (`/api/admin/smtp/*`) are strictly guarded by Laravel Sanctum authentication and the `EnsureAdmin` middleware. Non-admin tenants and unauthenticated requests receive immediate `403 Forbidden` or `401 Unauthorized` responses.
- **Fail-Safe Telemetry Degradation:** In the event of Redis downtime, the administrative control plane detects the disconnect cleanly (`isRedisAvailable()`), returns `telemetry_available: false`, and marks volatile metrics as `null` rather than fabricating zero counts. The UI displays an explicit warning banner, preventing operators from drawing false conclusions about cluster deliverability health.
- **Parent Hierarchy Invariant Enforcement:** When an administrator attempts to enable a mailbox (`is_active = true`), the system strictly evaluates three prerequisite invariants:
  1. The parent domain must have `status === 'active'`.
  2. The parent tenant must have `status === 'active'`.
  3. The parent tenant must have an active, non-expired subscription (`isSubscriptionActive() === true`).
  If any invariant fails, the request is rejected with HTTP 422 and a descriptive message. Disabling a mailbox is always allowed. This guarantees that disabled tenants or suspended domains cannot have active mailboxes provisioned or reactivated out of band.
- **Credential & Password Secrecy:**
  - Mailbox passwords are encrypted via SHA512-CRYPT using `crypt($password, '$6$' . Str::random(16) . '$')` prior to database persistence.
  - The `Mailbox` Eloquent model protects passwords with `$hidden = ['password']`, preventing credential leakage in JSON API responses.
  - The one-time generated password returned upon password reset is only presented to the administrator in memory in the API response and UI modal; it is never stored in plaintext, never cached in Redis, and never written to logs.
- **Consecutive Bounce Reset Scope:** Resetting consecutive hard bounces (`/api/admin/smtp/mailboxes/{id}/reset-bounces`) strictly executes `DEL outbound:abuse:mailbox:{id}:consecutive_hard`. It never alters or resets daily tenant or mailbox bounce counters, historical usage ledgers, or abuse alert history.
- **Structured Operational Audit Logging:** All administrative mutations (toggle mailbox, reset bounces, reset password) are logged to `storage/logs/admin-smtp.log` via the dedicated `admin_smtp` logging channel. Log entries capture the acting administrator ID, client IP, action, target entity, optional reason, and before/after states with all credentials explicitly redacted.
- **Command Injection Immunity:** Mail queue inspection relies on a safe, hardcoded static command (`postqueue -p | tail -n 1`) executed via `shell_exec`. Zero user input or dynamic parameters are concatenated into shell calls.

## 12. Persistent Administrative Audit Logging Security (Step 16B.1)
- **Application-Level Append-Only Ledger Semantics:** The `audit_logs` table represents an append-only relational ledger at the application layer. The application exposes strictly read-only query endpoints (`GET` index and show), contains no update or delete mutations, and disables Eloquent timestamp updates via `const UPDATED_AT = null`. Database-level immutability (such as database triggers, cryptographic hash chains, or WORM storage) is not part of Step 16B.1.
- **Centralized Single-Point Pre-Logging Redaction:** `AuditService::sanitizeState()` is the single authoritative sanitizer. `AdminSmtpService` passes raw states through `AuditService::sanitizeState()` prior to emitting to `Log::channel('admin_smtp')` and `AuditService::record()`. Raw state is strictly prohibited from reaching `admin-smtp.log`. Sanitization recursively inspects dictionary keys and values:
  - Any key matching sensitive patterns (`password`, `password_hash`, `new_password`, `token`, `secret`, `api_key`, `otp`, `private_key`, `signature`, `auth`, `credentials`) is immediately masked with `'[REDACTED]'`. (Operational flags such as `password_reset: true` remain visible as non-credential audit indicators).
  - Any scalar string value matching cryptographic hash signatures (e.g. SHA512-CRYPT prefix `$6$`, bcrypt prefix `$2y$` or `$2a$`, argon2 prefix `$argon2`) is immediately replaced with `'[REDACTED]'`.
  - Plaintext passwords and hashes are strictly prohibited from entering `admin-smtp.log` and `audit_logs`.
- **Fail-Safe Operation:** Database insertion errors during audit recording are caught by `AuditService` and logged to `storage/logs/admin-smtp.log` with safe metadata (error class, error code, action, entity, actor). Invariant: no sensitive state or SQL query parameter text is included in error logs. Failures return `null` and do not abort legitimate administrative tasks.
- **Dual Logging Redundancy:** Administrative actions are logged concurrently to both the persistent MariaDB ledger (`audit_logs`) and the operational file log (`storage/logs/admin-smtp.log`), with both destinations receiving identically sanitized data.
- **Data Boundary & Bounded Queries:** Audit log retrieval is strictly restricted to authenticated administrators via `EnsureAdmin`. Pagination is hard-capped at 50 records per request to mitigate denial-of-service via large query payloads.
- **Retention Status:** Retention and pruning policy is explicitly `NOT YET DEFINED — REQUIRES ARCHITECTURE APPROVAL`. No automated deletion or truncation exists.

## 13. Granular Role-Based Access Control (RBAC) & Administrative Governance (Step 16B.2)
- **Single Guard Architecture (`RBAC-DEC-01`):** All permissions, roles, and administrative authentications are bound exclusively to the Laravel `web` session/cookie guard. This prevents multi-guard token fragmentation and guarantees that Sanctum stateful sessions map directly to role assignments.
- **Explicit Super Admin Authorization (`RBAC-DEC-02`):** The Super Admin role does NOT utilize a global `Gate::before` bypass. All 13 permissions (`PermissionEnum`) are explicitly assigned to `RoleEnum::SUPER_ADMIN`. Every authorization check evaluates a deterministic permission node, ensuring complete auditability and preventing implicit privilege escalation.
- **Fail-Closed Default Posture (`RBAC-DEC-10`):** Newly created administrators or users flagged with `is_admin = true` without explicit role or permission assignments are strictly denied access (HTTP 403) across all administrative API routes guarded by `RequireAdminPermission`.
- **Administrative Perimeter Defense (`EnsureAdmin` / Step 16B.2A):** Perimeter checks require authenticated sessions, enforce that `$user->status !== 'suspended'`, and verify that the user is an administrator (`is_admin === true` or has an assigned administrative role). Suspended administrators receive an immediate HTTP 403 rejection.
- **Tenant Management Segregation (`RBAC-DEC-04`):** The tenant lifecycle controllers (`AdminApiController::suspendUser` and `activateUser`) strictly reject any target user where `is_admin = true` or who possesses any administrative role. Attempts to suspend or activate administrators through tenant endpoints return HTTP 403 and trigger operational denial logging. Administrators must be governed exclusively through administrative governance workflows.
- **Dedicated Concurrency Mutex (`RBAC-DEC-06`):** De-escalation or removal of Super Admin roles is guarded by `SuperAdminGovernanceService` using a dedicated synchronization table (`governance_locks`). Inside a serializable/exclusive transaction, the service executes `SELECT ... FOR UPDATE` on the `'super_admin_governance'` lock row before asserting that at least one active, non-suspended Super Admin remains. If the target is the final active Super Admin, the mutation is blocked with a `SuperAdminGovernanceException`. On contention, telemetry diagnoses blocking threads via MySQL 8.0 `performance_schema.data_lock_waits`.
- **Operational Denial Logging (`RBAC-DEC-05`):** Authorization denials from `RequireAdminPermission` and `AdminApiController` are routed via `SecurityAuditLogger` to `storage/logs/security.log` (`security` daily channel). Denials log actor metadata, target entities, IP address, and route details while executing recursive redaction of sensitive credentials, passwords, tokens, and hashes.
- **Emergency Account Recovery (`RBAC-DEC-07`):** The CLI recovery tool (`php artisan rbac:emergency-recovery {email} {--reactivate}`) assigns the Super Admin role and restores `is_admin = true`. By default, it preserves the existing account status (`status = suspended` remains suspended) to prevent accidental reactivation of compromised accounts, requiring the explicit `--reactivate` flag to alter account status.
- **Non-Destructive Migration Rollback:** Migration `2026_10_03_000003` records a `provenance = 'migration_step_16b2_backfill'` tag on legacy role assignments. If rolled back, only backfilled assignments are removed, strictly preserving subsequent legitimate role assignments.
- **Tenant Invoice Authorization Hardening (Finding 6 Remediation):** In `InvoicePolicy::view()`, the legacy `$user->is_admin` bypass has been replaced with granular RBAC enforcement. Cross-tenant invoice view/download access requires the explicit `admin.invoices.read` permission on the `web` guard and active account status (`status !== 'suspended'`). Normal customers retain strict ownership-based access (`$user->id === $invoice->user_id`). Zero-role administrators and roles lacking `admin.invoices.read` (such as Deliverability Operator) are strictly denied cross-tenant invoice access.

## 14. Tenant Suspension & Billing Lifecycle Security Invariants (Step 16B.3)
- **100% Administrative Exemption from Expiry Automation:** The automated suspension scheduler (`tenant:suspend-expired`) strictly excludes both legacy administrative flags (`where('is_admin', false)`) AND all accounts holding administrative Spatie RBAC roles (`whereDoesntHave('roles', fn ($q) => $q->whereIn('name', RoleEnum::adminRoles()))`). Administrators cannot have their accounts or operational domains suspended due to expiration timestamps.
- **Race-Safe State Transitions under Pessimistic Locks:**
  - Automated tenant suspension locks the candidate user row using `lockForUpdate()` within an isolated transaction and re-verifies that `status === 'active'` and `plan_expires_at < now()`, completely eliminating race conditions with concurrent payment renewal webhooks.
  - Payment activation in `BillingService::markInvoicePaid()` locks both the invoice and user row using `lockForUpdate()` and enforces lock-level idempotency (`if (! $lockedInvoice || $lockedInvoice->status === 'paid') return;`), guaranteeing that concurrent or duplicate webhook calls never double-extend subscriptions or produce inconsistent state.
- **Domain State Preservation & Scoping:**
  - Auto-suspension and manual administrative suspension update only active domains (`where('status', 'active')->update(['status' => 'suspended'])`), strictly preserving unverified or pending domains (`status = 'pending'`).
  - Reactivation updates only verified domains (`where('mx_verified', true)->update(['status' => 'active'])`), ensuring that domains awaiting DNS verification remain pending.
- **Mailbox Disablement Independence:**
  - Mailboxes independently disabled for security, abuse, or administrative reasons (`mailboxes.is_active = false`) are NEVER reset to active during tenant payment renewal or reactivation. Only the parent domain status is reactivated.
- **Strict Webhook Currency Verification:**
  - In addition to cryptographic hash signature and amount verification, `BillingService::handleIpn()` verifies that the incoming IPN currency matches the invoice currency (`BDT`). Mismatches are rejected and logged as errors without updating invoice or tenant status.
- **Comprehensive Lifecycle Audit Trails:**
  - Automated tenant suspension, payment invoice completion, and manual administrative suspension/activation are persistently logged to `audit_logs` via `AuditService::record()`, capturing before and after state snapshots with sensitive credential redaction.

## 15. Persistent Abuse Incident Ledger Security Invariants (Step 16B.4)
- **Zero Evidence Credential Exposure:** All incident evidence payloads submitted to `AbuseIncidentService::record()` undergo mandatory recursive sanitization via `AuditService::sanitizeState()`. Any key matching password, token, hash, or secret patterns, as well as any scalar string matching cryptographic hash patterns (`$6$`, `$2y$`, `$2a$`, `$argon2`), are replaced with `'[REDACTED]'`. No plaintext credentials, passwords, tokens, hashes, or email contents are ever stored in `abuse_incidents.evidence`.
- **Fail-Safe SMTP Daemon Decoupling:** In the latency-sensitive TCP Postfix policy daemon (`PolicyDecisionService`), quota rejections execute an atomic Redis `SET NX` cooldown check first. Subsequent rejections on the same day bail out in O(1) without touching MariaDB. If database or ledger persistence throws an exception, the exception is caught, logged safely, and the policy decision `REJECT 554 5.7.1` is strictly preserved and returned. Under no circumstances can database or ledger issues delay or fail-open a quota violation.
- **Durable Identity Preservation across Deletions:** Foreign keys from `abuse_incidents` to `users`, `domains`, and `mailboxes` are declared with `nullOnDelete()`. Crucially, immutable snapshot columns (`tenant_email`, `domain_name`, `mailbox_email`) capture entity identities at the moment the incident occurs. When a rogue tenant or compromised mailbox is permanently deleted from the platform, historical incident records remain intact for compliance, auditing, and deliverability reputation reporting.
- **Deduplication & Idempotency Key Semantics:** Every recorded incident requires or generates a deterministic `idempotency_key` (e.g. `policy_daemon:quota:{tenant_id}:{mailbox_id}:{date}` or `log_parser:{alert_type}:{tenant_id}:{date}`). Attempted inserts with identical idempotency keys return the existing incident without creating duplicate records or inflating incident metrics.
- **Separation of Operational Ledgers:** Routine automated incident ingestion from high-frequency background daemons (such as the policy daemon or mail log parser) writes directly to `abuse_incidents` and does NOT generate entries in `audit_logs` to prevent audit log flooding. However, human administrative lifecycle changes (`resolve` and `dismiss`) are mandatory audit events and are persistently logged to `audit_logs` via `AuditService::record()`.
- **Administrative RBAC Authorization Boundaries:**
  - Reading abuse incidents (`/api/admin/smtp/incidents`): requires `admin.smtp.read` (granted to Super Admin, Deliverability Operator, Customer Support).
  - Mutating abuse incidents (`/api/admin/smtp/incidents/{incident}/resolve`): requires `admin.smtp.mailbox.toggle` (granted to Super Admin and Deliverability Operator; Customer Support is denied HTTP 403).
  - Suspended administrators and zero-role administrators are denied access across all incident endpoints (HTTP 403).

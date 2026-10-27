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

## 16. Backup & Disaster Recovery Security Invariants (Step 17 & 17.2)
- **Restricted Filesystem Access Boundaries:**
  - Backup directories (`storage/backups/db`, `storage/backups/vmail`, and production `/var/backups/emailsaas/`) are provisioned with strict `0700` (`rwx------`) directory permissions.
  - Backup archives (`.sql.gz`, `.tar.gz`, `.sql.gz.enc`, `.tar.gz.enc`) and JSON manifests (`.manifest.json`) are provisioned with `0600` (`rw-------`) permissions, accessible solely by the `root` or `www-data` service execution identities.
- **Cryptographic & Stream Integrity Verification:**
  - Every backup archive generates a companion `.manifest.json` containing an immutable SHA-256 cryptographic digest.
  - `BackupVerificationService` enforces multi-tier validation:
    1. Physical file existence and non-zero byte size verification.
    2. Envelope verification (OpenSSL `Salted__` magic header for `.enc` or gzip `\x1f\x8b` for plaintext).
    3. Cryptographic hash comparison between the computed file digest and the recorded manifest digest using `hash_equals()`.
    4. Decryption and inner gzip decompression stream integrity testing.
- **Backup Encryption at Rest (AES-256-CBC with PBKDF2):**
  - Production-ready encryption at rest using OpenSSL AES-256-CBC with 10,000 PBKDF2 SHA-256 iterations and random salt.
  - Encryption keys (`BACKUP_ENCRYPTION_KEY` or file at `BACKUP_ENCRYPTION_KEY_PATH`) are maintained strictly outside backup directories.
  - Encryption keys must never be stored in `storage/backups/`, backup manifests, or version control.
  - When encryption is enabled (`BACKUP_ENCRYPTION_ENABLED=true`), any missing or unreadable key causes the backup subsystem to **fail closed** immediately.
- **Provider-Neutral Offsite Replication Fail-Safe Semantics:**
  - Offsite transport replication (`rsync`, `scp`, `local`, `custom`) verifies local archive integrity prior to initiation.
  - Remote transfer failures or timeouts **never** delete, mutate, or invalidate local backup copies.
  - Credentials, tokens, and SSH keys are automatically redacted in system logs.
- **Fail-Closed Disaster Recovery Migrations:**
  - Total disaster recovery orchestration (`scripts/disaster_recovery.sh`) eliminates silent migration errors (`migrate --force || true`).
  - Migration state is inspected via `php artisan migrate:status`, applied strictly under `--run-migrations`, and fails closed immediately on error.
- **Strict Maildir Ownership & Permissions Enforcement on Restore:**
  - Restoring mail storage via `scripts/restore_vmail.sh` or `BackupVerificationService` strictly enforces ownership to `vmail:vmail` (numeric UID/GID 5000:5000).
  - Mailbox directory permissions are locked to `0700` (`drwx------`) and email files to `0600` (`-rw-------`), strictly prohibiting cross-tenant read access across local accounts.
- **Non-Destructive Invariants on Retention Pruning:**
  - `BackupRetentionService` enforces a calendar-aware Grandfather-Father-Son (GFS) policy (7 daily, 4 weekly, 3 monthly).
  - The most recent valid backup (index 0) is strictly exempt from deletion under all circumstances.
  - If total backup count is ≤ 1, pruning aborts immediately without deleting files.
  - Intermediate backup files are written to `.tmp` paths and atomically promoted; incomplete or failed backup attempts never overwrite or delete the last known-good backup archive.
- **Atomic Concurrency Protection:**
  - Console commands employ Redis/Cache locks (`backup_run_master_lock`, `backup_database_lock`, `backup_vmail_lock`, `backup_prune_lock`) with explicit TTLs.
  - Companion Linux shell scripts employ non-blocking filesystem locks (`flock -n /var/lock/emailsaas_backup_*.lock`), preventing overlapping executions, resource exhaustion, and concurrent archive corruption.

## 17. Operational Reliability, Scheduled Jobs & System Health Security Boundaries (Step 19)
- **Public Minimal Liveness Isolation:** The public probe endpoint (`GET /api/health` and `/up`) returns strictly minimal operational state (`{"status": "HEALTHY", "timestamp": "..."}`). Zero internal server topology, connection latencies, database hosts, PHP configurations, or environment details are disclosed to unauthenticated callers.
- **Administrative Diagnostic Boundary:** Comprehensive multi-component diagnostics (`GET /api/admin/system/health` and `/api/admin/system/tasks`) are strictly guarded behind `auth:sanctum`, `EnsureAdmin`, and granular permission `admin.permission:admin.stats.read`. Non-administrators, suspended administrators, and zero-role administrators are immediately denied access with HTTP 403.
- **Fail-Safe Task Execution Tracking:** The `ScheduledTaskTrackerService` wraps all persistence logic in try/catch handlers. Database failures or lock timeouts encountered during run recording log safe warnings and never crash or interrupt scheduled business logic.
- **Single-Point Sanitization of Task Failures:** When a scheduled task fails with an unhandled exception, `ScheduledTaskTrackerService::sanitizeSummary()` automatically sanitizes the message before saving it to `scheduled_task_runs`:
  - Database DSN credentials (`mysql://user:pass@host/db`) are replaced with `[REDACTED]`.
  - Passwords, access tokens, API keys, and passphrases are scrubbed via regex.
  - Cryptographic hashes (`$6$`, `$2y$`, `$argon2`) are redacted.
  - Bearer authorization headers are masked.
- **Read-Only Health Semantics & No Web Shell Execution:** The system health service is strictly read-only and diagnostic. Web clients cannot trigger backup dumps, restore operations, daemon restarts, or arbitrary CLI commands from the browser.
- **DR Health API Governance Respect:** Step 18 governance mandating architectural approval for `GET /api/admin/system/backup-status` is strictly upheld. No unapproved backup HTTP API was silently created; backup status within system health is derived from local trusted companion manifests.

## 18. Staging Deployment, Cryptographic Authenticity & Isolation Boundaries (Step 20)
- **Cryptographic Authenticity Limitation of AES-256-CBC:**
  - Backups are encrypted using OpenSSL AES-256-CBC with PBKDF2 (`Salted__` envelope).
  - AES-256-CBC provides data confidentiality, but is an **unauthenticated encryption cipher** (it does not provide message authentication codes or cryptographic integrity verification).
  - System integrity relies on external SHA-256 digests in unencrypted companion `.manifest.json` files. In threat models where the storage medium is writable by an attacker, tampering with both ciphertext and manifest cannot be cryptographically proven without an HMAC or digital signature.
  - Production recommendation: Upgrade cipher to an authenticated mode (AEAD such as `aes-256-gcm`) or sign manifests with HMAC-SHA256 prior to production release.
- **Strict Staging Isolation & Production DB Protection:**
  - The repository's `.env.example` contains remote IP `DB_HOST=103.131.144.61`. In staging or development environments, execution of database migrations, cache operations, or database restores (`restore_db.sh`) against unverified hostnames is strictly blocked to prevent accidental damage to live production data.
  - All staging restore drills must execute against explicitly designated, disposable databases and test paths.
- **Installer Parameterization Safeguard:**
  - `scripts/install.sh` contains hardcoded hostnames (`mail.yourdomain.com`), unconditional UFW resets, and Let's Encrypt standalone challenges. Blind execution in a shared or staging environment without explicit parameterization (`--staging`, `--domain`) is identified as an operational security risk and blocked until parameterized.
- **Rollback Non-Destructive Invariants:**
  - Disaster recovery runbooks strictly forbid automated execution of `php artisan migrate --force`. Database schema changes must remain an explicit, reviewed administrative action.

## 19. Authenticated Backup Encryption & Cryptographic Integrity (Step 21)
- **AEAD Construction & Cipher Selection:**
  - Standardized on authenticated encryption with associated data: **AES-256-GCM** (`aes-256-gcm`).
  - Protects both confidentiality and cryptographic authenticity in a single unified primitive, eliminating bit-flipping attacks, padding oracle attacks, and unauthenticated ciphertext substitution.
- **Chunked Streaming Envelope (`EMAILSAAS_GCM_V1`):**
  - Uses a versioned, authenticated chunked streaming envelope with a constant memory footprint ($O(1)$, ~64 KB buffers), enabling encryption and decryption of arbitrarily large database dumps and mail storage tarballs without memory spikes or process exhaustion.
  - **40-Byte Envelope Header:**
    - Magic identifier (16 bytes): `EMAILSAAS_GCM_V1`
    - Salt (16 bytes): Cryptographically secure random bytes (`random_bytes(16)`)
    - Nonce Prefix (8 bytes): Cryptographically secure random bytes (`random_bytes(8)`)
  - **Chunk Frame Structure:**
    - Length (4 bytes, uint32 big-endian): Byte length of ciphertext chunk.
    - Terminal flag (1 byte, uint8): `1` for the final chunk, `0` for intermediate chunks.
    - Authentication Tag (16 bytes): AES-256-GCM authentication tag.
    - Ciphertext ($N$ bytes): Raw ciphertext bytes.
- **Nonce & AAD Cryptographic Bindings:**
  - **Per-chunk Nonce (12 bytes):** `NoncePrefix (8B) || ChunkCounter (4B BE)`. Guarantees unique nonces across all chunks even for massive multi-gigabyte archives.
  - **Associated Authenticated Data (AAD):** `EMAILSAAS_GCM_V1 || ChunkCounter (4B BE) || isLast (1B)`. Cryptographically binds the envelope version, chunk sequence order, and terminal chunk boundary into the authentication tag.
  - **Tamper Proofing:** Reordering chunks, dropping chunks, truncating the stream, or bit-flipping ciphertext/tags immediately causes GCM tag verification failure before any plaintext is released.
- **Fail-Closed Security & Partial Data Cleanup:**
  - Decryption fails closed upon any cryptographic mismatch, wrong key, truncated chunk, or corrupted tag.
  - All temporary files (`.tmp`) are immediately purged upon failure, preventing partial unauthenticated plaintext leakage to disk.
- **Companion Manifest HMAC-SHA256 Signing:**
  - Backup manifests (`.manifest.json`) are cryptographically signed using HMAC-SHA256 over canonicalized metadata using the backup encryption key (`manifest_hmac`).
  - Tampering with recorded SHA-256 digests, unencrypted checksums, file sizes, or backup metadata is immediately detected and rejected by `BackupVerificationService` and `backup:verify`.
- **Isolated Backward Compatibility:**
  - Legacy OpenSSL AES-256-CBC (`Salted__`) backups are supported via an isolated, dedicated fallback path in `BackupEncryptionService` and shell scripts (`restore_db.sh`, `restore_vmail.sh`, `verify_backup.sh`).
  - Legacy backups are strictly labeled: `LEGACY — AUTHENTICITY NOT GUARANTEED` (`authenticated: false`), issuing operational security warnings upon decryption and status display.
- **CLI Decryption Tooling & Secret Scrubbing:**
  - Added Artisan command `backup:decrypt {archive} {target?} {--key=} {--stdout}` supporting streaming pipe decryption directly into `mysql` or `tar`.
  - Passphrases and derived keys are strictly scrubbed from CLI outputs, logs, and exception messages.

## 20. Comprehensive Admin Audit Trail & Security Event Monitoring (Step 23)
- **Unified Security & Mutation Event Taxonomy:**
  - All critical administrative mutations, security denials, authentication events, and system operations are recorded in the central append-only `audit_logs` ledger:
    - **Authentication Events:** `auth.login` (successful login), `auth.logout` (session termination), `auth.failed` (failed authentication attempt recording attempted email and guard, strictly omitting submitted passwords/credentials).
    - **Perimeter & Authorization Events:** `security.authorization_denied` (persisted on 403 authorization denials, recording actor, client IP, route, required permission, and target resource).
    - **Tenant & Mail Administration:** `tenant.register`, `admin.users.suspend`, `admin.users.activate`, `domain.create`, `domain.delete`, `mailbox.create`, `mailbox.change_password`, `mailbox.toggle`, `mailbox.delete`, `admin.smtp.mailbox.toggle`, `admin.smtp.mailbox.reset_password`, `admin.smtp.mailbox.reset_consecutive_bounces`.
    - **Subscription Billing:** `plan.create`, `plan.update`, `plan.delete`.
    - **Super Admin Governance:** `admin.roles.assign`, `admin.roles.demote`, `admin.emergency_recovery`.
    - **Backup & Disaster Recovery:** `backup.database.create`, `backup.vmail.create`, `backup.verify`, `backup.prune`.
    - **Abuse Management:** `admin.abuse_incident.resolve`, `admin.abuse_incident.dismiss` with `incident_uuid` correlation in state snapshot.
- **Fail-Closed Security Posture:**
  - In `SecurityAuditLogger::logDenial()`, audit ledger persistence is wrapped in a fail-safe `try/catch` block. If database persistence encounters transient failure or lock contention, the operational security log is updated, and the HTTP 403 authorization denial remains strictly fail-closed.
- **Recursive Sensitive Data Protection:**
  - `AuditService::sanitizeState()` enforces single-point, recursive scrubbing across nested payloads:
    - **Expanded Sensitive Key Masking:** Masked with `'[REDACTED]'` across 38+ keys including `password`, `token`, `secret`, `api_key`, `db_password`, `encryption_key`, `backup_encryption_key`, `passphrase`, `authorization`, `bearer`, `cookie`, `session`, `x_csrf_token`, `card_number`.
    - **Content-Based Hash & JWT Masking:** Masked with `'[REDACTED]'` upon detecting cryptographic password hashes (`$6$`, `$2y$`, `$2a$`, `$argon2`) or JWT/Bearer token patterns (`eyJ...` length > 30) anywhere in scalar values.
    - **Failed Authentication Credential Scrubbing:** Event listener explicitly reads only `$event->credentials['email']` and discards the credentials array entirely.
- **Server-Side Authorization & Bounded Pagination:**
  - `GET /api/admin/audit-logs` and `GET /api/admin/audit-logs/{id}` are strictly guarded by `RequireAdminPermission:admin.audit_logs.read` and Sanctum `web` guard.
  - Pagination limit is strictly clamped (`min(max($perPage, 1), 50)`), preventing database exhaustion.
  - Validated sorting parameters: `sort_by` (`id`, `created_at`, `action`, `entity_type`) and `direction` (`asc`, `desc`).
  - Validated filtering parameters: `action`, `entity_type`, `entity_id`, `actor_user_id`, `search`, `date_from`, `date_to`, and `outcome` (`success`, `failure`, `denial`).
- **Application Ledger Tamper-Resistance Boundaries:**
  - **Implemented Protection:** Append-only relational ledger at application layer. Zero mutation endpoints (`POST`, `PUT`, `PATCH`, `DELETE` return HTTP 405 Method Not Allowed), Eloquent `$timestamps = false` with `const UPDATED_AT = null`, and audit log rows cannot be updated via the API.
  - **Infrastructure Limitations:** Application-layer append-only ledgers do not guarantee tamper-resistance against an attacker possessing direct MariaDB `root` access or physical storage access. True tamper-resistance requires database-level cryptographic hash chains, write-once-read-many (WORM) storage, or streaming to a remote SIEM / syslog server.

## 21. Tenant Usage, SMTP Quota & Billing Reconciliation Security Invariants (Step 24)
- **Quota Accounting Completeness & Leak Prevention:**
  - `OutboundQuotaService` always supplies `$tenantKey` to the atomic Lua script in Redis whenever quota consumption is evaluated, ensuring that tenants with unlimited plan quotas (`-1`) who have restricted mailboxes still have their total recipient count captured in Redis and synchronized to `tenant_outbound_usage`.
  - Missing plan configurations fail closed (`REJECTED_QUOTA`).
- **Payment Gateway & IPN Hardening:**
  - IPN callbacks targeting cancelled invoices (`status = 'cancelled'`) are rejected immediately, preventing payment revival of cancelled or superseded invoices.
  - IPN callbacks enforce currency validation (`BDT`) and amount matching against invoice totals with 0.01 tolerance.
  - `BillingService::markInvoicePaid()` enforces pessimistic concurrency locks (`lockForUpdate()`) on both `Invoice` and `User` records, guaranteeing financial idempotency under concurrent webhooks.
- **Tenant Activation Subscription Gate:**
  - Administrative activation (`POST /api/admin/users/{user}/activate`) strictly rejects customer tenants whose subscription has expired (`plan_expires_at->isPast()`) with HTTP 422, preventing inconsistent active states.
  - Administrative accounts remain segregated under `RBAC-DEC-04` (HTTP 403).
- **Read-Only Reconciliation Safety:**
  - `TenantUsageReconciliationService` and `php artisan tenant:reconcile-usage` are strictly read-only: zero database rows are inserted, updated, or deleted, and zero Redis keys are mutated.
  - Scheduled daily reconciliation (`05:00 UTC`) runs under `withoutOverlapping(15)` mutex.
  - API endpoints `/api/admin/reconciliation/*` are protected by `EnsureAdmin` and `admin.permission:admin.stats.read`.

## 22. Local Integration, Dummy Data Seeding & Security Verification (Step 25)
- **Local Dummy Data & Environment Safety Guards:**
  - `SeedAuditDemoCommand` (`php artisan emailsaas:seed-audit-demo`) strictly enforces that `app()->environment()` equals `'local'`. It immediately halts with exit code 1 in all other environments.
  - Validates active database connection parameters: host must be `127.0.0.1` or `localhost`, port must be `3306`, and database must be `emailsaas_local`.
  - Explicitly detects and halts on remote production IP `103.131.144.61`.
  - Uses explicit ownership markers (`audit-demo-` prefix, `@example.test` reserved RFC 2606 domain namespace) for all synthetic records.
  - Safe cleanup (`--cleanup`) strictly targets only records in the explicit ownership registry; canonical users (`test@example.com`, `admin@example.com`) and plans are completely preserved.
- **Full Security Matrix Enforcement Verified:**
  - **Unauthenticated Requests:** Protected admin endpoints return HTTP 401 Unauthenticated.
  - **Tenant Boundary:** Authenticated non-admin tenants attempting admin access receive HTTP 403 Forbidden.
  - **Cross-Tenant Isolation:** Tenants attempting to view or modify other tenants' domains or mailboxes receive HTTP 403 Forbidden.
  - **Suspended Tenant Guard:** Suspended accounts are immediately blocked from protected tenant API routes.
  - **Fail-Closed Quota Gate:** Tenants without an assigned plan fail closed (`REJECTED_QUOTA`) and cannot send email.
  - **Billing Gateway Hardening:** Cancelled invoices reject IPN callbacks, and duplicate payment callbacks process idempotently.
  - **Subscription Activation Gate:** Admin API rejects activating tenants with expired subscription timestamps (HTTP 422).
  - **Read-Only Audit Trail:** Audit log endpoints reject `POST`, `PUT`, `DELETE` mutations with HTTP 405 Method Not Allowed.
  - **Cryptographic Integrity:** Backup decryption with an invalid key fails closed and purges partial plaintexts; tampered manifest metadata fails HMAC-SHA256 verification.
  - **Credential Redaction:** Passwords and credentials in failed logins and state payloads are recursively scrubbed to `[REDACTED]`.

## 23. Tenant SMTP Access, Credentials Lifecycle & Diagnostics Security (Step 28)
- **Canonical Credentials Architecture:**
  - SMTP authentication utilizes Dovecot SASL querying the `mailboxes` table. Mailbox username (full email address) and mailbox password are the sole canonical SMTP credentials.
  - No redundant secondary credentials or application password tables exist, eliminating state desynchronization between webmail, IMAP, and SMTP.
- **One-Time Reveal & Zero Hash Exposure:**
  - Password rotation (`POST /api/smtp/credentials/{mailbox}/rotate-password`) generates a cryptographically secure 16-character password and updates the database with Dovecot `SHA512-CRYPT` (`$6$`).
  - The plaintext password is returned strictly once in the rotation API response. It is never stored in plaintext in the database, caches, browser storage, or logs.
  - All credentials endpoints (`GET /api/smtp/credentials`, `GET /api/mailboxes`, `GET /api/domains/{id}/mailboxes`) exclude the `password` column from resources. Stored password hashes are never exposed.
- **Strict Cross-Tenant Isolation:**
  - Delivery logs, credentials, and password rotation operations enforce strict tenant ownership checks. Cross-tenant access attempts receive `HTTP 403 Forbidden`.
  - Suspended tenants are blocked from all SMTP API endpoints (`EnsureActiveSubscription`).
- **Response Text Sanitization:**
  - `DeliveryLogResource` sanitizes raw MTA responses, stripping internal filesystem paths (e.g., `/var/vmail/...`, `/etc/...`) and truncating response text to safe limits to prevent information leakage.
- **Zero SSRF Risk in Diagnostics:**
  - The diagnostics endpoint (`GET /api/smtp/diagnostics`) evaluates configuration health strictly against internal database state, subscription models, and Redis usage keys.
  - It accepts zero host, port, or URL parameters and initiates zero outbound network socket connections or HTTP probes, completely eliminating Server-Side Request Forgery (SSRF) vulnerabilities.

## 24. Domain DNS Records Wizard, Inbound Authentication & Verification Security (Step 34)
- **External Resolver Protection & Rate Limiting:**
  - DNS lookup endpoints (`GET /api/domains/{domain}/dns-wizard` and `POST /api/domains/{domain}/verify-dns`) are bounded by Laravel's `throttle:30,1` middleware (maximum 30 verification calls per minute per authenticated session). This mitigates external DNS resolver amplification, cache thrashing, and DoS attacks.
- **Tenant Authorization Boundaries & Ownership Isolation:**
  - Access is guarded by `auth:sanctum` and `DomainPolicy` (`view` for wizard queries, `update` for verify requests). Tenants cannot trigger DNS checks, view public key data, or modify domains belonging to other tenants (HTTP 403 Forbidden).
  - Suspended accounts and expired subscriptions are blocked via `EnsureActiveSubscription` middleware.
- **SSRF Prevention & DNS Query Scoping:**
  - `DnsVerificationService` strictly queries standard DNS record types (`DNS_MX`, `DNS_TXT`, `DNS_A`, `DNS_CNAME`) targeting only the verified tenant domain hostname and standard subdomains (`<selector>._domainkey`, `_dmarc`, `mail`, `autodiscover`, `autoconfig`).
  - Zero arbitrary external URLs, IP addresses, or ports are queried, completely preventing Server-Side Request Forgery (SSRF) vulnerabilities.
- **Cryptographic Key Security & Zero Private Key Leakage:**
  - Only the public key (`dkim_public_key`) is stored in the database and exposed in DNS wizard configurations.
  - DKIM private keys are generated on server filesystem storage (`/var/vmail/dkim/` or staging equivalent), set with restricted file permissions (`chmod 600`), and are NEVER stored in database columns or exposed in API responses.
- **RFC 7208 PermError Detection:**
  - Multiple SPF records are explicitly identified and flagged as `MISMATCH` with consolidation guidance, protecting tenants from spoofing and MTA mail rejection caused by RFC 7208 section 3.2 violations.
- **Persistent Security Audit Trail:**
  - Every DNS verification action (`verifyDns` and backwards-compatible `verify`) emits a `domain.dns_verify` event into `audit_logs` via `AuditService::record()`, recording actor ID, IP address, timestamp, and sanitized before/after verification states.

## 25. Domain Authentication Hardening, Deliverability Reports & DMARC Parser Security (Step 35)
- **Zero Private Key Exposure Invariant:**
  - DKIM private keys are strictly generated and stored on the server filesystem (`/var/vmail/dkim/` with `chmod 600`) and are NEVER stored in database columns, returned in API payloads (`DomainResource`, `DomainApiController`, `DnsVerificationService`), rendered in frontend code, or persisted in `audit_logs`.
  - Audited and verified across all Step 35 endpoints (`GET /api/domains/{domain}/deliverability-report`, `POST /api/domains/{domain}/parse-dmarc-report`).
- **DMARC Aggregate Report Parser Hardening (XXE & DoS Protection):**
  - **XXE Injection Neutralization:** XML parsing in `DmarcReportParserService` strictly sets `LIBXML_NONET` to disable all external network entity resolution. The parser explicitly inspects the raw payload and rejects documents containing `<!DOCTYPE` or `<!ENTITY` declarations before DOM loading.
  - **Payload Size Bounding:** XML report uploads and raw string inputs are strictly bounded to 5MB (`max:5120`), mitigating XML bomb (Billion Laughs) and memory exhaustion attacks.
  - **Cross-Domain Report Spoofing Prevention:** The parser verifies that the report's `<policy_published><domain>` element matches the tenant's domain. Unmatched reports are rejected with HTTP 422 to prevent spoofed telemetry injection.
  - **Rate Limiting:** Report parsing endpoint (`POST /api/domains/{domain}/parse-dmarc-report`) is protected with `throttle:10,1` (maximum 10 parsing requests per minute per tenant).
  - **Idempotency & Deduplication:** Generates a deterministic SHA-256 digest from report metadata and record contents, preventing duplicate report insertion.
- **Inbound Collection Posture Truthfulness:**
  - The application truthfully reports that automated ingestion via an inbound IMAP/mail-pipe daemon is `PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL`. It does not expose unauthenticated open SMTP ingestion endpoints for aggregate reports.
- **DNS Drift Detection:**
  - `DnsVerificationService` compares active domain status against authoritative live DNS results. If an active domain loses its verified MX, SPF, or DKIM records, `dns_drift_detected: true` is flagged immediately in API responses and logged in `audit_logs`, alerting tenants before mail bounces occur.
- **SPF Vulnerability & PermError Detection:**
  - Insecure `+all` open-relay mechanisms are detected and flagged as a critical security risk.
  - DNS lookup mechanism counts (include, a, mx, ptr, exists, redirect) are tracked to warn tenants when records exceed the RFC 7208 section 4.6.4 10-lookup limit.
- **DMARC & MX Protocol Violations:**
  - Multiple TXT records at `_dmarc.<domain>` are detected and flagged as PermError per RFC 7489 section 6.6.3.
  - Bare IP addresses configured as MX targets are detected and rejected per RFC 5321 section 5.1 prohibition.
- **Clear Legal & Diagnostic Disclaimer:**
  - Deliverability reports explicitly include a disclaimer that DNS verification reflects published DNS records and does NOT guarantee external inbox placement, third-party spam filter exemption, or sender reputation.

## 26. Tenant Email Security & Deliverability Monitoring Dashboard Security (Step 36)
- **Strict Multi-Tenant Isolation & Zero Leakage:**
  - All dashboard queries (`Domain`, `DeliveryLog`, `TenantOutboundUsage`, `AbuseIncident`) are strictly filtered by `tenant_id = auth.user.tenant_id`.
  - Comprehensive feature tests explicitly verify that tenant A cannot see or access tenant B's domains, delivery events, or abuse incidents.
- **Sanitized Abuse Incident Presentation (`TenantAbuseIncidentResource`):**
  - Tenant-facing security incidents endpoint (`GET /api/security/incidents`) and dashboard payload format incidents through `TenantAbuseIncidentResource`.
  - Sensitive internal data — including server stack traces, database IDs of resolving staff (`resolved_by`), internal file system paths, and raw system evidence blobs — are strictly redacted before output.
  - Actionable remediation advice (`suggested_remediation`) is synthesized for tenants based on incident type (e.g. list hygiene, SPF/DKIM verification, quota management) without exposing internal heuristics.
- **Zero Live DNS Lookups on Dashboard Render:**
  - Dashboard render calculates domain deliverability scores and detects drift purely from persisted database columns (`mx_verified`, `spf_verified`, `dkim_verified`, `dmarc_verified`, `dns_drift_detected_at`).
  - No live network DNS queries (`dns_get_record`) are triggered during `GET /api/dashboard`, eliminating DNS amplification vulnerabilities, denial-of-service risks, and external resolver latency on dashboard loads.
- **Resilient Partial-Failure Fault Isolation:**
  - Each major dashboard section (`domain_security`, `smtp_usage`, `recent_activity`, `abuse_and_security`, `dmarc_reporting`) executes within its own isolated boundary.
  - If an upstream service (such as Redis) is unreachable, the system catches the exception and marks `smtp_usage.status = 'degraded'` with a sanitized error message, allowing other sections (domains, abuse incidents, billing) to render normally without a cascading 500 server crash.
- **Administrative Control Plane Integrity Maintained:**
  - Tenant endpoints are strictly read-only. Tenant access cannot mutate, resolve, dismiss, or delete abuse incident records.
  - Incident mutation remains strictly locked to `POST /api/admin/smtp/incidents/{incident}/resolve` under the `RequireAdminPermission:admin.smtp.mailbox.toggle` middleware.
- **Truthful DMARC Posture Reporting:**
  - The dashboard truthfully reports that while the RFC 7489 XML report parser is operational (`POST /api/domains/{domain}/parse-dmarc-report`), automated inbound mailbox ingestion remains `PENDING INFRASTRUCTURE & ARCHITECTURAL APPROVAL`. The application makes zero false claims of automated aggregate telemetry collection.

## 27. Tenant Notifications & Security Alert Center Security Invariants (Step 37)
- **Strict Multi-Tenant Isolation & Ownership Enforcement:**
  - The `tenant_notifications` table contains a direct, foreign-key constrained `tenant_id` referencing `users.id` with `cascadeOnDelete`.
  - All tenant notification endpoints (`GET /api/notifications`, `GET /api/notifications/unread-count`, `POST /api/notifications/{id}/read`, `POST /api/notifications/mark-all-read`, `POST /api/notifications/{id}/dismiss`) operate strictly on `tenant_id = auth()->user()->id`.
  - Individual notification updates and dismissals are authorized via `TenantNotificationPolicy` (`$user->id === $notification->tenant_id`), completely prohibiting cross-tenant reading, marking as read, or dismissal.
- **Recursive Sensitive Secret Scrubbing & Data Redaction:**
  - Notification `data` payloads are recursively sanitized via `TenantNotificationResource` and `TenantNotificationService::scrubSensitiveData()`.
  - Any sensitive or credential keys — including `password`, `secret`, `token`, `key`, `private_key`, `api_key`, `hash`, `salt`, `trace`, `file`, `line`, and `evidence` — are recursively purged before storage and JSON serialization.
  - Private DKIM keys, mailbox passwords, database credentials, server stack traces, and local filesystem paths are strictly prevented from ever appearing in tenant notification payloads or frontend views.
- **XSS & Content Sanitization:**
  - All notification `title` and `message` strings are sanitized using `strip_tags()` before being stored or served to prevent stored Cross-Site Scripting (XSS) attacks in the notification drawer or notification center.
- **Atomic Deduplication & Alert Storm Prevention:**
  - Ongoing, persistent conditions (such as persistent DNS drift, unresolved abuse incidents, or repeated daily outbound quota rejection events) utilize structured deduplication keys (`dns_drift:{domain_id}:active`, `quota_exhaustion:{tenant_id}:{date}`).
  - When an alert condition is already active and unread, new notification dispatches are suppressed, eliminating notification fatigue and preventing denial-of-service or database saturation from alert storms.
  - When an issue recovers (e.g. DNS verification passes), the active drift notification is automatically resolved and marked read, and a single recovery notice (`dns_recovered:{domain_id}:{date}`) is emitted.
- **Fail-Safe Non-Blocking Notification Trapping:**
  - Notification dispatching in `TenantNotificationService::notify()` is wrapped in a fail-safe exception handler.
  - An error during notification creation will never crash, interrupt, or abort critical primary business workflows (such as DNS verification, mailbox forwarding updates, abuse incident recording, invoice payment, or tenant suspension).
- **Idempotent Lifecycle State Transitions:**
  - Read (`POST /api/notifications/{id}/read`, `POST /api/notifications/mark-all-read`) and dismiss (`POST /api/notifications/{id}/dismiss`) actions are strictly idempotent. Re-submitting on an already-read or dismissed notification will not corrupt state, generate duplicate records, or trigger database errors.




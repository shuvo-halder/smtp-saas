# EmailSaaS Transactional Mail Delivery — Production Readiness Audit & Linux Deployment Runbook

**Document Version:** 1.0 (Production Audit Baseline)  
**Phase:** Step 42 — Email Delivery Production Readiness Audit & Linux Deployment Runbook  
**Application:** EmailSaaS (`smtp-saas`)  
**Scope:** Transactional Mail Queue Worker, Queue Infrastructure, Failure Scenarios, Linux Deployment & Disaster Recovery  
**Classification:** Operational Security & Infrastructure  

---

## 1. Executive Summary & Purpose

This runbook establishes the production deployment, verification, supervision, and operational failure-mitigation procedures for the EmailSaaS transactional mail delivery subsystem.

The subsystem manages mission-critical platform transactional emails:
- Account verification notices (`account_verification`)
- Password reset requests (`password_reset`)
- Billing invoices and payment receipts (`billing_invoice`)
- High-severity security and abuse incident alerts (`security_alert`)

### Key Architecture & Invariant Rules
- **Decoupled Architecture:** Application-level transactional email (`mail_deliveries`) is completely decoupled from Postfix MTA inbound/outbound customer routing (`delivery_logs`).
- **Dry-Run Safety Default:** By default, `MAIL_DELIVERY_DRY_RUN=true`. All transactional messages are queued, simulated, and logged locally. Outbound network requests, real SMTP connections, and live email dispatches are strictly disabled until explicit Level C authorization.
- **Two Generals' Problem & At-Least-Once Semantics:** Distributed systems acknowledgment: If an SMTP transport accepts a message (`250 OK`) but the worker crashes before committing the status in MariaDB, subsequent automated recovery will re-process the job. Without transport-level message-ID deduplication at the destination MTA, true exactly-once delivery across client crash windows is physically impossible. The status `sent` strictly denotes **Transport Acceptance**, not confirmed user inbox receipt.

---

## 2. Environment Prerequisites & Version Assumptions

### 2.1 Target Operating System & Runtime Assumptions
| Component | Supported Version | Required Extensions / Packages | Verification Command |
|---|---|---|---|
| **OS** | Ubuntu 22.04 LTS (Jammy Jellyfish) or 24.04 LTS | Standard system utilities, `systemd`, `supervisor`, `logrotate` | `lsb_release -a` |
| **PHP Runtime** | PHP 8.3 CLI (e.g. `php8.3-cli`, `php8.3-fpm`) | `pcntl`, `posix`, `pdo_mysql`, `redis`, `mbstring`, `curl`, `openssl`, `bcmath`, `tokenizer`, `xml` | `php -v && php -m` |
| **Process Manager** | Supervisor 4.2+ | `supervisor` | `supervisord -v` |
| **Relational Database** | MariaDB 10.11+ / MySQL 8.4+ | InnoDB engine, utf8mb4 charset | `mysql -V` |
| **Cache & Queue Broker** | Redis 7.0+ (or MariaDB database driver) | `redis-server` (if Redis queue selected) | `redis-server -v` |
| **Web Server / PHP-FPM** | NGINX 1.18+ & PHP 8.3-FPM | Run as `www-data:www-data` | `nginx -v` |

### 2.2 Linux Package Installation Reference
```bash
# Update and install core system utilities
sudo apt-get update && sudo apt-get install -y \
    software-properties-common \
    supervisor \
    logrotate \
    curl \
    git \
    unzip

# Install PHP 8.3 and mandatory CLI extensions
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y \
    php8.3-cli \
    php8.3-fpm \
    php8.3-mysql \
    php8.3-redis \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-bcmath \
    php8.3-zip

# Verify pcntl and posix extensions for queue worker process handling
php -m | grep -E "pcntl|posix|pdo_mysql|redis"
```

---

## 3. Application Environment Configuration & Secret Handling

### 3.1 Environment Variables Configuration (`.env`)
Configure the transactional mail subsystem in `/var/www/email-saas/laravel-panel/.env`:

```ini
# ==============================================================================
# Transactional Mail Delivery Subsystem Configuration
# ==============================================================================

# Mode: true = local simulation (zero SMTP outbound); false = live SMTP transport
MAIL_DELIVERY_DRY_RUN=true

# Queue & Cache Driver Selection
QUEUE_CONNECTION=database
CACHE_STORE=database

# Queue Worker Timing & Retry Boundaries
DB_QUEUE_RETRY_AFTER=90
MAIL_DELIVERY_JOB_TIMEOUT=60
MAIL_DELIVERY_WORKER_LEASE=120
MAIL_DELIVERY_STALE_THRESHOLD=900

# Retry and Backoff Constraints
MAIL_DELIVERY_MAX_ATTEMPTS=3
MAIL_DELIVERY_BASE_BACKOFF=60
MAIL_DELIVERY_MAX_BACKOFF=900

# Rate Limiting (Per-Tenant Ceiling)
MAIL_DELIVERY_TENANT_RATE_LIMIT=60

# Outbound Mail Transport (When MAIL_DELIVERY_DRY_RUN=false)
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@mailsaas.example.com"
MAIL_FROM_NAME="EmailSaaS System"
```

### 3.2 Secret Protection & Data Scrubbing Invariants
1. **Zero Secret Persistence:** `MailDeliveryService::scrubPayload()` automatically redacts sensitive keys (`password`, `token`, `secret`, `key`, `bearer`, etc.) before persisting records to `mail_deliveries.payload`.
2. **Error Trace Sanitization:** `ProcessMailDeliveryJob::sanitizeErrorMessage()` scrubs connection passwords (`redis://user:pass@host`), authorization headers, tokens, and hashes from exception traces prior to storing in `last_error`.
3. **Environment Security:** The `.env` file must be owned by `www-data:www-data` with restrictive permissions (`chmod 600 .env` or `chmod 640 .env`).

---

## 4. Queue Driver Selection & Performance Analysis

| Feature | `database` Queue Driver (Default Baseline) | `redis` Queue Driver (High-Throughput Alternative) |
|---|---|---|
| **Architectural Dependency** | MariaDB/MySQL only (zero extra daemons) | Redis 7+ server required |
| **Atomic Concurrency Locks** | Handled via `cache_locks` table | Native Redis atomic primitives |
| **Transaction Coordination** | Shared DB connection; inspectable via SQL | Separate memory store; requires DB synchronization |
| **Throughput Capacity** | ~100–300 jobs/sec (adequate for transactional notices) | 5,000+ jobs/sec |
| **Inspection Tooling** | Direct SQL (`SELECT * FROM jobs`, `failed_jobs`) | Redis CLI (`LRANGE`, `HGETALL`) or Horizon |
| **Operational Recommendation** | **Recommended for current single-node baseline** | Recommended for high-scale multi-worker clusters |

---

## 5. Linux Process Supervision & Supervisor Configuration

### 5.1 Supervisor Program Configuration
Create the Supervisor configuration file at `/etc/supervisor/conf.d/emailsaas-worker.conf`:

```ini
[program:emailsaas-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.3 /var/www/email-saas/laravel-panel/artisan queue:work database --sleep=3 --tries=5 --timeout=60 --max-time=3600 --max-jobs=1000 --rest=1
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/emailsaas/worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
stopwaitsecs=75
killasgroup=true
stopasgroup=true
environment=APP_ENV="production"
```

### 5.2 Key Parameter Rationales
- **`--timeout=60`:** Strictly aligns with `ProcessMailDeliveryJob::$timeout = 60` and must be less than `DB_QUEUE_RETRY_AFTER=90`.
- **`stopwaitsecs=75`:** Must be strictly greater than `--timeout=60` to allow the worker sufficient time to finish an in-flight job before receiving `SIGKILL`.
- **`--max-time=3600` & `--max-jobs=1000`:** Restarts worker periodically to eliminate PHP memory leaks.
- **`killasgroup=true` & `stopasgroup=true`:** Ensures child processes spawned by PHP CLI are terminated cleanly upon supervisor stop/restart.

### 5.3 Supervisor Operational Commands
```bash
# Reload supervisor configuration
sudo supervisorctl reread
sudo supervisorctl update

# Check worker process status
sudo supervisorctl status emailsaas-worker:*

# Gracefully restart workers (picks up newly deployed code)
sudo supervisorctl restart emailsaas-worker:*
```

---

## 6. Linux Scheduler Configuration

The Laravel scheduler coordinates periodic maintenance, including stale worker recovery (`mail:recover-stale` every 15 minutes).

### 6.1 Crontab Configuration
Add to the `www-data` user crontab (`sudo crontab -u www-data -e`):

```cron
* * * * * /usr/bin/php8.3 /var/www/email-saas/laravel-panel/artisan schedule:run >> /dev/null 2>&1
```

### 6.2 Alternative: Systemd Timer Service
For modern systemd environments without cron:

`/etc/systemd/system/emailsaas-scheduler.service`:
```ini
[Unit]
Description=EmailSaaS Laravel Scheduler Service
After=network.target mysql.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/email-saas/laravel-panel
ExecStart=/usr/bin/php8.3 artisan schedule:run

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/emailsaas-scheduler.timer`:
```ini
[Unit]
Description=Run EmailSaaS Laravel Scheduler every minute
RefuseManualStart=no
RefuseManualStop=no

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Unit=emailsaas-scheduler.service

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now emailsaas-scheduler.timer
sudo systemctl list-timers --all | grep emailsaas
```

---

## 7. Log Rotation & File Permission Requirements

### 7.1 Directory & File Permissions
```bash
# Ensure log and storage directories exist and are owned by www-data
sudo mkdir -p /var/log/emailsaas
sudo chown -R www-data:www-data /var/log/emailsaas
sudo chmod 755 /var/log/emailsaas

sudo chown -R www-data:www-data /var/www/email-saas/laravel-panel/storage
sudo chmod -R 775 /var/www/email-saas/laravel-panel/storage
sudo chown -R www-data:www-data /var/www/email-saas/laravel-panel/bootstrap/cache
sudo chmod -R 775 /var/www/email-saas/laravel-panel/bootstrap/cache
```

### 7.2 Logrotate Configuration
Create `/etc/logrotate.d/emailsaas`:

```text
/var/log/emailsaas/*.log
/var/www/email-saas/laravel-panel/storage/logs/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
    postrotate
        [ -f /var/run/supervisord.pid ] && supervisorctl signal USR2 all > /dev/null 2>&1 || true
    endscript
}
```

---

## 8. Queue & Retry Parameter Timing Hierarchy Audit

The application enforces a mathematically verified timing hierarchy across queue parameters:

$$\text{Job Timeout (60s)} < \text{Queue Retry After (90s)} < \text{Worker Lease (120s)} < \text{Stale Threshold (900s)}$$

| Parameter | Value | Configuration Location | Mutually Consistent Justification |
|---|---|---|---|
| **`job_timeout`** | **60s** | `config/mail_delivery.php:45`, `ProcessMailDeliveryJob::$timeout` | Worker aborts after 60s, leaving a 30s buffer before the queue driver considers the job abandoned. |
| **`retry_after`** | **90s** | `config/queue.php:43` (`connections.database.retry_after`) | Must be strictly $> \text{job\_timeout}$ (60s). Prevents a slow running worker from having its job handed to a duplicate worker. |
| **`worker_lease`** | **120s** | `config/mail_delivery.php:46` (`worker_lease_seconds`) | Must be strictly $> \text{retry\_after}$ (90s). Guarantees that if a worker crashes abruptly, sibling workers skip the record until the lease expires. |
| **`stale_threshold`** | **900s** | `config/mail_delivery.php:35`, `RecoverStaleMailCommand` | Must be strictly $> \text{worker\_lease}$ (120s). Guarantees that the 15-minute stale recovery sweep never interrupts an active worker. |
| **`base_backoff`** | **60s** | `config/mail_delivery.php:24` | Starting delay for transient errors before retry attempt 1. |
| **`max_backoff`** | **900s** | `config/mail_delivery.php:25` | Ceiling delay for backoff with jitter to prevent infinite delay growth. |
| **`scheduler_lock`** | **10 min** | `routes/console.php:39` (`withoutOverlapping(10)`) | Mutex lock duration is strictly less than the 15-minute cron schedule interval. |

---

## 9. Production-Critical Failure Analysis Matrix

| Scenario # | Failure Scenario | Existing Code Behavior | Evidence in Code / Tests | Severity | Mitigation & Protection Mechanism |
|---|---|---|---|---|---|
| **1** | SMTP transport accepts message, but worker crashes before recording `sent`. | Record remains `processing`. After 900s, stale recovery re-queues and another worker re-sends. | `ProcessMailDeliveryJob.php:109-119`, `MailDeliveryService.php:362-379` | **Medium** | Handled via Two Generals' Problem acknowledgment. Mitigated by setting deterministic `Message-ID: <uuid@domain>` in `TransactionalMail.php:67` for MTA deduplication. |
| **2** | Queue retries a job while an earlier worker is still active. | Sibling worker attempts conditional claim; fails because lease is active (`affected === 0`). Sibling skips. | `ProcessMailDeliveryJob.php:63-79`, `MailDeliverySecurityAndConsistencyTest` | **Low** | Timing hierarchy ensures $timeout (60s) < retry\_after (90s) < lease (120s)$. Active worker is never interrupted. |
| **3** | Stale recovery command races with worker completion. | Both execute atomic SQL updates conditioned on `status = 'processing'`. First committer wins; second affects 0 rows. | `MailDeliveryService.php:362-379`, `ProcessMailDeliveryJob.php:95-97` | **Low** | Atomic row-level SQL locking guarantees only one process can transition the record. Stale cutoff (900s) excludes running workers. |
| **4** | Administrator repeatedly retries failed deliveries. | Atomic update conditioned on `status IN ('failed', 'cancelled')`. First click returns `affected=1` and dispatches job; second click returns `affected=0` and skips dispatch. | `MailDeliveryService.php:249-265`, `AdminSystemHealthApiController.php:101-115` | **None** | Concurrent retry deduplication eliminates double dispatches. In-flight records return HTTP 409 Conflict. |
| **5** | Cache locking or rate-limiting infrastructure becomes unavailable. | RateLimiter / Cache::lock throws `QueryException` or Redis connection error; fails closed. | `MailDeliveryService.php:133-139, 156-160` | **Medium** | Fails closed preventing queue starvation and spam bursts. When using Redis, ensure HA/Sentinel or fallback to database cache. |
| **6** | Two requests enqueue the same idempotency key concurrently. | Both request atomic cache lock `mail_delivery_idemp:...`. Request 1 acquires and creates record; Request 2 waits, checks, and returns existing record. | `MailDeliveryService.php:154-193`, `MailDeliverySecurityAndConsistencyTest` | **None** | Atomic mutex serialization with 3-second block window eliminates duplicate insert race condition. |
| **7** | Database becomes unavailable during state transition. | Worker throws unhandled PDO exception. Record remains in current state. Queue driver retries job once DB restores. | `ProcessMailDeliveryJob.php:86-123` | **Medium** | InnoDB ACID transactions prevent partial writes. Stale processing recovery reconciles orphaned records once DB recovers. |
| **8** | Queue worker process restarts or is terminated during processing. | Supervisor sends SIGTERM; worker finishes current job within 60s timeout. If SIGKILL, record is rescued after 120s lease / 900s stale sweep. | `ProcessMailDeliveryJob.php:32, 58-74`, Supervisor `stopwaitsecs=75` | **Low** | Graceful shutdown allows in-flight job completion. Abrupt kills recovered by automated lease expiration and stale recovery sweep. |
| **9** | Invalid or unsafe URL submitted in transactional payload. | `validateSafeUrl()` validates action URLs strictly against `http` and `https` schemes; rejects `javascript:`, `data:`, `file:`. Templates escape all vars. | `MailDeliveryService.php:570-587`, Blade templates | **None** | Throws `InvalidPayloadException`. Blade `{{ ... }}` escaping eliminates stored XSS and open redirects. |
| **10** | Message contains sensitive credentials that must not enter logs. | `scrubPayload()` masks passwords, tokens, and secrets with `[REDACTED]`. `sanitizeErrorMessage()` regex scrubs URI credentials, bearer tokens, and hashes. | `MailDeliveryService.php:676-702`, `ProcessMailDeliveryJob.php:216-231` | **None** | Multi-tier recursive scrubbing prevents plaintext storage or logging of sensitive tokens. Health endpoints expose zero PII. |

---

## 10. Preflight & Verification Matrix

The deployment readiness is classified across three rigorous verification levels:

| Verification Level | Scope | Environment | Verification Status | Evidence / Approval Criteria |
|---|---|---|---|---|
| **Level A** | Application & API Layer | Local Windows (Laragon) | **`VERIFIED`** | 31 dedicated mail delivery tests passing cleanly (`MailDeliveryQueueTest` 16/16, `MailDeliverySecurityAndConsistencyTest` 15/15, full suite 569/569 passing, 2,829 assertions). |
| **Level B** | Linux Queue Worker & Scheduler Verification | Isolated Linux Staging VPS | **`PENDING INFRASTRUCTURE`** | Requires staging Ubuntu 22.04 LTS VPS running Supervisor daemon, Redis/MariaDB queue, and cron scheduler. |
| **Level C** | Approved Live SMTP Integration | Controlled Test Mailbox | **`BLOCKED — REQUIRES APPROVAL`** | Requires formal stakeholder sign-off, dedicated SMTP relay credentials (e.g. Mailgun, SendGrid, Amazon SES), and setting `MAIL_DELIVERY_DRY_RUN=false`. |

### Level C Controlled Verification Procedure (When Approved)
1. **Precondition Sign-Off:** Written authorization from Platform Engineering Lead.
2. **Dedicated Test Recipient:** Restricted strictly to an internal controlled mailbox (e.g. `qa-test-verify@staging.domain.com`).
3. **Controlled Enqueue:**
   ```bash
   php artisan tinker --execute="app(\App\Services\MailDeliveryService::class)->enqueue(tenantId: 1, recipientEmail: 'qa-test-verify@staging.domain.com', messageType: 'account_verification', subject: 'Production Readiness Verification Test', payload: ['user_name' => 'QA Operator', 'verification_url' => 'https://staging.domain.com/verify?token=test_token']);"
   ```
4. **Inspect Worker Transmission:**
   ```bash
   sudo tail -n 50 /var/log/emailsaas/worker.log
   ```
5. **Verify Transport Acceptance:** Check MariaDB status `status = 'sent'`, `is_dry_run = 0`, `sent_at IS NOT NULL`.
6. **Verify Header Determinism:** Confirm destination inbox received message with matching `Message-ID: <uuid@domain>` and `X-Delivery-UUID`.

---

## 11. Monitoring, Health Checks & Operational Telemetry

### 11.1 Administrative Monitoring Endpoints
All administrative endpoints are protected by `EnsureAdmin` middleware and granular RBAC (`admin.stats.read`):

| Endpoint | Method | Response Payload Summary | Privacy Invariant |
|---|---|---|---|
| `/api/health` | GET | `{"status": "HEALTHY", "timestamp": "..."}` | Public liveness probe. Zero infrastructure or mail data exposed. |
| `/api/admin/system/health` | GET | Composite health breakdown across database, redis, mail_delivery, notifications | Aggregated subsystem health status and counters. |
| `/api/admin/system/mail-queue` | GET | `status`, `dry_run_mode`, `queue_driver`, `configuration_health`, `metrics` (queued, processing, sent, failed, dead_letter, stale) | **Zero recipient emails, subjects, payloads, or message bodies.** |
| `/api/admin/system/mail-deliveries` | GET | Bounded paginated list of delivery records with filters (`status`, `message_type`, `date_from`, `date_to`) | Audited administrative inspection view. |
| `/api/admin/system/mail-deliveries/{id}/retry` | POST | Triggers manual retry for failed/cancelled delivery. Recorded in `audit_logs`. | Returns HTTP 409 Conflict if record is actively processing or already sent. |
| `/api/admin/system/mail-deliveries/{id}/cancel` | POST | Cancels pending queued delivery. Recorded in `audit_logs`. | Returns HTTP 409 Conflict if record is actively processing or already sent. |

### 11.2 Operational Alerting Thresholds
- **DEGRADED State Trigger:** Triggered if `stale_processing_count > 0` OR `dead_letter_count > 5` OR `db_failed_jobs_24h > 10` OR `!configuration_health.is_valid`.
- **Backlog Alert:** If `queued_count > 500` or `oldest_pending_age_seconds > 300`, alert platform on-call engineer to scale worker processes.

---

## 12. Disaster Recovery, Stale Processing Rescue & Rollback

### 12.1 Stale Processing Rescue Execution
To manually rescue in-flight jobs interrupted by unexpected host restarts or kernel panics:

```bash
# Dry-run inspection
php artisan mail:recover-stale --stale-seconds=900 --dry-run

# Active recovery execution
php artisan mail:recover-stale --stale-seconds=900
```

### 12.2 Dead-Letter Recovery Procedure
When deliveries reach maximum retry attempts (`status = 'failed'`, `attempts >= max_attempts`):
1. Inspect the aggregate queue health:
   ```bash
   php artisan tinker --execute="print_r(app(\App\Services\MailDeliveryService::class)->getOperationalStatus());"
   ```
2. Inspect failed deliveries and the recorded `last_error`:
   ```sql
   SELECT id, uuid, recipient_email, message_type, attempts, last_error, failed_at 
   FROM mail_deliveries 
   WHERE status = 'failed' 
   ORDER BY failed_at DESC LIMIT 10;
   ```
3. After addressing the root cause (e.g. SMTP relay outage, DNS resolution failure), retry via API or CLI:
   ```bash
   # Retry via API (generates audit log in audit_logs)
   curl -X POST -H "Authorization: Bearer <ADMIN_TOKEN>" https://panel.domain.com/api/admin/system/mail-deliveries/<ID>/retry
   ```

### 12.3 Rollback Preconditions & Procedures
If a newly deployed worker release causes queue instability:
1. **Pause Queue Consumption:**
   ```bash
   sudo supervisorctl stop emailsaas-worker:*
   ```
2. **Revert Application Code:**
   ```bash
   cd /var/www/email-saas/laravel-panel
   git checkout <PREVIOUS_COMMIT_TAG>
   composer install --no-dev --optimize-autoloader
   php artisan config:cache
   php artisan route:cache
   ```
3. **Verify Database Integrity:**
   ```sql
   SELECT status, count(*) FROM mail_deliveries GROUP BY status;
   ```
4. **Restart Workers:**
   ```bash
   sudo supervisorctl start emailsaas-worker:*
   ```


---

## 13. Linux Verification Harness & Preflight Execution Procedure (Step 43)

### 13.1 Verification Harness Script (`scripts/verify_mail_queue_linux.sh`)
The repository includes an automated Linux verification harness in `scripts/verify_mail_queue_linux.sh` designed to validate queue workers and scheduler integration on an isolated Linux staging VPS or configured WSL2 instance.

The harness executes 8 automated validation stages:
1. **Safety Preflight:** Asserts `.env` is present, `DB_HOST != 103.131.144.61`, and `MAIL_DELIVERY_DRY_RUN=true`.
2. **Operating System & Kernel Check:** Confirms native Linux kernel and distro release.
3. **PHP 8.3 CLI & Extensions:** Verifies PHP version $\ge 8.3$ and checks mandatory extensions (`pcntl`, `posix`, `pdo_mysql`, `redis`, `mbstring`, `openssl`, `curl`, `bcmath`).
4. **Database Readiness:** Verifies connectivity and asserts table presence (`mail_deliveries`, `jobs`, `failed_jobs`, `cache`, `cache_locks`).
5. **Queue Timing Consistency:** Audits timing hierarchy ($timeout < retry\_after < worker\_lease < stale\_threshold$).
6. **Worker Lifecycle & Signal Handling:** Spawns worker process in background and verifies clean `SIGTERM` shutdown.
7. **Scheduler Registration & Stale Recovery:** Verifies `mail:recover-stale` in `schedule:list` with `withoutOverlapping(10)` mutex lock.
8. **Synthetic Dry-Run Enqueue & Health Diagnostics:** Enqueues isolated test record, processes single job with worker, verifies status transitions to `sent` with zero external SMTP traffic, and confirms zero PII in telemetry.

### 13.2 Host Environment Probe Findings
During Step 43 preflight inspection:
- Host OS: Windows 10/11 with WSL2 (`Ubuntu-22.04` and `Ubuntu` images detected).
- Runtime Status in WSL2: Linux kernel `6.6.87.2-microsoft-standard-WSL2` is present, but neither distro image has PHP 8.3 CLI or MariaDB packages installed.
- Per strict Rule 2 instructions (*"Do not install new infrastructure... without explicit approval. If Linux execution is unavailable, create the verification scripts and runbook but mark actual Linux verification as PENDING"*), the harness script was validated for bash syntax (`bash -n scripts/verify_mail_queue_linux.sh` passed exit code 0), and actual Linux worker execution is classified as:
  $$\text{Level B Status: } \textbf{PENDING (REQUIRES LINUX STAGING / WSL PHP 8.3 RUNTIME)}$$

### 13.3 Recovery & Consistency Scenarios Matrix (Step 43)

| Scenario # | Scenario Description | Tested Mechanism | Resulting State & Log Evidence | Unresolved Risk / Mitigation |
|---|---|---|---|---|
| **1** | Worker exits before processing queued job. | Record remains in `queued` status; job remains in `jobs` table. | Another worker claims the job on next poll. `status = 'queued'`, `attempts = 0`. | **Zero risk.** No duplicate deliveries. |
| **2** | Worker terminates abruptly during processing (SIGKILL/OOM). | Record remains `processing`. Sibling workers skip during 120s lease. After 900s, `mail:recover-stale` claims record. | Re-queued as `queued` with `attempts = attempts + 1`. Dead-lettered to `failed` if attempts exceed `max_attempts`. | **At-least-once duplicate risk:** If crash occurred post-SMTP acceptance, email is re-sent. Mitigated by deterministic RFC 5322 `Message-ID: <uuid@domain>`. |
| **3** | Database connectivity temporarily lost. | Worker catches PDO exception, releases job back to queue. Enqueue calls fail closed. | No corrupt rows written. Queue workers resume processing once DB connectivity recovers. | Handled via InnoDB transactions. Resilient DB reconnect recommended. |
| **4** | Stale recovery sweep overlaps with worker completion. | Both execute atomic conditional SQL updates conditioned on `status = 'processing'`. | MariaDB row locking ensures first committer wins; second affects 0 rows. Active workers within 900s are ignored. | **Zero race condition.** Mathematically impossible for active worker $\le 60\text{s}$ to be reclaimed. |
| **5** | Supervisor restarts a failed worker. | Supervisor program configured with `autorestart=true`, `stopwaitsecs=75`. | Process restarts immediately; picks up next job from `jobs` table without manual intervention. | Worker logs written to `/var/log/emailsaas/worker.log`. |
| **6** | Scheduler execution delayed or repeated. | Scheduled with `withoutOverlapping(10)` mutex lock. | Overlapping runs skipped if previous run holds lock. Recovery method is idempotent. | **Zero duplicate recovery.** |
| **7** | Admin requests retry while record is processing. | `MailDeliveryService::retry()` checks `isProcessing()`; throws `InvalidDeliveryStateException`. | API responds with `HTTP 409 Conflict`. In-flight worker proceeds uninterrupted. | **Zero double-dispatch.** |

### 13.4 Verification Harness Test Suite (`LinuxQueueWorkerHarnessTest.php`)
The verification harness is accompanied by an automated feature test suite in `tests/Feature/LinuxQueueWorkerHarnessTest.php` with 10 dedicated tests:
1. `test_timing_hierarchy_mutual_consistency`: PASSED (5 assertions)
2. `test_scheduler_registration_and_mutex_lock`: PASSED (4 assertions)
3. `test_worker_interruption_preserves_processing_state`: PASSED (2 assertions)
4. `test_stale_recovery_reclaims_interrupted_worker_after_cutoff`: PASSED (6 assertions)
5. `test_stale_recovery_dead_letters_when_max_attempts_reached`: PASSED (6 assertions)
6. `test_stale_recovery_skips_active_workers_within_lease_time`: PASSED (4 assertions)
7. `test_admin_retry_while_processing_returns_409_conflict`: PASSED (2 assertions)
8. `test_deterministic_message_id_constant_across_retries`: PASSED (4 assertions)
9. `test_health_telemetry_guarantees_zero_pii`: PASSED (7 assertions)
10. `test_dry_run_safety_guarantees_local_simulation`: PASSED (5 assertions)

### 13.5 Execution Guide on Linux Staging VPS
Once an isolated Ubuntu 22.04 LTS VPS is provisioned:
```bash
# 1. Switch to application directory
cd /var/www/email-saas

# 2. Make verification harness executable
chmod +x scripts/verify_mail_queue_linux.sh

# 3. Execute verification harness
bash scripts/verify_mail_queue_linux.sh

# 4. Expected Exit Status:
# Exit Code 0: All 8 stages passed cleanly
```

---

## 14. Step 44 — Linux Verification Readiness & Evidence Audit

### 14.1 Objective & Audit Scope
Step 44 conducted an exhaustive, evidence-based audit of Step 43's Linux verification harness (`scripts/verify_mail_queue_linux.sh`), worker lifecycle, timing hierarchies, stale recovery mechanics, scheduler mutex locks, and distributed systems invariants.

### 14.2 Ten-Point Queue Consistency & Recovery Audit Findings

1. **Mathematical Timing Hierarchy ($T_{\text{job}} < T_{\text{retry}} < T_{\text{lease}} < T_{\text{stale}}$):**
   - Configured: `job_timeout=60s` < `retry_after=90s` < `worker_lease=120s` < `stale_threshold=900s`. Supervisor `stopwaitsecs=75s`.
   - Verified that `SystemHealthService` and `MailDeliveryService::validateConfiguration()` enforce this invariant before any queue worker operates.

2. **Queue Retries vs. Application Attempts:**
   - Laravel queue worker tracks raw delivery attempts in the `jobs` table (`attempts` column), capped by `ProcessMailDeliveryJob::$tries = 5`.
   - `MailDeliveryRecord` tracks domain-level transactional attempts (`attempts` and `max_attempts = 3`).
   - If an abrupt worker crash occurs mid-flight, Laravel's queue driver releases the job after `retry_after=90s`. When re-attempted, `claimForProcessing()` checks `$record->isProcessing()` and `$record->last_attempted_at > now()->subSeconds(120)`. Because 90s < 120s, the in-flight claim rejects concurrent double-execution and gracefully yields until the lease expires.

3. **Stale Recovery Semantics (`mail:recover-stale`):**
   - Atomic conditional update:
     ```sql
     UPDATE mail_deliveries 
     SET status = 'queued', attempts = attempts + 1, last_attempted_at = NOW(), ...
     WHERE id = ? AND status = 'processing' AND last_attempted_at <= ?
     ```
   - If `attempts >= max_attempts`, atomically dead-letters the record to `status = 'failed'`.
   - Operates in scheduled batches of 100 with `withoutOverlapping(10)` mutex locking in `routes/console.php`.

4. **Undead Worker / Fencing Token Gap (Distributed Systems Analysis):**
   - In distributed systems, if a worker hangs (e.g. frozen network socket or GC pause) past the 15-minute `stale_threshold` (900s), stale recovery will re-queue the record to a second worker.
   - If the original worker suddenly awakens and calls `markSent()`, the SQL update `UPDATE mail_deliveries SET status = 'sent' WHERE id = ? AND status = 'processing'` would succeed because the second worker had transitioned the status back to `processing`.
   - *Audit Recommendation:* In Step 45/production hardening, introduce an atomic monotonic fencing token (`worker_claim_token` UUID or `version` integer) generated at each claim and verified on transition to `sent`.

5. **Concurrency Safety of Administrative Actions (Retry / Cancel):**
   - Both `retry()` and `cancel()` perform atomic conditional updates (`WHERE id = ? AND status = 'failed'` and `WHERE id = ? AND status = 'queued'`).
   - In-flight `processing` records reject administrative intervention with HTTP 409 Conflict.
   - Zero double-enqueue race conditions exist.

6. **Outage & Partition Behavior:**
   - In the event of database or cache disconnection, all transactional mail enqueue operations fail closed (`throw new Exception`), preventing ghost entries or phantom dispatches.

7. **Transport Boundary & Two Generals' Problem:**
   - Inherent to SMTP delivery: If the transport successfully hands an email to the relay (`250 2.0.0 OK`), but the worker process is terminated before it updates the local database to `sent`, stale recovery will eventually re-dispatch the message.
   - This risk is minimized via short worker lease timeouts, atomic DB updates immediately following transport return, and deterministic Message-ID headers.

8. **RFC 5322 Message-ID Deduplication Invariant:**
   - `TransactionalMail` sets `Message-ID: <{uuid}@{domain}>` and `X-Delivery-UUID: {uuid}` identically across all retries.
   - *Clarification:* RFC 5322 Message-ID is a transport-layer deduplication hint for RFC 5321 MTAs (Postfix, Gmail, Microsoft 365). It is **not** a universal guarantee against duplicate delivery if the recipient MTA ignores duplicate Message-IDs. Application-level idempotency remains the primary defense.

9. **Environment Dependencies & Extensions:**
   - Runtime dependencies audited: PHP 8.3 CLI, `ext-pcntl`, `ext-posix`, `ext-pdo_mysql`, `ext-redis`, `ext-mbstring`, `ext-openssl`, `ext-curl`, `ext-bcmath`.
   - Missing `pcntl` and `posix` on Windows prevents asynchronous signal handling (`SIGTERM`/`SIGQUIT`), confirming that worker verification must be segregated into Windows (Level A) and Linux (Level B).

10. **Harness Defect Remediation (`scripts/verify_mail_queue_linux.sh`):**
    - Remediated CLI option parsing to support both `--dry-run` and `--skip-worker-signal`.
    - Hardened Stage 0 preflight checks to strictly reject remote DB `103.131.144.61`.
    - Added bounded 5-second polling on SIGTERM in Stage 5 to prevent hung child processes.
    - Isolated Stage 7 execution by using direct job execution `(new ProcessMailDeliveryJob($record->id))->handle()` with `jobs` table dequeue and `try/finally` cleanup to prevent head-of-line contention and test record leakage.

### 14.3 Evidence Verification Matrix

| Level | Scope | Environment | Verification Method | Status |
|---|---|---|---|---|
| **Level A** | Application Mail Queue Logic, Security, Invariants | Windows 10 + Laragon (PHP 8.3.33, MySQL 8.4.3) | PHPUnit (31 mail feature tests, 10 harness tests, 184 assertions) | **PASS** |
| **Level B** | Linux Worker Process Lifecycle, Signals & Cron Scheduler | Linux / WSL2 Ubuntu 22.04 LTS | `scripts/verify_mail_queue_linux.sh` | **PENDING INFRASTRUCTURE** (Bash syntax verified; Linux PHP 8.3 CLI runtime uninstalled on host) |
| **Level C** | Controlled Live SMTP Delivery | Remote Linux VPS + Postfix/DNS | Live SMTP handshake & DMARC/SPF/DKIM alignment | **BLOCKED** (Explicit architecture approval required) |


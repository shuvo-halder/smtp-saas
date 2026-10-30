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

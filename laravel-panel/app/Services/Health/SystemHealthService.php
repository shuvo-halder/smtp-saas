<?php

namespace App\Services\Health;

use App\Models\ScheduledTaskRun;
use App\Services\Scheduling\ScheduledTaskTrackerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthService
{
    public function __construct(
        protected ScheduledTaskTrackerService $schedulerTracker
    ) {}

    /**
     * Compute comprehensive system health diagnostics for authenticated administrators.
     *
     * @return array<string, mixed>
     */
    public function getHealth(): array
    {
        $app = $this->checkApp();
        $database = $this->checkDatabase();
        $redis = $this->checkRedis();
        $scheduler = $this->checkScheduler();
        $taskFailures = $this->checkTaskFailures();
        $queue = $this->checkQueue();
        $backup = $this->checkBackup();

        $components = [
            'app'           => $app,
            'database'      => $database,
            'redis'         => $redis,
            'scheduler'     => $scheduler,
            'task_failures' => $taskFailures,
            'queue'         => $queue,
            'backup'        => $backup,
        ];

        $overallStatus = $this->resolveOverallStatus($components);

        // Fetch up to 15 recent scheduled task runs
        $recentTasks = ScheduledTaskRun::recent(15)->get()->map(function (ScheduledTaskRun $run) {
            return [
                'id'              => $run->id,
                'uuid'            => $run->uuid,
                'task_name'       => $run->task_name,
                'expression'      => $run->expression,
                'status'          => $run->status,
                'started_at'      => $run->started_at?->toIso8601String(),
                'finished_at'     => $run->finished_at?->toIso8601String(),
                'duration_ms'     => $run->duration_ms,
                'failure_summary' => $run->failure_summary,
            ];
        });

        return [
            'status'       => $overallStatus,
            'timestamp'    => now()->toIso8601String(),
            'environment'  => app()->environment(),
            'components'   => $components,
            'recent_tasks' => $recentTasks,
        ];
    }

    /**
     * Minimal public liveness check revealing zero internal topology or secrets.
     *
     * @return array{status: string, timestamp: string}
     */
    public function getLiveness(): array
    {
        return [
            'status'    => 'HEALTHY',
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Check Laravel Application Core.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function checkApp(): array
    {
        return [
            'status'  => 'HEALTHY',
            'details' => [
                'php_version' => PHP_VERSION,
                'framework'   => 'Laravel ' . app()->version(),
                'environment' => app()->environment(),
                'timezone'    => config('app.timezone', 'UTC'),
                'debug_mode'  => (bool) config('app.debug', false),
            ],
        ];
    }

    /**
     * Check Database connectivity and latency with sanitized error handling.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function checkDatabase(): array
    {
        $connection = config('database.default', 'mysql');
        $start = microtime(true);

        try {
            DB::connection()->select('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status'  => 'HEALTHY',
                'details' => [
                    'connection' => $connection,
                    'latency_ms' => $latency,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'UNHEALTHY',
                'details' => [
                    'connection' => $connection,
                    'error'      => 'Database connection failed or timed out',
                ],
            ];
        }
    }

    /**
     * Check Redis availability and latency.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function checkRedis(): array
    {
        $host = config('database.redis.default.host');

        if (empty($host)) {
            return [
                'status'  => 'NOT_CONFIGURED',
                'details' => [
                    'reason' => 'Redis is not configured in this environment',
                ],
            ];
        }

        $start = microtime(true);

        try {
            Redis::ping();
            $latency = round((microtime(true) - $start) * 1000, 2);

            return [
                'status'  => 'HEALTHY',
                'details' => [
                    'latency_ms' => $latency,
                    'client'     => config('database.redis.client', 'phpredis'),
                ],
            ];
        } catch (Throwable $e) {
            // Redis fails open for outbound SMTP quotas per architecture, but degrades live OTP / telemetry
            return [
                'status'  => 'DEGRADED',
                'details' => [
                    'error' => 'Redis unreachable; system operating in fail-open quota mode',
                ],
            ];
        }
    }

    /**
     * Check Scheduler heartbeat freshness via ScheduledTaskTrackerService.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function checkScheduler(): array
    {
        $freshness = $this->schedulerTracker->getFreshness();

        return [
            'status'  => $freshness['status'],
            'details' => [
                'freshness'              => $freshness['freshness'],
                'last_run_at'            => $freshness['last_run_at'],
                'seconds_since_last_run' => $freshness['seconds_since_last_run'],
                'registered_tasks_count' => $freshness['registered_tasks_count'],
            ],
        ];
    }

    /**
     * Check Recent Scheduled Task Failures.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function checkTaskFailures(): array
    {
        $failures = $this->schedulerTracker->getRecentFailures(24);

        return [
            'status'  => $failures['status'],
            'details' => [
                'failures_24h'         => $failures['failures_24h'],
                'last_failure_at'      => $failures['last_failure_at'],
                'last_failed_command'  => $failures['last_failed_command'],
                'last_failure_summary' => $failures['last_failure_summary'],
            ],
        ];
    }

    /**
     * Check Queue status, pending jobs, and recent failed jobs.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function checkQueue(): array
    {
        $driver = config('queue.default', 'sync');

        if ($driver === 'sync') {
            return [
                'status'  => 'HEALTHY',
                'details' => [
                    'driver'          => 'sync',
                    'mode'            => 'Synchronous in-process queue',
                    'pending_jobs'    => 0,
                    'failed_jobs_24h' => 0,
                ],
            ];
        }

        try {
            $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
            $failed = Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subHours(24))->count()
                : 0;

            return [
                'status'  => $failed > 0 ? 'DEGRADED' : 'HEALTHY',
                'details' => [
                    'driver'          => $driver,
                    'pending_jobs'    => $pending,
                    'failed_jobs_24h' => $failed,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'UNKNOWN',
                'details' => [
                    'driver' => $driver,
                    'error'  => 'Unable to inspect queue tables',
                ],
            ];
        }
    }

    /**
     * Inspect Backup Posture from local trusted companion manifests.
     * Respects Step 18 governance: does NOT expose or invent a public/unapproved backup HTTP API.
     *
     * @return array{status: string, details: array<string, mixed>}
     */
    public function checkBackup(): array
    {
        try {
            $dbPath = config('backup.storage.db_path', storage_path('backups/db'));
            $vmailPath = config('backup.storage.vmail_path', storage_path('backups/vmail'));
            $encryptionEnabled = (bool) config('backup.encryption.enabled', false);

            $latestDbManifest = null;
            if (is_dir($dbPath)) {
                $files = glob("{$dbPath}/*.manifest.json");
                if (! empty($files)) {
                    rsort($files);
                    $latestDbManifest = @json_decode((string) file_get_contents($files[0]), true);
                }
            }

            $latestVmailManifest = null;
            if (is_dir($vmailPath)) {
                $files = glob("{$vmailPath}/*.manifest.json");
                if (! empty($files)) {
                    rsort($files);
                    $latestVmailManifest = @json_decode((string) file_get_contents($files[0]), true);
                }
            }

            if (! $latestDbManifest && ! $latestVmailManifest) {
                return [
                    'status'  => 'NOT_CONFIGURED',
                    'details' => [
                        'posture'            => 'No local backup manifests present in this environment',
                        'encryption_enabled' => $encryptionEnabled,
                        'note'               => 'Dedicated backup HTTP API: REQUIRES ARCHITECTURE APPROVAL',
                    ],
                ];
            }

            return [
                'status'  => 'HEALTHY',
                'details' => [
                    'latest_db_backup_at'    => $latestDbManifest['timestamp'] ?? null,
                    'latest_vmail_backup_at' => $latestVmailManifest['timestamp'] ?? null,
                    'db_verified'            => $latestDbManifest['verified'] ?? false,
                    'encryption_enabled'     => $encryptionEnabled,
                    'note'                   => 'Posture verified from local companion manifests; HTTP API requires approval',
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'UNKNOWN',
                'details' => [
                    'note' => 'Could not inspect local backup manifests',
                ],
            ];
        }
    }

    /**
     * Resolve the overall composite status from component states.
     *
     * @param array<string, array{status: string}> $components
     */
    protected function resolveOverallStatus(array $components): string
    {
        // 1. Critical failure: database or application core unhealthy
        if (($components['database']['status'] ?? '') === 'UNHEALTHY' || ($components['app']['status'] ?? '') === 'UNHEALTHY') {
            return 'UNHEALTHY';
        }

        // 2. Degraded failure: any component degraded or secondary component unhealthy
        foreach ($components as $component) {
            $status = $component['status'] ?? '';
            if ($status === 'DEGRADED' || $status === 'UNHEALTHY') {
                return 'DEGRADED';
            }
        }

        // 3. Otherwise Healthy
        return 'HEALTHY';
    }
}

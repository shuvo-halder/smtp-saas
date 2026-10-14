<?php

namespace App\Services\Scheduling;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScheduledTaskTrackerService
{
    /**
     * In-memory map of active task run IDs keyed by object ID of the Event instance.
     *
     * @var array<int, int>
     */
    protected array $activeRuns = [];

    /**
     * Record the start of a scheduled task.
     */
    public function recordStart(Event $task): ?ScheduledTaskRun
    {
        try {
            $taskName = $this->extractTaskName($task);
            $expression = $task->expression ?? null;

            $run = ScheduledTaskRun::create([
                'task_name'   => $taskName,
                'expression'  => $expression,
                'status'      => 'running',
                'started_at'  => now(),
            ]);

            $this->activeRuns[spl_object_id($task)] = $run->id;

            return $run;
        } catch (Throwable $e) {
            $this->logFailSafe('Failed to record task start', $e);
            return null;
        }
    }

    /**
     * Record the successful completion of a scheduled task.
     */
    public function recordFinish(Event $task, float $runtime): ?ScheduledTaskRun
    {
        try {
            $taskName = $this->extractTaskName($task);
            $durationMs = (int) round($runtime * 1000);
            $run = $this->findActiveRun($task, $taskName);

            if ($run) {
                $run->update([
                    'status'      => 'success',
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                ]);
            } else {
                $run = ScheduledTaskRun::create([
                    'task_name'   => $taskName,
                    'expression'  => $task->expression ?? null,
                    'status'      => 'success',
                    'started_at'  => now()->subMilliseconds($durationMs),
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                ]);
            }

            unset($this->activeRuns[spl_object_id($task)]);

            return $run;
        } catch (Throwable $e) {
            $this->logFailSafe('Failed to record task completion', $e);
            return null;
        }
    }

    /**
     * Record the failure of a scheduled task.
     */
    public function recordFailure(Event $task, Throwable $exception): ?ScheduledTaskRun
    {
        try {
            $taskName = $this->extractTaskName($task);
            $run = $this->findActiveRun($task, $taskName);
            $sanitizedSummary = $this->sanitizeSummary($exception->getMessage());

            if ($run) {
                $durationMs = $run->started_at ? now()->diffInMilliseconds($run->started_at) : null;
                $run->update([
                    'status'          => 'failed',
                    'finished_at'     => now(),
                    'duration_ms'     => $durationMs,
                    'failure_summary' => $sanitizedSummary,
                ]);
            } else {
                $run = ScheduledTaskRun::create([
                    'task_name'       => $taskName,
                    'expression'      => $task->expression ?? null,
                    'status'          => 'failed',
                    'started_at'      => now(),
                    'finished_at'     => now(),
                    'duration_ms'     => 0,
                    'failure_summary' => $sanitizedSummary,
                ]);
            }

            unset($this->activeRuns[spl_object_id($task)]);

            return $run;
        } catch (Throwable $e) {
            $this->logFailSafe('Failed to record task failure', $e);
            return null;
        }
    }

    /**
     * Record a skipped scheduled task (e.g. mutex lock held or condition filter returned false).
     */
    public function recordSkipped(Event $task, ?string $reason = null): ?ScheduledTaskRun
    {
        try {
            $taskName = $this->extractTaskName($task);
            $summary = $reason ? $this->sanitizeSummary($reason) : 'Skipped by mutex lock or environment condition.';

            $run = ScheduledTaskRun::create([
                'task_name'       => $taskName,
                'expression'      => $task->expression ?? null,
                'status'          => 'skipped',
                'started_at'      => now(),
                'finished_at'     => now(),
                'duration_ms'     => 0,
                'failure_summary' => Str::limit($summary, 500),
            ]);

            unset($this->activeRuns[spl_object_id($task)]);

            return $run;
        } catch (Throwable $e) {
            $this->logFailSafe('Failed to record task skip', $e);
            return null;
        }
    }

    /**
     * Prune old task execution records older than given days.
     */
    public function prune(int $keepDays = 7): int
    {
        try {
            return ScheduledTaskRun::where('started_at', '<', now()->subDays($keepDays))->delete();
        } catch (Throwable $e) {
            $this->logFailSafe('Failed to prune old scheduled task runs', $e);
            return 0;
        }
    }

    /**
     * Inspect scheduler freshness based on recent execution heartbeat.
     *
     * @return array{status: string, freshness: string, last_run_at: ?string, seconds_since_last_run: ?int, registered_tasks_count: int}
     */
    public function getFreshness(int $registeredTasksCount = 5): array
    {
        try {
            $latestRun = ScheduledTaskRun::latest('started_at')->first();

            if (! $latestRun) {
                return [
                    'status'                 => 'UNKNOWN',
                    'freshness'              => 'unknown',
                    'last_run_at'            => null,
                    'seconds_since_last_run' => null,
                    'registered_tasks_count' => $registeredTasksCount,
                ];
            }

            $secondsSince = (int) abs(now()->diffInSeconds($latestRun->started_at));

            // tenant:suspend-expired runs every minute; fresh if ran <= 300s (5m)
            if ($secondsSince <= 300) {
                $status = 'HEALTHY';
                $freshness = 'fresh';
            } elseif ($secondsSince <= 900) {
                $status = 'DEGRADED';
                $freshness = 'delayed';
            } else {
                $status = 'UNHEALTHY';
                $freshness = 'stale';
            }

            return [
                'status'                 => $status,
                'freshness'              => $freshness,
                'last_run_at'            => $latestRun->started_at->toIso8601String(),
                'seconds_since_last_run' => $secondsSince,
                'registered_tasks_count' => $registeredTasksCount,
            ];
        } catch (Throwable $e) {
            $this->logFailSafe('Failed to check scheduler freshness', $e);
            return [
                'status'                 => 'UNKNOWN',
                'freshness'              => 'unknown',
                'last_run_at'            => null,
                'seconds_since_last_run' => null,
                'registered_tasks_count' => $registeredTasksCount,
            ];
        }
    }

    /**
     * Inspect recent failures across all scheduled tasks in the given window.
     *
     * @return array{status: string, failures_24h: int, last_failure_at: ?string, last_failed_command: ?string, last_failure_summary: ?string}
     */
    public function getRecentFailures(int $hours = 24): array
    {
        try {
            $failuresQuery = ScheduledTaskRun::failed()->where('started_at', '>=', now()->subHours($hours));
            $count = $failuresQuery->count();
            $latestFailure = $failuresQuery->latest('started_at')->first();

            return [
                'status'               => $count > 0 ? 'DEGRADED' : 'HEALTHY',
                'failures_24h'         => $count,
                'last_failure_at'      => $latestFailure?->started_at?->toIso8601String(),
                'last_failed_command'  => $latestFailure?->task_name,
                'last_failure_summary' => $latestFailure?->failure_summary,
            ];
        } catch (Throwable $e) {
            $this->logFailSafe('Failed to inspect recent task failures', $e);
            return [
                'status'               => 'UNKNOWN',
                'failures_24h'         => 0,
                'last_failure_at'      => null,
                'last_failed_command'  => null,
                'last_failure_summary' => null,
            ];
        }
    }

    /**
     * Extract a clean, canonical task identifier from the scheduled event.
     */
    public function extractTaskName(Event $task): string
    {
        if (! empty($task->description)) {
            return trim($task->description);
        }

        $command = $task->command ?? '';

        if (! empty($command)) {
            // Strip binary path prefix (e.g. 'artisan' or /usr/bin/php)
            if (preg_match("/artisan['\"]?\s+([^>\s]+.*)$/i", $command, $matches)) {
                $raw = $matches[1];
            } else {
                $raw = $command;
            }

            // Strip redirection operators like > /dev/null 2>&1
            $clean = preg_replace('/\s*>\s*[^ ]+.*$/', '', $raw);
            $clean = trim($clean, " '\"\t\n\r\0\x0B");

            if (! empty($clean)) {
                return Str::limit($clean, 191, '');
            }
        }

        return Str::limit($task->getSummaryForDisplay(), 191, '');
    }

    /**
     * Find active run for a task from object mapping or database lookup.
     */
    protected function findActiveRun(Event $task, string $taskName): ?ScheduledTaskRun
    {
        $objectId = spl_object_id($task);

        if (isset($this->activeRuns[$objectId])) {
            $run = ScheduledTaskRun::find($this->activeRuns[$objectId]);
            if ($run) {
                return $run;
            }
        }

        // Fallback: lookup latest running instance started within last 15 minutes
        return ScheduledTaskRun::where('task_name', $taskName)
            ->where('status', 'running')
            ->where('started_at', '>=', now()->subMinutes(15))
            ->latest('started_at')
            ->first();
    }

    /**
     * Sanitize failure summary: redacts passwords, tokens, hashes, and secrets.
     */
    public function sanitizeSummary(string $summary): string
    {
        // 1. Redact cryptographic hash signatures ($6$, $2y$, $argon2)
        $summary = preg_replace('/(\$6\$|\$2y\$|\$2a\$|\$argon2[a-z]*\$)[^\s\'"]+/', '[REDACTED_HASH]', $summary);

        // 2. Redact key-value secrets (e.g. password=..., secret=..., token=..., key=...)
        $summary = preg_replace('/(?i)(password|secret|token|api_key|key|auth|passphrase)[=:]\s*([^\s,;]+)/', '$1=[REDACTED]', $summary);

        // 3. Redact Bearer tokens
        $summary = preg_replace('/Bearer\s+[A-Za-z0-9_\-\.\~]+/i', 'Bearer [REDACTED]', $summary);

        // 4. Redact database DSN credentials (e.g. mysql://user:pass@host:port/db)
        $summary = preg_replace('/:\/\/([^:]+):([^@]+)@/', '://[REDACTED]:[REDACTED]@', $summary);

        return Str::limit(trim($summary), 500);
    }

    /**
     * Fail-safe operational logging.
     */
    protected function logFailSafe(string $message, Throwable $e): void
    {
        Log::warning("[ScheduledTaskTrackerService] {$message}: " . $this->sanitizeSummary($e->getMessage()), [
            'exception_class' => get_class($e),
            'code'            => $e->getCode(),
        ]);
    }
}

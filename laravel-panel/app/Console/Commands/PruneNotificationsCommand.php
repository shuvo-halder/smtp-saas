<?php

namespace App\Console\Commands;

use App\Services\TenantNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune
                            {--read-days=90 : Days to retain read notifications (min: 1)}
                            {--dismissed-days=30 : Days to retain dismissed notifications (min: 1)}
                            {--stale-days=180 : Days to retain stale non-critical unread notifications (min: 1)}
                            {--batch-size=500 : Batch size for bounded chunk deletion (1-5000)}
                            {--dry-run : Simulate pruning without deleting records}';

    protected $description = 'Prune expired tenant notifications according to retention policy without touching unresolved critical alerts or audit logs.';

    public function handle(TenantNotificationService $notificationService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $readDays = (int) $this->option('read-days');
        $dismissedDays = (int) $this->option('dismissed-days');
        $staleDays = (int) $this->option('stale-days');
        $batchSize = (int) $this->option('batch-size');

        // Input validation for retention boundaries and batch sizing
        if ($readDays < 1) {
            $this->error('The --read-days option must be at least 1 day.');
            return Command::INVALID;
        }

        if ($dismissedDays < 1) {
            $this->error('The --dismissed-days option must be at least 1 day.');
            return Command::INVALID;
        }

        if ($staleDays < 1) {
            $this->error('The --stale-days option must be at least 1 day.');
            return Command::INVALID;
        }

        if ($batchSize < 1 || $batchSize > 5000) {
            $this->error('The --batch-size option must be between 1 and 5000.');
            return Command::INVALID;
        }

        $this->info('--> Running tenant notifications retention pruning...');
        $this->line("  Read retention:      {$readDays} days");
        $this->line("  Dismissed retention: {$dismissedDays} days");
        $this->line("  Stale unread:        {$staleDays} days");
        $this->line("  Batch size:          {$batchSize}");
        $this->line("  Dry run mode:        " . ($dryRun ? 'ENABLED' : 'DISABLED'));

        if (!$dryRun) {
            $lock = Cache::lock('notifications_prune_lock', 300);
            if (!$lock->get()) {
                $this->warn('Another notifications pruning process is currently running. Skipping execution.');
                return Command::SUCCESS;
            }
        }

        $startTime = microtime(true);

        try {
            $result = $notificationService->pruneExpiredNotifications(
                readDays: $readDays,
                dismissedDays: $dismissedDays,
                staleDays: $staleDays,
                batchSize: $batchSize,
                dryRun: $dryRun
            );

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            // Record operational execution summary in cache for administrator telemetry
            if (!$dryRun) {
                Cache::put('notifications:prune:last_execution', [
                    'executed_at'    => now()->toIso8601String(),
                    'status'         => 'success',
                    'read_days'      => $readDays,
                    'dismissed_days' => $dismissedDays,
                    'stale_days'     => $staleDays,
                    'batch_size'     => $batchSize,
                    'dry_run'        => false,
                    'total_eligible' => $result['total_eligible'],
                    'pruned_count'   => $result['pruned_count'],
                    'duration_ms'    => $durationMs,
                ], 86400 * 7);
            }

            $this->line('');
            $this->info('Pruning Results:');
            $this->line("  Total Eligible: {$result['total_eligible']}");
            $this->line("  Total Pruned:   {$result['pruned_count']}");

            if ($dryRun) {
                $this->comment('Dry run complete. No records were deleted from tenant_notifications.');
            } else {
                $this->info('Notification retention pruning completed successfully.');
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            if (!$dryRun) {
                Cache::put('notifications:prune:last_execution', [
                    'executed_at' => now()->toIso8601String(),
                    'status'      => 'failed',
                    'error'       => $e->getMessage(),
                ], 86400 * 7);
            }

            $this->error('Failed to prune expired tenant notifications: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            if (!$dryRun && isset($lock)) {
                $lock->release();
            }
        }
    }
}

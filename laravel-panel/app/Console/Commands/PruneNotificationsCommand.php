<?php

namespace App\Console\Commands;

use App\Services\TenantNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PruneNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune
                            {--read-days=90 : Days to retain read notifications}
                            {--dismissed-days=30 : Days to retain dismissed notifications}
                            {--batch-size=500 : Batch size for bounded chunk deletion}
                            {--dry-run : Simulate pruning without deleting records}';

    protected $description = 'Prune expired tenant notifications according to retention policy without touching unresolved critical alerts or audit logs.';

    public function handle(TenantNotificationService $notificationService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $readDays = (int) $this->option('read-days');
        $dismissedDays = (int) $this->option('dismissed-days');
        $batchSize = (int) $this->option('batch-size');

        $this->info('--> Running tenant notifications retention pruning...');
        $this->line("  Read retention:      {$readDays} days");
        $this->line("  Dismissed retention: {$dismissedDays} days");
        $this->line("  Batch size:          {$batchSize}");
        $this->line("  Dry run mode:        " . ($dryRun ? 'ENABLED' : 'DISABLED'));

        if (!$dryRun) {
            $lock = Cache::lock('notifications_prune_lock', 300);
            if (!$lock->get()) {
                $this->warn('Another notifications pruning process is currently running. Skipping execution.');
                return Command::SUCCESS;
            }
        }

        try {
            $result = $notificationService->pruneExpiredNotifications(
                readDays: $readDays,
                dismissedDays: $dismissedDays,
                batchSize: $batchSize,
                dryRun: $dryRun
            );

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
            $this->error('Failed to prune expired tenant notifications: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            if (!$dryRun && isset($lock)) {
                $lock->release();
            }
        }
    }
}

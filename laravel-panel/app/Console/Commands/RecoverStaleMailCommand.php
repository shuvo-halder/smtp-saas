<?php

namespace App\Console\Commands;

use App\Services\MailDeliveryService;
use Illuminate\Console\Command;
use Throwable;

class RecoverStaleMailCommand extends Command
{
    protected $signature = 'mail:recover-stale
                            {--stale-seconds=900 : Threshold in seconds for stale processing detection (min: 60)}
                            {--dry-run : Simulate recovery without modifying records}';

    protected $description = 'Recover interrupted or crashed transactional mail delivery records from processing status.';

    public function handle(MailDeliveryService $deliveryService): int
    {
        $staleSeconds = max(60, (int) $this->option('stale-seconds'));
        $dryRun = (bool) $this->option('dry-run');

        $this->info('--> Scanning for stale processing mail delivery records...');
        $this->line("  Threshold: {$staleSeconds} seconds");
        $this->line("  Dry run:   " . ($dryRun ? 'ENABLED' : 'DISABLED'));

        try {
            $result = $deliveryService->recoverStaleProcessing($staleSeconds, $dryRun);

            $this->line('');
            $this->info('Recovery Results:');
            $this->line("  Stale Records Found: {$result['stale_found']}");
            $this->line("  Recovered to Queue:  {$result['recovered']}");
            $this->line("  Dead-Lettered:       {$result['dead_lettered']}");

            if ($dryRun) {
                $this->comment('Dry run complete. No records were modified.');
            } else {
                $this->info('Stale mail delivery recovery completed successfully.');
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to recover stale mail delivery records: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

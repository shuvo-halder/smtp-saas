<?php

namespace App\Console\Commands;

use App\Services\OutboundUsageSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOutboundUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbound:usage-sync 
                            {--date= : Specific UTC date to synchronize (YYYY-MM-DD)} 
                            {--days=2 : Lookback window in days (default: 2, today & yesterday)} 
                            {--dry-run : Simulate synchronization without modifying database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize Redis tenant outbound recipient usage counters to the durable MariaDB historical ledger.';

    /**
     * Lock TTL in seconds (10 minutes).
     */
    private const LOCK_TTL_SECONDS = 600;

    /**
     * Execute the console command.
     *
     * @param OutboundUsageSyncService $syncService
     * @return int
     */
    public function handle(OutboundUsageSyncService $syncService): int
    {
        $lock = Cache::lock('outbound_usage_sync_lock', self::LOCK_TTL_SECONDS);

        if (!$lock->get()) {
            $this->warn('Another outbound usage synchronization process is already active. Skipping execution.');
            Log::channel('policy')->warning('OutboundUsageSync: Synchronization aborted due to active lock.');
            return Command::SUCCESS;
        }

        try {
            $dateOption = $this->option('date');
            if ($dateOption !== null) {
                if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $dateOption)) {
                    $this->error("Invalid date format: '{$dateOption}'. Expected YYYY-MM-DD.");
                    return Command::FAILURE;
                }

                try {
                    $parsed = Carbon::createFromFormat('Y-m-d', $dateOption, 'UTC');
                    if (!$parsed || $parsed->format('Y-m-d') !== $dateOption) {
                        $this->error("Invalid calendar date: '{$dateOption}'.");
                        return Command::FAILURE;
                    }
                } catch (Throwable) {
                    $this->error("Invalid calendar date: '{$dateOption}'.");
                    return Command::FAILURE;
                }
            }

            $daysOption = (int) $this->option('days');
            if ($daysOption <= 0) {
                $this->error("The --days option must be a positive integer.");
                return Command::FAILURE;
            }

            $dryRun = (bool) $this->option('dry-run');

            $this->info("Starting outbound recipient usage synchronization" . ($dryRun ? " [DRY-RUN]" : "") . "...");

            $result = $syncService->sync($dateOption, $daysOption, $dryRun);

            if (!$result['success']) {
                $errorMessage = $result['error'] ?? 'Unknown synchronization error';
                $this->error("Outbound usage synchronization failed: {$errorMessage}");
                Log::channel('policy')->error("OutboundUsageSync: Command failed: {$errorMessage}");
                return Command::FAILURE;
            }

            $this->info(sprintf(
                "Sync completed successfully. Scanned: %d | Processed: %d | Inserted: %d | Updated: %d | Preserved: %d | Unchanged: %d",
                $result['scanned_keys'],
                $result['processed'],
                $result['rows_inserted'],
                $result['rows_updated'],
                $result['rows_preserved'],
                $result['rows_noop']
            ));

            if ($result['missing_tenants'] > 0 || $result['malformed_keys'] > 0 || $result['invalid_counters'] > 0) {
                $this->warn(sprintf(
                    "Warnings: Missing Tenants: %d | Malformed Keys: %d | Invalid Counters: %d",
                    $result['missing_tenants'],
                    $result['malformed_keys'],
                    $result['invalid_counters']
                ));
            }

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->error("Unexpected error during sync: " . $e->getMessage());
            Log::channel('policy')->error('OutboundUsageSync: Unexpected exception in command handle', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }
}

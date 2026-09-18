<?php

namespace App\Console\Commands;

use App\Services\Abuse\AbuseDetectionService;
use App\Services\Abuse\BounceClassificationService;
use App\Services\Abuse\PostfixLogParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMailLogCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:process-log 
                            {--lines=1000 : Maximum number of lines to parse in this run} 
                            {--dry-run : Simulate log processing without updating counters or cursor} 
                            {--path= : Custom log file path (overrides config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse Postfix mail delivery logs to track delivery statuses, detect bounces, and evaluate abuse thresholds.';

    /**
     * Lock TTL in seconds (5 minutes).
     */
    private const LOCK_TTL_SECONDS = 300;

    public function handle(
        PostfixLogParserService $parserService,
        AbuseDetectionService $detectionService
    ): int {
        if (!Config::get('mail_abuse.enabled', true)) {
            $this->info('SMTP Abuse Detection is disabled in configuration. Skipping.');
            return Command::SUCCESS;
        }

        $logPath = (string) ($this->option('path') ?: Config::get('mail_abuse.log_path', '/var/log/mail.log'));
        $maxLines = (int) ($this->option('lines') ?: Config::get('mail_abuse.parser_batch_lines', 1000));
        $dryRun = (bool) $this->option('dry-run');

        // Check file existence and readability safely
        if (!file_exists($logPath)) {
            $msg = "Postfix log file does not exist: {$logPath}";
            $this->warn($msg);
            Log::channel('abuse')->warning("ProcessMailLog: {$msg}");
            return Command::SUCCESS;
        }

        if (!is_readable($logPath)) {
            $msg = "Postfix log file is not readable by the current process user. Production log access requires infrastructure-level permission configuration and was not changed by STEP 15: {$logPath}";
            $this->warn($msg);
            Log::channel('abuse')->warning("ProcessMailLog: {$msg}");
            return Command::SUCCESS;
        }

        $lock = Cache::lock('mail_process_log_lock', self::LOCK_TTL_SECONDS);
        if (!$lock->get()) {
            $this->warn('Another mail log processing command is currently running. Skipping execution.');
            return Command::SUCCESS;
        }

        try {
            $this->info("Processing Postfix mail log: {$logPath}" . ($dryRun ? " [DRY-RUN]" : "") . "...");

            $parseResult = $parserService->parseFile($logPath, $maxLines, $dryRun);

            if (!empty($parseResult['error'])) {
                $this->warn("Parser warning: {$parseResult['error']}");
                Log::channel('abuse')->warning("ProcessMailLog: {$parseResult['error']}");
                return Command::SUCCESS;
            }

            $events = $parseResult['events'];
            $linesRead = $parseResult['lines_read'];

            $stats = [
                'lines_read' => $linesRead,
                'events_parsed' => count($events),
                'queue_mappings' => 0,
                'evaluations' => 0,
                'hard_bounces' => 0,
                'soft_bounces' => 0,
                'successes' => 0,
                'alerts_triggered' => 0,
            ];

            foreach ($events as $event) {
                $processResult = $detectionService->processEvent($event, $dryRun);

                if ($processResult['action'] === 'QUEUE_CORRELATED') {
                    $stats['queue_mappings']++;
                } elseif ($processResult['action'] === 'EVALUATED') {
                    $stats['evaluations']++;
                    $classification = $processResult['classification'];

                    if ($classification === BounceClassificationService::CLASSIFICATION_HARD_BOUNCE) {
                        $stats['hard_bounces']++;
                    } elseif ($classification === BounceClassificationService::CLASSIFICATION_SOFT_BOUNCE) {
                        $stats['soft_bounces']++;
                    } elseif ($classification === BounceClassificationService::CLASSIFICATION_SUCCESS) {
                        $stats['successes']++;
                    }

                    if (!empty($processResult['alerts'])) {
                        $stats['alerts_triggered'] += count($processResult['alerts']);
                    }
                }
            }

            $summaryMsg = sprintf(
                "Mail log processing completed. Read: %d | Events: %d | Correlated: %d | Evaluated: %d | Hard Bounces: %d | Soft Bounces: %d | Success: %d | Alerts: %d",
                $stats['lines_read'],
                $stats['events_parsed'],
                $stats['queue_mappings'],
                $stats['evaluations'],
                $stats['hard_bounces'],
                $stats['soft_bounces'],
                $stats['successes'],
                $stats['alerts_triggered']
            );

            $this->info($summaryMsg);
            Log::channel('abuse')->info("ProcessMailLog: {$summaryMsg}", $stats);

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->error("Unexpected error during mail log processing: " . $e->getMessage());
            Log::channel('abuse')->error('ProcessMailLog: Unexpected command exception', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }
}

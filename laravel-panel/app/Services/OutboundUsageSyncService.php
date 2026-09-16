<?php

namespace App\Services;

use App\Models\User;
use App\Models\TenantOutboundUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboundUsageSyncService
{
    /**
     * Regex to match and parse tenant daily recipient usage keys.
     * Handles keys with or without connection prefixes:
     * e.g., "outbound:tenant:123:recipients:daily:2026-09-17"
     */
    private const KEY_REGEX = '/(?:^|:)outbound:tenant:([0-9]+):recipients:daily:([0-9]{4}-[0-9]{2}-[0-9]{2})$/';

    /**
     * Maximum integer value allowed for MariaDB signed INT.
     */
    private const MAX_INT_VALUE = 2147483647;

    /**
     * Synchronize Redis tenant outbound recipient usage counters to MariaDB.
     *
     * @param string|null $targetDate Specific UTC date (YYYY-MM-DD) or null for sliding window
     * @param int $lookbackDays Number of days to include in sliding window (default: 2, today & yesterday)
     * @param bool $dryRun When true, simulates sync without writing to database
     * @return array
     */
    public function sync(?string $targetDate = null, int $lookbackDays = 2, bool $dryRun = false): array
    {
        $stats = [
            'scanned_keys' => 0,
            'processed' => 0,
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'rows_preserved' => 0,
            'rows_noop' => 0,
            'missing_tenants' => 0,
            'malformed_keys' => 0,
            'invalid_counters' => 0,
            'skipped_expired' => 0,
            'skipped_outside_window' => 0,
        ];

        Log::channel('policy')->info('OutboundUsageSync: Starting synchronization run', [
            'target_date' => $targetDate,
            'lookback_days' => $lookbackDays,
            'dry_run' => $dryRun,
        ]);

        try {
            // 1. Scan Redis keys
            $scanResult = $this->scanTenantKeys($targetDate, $lookbackDays);
            $stats['scanned_keys'] = $scanResult['scanned_keys'];
            $stats['malformed_keys'] = $scanResult['malformed_keys'];
            $stats['skipped_outside_window'] = $scanResult['skipped_outside_window'];

            $validKeys = $scanResult['valid_keys'];

            // Local cache to minimize User::exists queries across multiple keys for same tenant
            $tenantExistenceCache = [];

            // 2. Process each discovered key
            foreach ($validKeys as $item) {
                $rawKey = $item['key'];
                $tenantId = $item['tenant_id'];
                $usageDate = $item['usage_date'];

                // Verify tenant existence (Tenant Isolation)
                if (!array_key_exists($tenantId, $tenantExistenceCache)) {
                    $tenantExistenceCache[$tenantId] = User::where('id', $tenantId)->exists();
                }

                if (!$tenantExistenceCache[$tenantId]) {
                    $stats['missing_tenants']++;
                    Log::channel('policy')->warning("OutboundUsageSync: Tenant ID {$tenantId} does not exist in users table. Skipping key: {$rawKey}");
                    continue;
                }

                // Fetch current Redis counter value
                $rawVal = Redis::get($rawKey);

                // Handle missing or expired key
                if ($rawVal === null || $rawVal === false) {
                    $stats['skipped_expired']++;
                    Log::channel('policy')->info("OutboundUsageSync: Key missing or expired before GET: {$rawKey}");
                    continue;
                }

                // Validate counter format (strict non-negative integer)
                if (!is_numeric($rawVal) || !preg_match('/^[0-9]+$/', (string) $rawVal)) {
                    $stats['invalid_counters']++;
                    Log::channel('policy')->warning("OutboundUsageSync: Invalid counter value '{$rawVal}' for key: {$rawKey}");
                    continue;
                }

                $recipientCount = (int) $rawVal;
                if ($recipientCount < 0 || $recipientCount > self::MAX_INT_VALUE) {
                    $stats['invalid_counters']++;
                    Log::channel('policy')->warning("OutboundUsageSync: Counter out of bounds ({$recipientCount}) for key: {$rawKey}");
                    continue;
                }

                // 3. Reconcile with MariaDB (Idempotent & Monotonic)
                if (!$dryRun) {
                    DB::transaction(function () use ($tenantId, $usageDate, $recipientCount, &$stats) {
                        $record = TenantOutboundUsage::where('user_id', $tenantId)
                            ->where('usage_date', $usageDate)
                            ->lockForUpdate()
                            ->first();

                        if (!$record) {
                            TenantOutboundUsage::create([
                                'user_id' => $tenantId,
                                'usage_date' => $usageDate,
                                'recipient_count' => $recipientCount,
                            ]);
                            $stats['rows_inserted']++;
                        } elseif ($recipientCount > $record->recipient_count) {
                            $record->update(['recipient_count' => $recipientCount]);
                            $stats['rows_updated']++;
                        } elseif ($recipientCount < $record->recipient_count) {
                            // Never decrement durable historical usage if Redis reset/flushed
                            $stats['rows_preserved']++;
                            Log::channel('policy')->warning("OutboundUsageSync: Redis counter ({$recipientCount}) is lower than existing MariaDB ledger ({$record->recipient_count}) for tenant {$tenantId} on {$usageDate}. Retaining higher ledger value.");
                        } else {
                            $stats['rows_noop']++;
                        }
                    });
                } else {
                    // Dry run: simulate ledger check without writing
                    $record = TenantOutboundUsage::where('user_id', $tenantId)
                        ->where('usage_date', $usageDate)
                        ->first();

                    if (!$record) {
                        $stats['rows_inserted']++;
                    } elseif ($recipientCount > $record->recipient_count) {
                        $stats['rows_updated']++;
                    } elseif ($recipientCount < $record->recipient_count) {
                        $stats['rows_preserved']++;
                    } else {
                        $stats['rows_noop']++;
                    }
                }

                $stats['processed']++;
            }

            Log::channel('policy')->info('OutboundUsageSync: Synchronization run completed successfully', $stats);

            return array_merge(['success' => true, 'dry_run' => $dryRun], $stats);

        } catch (Throwable $e) {
            Log::channel('policy')->error('OutboundUsageSync: Synchronization run failed with exception', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return array_merge([
                'success' => false,
                'error' => $e->getMessage(),
                'dry_run' => $dryRun,
            ], $stats);
        }
    }

    /**
     * Discover tenant daily usage keys using bounded SCAN.
     *
     * @param string|null $targetDate
     * @param int $lookbackDays
     * @param int $chunkSize
     * @return array
     */
    public function scanTenantKeys(?string $targetDate = null, int $lookbackDays = 2, int $chunkSize = 100): array
    {
        $pattern = $targetDate !== null
            ? "outbound:tenant:*:recipients:daily:{$targetDate}"
            : "outbound:tenant:*:recipients:daily:*";

        $cursor = 0;
        $allKeys = [];
        $maxIterations = 10000;
        $iterations = 0;

        do {
            $iterations++;
            if ($iterations > $maxIterations) {
                Log::channel('policy')->warning("OutboundUsageSync: Redis SCAN exceeded iteration limit ({$maxIterations}). Aborting scan loop.");
                break;
            }

            $scanResult = Redis::scan($cursor, [
                'match' => $pattern,
                'count' => $chunkSize,
            ]);

            if ($scanResult === false || $scanResult === null) {
                break;
            }

            $batchKeys = [];
            if (is_array($scanResult)) {
                if (count($scanResult) === 2 && (is_int($scanResult[0]) || is_string($scanResult[0])) && is_array($scanResult[1])) {
                    $cursor = (int) $scanResult[0];
                    $batchKeys = $scanResult[1];
                } else {
                    $batchKeys = $scanResult;
                    $cursor = 0;
                }
            } else {
                break;
            }

            foreach ($batchKeys as $key) {
                $allKeys[] = (string) $key;
            }

        } while ($cursor !== 0 && $cursor !== '0');

        // Deduplicate keys (Redis SCAN can return duplicates across iterations)
        $uniqueKeys = array_values(array_unique($allKeys));
        $scannedCount = count($uniqueKeys);

        $validKeys = [];
        $malformedCount = 0;
        $outsideWindowCount = 0;

        // Calculate sliding window boundaries
        $today = Carbon::now('UTC')->startOfDay();
        $earliestAllowed = $today->copy()->subDays(max(0, $lookbackDays - 1))->startOfDay();
        $latestAllowed = $today->copy()->endOfDay();

        foreach ($uniqueKeys as $key) {
            if (!preg_match(self::KEY_REGEX, $key, $matches)) {
                $malformedCount++;
                Log::channel('policy')->warning("OutboundUsageSync: Key failed regex match: {$key}");
                continue;
            }

            $tenantId = (int) $matches[1];
            $dateStr = $matches[2];

            if ($tenantId <= 0) {
                $malformedCount++;
                Log::channel('policy')->warning("OutboundUsageSync: Key contains non-positive tenant ID: {$key}");
                continue;
            }

            // Validate date is real calendar date
            try {
                $parsedDate = Carbon::createFromFormat('Y-m-d', $dateStr, 'UTC');
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateStr) {
                    $malformedCount++;
                    Log::channel('policy')->warning("OutboundUsageSync: Key contains invalid calendar date: {$key}");
                    continue;
                }
            } catch (Throwable) {
                $malformedCount++;
                Log::channel('policy')->warning("OutboundUsageSync: Key date parsing exception: {$key}");
                continue;
            }

            // Check if specific target date requested
            if ($targetDate !== null) {
                if ($dateStr !== $targetDate) {
                    $outsideWindowCount++;
                    continue;
                }
            } else {
                // Check sliding window
                $keyDate = $parsedDate->copy()->startOfDay();
                if ($keyDate->lt($earliestAllowed) || $keyDate->gt($latestAllowed)) {
                    $outsideWindowCount++;
                    Log::channel('policy')->info("OutboundUsageSync: Key {$key} is outside lookback window ({$earliestAllowed->toDateString()} to {$latestAllowed->toDateString()}).");
                    continue;
                }
            }

            $validKeys[] = [
                'key' => $key,
                'tenant_id' => $tenantId,
                'usage_date' => $dateStr,
            ];
        }

        return [
            'scanned_keys' => $scannedCount,
            'malformed_keys' => $malformedCount,
            'skipped_outside_window' => $outsideWindowCount,
            'valid_keys' => $validKeys,
        ];
    }
}

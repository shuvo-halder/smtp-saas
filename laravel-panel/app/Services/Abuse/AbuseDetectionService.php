<?php

namespace App\Services\Abuse;

use App\Models\TenantOutboundUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class AbuseDetectionService
{
    private int $queueTtl;
    private int $counterTtl;
    private int $minRecipientsForRate;
    private float $hardBounceRateThreshold;
    private int $dailyHardBounceMax;
    private int $consecutiveHardMax;
    private int $cooldownSeconds;

    public function __construct(
        private readonly BounceClassificationService $classifier,
        private readonly AbuseAttributionService $attributionService,
    ) {
        $this->queueTtl = (int) Config::get('mail_abuse.queue_correlation_ttl', 86400);
        $this->counterTtl = (int) Config::get('mail_abuse.counter_ttl', 172800);
        $this->minRecipientsForRate = (int) Config::get('mail_abuse.min_recipients_for_rate_check', 20);
        $this->hardBounceRateThreshold = (float) Config::get('mail_abuse.hard_bounce_rate_threshold', 0.10);
        $this->dailyHardBounceMax = (int) Config::get('mail_abuse.daily_hard_bounce_max', 50);
        $this->consecutiveHardMax = (int) Config::get('mail_abuse.consecutive_hard_bounces_max', 15);
        $this->cooldownSeconds = (int) Config::get('mail_abuse.alert_cooldown_seconds', 86400);
    }

    /**
     * Process a normalized mail event.
     *
     * @param NormalizedMailEvent $event
     * @param bool $dryRun
     * @return array{processed: bool, action: string, classification: ?string, tenant_id: ?int, mailbox_id: ?int, alerts: array}
     */
    public function processEvent(NormalizedMailEvent $event, bool $dryRun = false): array
    {
        $result = [
            'processed' => false,
            'action' => 'NOOP',
            'classification' => null,
            'tenant_id' => null,
            'mailbox_id' => null,
            'alerts' => [],
        ];

        // 1. Handle Queue Manager Sender Event (QMGR_FROM)
        if ($event->isQmgrSenderEvent()) {
            if (!$dryRun) {
                $this->saveQueueSender($event->queueId, $event->sender);
            }
            $result['processed'] = true;
            $result['action'] = 'QUEUE_CORRELATED';
            return $result;
        }

        // 2. Handle Delivery Status Event (DELIVERY_STATUS)
        if ($event->isDeliveryEvent()) {
            $senderEmail = $event->sender;

            // If sender not directly on delivery line, resolve via Queue ID correlation
            if (empty($senderEmail) && !empty($event->queueId)) {
                $senderEmail = $this->getQueueSender($event->queueId);
            }

            // If sender cannot be determined, skip attribution
            if (empty($senderEmail)) {
                $result['action'] = 'UNATTRIBUTED_DELIVERY';
                return $result;
            }

            // 3. Resolve Tenant & Mailbox Attribution
            $attribution = $this->attributionService->attribute($senderEmail);
            if (!$this->attributionService->isValidTenantMailbox($attribution)) {
                $result['action'] = 'NON_TENANT_DELIVERY';
                return $result;
            }

            $tenantId = $attribution['tenant_id'];
            $mailboxId = $attribution['mailbox_id'];
            $result['tenant_id'] = $tenantId;
            $result['mailbox_id'] = $mailboxId;

            // 4. Classify Bounce / Delivery Result
            $classification = $this->classifier->classify($event);
            $result['classification'] = $classification;

            $date = Carbon::now('UTC')->format('Y-m-d');

            if (!$dryRun) {
                // 5. Update Redis Abuse Counters
                $this->updateCounters($tenantId, $mailboxId, $classification, $date);

                // Clean up Queue ID mapping on terminal delivery if queueId known
                if (!empty($event->queueId) && ($classification === BounceClassificationService::CLASSIFICATION_SUCCESS || $classification === BounceClassificationService::CLASSIFICATION_HARD_BOUNCE)) {
                    $this->forgetQueueSender($event->queueId);
                }

                // 6. Evaluate Thresholds & Emit Alerts
                $alerts = $this->evaluateThresholds($tenantId, $mailboxId, $date);
                $result['alerts'] = $alerts;
            }

            $result['processed'] = true;
            $result['action'] = 'EVALUATED';
            return $result;
        }

        return $result;
    }

    /**
     * Store Queue ID -> Sender mapping in Redis.
     */
    public function saveQueueSender(string $queueId, string $sender): void
    {
        try {
            $key = "outbound:abuse:qid:{$queueId}";
            Redis::setex($key, $this->queueTtl, json_encode([
                'sender' => $sender,
                'created_at' => time(),
            ]));
        } catch (Throwable $e) {
            Log::channel('abuse')->warning('AbuseDetection: Failed to save Queue ID correlation', [
                'queue_id' => $queueId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retrieve envelope sender from Queue ID mapping.
     */
    public function getQueueSender(string $queueId): ?string
    {
        try {
            $key = "outbound:abuse:qid:{$queueId}";
            $raw = Redis::get($key);
            if (!empty($raw)) {
                $decoded = json_decode((string) $raw, true);
                return $decoded['sender'] ?? null;
            }
        } catch (Throwable $e) {
            Log::channel('abuse')->warning('AbuseDetection: Failed to get Queue ID correlation', [
                'queue_id' => $queueId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Remove Queue ID mapping after terminal delivery.
     */
    public function forgetQueueSender(string $queueId): void
    {
        try {
            $key = "outbound:abuse:qid:{$queueId}";
            Redis::del($key);
        } catch (Throwable) {
            // Non-fatal cleanup
        }
    }

    /**
     * Update atomic Redis counters according to classification.
     */
    public function updateCounters(int $tenantId, int $mailboxId, string $classification, string $date): void
    {
        try {
            if ($classification === BounceClassificationService::CLASSIFICATION_HARD_BOUNCE) {
                // Increment Tenant Daily Hard Bounce Counter
                $tenantKey = "outbound:abuse:tenant:{$tenantId}:bounces:hard:daily:{$date}";
                $newTenantVal = Redis::incr($tenantKey);
                if ($newTenantVal === 1) {
                    Redis::expire($tenantKey, $this->counterTtl);
                }

                // Increment Mailbox Daily Hard Bounce Counter
                $mbKey = "outbound:abuse:mailbox:{$mailboxId}:bounces:hard:daily:{$date}";
                $newMbVal = Redis::incr($mbKey);
                if ($newMbVal === 1) {
                    Redis::expire($mbKey, $this->counterTtl);
                }

                // Increment Mailbox Consecutive Hard Bounces
                $consecutiveKey = "outbound:abuse:mailbox:{$mailboxId}:consecutive_hard";
                $newConsecutive = Redis::incr($consecutiveKey);
                if ($newConsecutive === 1) {
                    Redis::expire($consecutiveKey, $this->counterTtl);
                }
            } elseif ($classification === BounceClassificationService::CLASSIFICATION_SOFT_BOUNCE) {
                // Increment Tenant Daily Soft Bounce Counter
                $tenantKey = "outbound:abuse:tenant:{$tenantId}:bounces:soft:daily:{$date}";
                $newTenantVal = Redis::incr($tenantKey);
                if ($newTenantVal === 1) {
                    Redis::expire($tenantKey, $this->counterTtl);
                }

                // Increment Mailbox Daily Soft Bounce Counter
                $mbKey = "outbound:abuse:mailbox:{$mailboxId}:bounces:soft:daily:{$date}";
                $newMbVal = Redis::incr($mbKey);
                if ($newMbVal === 1) {
                    Redis::expire($mbKey, $this->counterTtl);
                }
            } elseif ($classification === BounceClassificationService::CLASSIFICATION_SUCCESS) {
                // Successful delivery resets consecutive hard bounce counter for this mailbox
                $consecutiveKey = "outbound:abuse:mailbox:{$mailboxId}:consecutive_hard";
                Redis::del($consecutiveKey);
            }
        } catch (Throwable $e) {
            Log::channel('abuse')->error('AbuseDetection: Redis failure while updating abuse counters', [
                'tenant_id' => $tenantId,
                'mailbox_id' => $mailboxId,
                'classification' => $classification,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Evaluate abuse thresholds and emit structured alert logs.
     *
     * @param int $tenantId
     * @param int $mailboxId
     * @param string $date
     * @return array
     */
    public function evaluateThresholds(int $tenantId, int $mailboxId, string $date): array
    {
        $triggeredAlerts = [];

        try {
            // 1. Fetch current daily hard bounces
            $tenantHardKey = "outbound:abuse:tenant:{$tenantId}:bounces:hard:daily:{$date}";
            $dailyHardBounces = (int) (Redis::get($tenantHardKey) ?? 0);

            // 2. Fetch consecutive hard bounces for mailbox
            $consecutiveKey = "outbound:abuse:mailbox:{$mailboxId}:consecutive_hard";
            $consecutiveHardBounces = (int) (Redis::get($consecutiveKey) ?? 0);

            // 3. Fetch attempted recipients (from Step 14 historical ledger or Step 12 Redis key)
            $attemptedRecipients = $this->getAttemptedRecipients($tenantId, $date);

            // Check A: Daily Absolute Hard Bounce Limit
            if ($dailyHardBounces >= $this->dailyHardBounceMax) {
                $alertType = 'DAILY_HARD_BOUNCE_LIMIT_EXCEEDED';
                if ($this->acquireAlertCooldown($tenantId, $alertType, $date)) {
                    $this->emitAlert($alertType, [
                        'tenant_id' => $tenantId,
                        'mailbox_id' => $mailboxId,
                        'date' => $date,
                        'daily_hard_bounces' => $dailyHardBounces,
                        'threshold' => $this->dailyHardBounceMax,
                        'attempted_recipients' => $attemptedRecipients,
                    ]);
                    $triggeredAlerts[] = $alertType;
                }
            }

            // Check B: Consecutive Hard Bounces on Mailbox
            if ($consecutiveHardBounces >= $this->consecutiveHardMax) {
                $alertType = 'CONSECUTIVE_HARD_BOUNCES_EXCEEDED';
                if ($this->acquireAlertCooldown($tenantId, $alertType, $date)) {
                    $this->emitAlert($alertType, [
                        'tenant_id' => $tenantId,
                        'mailbox_id' => $mailboxId,
                        'date' => $date,
                        'consecutive_hard_bounces' => $consecutiveHardBounces,
                        'threshold' => $this->consecutiveHardMax,
                    ]);
                    $triggeredAlerts[] = $alertType;
                }
            }

            // Check C: Hard Bounce Rate (only if minimum sample size satisfied)
            if ($attemptedRecipients >= $this->minRecipientsForRate) {
                $bounceRate = $dailyHardBounces / max(1, $attemptedRecipients);
                if ($bounceRate >= $this->hardBounceRateThreshold) {
                    $alertType = 'HIGH_HARD_BOUNCE_RATE';
                    if ($this->acquireAlertCooldown($tenantId, $alertType, $date)) {
                        $this->emitAlert($alertType, [
                            'tenant_id' => $tenantId,
                            'mailbox_id' => $mailboxId,
                            'date' => $date,
                            'bounce_rate' => round($bounceRate * 100, 2) . '%',
                            'rate_value' => $bounceRate,
                            'threshold' => round($this->hardBounceRateThreshold * 100, 2) . '%',
                            'daily_hard_bounces' => $dailyHardBounces,
                            'attempted_recipients' => $attemptedRecipients,
                        ]);
                        $triggeredAlerts[] = $alertType;
                    }
                }
            }

        } catch (Throwable $e) {
            Log::channel('abuse')->error('AbuseDetection: Failed to evaluate abuse thresholds', [
                'tenant_id' => $tenantId,
                'mailbox_id' => $mailboxId,
                'error' => $e->getMessage(),
            ]);
        }

        return $triggeredAlerts;
    }

    /**
     * Retrieve total attempted recipient submissions for rate calculations.
     * Reads from Step 14 ledger (MariaDB) or Step 12 Redis key without modifying either.
     */
    public function getAttemptedRecipients(int $tenantId, string $date): int
    {
        try {
            // First check Step 14 MariaDB durable ledger
            $dbCount = TenantOutboundUsage::where('user_id', $tenantId)
                ->where('usage_date', $date)
                ->value('recipient_count');

            if ($dbCount !== null) {
                return (int) $dbCount;
            }

            // If not yet synced today, check Step 12 Redis runtime key (read-only)
            $step12Key = "outbound:tenant:{$tenantId}:recipients:daily:{$date}";
            $redisCount = Redis::get($step12Key);
            if ($redisCount !== null && is_numeric($redisCount)) {
                return (int) $redisCount;
            }
        } catch (Throwable) {
            // Fail safely
        }

        return 0;
    }

    /**
     * Race-safe alert cooldown acquisition using Redis SET NX.
     */
    private function acquireAlertCooldown(int $tenantId, string $alertType, string $date): bool
    {
        try {
            $key = "outbound:abuse:alert:cooldown:{$tenantId}:{$alertType}:{$date}";
            // SET key 1 EX cooldown NX (sets only if not exists)
            $result = Redis::set($key, '1', 'EX', $this->cooldownSeconds, 'NX');
            return $result === true || $result === 'OK';
        } catch (Throwable) {
            // If Redis fails, return true so alert is logged rather than silently dropped
            return true;
        }
    }

    /**
     * Emit structured operational alert.
     */
    private function emitAlert(string $alertType, array $context): void
    {
        Log::channel('abuse')->warning("[ABUSE-ALERT] {$alertType}", array_merge([
            'alert_type' => $alertType,
            'timestamp' => now()->toIso8601String(),
        ], $context));
    }
}

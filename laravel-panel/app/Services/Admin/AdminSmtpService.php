<?php

namespace App\Services\Admin;

use App\Http\Resources\AdminSmtpMailboxResource;
use App\Http\Resources\AdminSmtpTenantResource;
use App\Models\Mailbox;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class AdminSmtpService
{
    /**
     * Checks whether Redis connection is live.
     */
    public function isRedisAvailable(): bool
    {
        try {
            Redis::ping();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Retrieve cluster-wide SMTP deliverability overview.
     */
    public function getOverview(): array
    {
        $today = Carbon::now('UTC')->format('Y-m-d');
        $queueSize = $this->getMailQueueSize();
        $totalTenants = User::count();
        $totalMailboxes = Mailbox::count();

        if (!$this->isRedisAvailable()) {
            return [
                'telemetry_available'         => false,
                'cluster_recipients_today'    => null,
                'cluster_hard_bounces_today'   => null,
                'cluster_soft_bounces_today'   => null,
                'cluster_bounce_rate'         => null,
                'mail_queue_size'             => $queueSize,
                'total_tenants'               => $totalTenants,
                'total_mailboxes'             => $totalMailboxes,
                'active_abuse_warnings_count' => null,
                'checked_at'                  => Carbon::now('UTC')->toIso8601String(),
            ];
        }

        try {
            $tenantIds = User::pluck('id')->toArray();
            $totalRecipients = 0;
            $totalHard = 0;
            $totalSoft = 0;

            if (!empty($tenantIds)) {
                // Chunk to prevent overly large single MGET payloads
                $chunks = array_chunk($tenantIds, 100);
                foreach ($chunks as $chunk) {
                    $keys = [];
                    foreach ($chunk as $id) {
                        $keys[] = "outbound:tenant:{$id}:recipients:daily:{$today}";
                        $keys[] = "outbound:abuse:tenant:{$id}:bounces:hard:daily:{$today}";
                        $keys[] = "outbound:abuse:tenant:{$id}:bounces:soft:daily:{$today}";
                    }

                    $values = Redis::mget($keys);
                    $valCount = count($values);
                    for ($i = 0; $i < $valCount; $i += 3) {
                        $recipients = isset($values[$i]) && is_numeric($values[$i]) ? (int) $values[$i] : 0;
                        $hard       = isset($values[$i + 1]) && is_numeric($values[$i + 1]) ? (int) $values[$i + 1] : 0;
                        $soft       = isset($values[$i + 2]) && is_numeric($values[$i + 2]) ? (int) $values[$i + 2] : 0;

                        $totalRecipients += $recipients;
                        $totalHard       += $hard;
                        $totalSoft       += $soft;
                    }
                }
            }

            // Denominator is accepted outbound attempts (Step 13/14), not confirmed remote deliveries
            $bounceRate = $totalRecipients > 0 ? round($totalHard / $totalRecipients, 4) : null;
            $warnings = $this->getAbuseWarnings();

            return [
                'telemetry_available'         => true,
                'cluster_recipients_today'    => $totalRecipients,
                'cluster_hard_bounces_today'   => $totalHard,
                'cluster_soft_bounces_today'   => $totalSoft,
                'cluster_bounce_rate'         => $bounceRate,
                'mail_queue_size'             => $queueSize,
                'total_tenants'               => $totalTenants,
                'total_mailboxes'             => $totalMailboxes,
                'active_abuse_warnings_count' => count($warnings['warnings'] ?? []),
                'checked_at'                  => Carbon::now('UTC')->toIso8601String(),
            ];
        } catch (Throwable $e) {
            Log::channel('admin_smtp')->error('Failed to compute SMTP overview metrics: ' . $e->getMessage());
            return [
                'telemetry_available'         => false,
                'cluster_recipients_today'    => null,
                'cluster_hard_bounces_today'   => null,
                'cluster_soft_bounces_today'   => null,
                'cluster_bounce_rate'         => null,
                'mail_queue_size'             => $queueSize,
                'total_tenants'               => $totalTenants,
                'total_mailboxes'             => $totalMailboxes,
                'active_abuse_warnings_count' => null,
                'checked_at'                  => Carbon::now('UTC')->toIso8601String(),
            ];
        }
    }

    /**
     * Inspect Postfix queue size safely using established shell pattern.
     */
    public function getMailQueueSize(): ?int
    {
        try {
            $output = shell_exec('postqueue -p | tail -n 1');
            if ($output && preg_match('/-- (\d+) Kbytes in (\d+) Requests/', $output, $matches)) {
                return (int) $matches[2];
            }
            if ($output && str_contains($output, 'Mail queue is empty')) {
                return 0;
            }
        } catch (Throwable) {
            // Fail safely
        }
        return 0;
    }

    /**
     * Retrieve paginated tenants merged with live Redis quota and bounce telemetry.
     */
    public function getTenants(Request $request)
    {
        $today = Carbon::now('UTC')->format('Y-m-d');
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);

        $query = User::query()->with(['plan'])->withCount(['domains']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->latest()->paginate($perPage);
        $redisAvailable = $this->isRedisAvailable();

        $rateThreshold = config('mail_abuse.hard_bounce_rate_threshold', 0.10);
        $minRecipients = config('mail_abuse.min_recipients_for_rate_check', 20);
        $dailyHardMax  = config('mail_abuse.daily_hard_bounce_max', 50);

        if (!$redisAvailable) {
            foreach ($paginator as $tenant) {
                $tenant->telemetry_available = false;
                $tenant->today_recipients    = null;
                $tenant->today_hard_bounces  = null;
                $tenant->today_soft_bounces  = null;
                $tenant->bounce_rate         = null;
                $tenant->abuse_status        = 'unknown';
            }
            return AdminSmtpTenantResource::collection($paginator);
        }

        $tenantIds = $paginator->pluck('id')->toArray();
        if (empty($tenantIds)) {
            return AdminSmtpTenantResource::collection($paginator);
        }

        $keys = [];
        foreach ($tenantIds as $id) {
            $keys[] = "outbound:tenant:{$id}:recipients:daily:{$today}";
            $keys[] = "outbound:abuse:tenant:{$id}:bounces:hard:daily:{$today}";
            $keys[] = "outbound:abuse:tenant:{$id}:bounces:soft:daily:{$today}";
        }

        try {
            $values = Redis::mget($keys);
            $idx = 0;

            foreach ($paginator as $tenant) {
                $recipients = isset($values[$idx]) && is_numeric($values[$idx]) ? (int) $values[$idx] : 0;
                $hard       = isset($values[$idx + 1]) && is_numeric($values[$idx + 1]) ? (int) $values[$idx + 1] : 0;
                $soft       = isset($values[$idx + 2]) && is_numeric($values[$idx + 2]) ? (int) $values[$idx + 2] : 0;
                $idx += 3;

                $rate = $recipients > 0 ? round($hard / $recipients, 4) : null;

                $abuseStatus = 'healthy';
                if ($hard >= $dailyHardMax || ($recipients >= $minRecipients && $rate !== null && $rate >= $rateThreshold)) {
                    $abuseStatus = 'critical';
                } elseif ($hard >= 20 || ($recipients >= $minRecipients && $rate !== null && $rate >= ($rateThreshold / 2))) {
                    $abuseStatus = 'warning';
                }

                $tenant->telemetry_available = true;
                $tenant->today_recipients    = $recipients;
                $tenant->today_hard_bounces  = $hard;
                $tenant->today_soft_bounces  = $soft;
                $tenant->bounce_rate         = $rate;
                $tenant->abuse_status        = $abuseStatus;
            }
        } catch (Throwable $e) {
            Log::channel('admin_smtp')->warning('Redis mget failed in getTenants: ' . $e->getMessage());
            foreach ($paginator as $tenant) {
                $tenant->telemetry_available = false;
                $tenant->today_recipients    = null;
                $tenant->today_hard_bounces  = null;
                $tenant->today_soft_bounces  = null;
                $tenant->bounce_rate         = null;
                $tenant->abuse_status        = 'unknown';
            }
        }

        return AdminSmtpTenantResource::collection($paginator);
    }

    /**
     * Retrieve detailed SMTP profile for a single tenant including 30-day historical usage.
     */
    public function getTenantDetail(User $user): array
    {
        $user->load(['plan', 'domains' => fn($q) => $q->withCount('mailboxes')]);
        $today = Carbon::now('UTC')->format('Y-m-d');
        $redisAvailable = $this->isRedisAvailable();

        // 30-day historical usage strictly from MariaDB tenant_outbound_usage
        $thirtyDaysAgo = Carbon::now('UTC')->subDays(30)->format('Y-m-d');
        $history = TenantOutboundUsage::where('user_id', $user->id)
            ->where('usage_date', '>=', $thirtyDaysAgo)
            ->orderBy('usage_date', 'asc')
            ->get(['usage_date', 'recipient_count'])
            ->toArray();

        $todayRecipients = null;
        $todayHard = null;
        $todaySoft = null;
        $bounceRate = null;
        $abuseStatus = 'unknown';
        $activeAlerts = [];

        if ($redisAvailable) {
            try {
                $keys = [
                    "outbound:tenant:{$user->id}:recipients:daily:{$today}",
                    "outbound:abuse:tenant:{$user->id}:bounces:hard:daily:{$today}",
                    "outbound:abuse:tenant:{$user->id}:bounces:soft:daily:{$today}",
                    "outbound:abuse:alert:cooldown:{$user->id}:HIGH_HARD_BOUNCE_RATE:{$today}",
                    "outbound:abuse:alert:cooldown:{$user->id}:EXCESSIVE_DAILY_HARD_BOUNCES:{$today}",
                ];

                $vals = Redis::mget($keys);
                $todayRecipients = isset($vals[0]) && is_numeric($vals[0]) ? (int) $vals[0] : 0;
                $todayHard       = isset($vals[1]) && is_numeric($vals[1]) ? (int) $vals[1] : 0;
                $todaySoft       = isset($vals[2]) && is_numeric($vals[2]) ? (int) $vals[2] : 0;

                if (!empty($vals[3])) {
                    $activeAlerts[] = 'HIGH_HARD_BOUNCE_RATE';
                }
                if (!empty($vals[4])) {
                    $activeAlerts[] = 'EXCESSIVE_DAILY_HARD_BOUNCES';
                }

                $bounceRate = $todayRecipients > 0 ? round($todayHard / $todayRecipients, 4) : null;

                $rateThreshold = config('mail_abuse.hard_bounce_rate_threshold', 0.10);
                $minRecipients = config('mail_abuse.min_recipients_for_rate_check', 20);
                $dailyHardMax  = config('mail_abuse.daily_hard_bounce_max', 50);

                if ($todayHard >= $dailyHardMax || ($todayRecipients >= $minRecipients && $bounceRate !== null && $bounceRate >= $rateThreshold)) {
                    $abuseStatus = 'critical';
                } elseif ($todayHard >= 20 || ($todayRecipients >= $minRecipients && $bounceRate !== null && $bounceRate >= ($rateThreshold / 2))) {
                    $abuseStatus = 'warning';
                } else {
                    $abuseStatus = 'healthy';
                }
            } catch (Throwable $e) {
                Log::channel('admin_smtp')->warning("Redis lookup failed for tenant detail {$user->id}: " . $e->getMessage());
                $redisAvailable = false;
            }
        }

        return [
            'tenant' => [
                'id'                     => $user->id,
                'name'                   => $user->name,
                'email'                  => $user->email,
                'status'                 => $user->status,
                'is_subscription_active' => $user->isSubscriptionActive(),
                'plan_name'              => $user->plan?->name ?? 'No Plan',
                'daily_quota'            => $user->plan?->daily_outbound_recipients ?? -1,
                'mailbox_daily_quota'    => $user->plan?->mailbox_daily_outbound_recipients ?? -1,
                'plan_expires_at'        => $user->plan_expires_at,
            ],
            'telemetry' => [
                'available'    => $redisAvailable,
                'recipients'   => $todayRecipients,
                'hard_bounces' => $todayHard,
                'soft_bounces' => $todaySoft,
                'bounce_rate'  => $bounceRate,
                'abuse_status' => $abuseStatus,
                'alerts'       => $activeAlerts,
            ],
            'domains' => $user->domains->map(function ($d) {
                return [
                    'id'              => $d->id,
                    'domain_name'     => $d->domain_name,
                    'status'          => $d->status,
                    'mx_verified'     => (bool) $d->mx_verified,
                    'mailboxes_count' => $d->mailboxes_count,
                ];
            }),
            'historical_usage' => $history,
        ];
    }

    /**
     * Retrieve paginated mailboxes merged with Redis quota, bounce, and streak telemetry.
     */
    public function getMailboxes(Request $request)
    {
        $today = Carbon::now('UTC')->format('Y-m-d');
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);

        $query = Mailbox::query()->with(['domain.user.plan']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('local_part', 'like', "%{$search}%");
            });
        }

        if ($request->filled('domain_id')) {
            $query->where('domain_id', $request->input('domain_id'));
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $paginator = $query->latest()->paginate($perPage);
        $redisAvailable = $this->isRedisAvailable();

        if (!$redisAvailable) {
            foreach ($paginator as $mb) {
                $mb->telemetry_available        = false;
                $mb->today_recipients           = null;
                $mb->today_hard_bounces         = null;
                $mb->today_soft_bounces         = null;
                $mb->consecutive_hard_bounces   = null;
            }
            return AdminSmtpMailboxResource::collection($paginator);
        }

        $mbIds = $paginator->pluck('id')->toArray();
        if (empty($mbIds)) {
            return AdminSmtpMailboxResource::collection($paginator);
        }

        $keys = [];
        foreach ($mbIds as $id) {
            $keys[] = "outbound:mailbox:{$id}:recipients:daily:{$today}";
            $keys[] = "outbound:abuse:mailbox:{$id}:bounces:hard:daily:{$today}";
            $keys[] = "outbound:abuse:mailbox:{$id}:bounces:soft:daily:{$today}";
            $keys[] = "outbound:abuse:mailbox:{$id}:consecutive_hard";
        }

        try {
            $values = Redis::mget($keys);
            $idx = 0;

            foreach ($paginator as $mb) {
                $recipients  = isset($values[$idx]) && is_numeric($values[$idx]) ? (int) $values[$idx] : 0;
                $hard        = isset($values[$idx + 1]) && is_numeric($values[$idx + 1]) ? (int) $values[$idx + 1] : 0;
                $soft        = isset($values[$idx + 2]) && is_numeric($values[$idx + 2]) ? (int) $values[$idx + 2] : 0;
                $consecutive = isset($values[$idx + 3]) && is_numeric($values[$idx + 3]) ? (int) $values[$idx + 3] : 0;
                $idx += 4;

                $mb->telemetry_available        = true;
                $mb->today_recipients           = $recipients;
                $mb->today_hard_bounces         = $hard;
                $mb->today_soft_bounces         = $soft;
                $mb->consecutive_hard_bounces   = $consecutive;
            }
        } catch (Throwable $e) {
            Log::channel('admin_smtp')->warning('Redis mget failed in getMailboxes: ' . $e->getMessage());
            foreach ($paginator as $mb) {
                $mb->telemetry_available        = false;
                $mb->today_recipients           = null;
                $mb->today_hard_bounces         = null;
                $mb->today_soft_bounces         = null;
                $mb->consecutive_hard_bounces   = null;
            }
        }

        return AdminSmtpMailboxResource::collection($paginator);
    }

    /**
     * Retrieve active abuse warnings and threshold breaches for today.
     */
    public function getAbuseWarnings(): array
    {
        $today = Carbon::now('UTC')->format('Y-m-d');
        if (!$this->isRedisAvailable()) {
            return [
                'telemetry_available' => false,
                'warnings'            => [],
            ];
        }

        $rateThreshold      = config('mail_abuse.hard_bounce_rate_threshold', 0.10);
        $minRecipients      = config('mail_abuse.min_recipients_for_rate_check', 20);
        $dailyHardMax       = config('mail_abuse.daily_hard_bounce_max', 50);
        $consecutiveHardMax = config('mail_abuse.consecutive_hard_bounces_max', 15);

        $warnings = [];

        try {
            // Check active tenant breaches
            $tenants = User::where('status', 'active')->get(['id', 'name', 'email']);
            if ($tenants->isNotEmpty()) {
                $tenantKeys = [];
                foreach ($tenants as $t) {
                    $tenantKeys[] = "outbound:tenant:{$t->id}:recipients:daily:{$today}";
                    $tenantKeys[] = "outbound:abuse:tenant:{$t->id}:bounces:hard:daily:{$today}";
                }

                $tVals = Redis::mget($tenantKeys);
                $idx = 0;
                foreach ($tenants as $t) {
                    $recipients = isset($tVals[$idx]) && is_numeric($tVals[$idx]) ? (int) $tVals[$idx] : 0;
                    $hard       = isset($tVals[$idx + 1]) && is_numeric($tVals[$idx + 1]) ? (int) $tVals[$idx + 1] : 0;
                    $idx += 2;

                    if ($hard >= $dailyHardMax) {
                        $warnings[] = [
                            'entity_type'   => 'tenant',
                            'entity_id'     => $t->id,
                            'identifier'    => $t->email,
                            'name'          => $t->name,
                            'alert_type'    => 'EXCESSIVE_DAILY_HARD_BOUNCES',
                            'current_value' => $hard,
                            'threshold'     => $dailyHardMax,
                            'message'       => "Tenant exceeded daily hard bounce limit ({$hard} / {$dailyHardMax})",
                        ];
                    }

                    if ($recipients >= $minRecipients) {
                        $rate = round($hard / $recipients, 4);
                        if ($rate >= $rateThreshold) {
                            $warnings[] = [
                                'entity_type'   => 'tenant',
                                'entity_id'     => $t->id,
                                'identifier'    => $t->email,
                                'name'          => $t->name,
                                'alert_type'    => 'HIGH_HARD_BOUNCE_RATE',
                                'current_value' => round($rate * 100, 2) . '%',
                                'threshold'     => round($rateThreshold * 100, 2) . '%',
                                'message'       => "Tenant hard bounce rate ({$rate}) exceeded threshold on {$recipients} attempts",
                            ];
                        }
                    }
                }
            }

            // Check active mailbox consecutive streaks in bounded batches of at most 100 keys
            $mailboxes = Mailbox::where('is_active', true)->with('domain.user')->get(['id', 'email', 'domain_id']);
            if ($mailboxes->isNotEmpty()) {
                $mailboxChunks = $mailboxes->chunk(100);

                foreach ($mailboxChunks as $chunk) {
                    $mbKeys = [];
                    foreach ($chunk as $m) {
                        $mbKeys[] = "outbound:abuse:mailbox:{$m->id}:consecutive_hard";
                    }

                    $mVals = Redis::mget($mbKeys);
                    $i = 0;
                    foreach ($chunk as $m) {
                        $consecutive = isset($mVals[$i]) && is_numeric($mVals[$i]) ? (int) $mVals[$i] : 0;
                        $i++;

                        if ($consecutive >= $consecutiveHardMax) {
                            $warnings[] = [
                                'entity_type'   => 'mailbox',
                                'entity_id'     => $m->id,
                                'identifier'    => $m->email,
                                'name'          => $m->domain?->user?->name ?? 'Unknown',
                                'alert_type'    => 'CONSECUTIVE_HARD_BOUNCES',
                                'current_value' => $consecutive,
                                'threshold'     => $consecutiveHardMax,
                                'message'       => "Mailbox exceeded consecutive hard bounce threshold ({$consecutive} / {$consecutiveHardMax})",
                            ];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::channel('admin_smtp')->error('Failed to evaluate abuse warnings: ' . $e->getMessage());
            return [
                'telemetry_available' => false,
                'warnings'            => [],
            ];
        }

        return [
            'telemetry_available' => true,
            'warnings'            => $warnings,
        ];
    }

    /**
     * Administrative mailbox state toggle with strict parent invariant verification.
     */
    public function toggleMailbox(Mailbox $mailbox, ?string $reason = null, ?User $actor = null): Mailbox
    {
        return DB::transaction(function () use ($mailbox, $reason, $actor) {
            $currentlyActive = (bool) $mailbox->is_active;

            if ($currentlyActive) {
                // Disabling is ALWAYS allowed as immediate security/abuse containment
                $before = ['is_active' => true];
                $mailbox->update(['is_active' => false]);
                $after = ['is_active' => false];

                $this->logMutation('mailbox_toggle', 'Mailbox', $mailbox->id, $before, $after, $actor, $reason);
                return $mailbox->fresh();
            }

            // Enabling requires invariant check: Domain active AND Tenant active AND Subscription valid
            $domain = $mailbox->domain;
            if (!$domain || $domain->status !== 'active') {
                throw new \InvalidArgumentException('Cannot enable mailbox belonging to an inactive or suspended domain.');
            }

            $tenant = $domain->user;
            if (!$tenant || $tenant->status !== 'active' || !$tenant->isSubscriptionActive()) {
                throw new \InvalidArgumentException('Cannot enable mailbox belonging to an inactive, suspended, or expired tenant.');
            }

            $before = ['is_active' => false];
            $mailbox->update(['is_active' => true]);
            $after = ['is_active' => true];

            $this->logMutation('mailbox_toggle', 'Mailbox', $mailbox->id, $before, $after, $actor, $reason);
            return $mailbox->fresh();
        });
    }

    /**
     * Reset consecutive hard bounce counter for a mailbox in Redis.
     */
    public function resetConsecutiveBounces(Mailbox $mailbox, ?string $reason = null, ?User $actor = null): array
    {
        $key = "outbound:abuse:mailbox:{$mailbox->id}:consecutive_hard";
        $beforeVal = 0;

        try {
            $existing = Redis::get($key);
            $beforeVal = is_numeric($existing) ? (int) $existing : 0;
            Redis::del($key);
        } catch (Throwable $e) {
            Log::channel('admin_smtp')->error("Failed to delete Redis consecutive key {$key}: " . $e->getMessage());
            throw new \RuntimeException('Redis connection failure while resetting consecutive bounces.');
        }

        $this->logMutation('mailbox_bounce_reset', 'Mailbox', $mailbox->id, [
            'consecutive_hard_bounces' => $beforeVal,
        ], [
            'consecutive_hard_bounces' => 0,
        ], $actor, $reason);

        return [
            'mailbox_id'               => $mailbox->id,
            'consecutive_hard_bounces' => 0,
            'message'                  => 'Consecutive hard bounce counter successfully reset.',
        ];
    }

    /**
     * Reset mailbox password using canonical SHA512-CRYPT scheme.
     */
    public function resetPassword(Mailbox $mailbox, ?string $plainPassword = null, ?string $reason = null, ?User $actor = null): array
    {
        if (empty($plainPassword)) {
            $plainPassword = Str::random(16);
        }

        $salt = Str::random(16);
        $hashed = crypt($plainPassword, '$6$' . $salt . '$');

        DB::transaction(function () use ($mailbox, $hashed, $actor, $reason) {
            $mailbox->update([
                'password' => $hashed,
            ]);

            // Structured logging: NEVER log plaintext or hash!
            $this->logMutation('mailbox_password_reset', 'Mailbox', $mailbox->id, [
                'password_hash' => '[REDACTED]',
            ], [
                'password_hash' => '[UPDATED_SHA512_CRYPT]',
            ], $actor, $reason);
        });

        return [
            'mailbox_id'   => $mailbox->id,
            'email'        => $mailbox->email,
            'new_password' => $plainPassword,
            'message'      => 'Mailbox password successfully reset. Securely communicate this to the user.',
        ];
    }

    /**
     * Write structured operational record to dedicated file channel.
     */
    protected function logMutation(
        string $action,
        string $entityType,
        int $entityId,
        array $before,
        array $after,
        ?User $actor,
        ?string $reason = null
    ): void {
        Log::channel('admin_smtp')->info('Admin SMTP Mutation', [
            'timestamp'   => Carbon::now('UTC')->toIso8601String(),
            'action'      => $action,
            'actor_id'    => $actor?->id,
            'actor_email' => $actor?->email,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'before'      => $before,
            'after'       => $after,
            'reason'      => $reason,
            'ip_address'  => request()->ip(),
            'user_agent'  => request()->userAgent(),
        ]);
    }
}

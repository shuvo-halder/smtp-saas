<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\MailDeliveryService;
use App\Services\TenantNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendSubscriptionExpiryRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:send-expiry-reminders 
                            {--days=7,3,1 : Comma-separated list of days before expiration to send reminders}
                            {--dry-run : Simulate sending without dispatching jobs or mutating notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatches subscription expiry reminder notifications and transactional emails for upcoming expirations.';

    /**
     * Execute the console command.
     */
    public function handle(MailDeliveryService $mailService, TenantNotificationService $notificationService): int
    {
        $daysOption = (string) $this->option('days');
        $targetDays = array_map('intval', array_filter(explode(',', $daysOption)));
        if (empty($targetDays)) {
            $targetDays = [7, 3, 1];
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info('Scanning for active subscriptions expiring in ' . implode(', ', $targetDays) . ' days (dry-run: ' . ($dryRun ? 'true' : 'false') . ')...');

        $activeTenants = User::where('status', 'active')
            ->where('is_admin', false)
            ->whereDoesntHave('roles', function ($q) {
                $q->whereIn('name', RoleEnum::adminRoles());
            })
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '>', now())
            ->with('plan')
            ->get();

        if ($activeTenants->isEmpty()) {
            $this->info('No active tenants with scheduled expiration found.');
            return 0;
        }

        $remindersCount = 0;

        foreach ($activeTenants as $tenant) {
            $daysRemaining = (int) ceil(now()->diffInDays($tenant->plan_expires_at, false));

            if (!in_array($daysRemaining, $targetDays, true)) {
                continue;
            }

            $dateString = $tenant->plan_expires_at->toDateString();
            $expiryFormatted = $tenant->plan_expires_at->format('M d, Y');
            $planName = $tenant->plan?->name ?? 'EmailSaaS Subscription';
            $renewUrl = config('app.frontend_url', config('app.url')) . '/billing';

            $idempotencyKey = "subscription_expiry_reminder:user_{$tenant->id}:{$daysRemaining}d:{$dateString}";

            if ($dryRun) {
                $this->line("[DRY-RUN] Would dispatch reminder for Tenant #{$tenant->id} ({$tenant->email}): {$daysRemaining} days remaining, expires {$expiryFormatted}");
                $remindersCount++;
                continue;
            }

            try {
                // 1. Transactional Email Enqueue
                $mailService->enqueue(
                    tenantId: $tenant->id,
                    recipientEmail: $tenant->email,
                    messageType: 'subscription_expiry_reminder',
                    subject: "Action Required: Your EmailSaaS Subscription Expires in {$daysRemaining} Days",
                    payload: [
                        'days_remaining' => $daysRemaining,
                        'expires_at'     => $expiryFormatted,
                        'plan_name'      => $planName,
                        'renew_url'      => $renewUrl,
                    ],
                    idempotencyKey: $idempotencyKey,
                );

                // 2. In-App Tenant Notification
                $notificationService->notify(
                    tenantId: $tenant->id,
                    category: 'billing_subscription',
                    severity: $daysRemaining <= 3 ? 'critical' : 'warning',
                    title: "Subscription Expiring in {$daysRemaining} Days",
                    message: "Your {$planName} subscription will expire on {$expiryFormatted}. Please renew your plan to prevent service disruption.",
                    actionUrl: '/billing',
                    deduplicationKey: $idempotencyKey,
                    data: [
                        'days_remaining'  => $daysRemaining,
                        'plan_expires_at' => $tenant->plan_expires_at->toIso8601String(),
                    ]
                );

                $this->info("Dispatched reminder to Tenant #{$tenant->id} ({$tenant->email}) [{$daysRemaining} days remaining]");
                $remindersCount++;
            } catch (Throwable $e) {
                Log::error("Failed to dispatch expiry reminder for tenant ID {$tenant->id}: " . $e->getMessage(), [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->info("Completed subscription expiry reminder scan. Total reminders dispatched: {$remindersCount}");

        return 0;
    }
}

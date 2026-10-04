<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuspendExpiredTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:suspend-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Suspends tenants whose subscription has expired and immediately disables their mail daemons.';

    /**
     * Execute the console command.
     */
    public function handle(AuditService $auditService): int
    {
        $this->info('Checking for expired tenants...');

        // Exclude legacy is_admin users AND accounts holding administrative RBAC roles per RBAC-DEC-04 & Invariant B.1
        $expiredTenants = User::where('status', 'active')
            ->where('is_admin', false)
            ->whereDoesntHave('roles', function ($q) {
                $q->whereIn('name', RoleEnum::adminRoles());
            })
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<', now())
            ->get(['id', 'email']);

        if ($expiredTenants->isEmpty()) {
            $this->info('No expired tenants found.');
            return 0;
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($expiredTenants as $expiredTenant) {
            try {
                DB::transaction(function () use ($expiredTenant, $auditService, &$successCount) {
                    $tenant = User::where('id', $expiredTenant->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $tenant || $tenant->status !== 'active') {
                        return;
                    }

                    // Re-check plan_expires_at inside lock to eliminate race conditions with concurrent renewal
                    if ($tenant->plan_expires_at === null || $tenant->plan_expires_at >= now()) {
                        return;
                    }

                    $beforeState = [
                        'status' => $tenant->status,
                        'plan_expires_at' => $tenant->plan_expires_at?->toIso8601String(),
                    ];

                    // 1. Suspend the tenant (User)
                    $tenant->update(['status' => 'suspended']);

                    // 2. Suspend only active domains associated with the tenant
                    // Postfix/Dovecot are configured to instantly reject/disable mail
                    // for domains where status != 'active'
                    $tenant->domains()->where('status', 'active')->update(['status' => 'suspended']);

                    $afterState = [
                        'status' => 'suspended',
                        'plan_expires_at' => $tenant->plan_expires_at?->toIso8601String(),
                    ];

                    $auditService->record(
                        action: 'tenant.auto_suspend',
                        entityType: 'User',
                        entityId: $tenant->id,
                        before: $beforeState,
                        after: $afterState,
                        actor: null,
                        reason: 'Subscription expired on ' . ($tenant->plan_expires_at?->toIso8601String() ?? 'N/A')
                    );

                    Log::info("Suspended expired tenant ID: {$tenant->id}, Email: {$tenant->email}");
                    $this->info("Suspended tenant: {$tenant->email}");
                    $successCount++;
                });
            } catch (Throwable $e) {
                $failCount++;
                Log::error("Failed to suspend expired tenant ID {$expiredTenant->id}: " . $e->getMessage(), [
                    'tenant_id' => $expiredTenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failCount > 0) {
            $this->warn("Processed {$successCount} tenants successfully, {$failCount} failed. Check logs.");
            return 1;
        }

        $this->info("Successfully processed {$successCount} expired tenants.");
        return 0;
    }
}

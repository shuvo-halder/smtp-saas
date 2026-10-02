<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SecurityAuditLogger;
use App\Services\SuperAdminGovernanceService;
use Illuminate\Console\Command;

class RbacEmergencyRecoveryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rbac:emergency-recovery 
                            {email : The email address of the administrator account to recover}
                            {--reactivate : Explicitly reactivate the account if currently suspended}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recover administrator access with explicit status preservation per RBAC-DEC-07';

    /**
     * Execute the console command.
     */
    public function handle(SuperAdminGovernanceService $governanceService, AuditService $auditService): int
    {
        $email = (string) $this->argument('email');
        $reactivate = (bool) $this->option('reactivate');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email [{$email}] not found.");
            return 1;
        }

        // RBAC-DEC-07: Preserve status + require explicit --reactivate
        if ($user->status === 'suspended') {
            if (! $reactivate) {
                $this->error("Account [{$email}] is suspended. To reactivate, re-run with --reactivate. Current status preserved.");

                SecurityAuditLogger::logDenial(
                    null,
                    'Emergency recovery attempted on suspended account without --reactivate flag',
                    'admin.roles.manage',
                    'user',
                    (string) $user->id,
                    ['email' => $email]
                );

                return 1;
            }

            $user->update(['status' => 'active']);
            $this->info("Account [{$email}] reactivated to active status.");
        }

        // Ensure administrator identity flag is active
        if (! $user->is_admin) {
            $user->update(['is_admin' => true]);
        }

        // Assign Super Admin role under the governance lock
        $governanceService->assignSuperAdmin($user);

        $auditService->record(
            action: 'admin.emergency_recovery',
            entityType: 'User',
            entityId: $user->id,
            before: ['status' => 'suspended'],
            after: ['status' => $user->status, 'role' => RoleEnum::SUPER_ADMIN->value],
            actor: $user,
            reason: 'Emergency recovery executed via CLI' . ($reactivate ? ' with --reactivate' : '')
        );

        $this->info("Successfully restored Super Admin privileges for [{$email}].");

        return 0;
    }
}

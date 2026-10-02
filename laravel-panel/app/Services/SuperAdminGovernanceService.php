<?php

namespace App\Services;

use App\Enums\RoleEnum;
use App\Exceptions\SuperAdminGovernanceException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuperAdminGovernanceService
{
    public const LOCK_NAME = 'super_admin_governance';

    /**
     * Execute a critical governance operation protected by the dedicated governance_locks mutex.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws SuperAdminGovernanceException
     */
    public function executeWithLock(callable $operation)
    {
        return DB::transaction(function () use ($operation) {
            // 1. Acquire pessimistic row lock on dedicated governance_locks table per RBAC-DEC-06
            $lock = DB::table('governance_locks')
                ->where('lock_name', self::LOCK_NAME)
                ->lockForUpdate()
                ->first();

            if (! $lock) {
                // Self-healing insertion if missing
                DB::table('governance_locks')->insert([
                    'lock_name'   => self::LOCK_NAME,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                $lock = DB::table('governance_locks')
                    ->where('lock_name', self::LOCK_NAME)
                    ->lockForUpdate()
                    ->first();
            }

            $ownerId = gethostname() . ':' . getmypid() . ':' . uniqid('', true);

            DB::table('governance_locks')
                ->where('lock_name', self::LOCK_NAME)
                ->update([
                    'owner'       => $ownerId,
                    'acquired_at' => now(),
                    'updated_at'  => now(),
                ]);

            try {
                $result = $operation();
            } catch (SuperAdminGovernanceException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::channel('security')->error('SuperAdmin governance operation failed inside lock', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw new SuperAdminGovernanceException('Governance operation failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
            } finally {
                DB::table('governance_locks')
                    ->where('lock_name', self::LOCK_NAME)
                    ->update([
                        'owner'       => null,
                        'acquired_at' => null,
                        'updated_at'  => now(),
                    ]);
            }

            return $result;
        });
    }

    /**
     * Assert that the target user can be safely demoted, de-escalated, or deactivated
     * without reducing the number of active Super Admins below 1.
     *
     * @throws SuperAdminGovernanceException
     */
    public function assertCanDeescalateOrDeactivate(int $targetUserId): void
    {
        $targetUser = User::find($targetUserId);
        if (! $targetUser) {
            return;
        }

        // If the target is not currently an active Super Admin, de-escalation does not reduce active count
        if ($targetUser->status !== 'active' || ! $targetUser->hasRole(RoleEnum::SUPER_ADMIN->value)) {
            return;
        }

        // Count remaining active Super Admins excluding the target
        $remainingCount = User::where('id', '!=', $targetUserId)
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', RoleEnum::SUPER_ADMIN->value))
            ->count();

        if ($remainingCount < 1) {
            throw new SuperAdminGovernanceException('Cannot de-escalate or deactivate the final active Super Admin.');
        }
    }

    /**
     * Safely demote a Super Admin under the governance lock.
     *
     * @throws SuperAdminGovernanceException
     */
    public function demoteSuperAdmin(User $targetUser): void
    {
        $this->executeWithLock(function () use ($targetUser) {
            $this->assertCanDeescalateOrDeactivate($targetUser->id);
            $targetUser->removeRole(RoleEnum::SUPER_ADMIN->value);
        });
    }

    /**
     * Safely assign the Super Admin role under the governance lock.
     */
    public function assignSuperAdmin(User $targetUser): void
    {
        $this->executeWithLock(function () use ($targetUser) {
            if (! $targetUser->hasRole(RoleEnum::SUPER_ADMIN->value)) {
                $targetUser->assignRole(RoleEnum::SUPER_ADMIN->value);
            }
        });
    }

    /**
     * Count currently active Super Admins.
     */
    public function getActiveSuperAdminCount(): int
    {
        return User::where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->where('name', RoleEnum::SUPER_ADMIN->value))
            ->count();
    }

    /**
     * Query MySQL 8.0 performance_schema lock waits telemetry per Supplementary Decision 1.
     *
     * @return array<int, object>
     */
    public function getLockWaitDiagnostics(): array
    {
        try {
            return DB::select("
                SELECT 
                    r.trx_id AS requesting_trx_id,
                    r.trx_mysql_thread_id AS requesting_thread_id,
                    b.trx_id AS blocking_trx_id,
                    b.trx_mysql_thread_id AS blocking_thread_id,
                    w.requesting_engine_lock_id,
                    w.blocking_engine_lock_id
                FROM performance_schema.data_lock_waits w
                INNER JOIN performance_schema.data_locks r ON r.engine_lock_id = w.requesting_engine_lock_id
                INNER JOIN performance_schema.data_locks b ON b.engine_lock_id = w.blocking_engine_lock_id
            ");
        } catch (Throwable $e) {
            Log::channel('security')->warning('Lock wait diagnostics unavailable: ' . $e->getMessage());
            return [];
        }
    }
}

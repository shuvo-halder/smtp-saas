<?php

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Provenance tag identifying records created by this migration backfill.
     * Used for safe, selective rollback per Supplementary Decision 2.
     */
    public const BACKFILL_PROVENANCE = 'migration_step_16b2_backfill';

    /**
     * Run the migrations.
     * Pure-DML backfill of legacy administrators and RBAC seed per RBAC-DEC-09.
     */
    public function up(): void
    {
        $now = now();
        $guard = 'web';

        // 1. Seed Permissions
        foreach (PermissionEnum::cases() as $permission) {
            $exists = DB::table('permissions')
                ->where('name', $permission->value)
                ->where('guard_name', $guard)
                ->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name'       => $permission->value,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 2. Seed Roles
        foreach (RoleEnum::cases() as $role) {
            $exists = DB::table('roles')
                ->where('name', $role->value)
                ->where('guard_name', $guard)
                ->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'name'       => $role->value,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 3. Map Permissions to Roles
        $permissionsMap = DB::table('permissions')
            ->where('guard_name', $guard)
            ->pluck('id', 'name')
            ->toArray();

        $rolesMap = DB::table('roles')
            ->where('guard_name', $guard)
            ->pluck('id', 'name')
            ->toArray();

        foreach (RoleEnum::cases() as $roleEnum) {
            $roleId = $rolesMap[$roleEnum->value] ?? null;
            if (! $roleId) {
                continue;
            }

            foreach ($roleEnum->permissions() as $permissionName) {
                $permissionId = $permissionsMap[$permissionName] ?? null;
                if (! $permissionId) {
                    continue;
                }

                $mapped = DB::table('role_has_permissions')
                    ->where('permission_id', $permissionId)
                    ->where('role_id', $roleId)
                    ->exists();

                if (! $mapped) {
                    DB::table('role_has_permissions')->insert([
                        'permission_id' => $permissionId,
                        'role_id'       => $roleId,
                    ]);
                }
            }
        }

        // 4. Backfill Legacy Administrators (users where is_admin = true)
        $superAdminRoleId = $rolesMap[RoleEnum::SUPER_ADMIN->value] ?? null;
        if ($superAdminRoleId) {
            $legacyAdmins = DB::table('users')
                ->where('is_admin', true)
                ->orWhere('is_admin', 1)
                ->get(['id', 'email']);

            foreach ($legacyAdmins as $admin) {
                $hasRole = DB::table('model_has_roles')
                    ->where('role_id', $superAdminRoleId)
                    ->where('model_type', 'App\\Models\\User')
                    ->where('model_id', $admin->id)
                    ->exists();

                if (! $hasRole) {
                    DB::table('model_has_roles')->insert([
                        'role_id'    => $superAdminRoleId,
                        'model_type' => 'App\\Models\\User',
                        'model_id'   => $admin->id,
                        'provenance' => self::BACKFILL_PROVENANCE,
                    ]);
                }
            }
        }

        // Flush Spatie cache
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     * Preserves legitimate non-backfilled assignments per Supplementary Decision 2.
     */
    public function down(): void
    {
        $guard = 'web';

        // 1. Delete only backfilled role assignments tagged with provenance
        DB::table('model_has_roles')
            ->where('provenance', self::BACKFILL_PROVENANCE)
            ->delete();

        // 2. Delete role-permission mappings for defined roles
        $roleIds = DB::table('roles')
            ->whereIn('name', RoleEnum::all())
            ->where('guard_name', $guard)
            ->pluck('id');

        DB::table('role_has_permissions')
            ->whereIn('role_id', $roleIds)
            ->delete();

        // 3. Delete seeded roles
        DB::table('roles')
            ->whereIn('name', RoleEnum::all())
            ->where('guard_name', $guard)
            ->delete();

        // 4. Delete seeded permissions
        DB::table('permissions')
            ->whereIn('name', PermissionEnum::all())
            ->where('guard_name', $guard)
            ->delete();

        // Flush Spatie cache
        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};

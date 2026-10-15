<?php

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     * Guaranteed idempotent across multiple executions.
     */
    public function run(): void
    {
        // 1. Standard Subscription Plans (always seeded idempotently)
        $this->call([
            PlanSeeder::class,
        ]);

        // 2. Local/Testing Development Accounts (strictly guarded to non-production)
        if (app()->environment('local', 'testing')) {
            $starterPlan = Plan::where('slug', 'starter')->first();

            // Local Tenant User
            User::firstOrCreate(
                ['email' => 'test@example.com'],
                [
                    'name' => 'Test User',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'plan_id' => $starterPlan?->id,
                    'is_admin' => false,
                    'status' => 'active',
                ]
            );

            // Local Super Admin User per RBAC-DEC-08/10
            $admin = User::firstOrCreate(
                ['email' => 'admin@example.com'],
                [
                    'name' => 'Local Admin',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'is_admin' => true,
                    'status' => 'active',
                ]
            );

            if (! $admin->hasRole(RoleEnum::SUPER_ADMIN->value)) {
                $admin->assignRole(RoleEnum::SUPER_ADMIN->value);
            }
        }
    }
}

<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_seeder_is_idempotent(): void
    {
        $this->seed(PlanSeeder::class);
        $countAfterFirst = Plan::count();
        $this->assertEquals(3, $countAfterFirst);

        // Run second time — must not throw unique constraint violation or duplicate rows
        $this->seed(PlanSeeder::class);
        $countAfterSecond = Plan::count();
        $this->assertEquals(3, $countAfterSecond);

        $starter = Plan::where('slug', 'starter')->first();
        $this->assertNotNull($starter);
        $this->assertEquals(1, $starter->max_domains);
        $this->assertEquals(500, $starter->daily_outbound_recipients);
    }

    public function test_database_seeder_is_idempotent_in_local_environment(): void
    {
        // First execution
        $this->seed(DatabaseSeeder::class);

        $this->assertEquals(3, Plan::count());
        $this->assertEquals(2, User::count());

        $testUser = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($testUser);
        $this->assertFalse((bool) $testUser->is_admin);

        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue((bool) $admin->is_admin);
        $this->assertTrue($admin->hasRole(RoleEnum::SUPER_ADMIN->value));

        // Second execution — must succeed cleanly without duplicate key errors
        $this->seed(DatabaseSeeder::class);

        $this->assertEquals(3, Plan::count());
        $this->assertEquals(2, User::count());
    }

    public function test_database_seeder_does_not_seed_default_users_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ])->assertSuccessful();

        // Plans should be seeded
        $this->assertEquals(3, Plan::count());

        // Users with default passwords must NOT be seeded in production
        $this->assertEquals(0, User::count());
        $this->assertNull(User::where('email', 'test@example.com')->first());
        $this->assertNull(User::where('email', 'admin@example.com')->first());
    }
}

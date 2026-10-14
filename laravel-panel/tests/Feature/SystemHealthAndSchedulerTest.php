<?php

namespace Tests\Feature;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Health\SystemHealthService;
use App\Services\Scheduling\ScheduledTaskTrackerService;
use Exception;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemHealthAndSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles and permissions for tests
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);
    }

    public function test_public_liveness_endpoint_returns_minimal_payload(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
            ])
            ->assertJson([
                'status' => 'HEALTHY',
            ]);

        // Assert zero secrets, connection strings, or internal infrastructure details exposed
        $data = $response->json();
        $this->assertArrayNotHasKey('components', $data);
        $this->assertArrayNotHasKey('database', $data);
        $this->assertArrayNotHasKey('env', $data);
        $this->assertArrayNotHasKey('php_version', $data);
    }

    public function test_admin_health_endpoint_rejects_unauthenticated_request(): void
    {
        $response = $this->getJson('/api/admin/system/health');

        $response->assertStatus(401);
    }

    public function test_admin_health_endpoint_rejects_ordinary_tenant(): void
    {
        $tenant = User::factory()->create([
            'is_admin' => false,
            'status'   => 'active',
        ]);

        $response = $this->actingAs($tenant)->getJson('/api/admin/system/health');

        $response->assertStatus(403);
    }

    public function test_admin_health_endpoint_rejects_suspended_admin(): void
    {
        $admin = User::factory()->superAdmin()->create([
            'status' => 'suspended',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/system/health');

        $response->assertStatus(403);
    }

    public function test_admin_health_endpoint_rejects_zero_role_admin(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'status'   => 'active',
        ]);
        // Zero roles/permissions

        $response = $this->actingAs($admin)->getJson('/api/admin/system/health');

        $response->assertStatus(403);
    }

    public function test_admin_health_endpoint_allows_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create([
            'status' => 'active',
        ]);

        // Record a dummy task run so scheduler has telemetry
        ScheduledTaskRun::create([
            'task_name'   => 'tenant:suspend-expired',
            'expression'  => '* * * * *',
            'status'      => 'success',
            'started_at'  => now()->subMinutes(1),
            'finished_at' => now()->subMinutes(1)->addSeconds(2),
            'duration_ms' => 2000,
        ]);

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/system/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
                'environment',
                'components' => [
                    'app' => ['status', 'details'],
                    'database' => ['status', 'details'],
                    'redis' => ['status', 'details'],
                    'scheduler' => ['status', 'details'],
                    'task_failures' => ['status', 'details'],
                    'queue' => ['status', 'details'],
                    'backup' => ['status', 'details'],
                ],
                'recent_tasks',
            ]);

        $json = $response->json();
        $this->assertEquals('HEALTHY', $json['components']['database']['status']);
        $this->assertEquals('HEALTHY', $json['components']['app']['status']);
        $this->assertEquals('HEALTHY', $json['components']['scheduler']['status']);
        $this->assertNotEmpty($json['recent_tasks']);
    }

    public function test_admin_health_endpoint_allows_deliverability_operator(): void
    {
        $operator = User::factory()->deliverabilityOperator()->create([
            'status' => 'active',
        ]);

        $response = $this->actingAs($operator)->getJson('/api/admin/system/health');

        $response->assertStatus(200);
    }

    public function test_admin_health_endpoint_allows_customer_support(): void
    {
        $support = User::factory()->customerSupport()->create([
            'status' => 'active',
        ]);

        $response = $this->actingAs($support)->getJson('/api/admin/system/health');

        $response->assertStatus(200);
    }

    public function test_step18_backup_status_endpoint_is_not_silently_invented(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        // Step 18 governance mandate: GET /api/admin/system/backup-status requires architecture approval.
        // Confirming it is NOT registered as a route.
        $response = $this->actingAs($superAdmin)->getJson('/api/admin/system/backup-status');

        $response->assertStatus(404);
    }

    public function test_scheduled_task_tracker_service_records_lifecycle(): void
    {
        $service = app(ScheduledTaskTrackerService::class);
        $mutex = app(\Illuminate\Console\Scheduling\CacheEventMutex::class);

        // Create a mock/event task
        $task = new Event(
            $mutex,
            "'php' 'artisan' tenant:suspend-expired"
        );
        $task->cron('* * * * *');

        // 1. Record Start
        $run = $service->recordStart($task);
        $this->assertNotNull($run);
        $this->assertEquals('tenant:suspend-expired', $run->task_name);
        $this->assertEquals('running', $run->status);
        $this->assertNotNull($run->uuid);

        // 2. Record Finish
        $finishedRun = $service->recordFinish($task, 0.45);
        $this->assertNotNull($finishedRun);
        $this->assertEquals('success', $finishedRun->status);
        $this->assertEquals(450, $finishedRun->duration_ms);
        $this->assertNotNull($finishedRun->finished_at);
    }

    public function test_scheduled_task_tracker_service_records_and_sanitizes_failure(): void
    {
        $service = app(ScheduledTaskTrackerService::class);
        $mutex = app(\Illuminate\Console\Scheduling\CacheEventMutex::class);

        $task = new Event(
            $mutex,
            "'artisan' backup:run --verify"
        );

        $run = $service->recordStart($task);

        // Exception containing sensitive password, token, hash, and DSN
        $sensitiveMessage = "Connection failed to mysql://smtp_user:SuperSecretPassword123!@10.0.0.1:3306/db with Bearer abc123secret and hash \$6\$rounds=5000\$salt\$hashvalue password=secret_token";
        $exception = new Exception($sensitiveMessage);

        $failedRun = $service->recordFailure($task, $exception);

        $this->assertNotNull($failedRun);
        $this->assertEquals('failed', $failedRun->status);
        $this->assertNotNull($failedRun->failure_summary);

        // Verify sanitization
        $this->assertStringNotContainsString('SuperSecretPassword123!', $failedRun->failure_summary);
        $this->assertStringNotContainsString('abc123secret', $failedRun->failure_summary);
        $this->assertStringNotContainsString('hashvalue', $failedRun->failure_summary);
        $this->assertStringNotContainsString('secret_token', $failedRun->failure_summary);
        $this->assertStringContainsString('[REDACTED', $failedRun->failure_summary);
    }

    public function test_scheduled_task_tracker_service_records_skipped(): void
    {
        $service = app(ScheduledTaskTrackerService::class);
        $mutex = app(\Illuminate\Console\Scheduling\CacheEventMutex::class);

        $task = new Event(
            $mutex,
            "'artisan' mail:process-log"
        );

        $skippedRun = $service->recordSkipped($task, 'Mutex locked by another worker');

        $this->assertNotNull($skippedRun);
        $this->assertEquals('skipped', $skippedRun->status);
        $this->assertEquals(0, $skippedRun->duration_ms);
        $this->assertStringContainsString('Mutex locked', $skippedRun->failure_summary);
    }

    public function test_scheduled_task_tracker_service_freshness_calculation(): void
    {
        $service = app(ScheduledTaskTrackerService::class);

        // 1. Fresh (ran 2 minutes ago)
        ScheduledTaskRun::truncate();
        ScheduledTaskRun::create([
            'task_name'   => 'tenant:suspend-expired',
            'status'      => 'success',
            'started_at'  => now()->subMinutes(2),
            'finished_at' => now()->subMinutes(2)->addSecond(),
            'duration_ms' => 1000,
        ]);

        $freshness = $service->getFreshness();
        $this->assertEquals('HEALTHY', $freshness['status']);
        $this->assertEquals('fresh', $freshness['freshness']);

        // 2. Delayed (ran 10 minutes ago)
        ScheduledTaskRun::truncate();
        ScheduledTaskRun::create([
            'task_name'   => 'tenant:suspend-expired',
            'status'      => 'success',
            'started_at'  => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(10)->addSecond(),
            'duration_ms' => 1000,
        ]);

        $freshness = $service->getFreshness();
        $this->assertEquals('DEGRADED', $freshness['status']);
        $this->assertEquals('delayed', $freshness['freshness']);

        // 3. Stale (ran 30 minutes ago)
        ScheduledTaskRun::truncate();
        ScheduledTaskRun::create([
            'task_name'   => 'tenant:suspend-expired',
            'status'      => 'success',
            'started_at'  => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(30)->addSecond(),
            'duration_ms' => 1000,
        ]);

        $freshness = $service->getFreshness();
        $this->assertEquals('UNHEALTHY', $freshness['status']);
        $this->assertEquals('stale', $freshness['freshness']);
    }

    public function test_scheduled_task_tracker_service_prune(): void
    {
        $service = app(ScheduledTaskTrackerService::class);

        ScheduledTaskRun::truncate();

        // 1. Old run (10 days ago)
        ScheduledTaskRun::create([
            'task_name'   => 'tenant:suspend-expired',
            'status'      => 'success',
            'started_at'  => now()->subDays(10),
            'created_at'  => now()->subDays(10),
        ]);

        // 2. Recent run (2 days ago)
        ScheduledTaskRun::create([
            'task_name'   => 'tenant:suspend-expired',
            'status'      => 'success',
            'started_at'  => now()->subDays(2),
            'created_at'  => now()->subDays(2),
        ]);

        $pruned = $service->prune(7);
        $this->assertEquals(1, $pruned);
        $this->assertEquals(1, ScheduledTaskRun::count());
    }

    public function test_admin_tasks_endpoint_supports_filtering_and_pagination(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        ScheduledTaskRun::truncate();

        for ($i = 1; $i <= 20; $i++) {
            ScheduledTaskRun::create([
                'task_name'   => $i % 2 === 0 ? 'tenant:suspend-expired' : 'mail:process-log',
                'status'      => $i === 1 ? 'failed' : 'success',
                'started_at'  => now()->subMinutes($i * 5),
                'duration_ms' => $i * 100,
            ]);
        }

        // Test basic pagination
        $response = $this->actingAs($superAdmin)->getJson('/api/admin/system/tasks?per_page=10');
        $response->assertStatus(200)
            ->assertJsonPath('total', 20)
            ->assertJsonPath('per_page', 10);

        // Test status filtering
        $responseFailed = $this->actingAs($superAdmin)->getJson('/api/admin/system/tasks?status=failed');
        $responseFailed->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'failed');

        // Test search filtering
        $responseSearch = $this->actingAs($superAdmin)->getJson('/api/admin/system/tasks?search=suspend-expired');
        $responseSearch->assertStatus(200)
            ->assertJsonPath('total', 10);

        // Test max per_page bounded to 50
        $responseMax = $this->actingAs($superAdmin)->getJson('/api/admin/system/tasks?per_page=100');
        $responseMax->assertStatus(200)
            ->assertJsonPath('per_page', 50);
    }
}

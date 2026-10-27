<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TenantNotification;
use App\Models\User;
use App\Services\Health\SystemHealthService;
use App\Services\TenantNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TenantNotificationReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $admin;
    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // Seed roles and permissions for tests
        $this->artisan('db:seed', ['--class' => 'DatabaseSeeder']);

        $this->tenant = User::factory()->create([
            'status' => 'active',
        ]);

        $this->admin = User::factory()->superAdmin()->create([
            'status' => 'active',
        ]);

        $this->operator = User::factory()->deliverabilityOperator()->create([
            'status' => 'active',
        ]);
    }

    public function test_concurrent_notification_deduplication_prevents_duplicate_records(): void
    {
        $service = app(TenantNotificationService::class);
        $dedupKey = 'dns_drift:test_domain:active';

        // Simulate concurrent attempts to dispatch the identical notification
        $notif1 = $service->notify(
            tenantId: $this->tenant->id,
            category: 'domain_dns',
            severity: 'critical',
            title: 'DNS Drift Detected',
            message: 'MX records missing',
            deduplicationKey: $dedupKey
        );

        $notif2 = $service->notify(
            tenantId: $this->tenant->id,
            category: 'domain_dns',
            severity: 'critical',
            title: 'DNS Drift Detected',
            message: 'MX records missing',
            deduplicationKey: $dedupKey
        );

        $this->assertNotNull($notif1);
        $this->assertNotNull($notif2);
        $this->assertEquals($notif1->id, $notif2->id);

        // Exactly one notification record must exist in database
        $this->assertEquals(1, TenantNotification::forTenant($this->tenant->id)->count());
        $this->assertEquals(1, (int) Cache::get('notifications:deduplicated_24h_count'));
    }

    public function test_malformed_metadata_handling_scrubs_resources_objects_and_deep_nesting(): void
    {
        $service = app(TenantNotificationService::class);

        // Create stream resource
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'temporary stream data');
        rewind($stream);

        // Nested array exceeding 10 levels deep
        $deep = ['level' => 1];
        $curr = &$deep;
        for ($i = 2; $i <= 15; $i++) {
            $curr['nested'] = ['level' => $i];
            $curr = &$curr['nested'];
        }

        // Test object with toArray
        $objWithToArray = new class {
            public function toArray(): array {
                return ['obj_key' => 'obj_val', 'password' => 'secret_should_redact'];
            }
        };

        // Non-UTF8 string containing invalid byte sequence
        $invalidUtf8 = "Valid UTF-8 part \xC3\x28 invalid sequence";

        $metadata = [
            'stream_handle'  => $stream,
            'deep_structure' => $deep,
            'custom_object'  => $objWithToArray,
            'raw_string'     => $invalidUtf8,
            'api_key'        => 'super_secret_api_key_redact',
        ];

        $notif = $service->notify(
            tenantId: $this->tenant->id,
            category: 'system',
            severity: 'info',
            title: 'Complex Metadata Test',
            message: 'Testing metadata safety',
            data: $metadata
        );

        fclose($stream);

        $this->assertNotNull($notif);
        $this->assertIsArray($notif->data);

        // Sensitive key must be stripped
        $this->assertArrayNotHasKey('api_key', $notif->data);

        // Stream resource must be converted to non-crashing descriptor
        $this->assertStringContainsString('Resource: stream', $notif->data['stream_handle']);

        // Deep structure must be truncated safely
        $jsonEncoded = json_encode($notif->data);
        $this->assertNotFalse($jsonEncoded);
        $this->assertStringContainsString('_truncated', $jsonEncoded);

        // Object with toArray converted and sanitized
        $this->assertEquals('obj_val', $notif->data['custom_object']['obj_key']);
        $this->assertArrayNotHasKey('password', $notif->data['custom_object']);
    }

    public function test_api_index_validates_category_and_severity_strictly(): void
    {
        // 1. Invalid category returns 422
        $respCat = $this->actingAs($this->tenant)->getJson('/api/notifications?category=invalid_category');
        $respCat->assertStatus(422)
            ->assertJsonValidationErrors(['category']);

        // 2. Invalid severity returns 422
        $respSev = $this->actingAs($this->tenant)->getJson('/api/notifications?severity=invalid_severity');
        $respSev->assertStatus(422)
            ->assertJsonValidationErrors(['severity']);

        // 3. Valid parameters return 200
        $respValid = $this->actingAs($this->tenant)->getJson('/api/notifications?category=domain_dns&severity=critical&status=all');
        $respValid->assertStatus(200);
    }

    public function test_prune_command_validates_read_retention_days(): void
    {
        $this->artisan('notifications:prune --read-days=0')
            ->expectsOutputToContain('The --read-days option must be at least 1 day.')
            ->assertExitCode(2);
    }

    public function test_prune_command_validates_dismissed_retention_days(): void
    {
        $this->artisan('notifications:prune --dismissed-days=-5')
            ->expectsOutputToContain('The --dismissed-days option must be at least 1 day.')
            ->assertExitCode(2);
    }

    public function test_prune_command_validates_stale_days(): void
    {
        $this->artisan('notifications:prune --stale-days=0')
            ->expectsOutputToContain('The --stale-days option must be at least 1 day.')
            ->assertExitCode(2);
    }

    public function test_prune_command_validates_batch_size_limits(): void
    {
        $this->artisan('notifications:prune --batch-size=0')
            ->expectsOutputToContain('The --batch-size option must be between 1 and 5000.')
            ->assertExitCode(2);

        $this->artisan('notifications:prune --batch-size=9999')
            ->expectsOutputToContain('The --batch-size option must be between 1 and 5000.')
            ->assertExitCode(2);
    }

    public function test_prune_command_captures_execution_summary_in_cache(): void
    {
        $this->artisan('notifications:prune --read-days=90 --dismissed-days=30')
            ->assertExitCode(0);

        $summary = Cache::get('notifications:prune:last_execution');
        $this->assertNotNull($summary);
        $this->assertEquals('success', $summary['status']);
        $this->assertEquals(90, $summary['read_days']);
        $this->assertEquals(30, $summary['dismissed_days']);
        $this->assertEquals(180, $summary['stale_days']);
        $this->assertFalse($summary['dry_run']);
        $this->assertArrayHasKey('duration_ms', $summary);
    }

    public function test_prune_command_overlap_protection_prevents_concurrent_runs(): void
    {
        // Acquire lock manually to simulate active concurrent pruning process
        $lock = Cache::lock('notifications_prune_lock', 300);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('notifications:prune')
                ->expectsOutputToContain('Another notifications pruning process is currently running. Skipping execution.')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }
    }

    public function test_stale_unread_rule_boundary_and_critical_protection(): void
    {
        // 1. Stale unread notice (created 190 days ago, non-critical) -> PRUNED
        TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'Stale Notice',
            'message'      => 'Info created 190 days ago',
            'created_at'   => Carbon::now()->subDays(190),
            'read_at'      => null,
            'dismissed_at' => null,
        ]);

        // 2. Fresh unread notice (created 100 days ago, non-critical) -> RETAINED
        TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'Recent Unread Notice',
            'message'      => 'Info created 100 days ago',
            'created_at'   => Carbon::now()->subDays(100),
            'read_at'      => null,
            'dismissed_at' => null,
        ]);

        // 3. Stale unread CRITICAL notice (created 190 days ago) -> RETAINED (PROTECTED)
        TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'domain_dns',
            'severity'     => 'critical',
            'title'        => 'Critical Unresolved Alert',
            'message'      => 'Critical created 190 days ago and still unread',
            'created_at'   => Carbon::now()->subDays(190),
            'read_at'      => null,
            'dismissed_at' => null,
        ]);

        $this->assertEquals(3, TenantNotification::count());

        $this->artisan('notifications:prune --stale-days=180')
            ->expectsOutputToContain('Total Eligible: 1')
            ->expectsOutputToContain('Total Pruned:   1')
            ->assertExitCode(0);

        // 2 records should remain (recent unread + protected critical unread)
        $this->assertEquals(2, TenantNotification::count());
        $this->assertEquals(1, TenantNotification::where('severity', 'critical')->count());
    }

    public function test_admin_system_notifications_endpoint_requires_admin_authorization(): void
    {
        // Unauthenticated -> 401
        $guestResp = $this->getJson('/api/admin/system/notifications');
        $guestResp->assertStatus(401);

        // Tenant -> 403
        $tenantResp = $this->actingAs($this->tenant)->getJson('/api/admin/system/notifications');
        $tenantResp->assertStatus(403);

        // Operator with admin.stats.read -> 200
        $opResp = $this->actingAs($this->operator)->getJson('/api/admin/system/notifications');
        $opResp->assertStatus(200);

        $json = $opResp->json();
        $this->assertEquals('HEALTHY', $json['status']);
        $this->assertArrayHasKey('summary', $json);
        $this->assertArrayHasKey('pruning', $json);
        $this->assertArrayHasKey('scheduler', $json);
    }

    public function test_admin_system_notifications_endpoint_exposes_zero_tenant_content(): void
    {
        TenantNotification::create([
            'tenant_id' => $this->tenant->id,
            'category'  => 'security_abuse',
            'severity'  => 'critical',
            'title'     => 'Confidential Tenant Security Breach Alert',
            'message'   => 'Contains private tenant internal details and secret data',
            'data'      => ['confidential_key' => 'tenant_secret_value'],
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/system/notifications');
        $response->assertStatus(200);

        $content = $response->getContent();

        // Must NOT contain any tenant private content, titles, messages, or emails
        $this->assertStringNotContainsString('Confidential Tenant Security Breach Alert', $content);
        $this->assertStringNotContainsString('Contains private tenant internal details', $content);
        $this->assertStringNotContainsString('tenant_secret_value', $content);
        $this->assertStringNotContainsString($this->tenant->email, $content);
    }

    public function test_system_health_service_includes_notifications_diagnostic_component(): void
    {
        $healthService = app(SystemHealthService::class);
        $health = $healthService->getHealth();

        $this->assertArrayHasKey('components', $health);
        $this->assertArrayHasKey('notifications', $health['components']);

        $notifComponent = $health['components']['notifications'];
        $this->assertArrayHasKey('status', $notifComponent);
        $this->assertArrayHasKey('details', $notifComponent);
        $this->assertArrayHasKey('total_notifications', $notifComponent['details']);
        $this->assertArrayHasKey('unread_notifications', $notifComponent['details']);
        $this->assertArrayHasKey('unresolved_critical_alerts', $notifComponent['details']);
        $this->assertArrayHasKey('last_prune_status', $notifComponent['details']);
    }

    public function test_read_dismiss_and_restore_operations_are_strictly_idempotent(): void
    {
        $notif = TenantNotification::create([
            'tenant_id' => $this->tenant->id,
            'category'  => 'system',
            'severity'  => 'info',
            'title'     => 'Idempotency Test',
            'message'   => 'Testing repeated operations',
        ]);

        // 1. Mark as read first time
        $respRead1 = $this->actingAs($this->tenant)->postJson("/api/notifications/{$notif->id}/read");
        $respRead1->assertStatus(200);
        $readAt1 = $notif->fresh()->read_at;
        $this->assertNotNull($readAt1);

        // Mark as read second time (idempotent)
        $respRead2 = $this->actingAs($this->tenant)->postJson("/api/notifications/{$notif->id}/read");
        $respRead2->assertStatus(200);
        $readAt2 = $notif->fresh()->read_at;
        $this->assertEquals($readAt1->toDateTimeString(), $readAt2->toDateTimeString());

        // 2. Dismiss first time
        $respDismiss1 = $this->actingAs($this->tenant)->postJson("/api/notifications/{$notif->id}/dismiss");
        $respDismiss1->assertStatus(200);
        $dismissedAt1 = $notif->fresh()->dismissed_at;
        $this->assertNotNull($dismissedAt1);

        // Dismiss second time (idempotent)
        $respDismiss2 = $this->actingAs($this->tenant)->postJson("/api/notifications/{$notif->id}/dismiss");
        $respDismiss2->assertStatus(200);
        $dismissedAt2 = $notif->fresh()->dismissed_at;
        $this->assertEquals($dismissedAt1->toDateTimeString(), $dismissedAt2->toDateTimeString());

        // 3. Restore first time
        $respRestore1 = $this->actingAs($this->tenant)->postJson("/api/notifications/{$notif->id}/restore");
        $respRestore1->assertStatus(200);
        $this->assertNull($notif->fresh()->dismissed_at);

        // Restore second time (idempotent)
        $respRestore2 = $this->actingAs($this->tenant)->postJson("/api/notifications/{$notif->id}/restore");
        $respRestore2->assertStatus(200);
        $this->assertNull($notif->fresh()->dismissed_at);
    }

    public function test_critical_notifications_bypass_muted_preferences(): void
    {
        // Mute domain_dns category for tenant
        $this->tenant->update([
            'notification_preferences' => [
                'domain_dns' => false,
            ],
        ]);

        $service = app(TenantNotificationService::class);

        // Non-critical notification is muted
        $infoNotif = $service->notify(
            tenantId: $this->tenant->id,
            category: 'domain_dns',
            severity: 'info',
            title: 'Info DNS Note',
            message: 'Informational note'
        );
        $this->assertNull($infoNotif);

        // Critical alert bypasses mute filter and is delivered
        $critNotif = $service->notify(
            tenantId: $this->tenant->id,
            category: 'domain_dns',
            severity: 'critical',
            title: 'Critical DNS Outage',
            message: 'All MX records dropped'
        );
        $this->assertNotNull($critNotif);
        $this->assertEquals('domain_dns', $critNotif->category);
        $this->assertEquals('critical', $critNotif->severity);
    }

    public function test_preference_update_strictly_validates_immutable_categories(): void
    {
        // Attempt to mute security_abuse must fail with 422
        $respSecurity = $this->actingAs($this->tenant)->putJson('/api/notifications/preferences', [
            'security_abuse' => false,
        ]);
        $respSecurity->assertStatus(422)
            ->assertJsonValidationErrors(['security_abuse']);

        // Attempt to mute billing_subscription must fail with 422
        $respBilling = $this->actingAs($this->tenant)->putJson('/api/notifications/preferences', [
            'billing_subscription' => false,
        ]);
        $respBilling->assertStatus(422)
            ->assertJsonValidationErrors(['billing_subscription']);
    }

    public function test_cross_tenant_isolation_on_all_endpoints(): void
    {
        $otherTenant = User::factory()->create(['status' => 'active']);

        $otherNotif = TenantNotification::create([
            'tenant_id' => $otherTenant->id,
            'category'  => 'system',
            'severity'  => 'info',
            'title'     => 'Other Tenant Secret Alert',
            'message'   => 'Other tenant message',
        ]);

        // 1. Index cannot see other tenant's alert
        $indexResp = $this->actingAs($this->tenant)->getJson('/api/notifications');
        $indexResp->assertStatus(200);
        $this->assertCount(0, $indexResp->json('data'));

        // 2. Cannot read other tenant's alert
        $readResp = $this->actingAs($this->tenant)->postJson("/api/notifications/{$otherNotif->id}/read");
        $readResp->assertStatus(404);

        // 3. Cannot dismiss other tenant's alert
        $dismissResp = $this->actingAs($this->tenant)->postJson("/api/notifications/{$otherNotif->id}/dismiss");
        $dismissResp->assertStatus(404);

        // 4. Cannot restore other tenant's alert
        $restoreResp = $this->actingAs($this->tenant)->postJson("/api/notifications/{$otherNotif->id}/restore");
        $restoreResp->assertStatus(404);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AbuseIncident;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\TenantNotification;
use App\Models\User;
use App\Services\TenantNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenant;
    protected User $otherTenant;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                     => 'Standard Plan',
            'slug'                     => 'standard-plan',
            'max_domains'              => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox'   => 1024,
            'price_monthly'            => 29.99,
            'price_yearly'             => 299.99,
        ]);

        $this->tenant = User::factory()->create([
            'plan_id'                  => $this->plan->id,
            'status'                   => 'active',
            'plan_expires_at'          => now()->addMonth(),
            'notification_preferences' => null, // Default
        ]);

        $this->otherTenant = User::factory()->create([
            'plan_id'                  => $this->plan->id,
            'status'                   => 'active',
            'plan_expires_at'          => now()->addMonth(),
        ]);
    }

    public function test_tenant_gets_default_preferences_when_none_are_set(): void
    {
        $prefs = $this->tenant->getNotificationPreferences();

        $this->assertTrue($prefs['domain_dns']);
        $this->assertTrue($prefs['security_abuse']);
        $this->assertTrue($prefs['smtp_quota']);
        $this->assertTrue($prefs['billing_subscription']);
        $this->assertTrue($prefs['mailbox_rule']);
        $this->assertTrue($prefs['system']);

        $this->assertTrue($this->tenant->isNotificationCategoryEnabled('mailbox_rule'));
        $this->assertTrue($this->tenant->isNotificationCategoryEnabled('domain_dns'));
    }

    public function test_tenant_can_retrieve_preferences_via_api(): void
    {
        $response = $this->actingAs($this->tenant)->getJson('/api/notifications/preferences');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'preferences' => [
                    'domain_dns',
                    'security_abuse',
                    'smtp_quota',
                    'billing_subscription',
                    'mailbox_rule',
                    'system',
                ],
                'immutable_categories',
                'category_labels',
                'critical_notice',
            ]);

        $response->assertJson([
            'immutable_categories' => ['security_abuse', 'billing_subscription'],
        ]);
    }

    public function test_tenant_can_update_mutable_notification_preferences(): void
    {
        $payload = [
            'domain_dns'   => false,
            'smtp_quota'   => false,
            'mailbox_rule' => false,
            'system'       => true,
        ];

        $response = $this->actingAs($this->tenant)->putJson('/api/notifications/preferences', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'message'     => 'Notification preferences updated successfully.',
                'preferences' => [
                    'domain_dns'           => false,
                    'smtp_quota'           => false,
                    'mailbox_rule'         => false,
                    'system'               => true,
                    'security_abuse'       => true, // Immutable, stays true
                    'billing_subscription' => true, // Immutable, stays true
                ],
            ]);

        $this->tenant->refresh();
        $this->assertFalse($this->tenant->isNotificationCategoryEnabled('domain_dns'));
        $this->assertFalse($this->tenant->isNotificationCategoryEnabled('mailbox_rule'));
        $this->assertTrue($this->tenant->isNotificationCategoryEnabled('security_abuse'));
    }

    public function test_tenant_cannot_disable_immutable_categories(): void
    {
        $payload = [
            'security_abuse' => false,
        ];

        $response = $this->actingAs($this->tenant)->putJson('/api/notifications/preferences', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['security_abuse']);

        $billingPayload = [
            'billing_subscription' => false,
        ];

        $billingResponse = $this->actingAs($this->tenant)->putJson('/api/notifications/preferences', $billingPayload);

        $billingResponse->assertStatus(422)
            ->assertJsonValidationErrors(['billing_subscription']);
    }

    public function test_preference_updates_are_persisted_and_audited(): void
    {
        $payload = [
            'mailbox_rule' => false,
        ];

        $response = $this->actingAs($this->tenant)->putJson('/api/notifications/preferences', $payload);
        $response->assertStatus(200);

        $audit = AuditLog::where('action', 'tenant.notification_preferences.update')
            ->where('entity_id', (string) $this->tenant->id)
            ->latest()
            ->first();

        $this->assertNotNull($audit);
        $this->assertEquals($this->tenant->email, $audit->actor_email);
        $this->assertIsArray($audit->after_state);
        $this->assertFalse($audit->after_state['mailbox_rule']);
    }

    public function test_tenant_isolation_in_preferences(): void
    {
        // Tenant A updates mailbox_rule to false
        $this->actingAs($this->tenant)->putJson('/api/notifications/preferences', ['mailbox_rule' => false]);

        // Tenant B's preferences should remain default (true)
        $this->otherTenant->refresh();
        $this->assertTrue($this->otherTenant->isNotificationCategoryEnabled('mailbox_rule'));

        $response = $this->actingAs($this->otherTenant)->getJson('/api/notifications/preferences');
        $response->assertStatus(200)
            ->assertJsonPath('preferences.mailbox_rule', true);
    }

    public function test_muted_category_suppresses_non_critical_notifications(): void
    {
        // Tenant mutes mailbox_rule
        $this->tenant->update(['notification_preferences' => ['mailbox_rule' => false]]);

        $service = app(TenantNotificationService::class);

        // Dispatch a non-critical notification for mailbox_rule
        $notif = $service->notify(
            tenantId: $this->tenant->id,
            category: 'mailbox_rule',
            severity: 'info',
            title: 'Forwarding Updated',
            message: 'Mailbox forwarding was updated.'
        );

        $this->assertNull($notif);
        $this->assertEquals(0, TenantNotification::forTenant($this->tenant->id)->count());
    }

    public function test_critical_severity_alerts_bypass_muted_categories(): void
    {
        // Tenant mutes domain_dns
        $this->tenant->update(['notification_preferences' => ['domain_dns' => false]]);

        $service = app(TenantNotificationService::class);

        // Dispatch a CRITICAL notification for domain_dns (e.g. DNS drift)
        $notif = $service->notify(
            tenantId: $this->tenant->id,
            category: 'domain_dns',
            severity: 'critical',
            title: 'Critical DNS Drift Detected',
            message: 'Your domain has lost required MX records.'
        );

        $this->assertNotNull($notif);
        $this->assertEquals('domain_dns', $notif->category);
        $this->assertEquals('critical', $notif->severity);
        $this->assertEquals(1, TenantNotification::forTenant($this->tenant->id)->count());
    }

    public function test_tenant_can_retrieve_category_and_severity_counts(): void
    {
        // Create 2 unread, 1 read, 1 dismissed notification
        TenantNotification::create([
            'tenant_id' => $this->tenant->id,
            'category'  => 'domain_dns',
            'severity'  => 'critical',
            'title'     => 'DNS Drift',
            'message'   => 'Drift message',
        ]);

        TenantNotification::create([
            'tenant_id' => $this->tenant->id,
            'category'  => 'smtp_quota',
            'severity'  => 'warning',
            'title'     => 'Quota 80%',
            'message'   => 'Quota warning',
        ]);

        TenantNotification::create([
            'tenant_id' => $this->tenant->id,
            'category'  => 'billing_subscription',
            'severity'  => 'info',
            'title'     => 'Payment Received',
            'message'   => 'Paid invoice',
            'read_at'   => now(),
        ]);

        TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'System Update',
            'message'      => 'Maintenance',
            'dismissed_at' => now(),
        ]);

        $response = $this->actingAs($this->tenant)->getJson('/api/notifications/counts');

        $response->assertStatus(200)
            ->assertJson([
                'unread'       => 2,
                'read'         => 1,
                'dismissed'    => 1,
                'total_active' => 3,
                'by_category'  => [
                    'domain_dns'           => ['unread' => 1, 'total' => 1],
                    'smtp_quota'           => ['unread' => 1, 'total' => 1],
                    'billing_subscription' => ['unread' => 0, 'total' => 1],
                ],
                'by_severity'  => [
                    'critical' => 1,
                    'warning'  => 1,
                ],
            ]);
    }

    public function test_tenant_can_view_and_restore_dismissed_notifications(): void
    {
        $notif = TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'Archived Alert',
            'message'      => 'Old note',
            'dismissed_at' => now(),
        ]);

        // Active list should not include dismissed
        $activeList = $this->actingAs($this->tenant)->getJson('/api/notifications');
        $activeList->assertStatus(200);
        $this->assertCount(0, $activeList->json('data'));

        // Dismissed list should include it
        $dismissedList = $this->actingAs($this->tenant)->getJson('/api/notifications?status=dismissed');
        $dismissedList->assertStatus(200);
        $this->assertCount(1, $dismissedList->json('data'));
        $this->assertEquals($notif->id, $dismissedList->json('data.0.id'));

        // Restore notification
        $restoreResp = $this->actingAs($this->tenant)->postJson("/api/notifications/{$notif->id}/restore");
        $restoreResp->assertStatus(200)
            ->assertJson([
                'message' => 'Notification restored successfully.',
            ]);

        $notif->refresh();
        $this->assertFalse($notif->isDismissed());

        // Now appears in active list
        $activeListAfter = $this->actingAs($this->tenant)->getJson('/api/notifications');
        $activeListAfter->assertStatus(200);
        $this->assertCount(1, $activeListAfter->json('data'));
    }

    public function test_tenant_cannot_restore_another_tenants_notification(): void
    {
        $otherNotif = TenantNotification::create([
            'tenant_id'    => $this->otherTenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'Other Alert',
            'message'      => 'Other note',
            'dismissed_at' => now(),
        ]);

        $response = $this->actingAs($this->tenant)->postJson("/api/notifications/{$otherNotif->id}/restore");
        $response->assertStatus(404);
    }

    public function test_prune_command_deletes_expired_read_and_dismissed_notifications(): void
    {
        // 1. Expired read notification (read 95 days ago) -> PRUNABLE
        TenantNotification::create([
            'tenant_id'  => $this->tenant->id,
            'category'   => 'system',
            'severity'   => 'info',
            'title'      => 'Old Read Notice',
            'message'    => 'Read 95 days ago',
            'read_at'    => Carbon::now()->subDays(95),
            'created_at' => Carbon::now()->subDays(100),
        ]);

        // 2. Fresh read notification (read 10 days ago) -> RETAINED
        TenantNotification::create([
            'tenant_id'  => $this->tenant->id,
            'category'   => 'system',
            'severity'   => 'info',
            'title'      => 'Fresh Read Notice',
            'message'    => 'Read 10 days ago',
            'read_at'    => Carbon::now()->subDays(10),
            'created_at' => Carbon::now()->subDays(15),
        ]);

        // 3. Expired dismissed notification (dismissed 35 days ago) -> PRUNABLE
        TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'Old Dismissed Notice',
            'message'      => 'Dismissed 35 days ago',
            'dismissed_at' => Carbon::now()->subDays(35),
            'created_at'   => Carbon::now()->subDays(40),
        ]);

        // 4. Fresh dismissed notification (dismissed 5 days ago) -> RETAINED
        TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'Fresh Dismissed Notice',
            'message'      => 'Dismissed 5 days ago',
            'dismissed_at' => Carbon::now()->subDays(5),
            'created_at'   => Carbon::now()->subDays(10),
        ]);

        // 5. Stale non-critical unread notification (created 200 days ago) -> PRUNABLE
        TenantNotification::create([
            'tenant_id'  => $this->tenant->id,
            'category'   => 'system',
            'severity'   => 'info',
            'title'      => 'Stale Info Notice',
            'message'    => 'Created 200 days ago',
            'created_at' => Carbon::now()->subDays(200),
        ]);

        $this->assertEquals(5, TenantNotification::count());

        // Run the prune command
        $this->artisan('notifications:prune --read-days=90 --dismissed-days=30')
            ->expectsOutputToContain('Total Eligible: 3')
            ->expectsOutputToContain('Total Pruned:   3')
            ->assertExitCode(0);

        // 2 records should remain (fresh read + fresh dismissed)
        $this->assertEquals(2, TenantNotification::count());
    }

    public function test_prune_command_never_deletes_unresolved_critical_alerts(): void
    {
        // Unresolved critical alert created 200 days ago
        TenantNotification::create([
            'tenant_id'  => $this->tenant->id,
            'category'   => 'domain_dns',
            'severity'   => 'critical',
            'title'      => 'Unresolved Critical DNS Drift',
            'message'    => 'Created 200 days ago and still unread',
            'read_at'    => null,
            'dismissed_at' => null,
            'created_at' => Carbon::now()->subDays(200),
        ]);

        $this->artisan('notifications:prune --read-days=90 --dismissed-days=30')
            ->expectsOutputToContain('Total Eligible: 0')
            ->expectsOutputToContain('Total Pruned:   0')
            ->assertExitCode(0);

        $this->assertEquals(1, TenantNotification::count());
    }

    public function test_prune_command_never_touches_audit_logs_or_abuse_incidents(): void
    {
        // Create an audit log and an abuse incident
        $audit = AuditLog::create([
            'actor_user_id' => $this->tenant->id,
            'actor_email'   => $this->tenant->email,
            'action'        => 'test.audit_action',
            'entity_type'   => 'User',
            'entity_id'     => (string) $this->tenant->id,
            'created_at'    => Carbon::now()->subDays(365),
        ]);

        $abuse = AbuseIncident::create([
            'tenant_id'      => $this->tenant->id,
            'incident_type'  => 'daily_hard_bounce_threshold',
            'severity'       => 'high',
            'status'         => 'resolved',
            'summary'        => 'Exceeded bounce threshold',
            'occurred_at'    => Carbon::now()->subDays(365),
            'detected_date'  => Carbon::now()->subDays(365)->toDateString(),
        ]);

        $this->artisan('notifications:prune')
            ->assertExitCode(0);

        // AuditLog and AbuseIncident must remain completely untouched
        $this->assertDatabaseHas('audit_logs', ['id' => $audit->id]);
        $this->assertDatabaseHas('abuse_incidents', ['id' => $abuse->id]);
    }

    public function test_prune_command_dry_run_mode(): void
    {
        TenantNotification::create([
            'tenant_id'    => $this->tenant->id,
            'category'     => 'system',
            'severity'     => 'info',
            'title'        => 'Old Dismissed Notice',
            'message'      => 'Dismissed 35 days ago',
            'dismissed_at' => Carbon::now()->subDays(35),
            'created_at'   => Carbon::now()->subDays(40),
        ]);

        $this->artisan('notifications:prune --dry-run')
            ->expectsOutputToContain('Total Eligible: 1')
            ->expectsOutputToContain('Total Pruned:   0')
            ->expectsOutputToContain('Dry run complete. No records were deleted')
            ->assertExitCode(0);

        // Record still exists in database
        $this->assertEquals(1, TenantNotification::count());
    }
}

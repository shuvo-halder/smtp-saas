<?php

namespace Tests\Feature;

use App\Console\Commands\SuspendExpiredTenants;
use App\Models\AbuseIncident;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\TenantNotification;
use App\Models\User;
use App\Services\Abuse\AbuseIncidentService;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\DnsVerificationService;
use App\Services\TenantNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $tenantA;
    protected User $tenantB;
    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name'                        => 'Pro Plan',
            'slug'                        => 'pro',
            'price_monthly'               => 29.99,
            'price_yearly'                => 299.99,
            'max_domains'                 => 5,
            'max_mailboxes_per_domain'    => 10,
            'storage_mb_per_mailbox'      => 1024,
            'daily_outbound_recipients'   => 1000,
            'is_active'                   => true,
        ]);

        $this->tenantA = User::factory()->create([
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->tenantB = User::factory()->create([
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);
    }

    /**
     * Unauthenticated users cannot access notifications.
     */
    public function test_unauthenticated_user_cannot_access_notifications(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/notifications/unread-count')->assertStatus(401);
        $this->postJson('/api/notifications/1/read')->assertStatus(401);
        $this->postJson('/api/notifications/mark-all-read')->assertStatus(401);
        $this->postJson('/api/notifications/1/dismiss')->assertStatus(401);
    }

    /**
     * Authenticated tenant can list their notifications and see unread count.
     */
    public function test_tenant_can_list_notifications_and_get_unread_count(): void
    {
        TenantNotification::create([
            'tenant_id'  => $this->tenantA->id,
            'category'   => 'domain_dns',
            'severity'   => 'warning',
            'title'      => 'DNS Check Warning',
            'message'    => 'MX record verification failed.',
            'action_url' => '/domains/1/setup',
        ]);

        TenantNotification::create([
            'tenant_id'  => $this->tenantA->id,
            'category'   => 'smtp_quota',
            'severity'   => 'info',
            'title'      => 'Sending Update',
            'message'    => 'Daily quota reset.',
            'action_url' => '/smtp',
        ]);

        $response = $this->actingAs($this->tenantA)->getJson('/api/notifications');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Sending Update')
            ->assertJsonPath('data.1.title', 'DNS Check Warning');

        $countResponse = $this->actingAs($this->tenantA)->getJson('/api/notifications/unread-count');
        $countResponse->assertStatus(200)
            ->assertJson(['unread_count' => 2]);
    }

    /**
     * Cross-tenant isolation: Tenant A cannot see, read, or dismiss Tenant B's notifications.
     */
    public function test_strict_cross_tenant_isolation_enforced(): void
    {
        $notifB = TenantNotification::create([
            'tenant_id'  => $this->tenantB->id,
            'category'   => 'security_abuse',
            'severity'   => 'critical',
            'title'      => 'Tenant B Secret Incident',
            'message'    => 'Compromised mailbox detected.',
            'action_url' => '/dashboard',
        ]);

        // Tenant A listing does not include Tenant B's notification
        $response = $this->actingAs($this->tenantA)->getJson('/api/notifications');
        $response->assertStatus(200)->assertJsonCount(0, 'data');

        // Tenant A unread count is 0
        $this->actingAs($this->tenantA)->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJson(['unread_count' => 0]);

        // Tenant A cannot mark Tenant B's notification as read
        $this->actingAs($this->tenantA)->postJson("/api/notifications/{$notifB->id}/read")
            ->assertStatus(404);

        // Tenant A cannot dismiss Tenant B's notification
        $this->actingAs($this->tenantA)->postJson("/api/notifications/{$notifB->id}/dismiss")
            ->assertStatus(404);

        // Verify Tenant B's notification remains unread and untouched
        $this->assertNull($notifB->fresh()->read_at);
        $this->assertNull($notifB->fresh()->dismissed_at);
    }

    /**
     * Mark-as-read state transition is idempotent.
     */
    public function test_mark_as_read_is_idempotent(): void
    {
        $notif = TenantNotification::create([
            'tenant_id'  => $this->tenantA->id,
            'category'   => 'billing_subscription',
            'severity'   => 'info',
            'title'      => 'Invoice Generated',
            'message'    => 'New invoice #1001 is available.',
            'action_url' => '/billing',
        ]);

        $this->assertNull($notif->read_at);

        // First call marks as read
        $res1 = $this->actingAs($this->tenantA)->postJson("/api/notifications/{$notif->id}/read");
        $res1->assertStatus(200)
            ->assertJsonPath('notification.is_read', true);

        $readAt = $notif->fresh()->read_at;
        $this->assertNotNull($readAt);

        // Second call is idempotent
        $res2 = $this->actingAs($this->tenantA)->postJson("/api/notifications/{$notif->id}/read");
        $res2->assertStatus(200)
            ->assertJsonPath('notification.is_read', true);

        // Unread count is now 0
        $this->actingAs($this->tenantA)->getJson('/api/notifications/unread-count')
            ->assertJson(['unread_count' => 0]);
    }

    /**
     * Mark-all-read bulk updates all unread notifications idempotently.
     */
    public function test_mark_all_read_bulk_update(): void
    {
        TenantNotification::create([
            'tenant_id' => $this->tenantA->id,
            'category'  => 'domain_dns',
            'severity'  => 'warning',
            'title'     => 'Notif 1',
            'message'   => 'Msg 1',
        ]);

        TenantNotification::create([
            'tenant_id' => $this->tenantA->id,
            'category'  => 'domain_dns',
            'severity'  => 'warning',
            'title'     => 'Notif 2',
            'message'   => 'Msg 2',
        ]);

        $this->actingAs($this->tenantA)->getJson('/api/notifications/unread-count')
            ->assertJson(['unread_count' => 2]);

        $res = $this->actingAs($this->tenantA)->postJson('/api/notifications/mark-all-read');
        $res->assertStatus(200)
            ->assertJson(['updated_count' => 2]);

        $this->actingAs($this->tenantA)->getJson('/api/notifications/unread-count')
            ->assertJson(['unread_count' => 0]);

        // Calling again updates 0
        $res2 = $this->actingAs($this->tenantA)->postJson('/api/notifications/mark-all-read');
        $res2->assertStatus(200)
            ->assertJson(['updated_count' => 0]);
    }

    /**
     * Dismissing a notification archives it from active list and unread count.
     */
    public function test_dismiss_archives_notification(): void
    {
        $notif = TenantNotification::create([
            'tenant_id' => $this->tenantA->id,
            'category'  => 'system',
            'severity'  => 'info',
            'title'     => 'Maintenance Notice',
            'message'   => 'Scheduled system maintenance on Sunday.',
        ]);

        $this->actingAs($this->tenantA)->getJson('/api/notifications/unread-count')
            ->assertJson(['unread_count' => 1]);

        $dismissRes = $this->actingAs($this->tenantA)->postJson("/api/notifications/{$notif->id}/dismiss");
        $dismissRes->assertStatus(200);

        $this->assertNotNull($notif->fresh()->dismissed_at);

        // Excluded from default active listing
        $listRes = $this->actingAs($this->tenantA)->getJson('/api/notifications');
        $listRes->assertStatus(200)->assertJsonCount(0, 'data');

        // Excluded from unread count
        $this->actingAs($this->tenantA)->getJson('/api/notifications/unread-count')
            ->assertJson(['unread_count' => 0]);
    }

    /**
     * Filter validation and bounded pagination.
     */
    public function test_filter_and_pagination_bounds(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            TenantNotification::create([
                'tenant_id' => $this->tenantA->id,
                'category'  => ($i % 2 === 0) ? 'domain_dns' : 'smtp_quota',
                'severity'  => ($i <= 10) ? 'critical' : 'info',
                'title'     => "Notification #{$i}",
                'message'   => "Body for notification #{$i}",
                'read_at'   => ($i <= 5) ? now() : null,
            ]);
        }

        // Bounded per_page (clamped 1-50, default 15)
        $resDefault = $this->actingAs($this->tenantA)->getJson('/api/notifications');
        $resDefault->assertStatus(200)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonCount(15, 'data');

        // Custom per_page clamped at 50
        $resPerPage = $this->actingAs($this->tenantA)->getJson('/api/notifications?per_page=100');
        $resPerPage->assertStatus(422); // Validation error: max:50

        // Filter status=unread
        $resUnread = $this->actingAs($this->tenantA)->getJson('/api/notifications?status=unread');
        $resUnread->assertStatus(200)
            ->assertJsonPath('meta.total', 20); // 25 total - 5 read = 20 unread

        // Filter status=read
        $resRead = $this->actingAs($this->tenantA)->getJson('/api/notifications?status=read');
        $resRead->assertStatus(200)
            ->assertJsonPath('meta.total', 5);

        // Filter category=domain_dns
        $resDns = $this->actingAs($this->tenantA)->getJson('/api/notifications?category=domain_dns');
        $resDns->assertStatus(200)
            ->assertJsonPath('meta.total', 12); // 12 even numbers between 1-25

        // Filter severity=critical
        $resCrit = $this->actingAs($this->tenantA)->getJson('/api/notifications?severity=critical');
        $resCrit->assertStatus(200)
            ->assertJsonPath('meta.total', 10);
    }

    /**
     * Alert deduplication suppresses repeated notifications for the same ongoing issue.
     */
    public function test_alert_deduplication_suppresses_repeated_alerts(): void
    {
        $service = app(TenantNotificationService::class);

        // Dispatch initial alert
        $notif1 = $service->notify(
            tenantId: $this->tenantA->id,
            category: 'domain_dns',
            severity: 'critical',
            title: 'DNS Drift Detected: acme.com',
            message: 'MX records are missing.',
            actionUrl: '/domains/1/setup',
            deduplicationKey: 'dns_drift:1:active'
        );

        $this->assertNotNull($notif1);

        // Repeated check 5 minutes later with same deduplication key
        $notif2 = $service->notify(
            tenantId: $this->tenantA->id,
            category: 'domain_dns',
            severity: 'critical',
            title: 'DNS Drift Detected: acme.com',
            message: 'MX records are missing.',
            actionUrl: '/domains/1/setup',
            deduplicationKey: 'dns_drift:1:active'
        );

        // Must return the exact same instance without creating a second record
        $this->assertEquals($notif1->id, $notif2->id);
        $this->assertEquals(1, TenantNotification::where('tenant_id', $this->tenantA->id)->count());
    }

    /**
     * New notification is created when an issue recurs after resolution.
     */
    public function test_notification_created_when_issue_recurs(): void
    {
        $service = app(TenantNotificationService::class);

        // Day 1: Quota exceeded
        $notifDay1 = $service->notify(
            tenantId: $this->tenantA->id,
            category: 'smtp_quota',
            severity: 'critical',
            title: 'Daily Outbound Quota Exceeded',
            message: 'Quota exceeded for 2026-10-27',
            deduplicationKey: "quota_exceeded:{$this->tenantA->id}:2026-10-27"
        );

        // Day 2: Quota exceeded again (new date/epoch in deduplication key)
        $notifDay2 = $service->notify(
            tenantId: $this->tenantA->id,
            category: 'smtp_quota',
            severity: 'critical',
            title: 'Daily Outbound Quota Exceeded',
            message: 'Quota exceeded for 2026-10-28',
            deduplicationKey: "quota_exceeded:{$this->tenantA->id}:2026-10-28"
        );

        $this->assertNotEquals($notifDay1->id, $notifDay2->id);
        $this->assertEquals(2, TenantNotification::where('tenant_id', $this->tenantA->id)->count());
    }

    /**
     * Sensitive internal credentials and system traces are strictly redacted from notification data.
     */
    public function test_sensitive_data_strictly_redacted(): void
    {
        $notif = TenantNotification::create([
            'tenant_id' => $this->tenantA->id,
            'category'  => 'security_abuse',
            'severity'  => 'critical',
            'title'     => 'Security Alert',
            'message'   => 'Bounce threshold exceeded',
            'data'      => [
                'domain_name'      => 'example.com',
                'password'         => 'SuperSecret123!',
                'private_key'      => '-----BEGIN RSA PRIVATE KEY-----...',
                'api_token'        => 'secret_tok_9999',
                'internal_trace'   => '/var/www/vendor/laravel/...',
                'safe_metric'      => '15%',
            ],
        ]);

        $response = $this->actingAs($this->tenantA)->getJson('/api/notifications');
        $response->assertStatus(200);

        $data = $response->json('data.0.data');
        $this->assertEquals('example.com', $data['domain_name']);
        $this->assertEquals('15%', $data['safe_metric']);

        // Sensitive fields redacted
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('private_key', $data);
        $this->assertArrayNotHasKey('api_token', $data);
        $this->assertArrayNotHasKey('internal_trace', $data);
    }

    /**
     * Subscription lifecycle: Payment verification triggers a success notification.
     */
    public function test_billing_payment_triggers_notification(): void
    {
        $invoice = Invoice::create([
            'user_id'        => $this->tenantA->id,
            'plan_id'        => $this->plan->id,
            'invoice_number' => 'INV-2026-TEST-001',
            'billing_cycle'  => 'monthly',
            'subtotal'       => 29.99,
            'total'          => 29.99,
            'status'         => 'pending',
            'due_date'       => now()->addDays(7),
        ]);

        $billingService = app(BillingService::class);
        $billingService->markInvoicePaid($invoice, ['bank_tran_id' => 'TRAN-123456']);

        $notification = TenantNotification::where('tenant_id', $this->tenantA->id)
            ->where('category', 'billing_subscription')
            ->first();

        $this->assertNotNull($notification);
        $this->assertEquals('success', $notification->severity);
        $this->assertStringContainsString('INV-2026-TEST-001', $notification->title);
        $this->assertEquals('/billing', $notification->action_url);
    }

    /**
     * Subscription lifecycle: SuspendExpiredTenants triggers critical expiration notification.
     */
    public function test_suspend_expired_tenants_triggers_notification(): void
    {
        $expiredTenant = User::factory()->create([
            'status'          => 'active',
            'plan_id'         => $this->plan->id,
            'plan_expires_at' => now()->subDay(),
            'is_admin'        => false,
        ]);

        $this->artisan(SuspendExpiredTenants::class)->assertExitCode(0);

        $notification = TenantNotification::where('tenant_id', $expiredTenant->id)
            ->where('category', 'billing_subscription')
            ->where('severity', 'critical')
            ->first();

        $this->assertNotNull($notification);
        $this->assertEquals('Subscription Expired', $notification->title);
        $this->assertEquals('/billing', $notification->action_url);
    }

    /**
     * Abuse incident creation triggers security alert notification for tenant.
     */
    public function test_abuse_incident_creation_triggers_notification(): void
    {
        $abuseService = app(AbuseIncidentService::class);

        $incident = $abuseService->record([
            'tenant_id'        => $this->tenantA->id,
            'incident_type'    => 'high_hard_bounce_rate',
            'severity'         => 'critical',
            'summary'          => 'Hard bounce rate reached 14%',
            'detection_source' => 'log_parser',
        ]);

        $this->assertNotNull($incident);

        $notification = TenantNotification::where('tenant_id', $this->tenantA->id)
            ->where('category', 'security_abuse')
            ->first();

        $this->assertNotNull($notification);
        $this->assertEquals('critical', $notification->severity);
        $this->assertStringContainsString('High hard bounce rate', $notification->title);
        $this->assertEquals('Hard bounce rate reached 14%', $notification->message);
    }

    /**
     * DNS drift detection triggers critical notification and recovery restores it.
     */
    public function test_dns_drift_detection_and_recovery_triggers_notifications(): void
    {
        $domain = Domain::create([
            'user_id'       => $this->tenantA->id,
            'domain_name'   => 'drift-test.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $dnsService = app(DnsVerificationService::class);
        $this->app->instance(DnsVerificationService::class, $dnsService);

        // Inject mock resolver that fails MX and SPF (drift occurred)
        $dnsService->setResolver(function (string $hostname, int $type) {
            return []; // Empty results -> fail
        });

        $this->actingAs($this->tenantA)
            ->postJson("/api/domains/{$domain->id}/verify-dns")
            ->assertStatus(200);

        $driftNotif = TenantNotification::where('tenant_id', $this->tenantA->id)
            ->where('category', 'domain_dns')
            ->where('severity', 'critical')
            ->first();

        $this->assertNotNull($driftNotif);
        $this->assertStringContainsString('DNS Drift Detected', $driftNotif->title);
        $this->assertStringContainsString('drift-test.com', $driftNotif->message);

        $expectedHost = config('mail.mail_server_host') ?: config('app.mail_server_host') ?: 'mail.mailsaas.com';
        $testDkimKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0';

        // Update domain key to match mock
        $domain->update([
            'dkim_public_key' => $testDkimKey,
            'dkim_selector'   => 'default',
        ]);

        // Now inject mock resolver that passes all records (recovery)
        $dnsService->setResolver(function (string $hostname, int $type) use ($expectedHost, $testDkimKey) {
            if ($type === DNS_MX) {
                return [['target' => $expectedHost, 'pri' => 10]];
            }
            if ($type === DNS_TXT) {
                if (str_starts_with($hostname, 'default._domainkey')) {
                    return [['txt' => "v=DKIM1; k=rsa; p={$testDkimKey}"]];
                }
                return [['txt' => "v=spf1 mx a:{$expectedHost} ~all"]];
            }
            return [];
        });

        $this->actingAs($this->tenantA)
            ->postJson("/api/domains/{$domain->id}/verify-dns")
            ->assertStatus(200);

        // Verify recovery notification was emitted
        $recoveryNotif = TenantNotification::where('tenant_id', $this->tenantA->id)
            ->where('category', 'domain_dns')
            ->where('severity', 'success')
            ->first();

        $this->assertNotNull($recoveryNotif);
        $this->assertStringContainsString('DNS Authentication Restored', $recoveryNotif->title);

        // Verify the original drift notification was marked as read
        $this->assertTrue($driftNotif->fresh()->isRead());
    }

    /**
     * Mailbox rule modifications trigger info notifications.
     */
    public function test_mailbox_rule_changes_trigger_notifications(): void
    {
        $domain = Domain::create([
            'user_id'       => $this->tenantA->id,
            'domain_name'   => 'rules-test.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $mailbox = Mailbox::create([
            'domain_id'   => $domain->id,
            'email'       => 'alex@rules-test.com',
            'local_part'  => 'alex',
            'is_active'   => true,
            'storage_mb'  => 1024,
            'password'    => 'hashed_password',
        ]);

        // 1. Update forwarding
        $this->actingAs($this->tenantA)
            ->putJson("/api/mailboxes/{$mailbox->id}/forwarding", [
                'is_forwarding_enabled'   => true,
                'destinations'            => ['target@gmail.com'],
                'keep_copy'               => true,
            ])
            ->assertStatus(200);

        $fwdNotif = TenantNotification::where('tenant_id', $this->tenantA->id)
            ->where('category', 'mailbox_rule')
            ->where('title', 'like', '%Mail Forwarding Updated%')
            ->first();

        $this->assertNotNull($fwdNotif);
        $this->assertStringContainsString('target@gmail.com', $fwdNotif->message);

        // 2. Update auto-reply
        $this->actingAs($this->tenantA)
            ->putJson("/api/mailboxes/{$mailbox->id}/auto-reply", [
                'auto_reply_enabled' => true,
                'subject'            => 'On Vacation',
                'body'               => 'I will return on Monday.',
                'start_date'         => now()->format('Y-m-d'),
                'end_date'           => now()->addDays(5)->format('Y-m-d'),
            ])
            ->assertStatus(200);

        $replyNotif = TenantNotification::where('tenant_id', $this->tenantA->id)
            ->where('category', 'mailbox_rule')
            ->where('title', 'like', '%Auto-Reply Configured%')
            ->first();

        $this->assertNotNull($replyNotif);
        $this->assertStringContainsString('alex@rules-test.com', $replyNotif->title);
    }

    /**
     * Mailbox active status toggle triggers notification.
     */
    public function test_mailbox_toggle_triggers_notification(): void
    {
        $domain = Domain::create([
            'user_id'       => $this->tenantA->id,
            'domain_name'   => 'toggle-test.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $mailbox = Mailbox::create([
            'domain_id'   => $domain->id,
            'email'       => 'sarah@toggle-test.com',
            'local_part'  => 'sarah',
            'is_active'   => true,
            'storage_mb'  => 1024,
            'password'    => 'hashed_password',
        ]);

        $this->actingAs($this->tenantA)
            ->postJson("/api/mailboxes/{$mailbox->id}/toggle")
            ->assertStatus(200);

        $toggleNotif = TenantNotification::where('tenant_id', $this->tenantA->id)
            ->where('category', 'mailbox_rule')
            ->where('title', 'like', '%Mailbox Status Changed%')
            ->first();

        $this->assertNotNull($toggleNotif);
        $this->assertStringContainsString('disabled', $toggleNotif->message);
    }
}

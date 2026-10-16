<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\MailboxStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantMailboxStorageTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private User $tenant;
    private User $otherTenant;
    private Domain $domain;
    private Domain $otherDomain;
    private Mailbox $mailbox;
    private Mailbox $otherMailbox;

    protected function setUp(): void
    {
        parent::setUp();

        MailboxStorageService::restore();

        $this->plan = Plan::create([
            'name'                              => 'Business Cloud',
            'slug'                              => 'business-cloud',
            'description'                       => 'Standard plan with 2GB storage cap',
            'max_domains'                       => 3,
            'max_mailboxes_per_domain'          => 10,
            'storage_mb_per_mailbox'            => 2048, // 2 GB
            'max_aliases_per_domain'            => 10,
            'daily_outbound_recipients'         => 1000,
            'mailbox_daily_outbound_recipients' => 200,
            'price_monthly'                     => 2000.00,
            'price_yearly'                      => 20000.00,
            'features'                          => ['Custom Domain', 'Webmail'],
            'is_active'                         => true,
            'is_featured'                       => true,
            'sort_order'                        => 1,
        ]);

        $this->tenant = User::create([
            'name'            => 'Storage Tenant',
            'email'           => 'tenant_storage@example.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Storage Corp',
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->otherTenant = User::create([
            'name'            => 'Other Tenant',
            'email'           => 'other_storage@example.com',
            'password'        => bcrypt('Password123!'),
            'company_name'    => 'Other Corp',
            'plan_id'         => $this->plan->id,
            'status'          => 'active',
            'plan_expires_at' => now()->addDays(30),
            'is_admin'        => false,
        ]);

        $this->domain = Domain::create([
            'user_id'       => $this->tenant->id,
            'domain_name'   => 'storage-corp.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $this->otherDomain = Domain::create([
            'user_id'       => $this->otherTenant->id,
            'domain_name'   => 'other-corp.com',
            'status'        => 'active',
            'mx_verified'   => true,
            'spf_verified'  => true,
            'dkim_verified' => true,
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id'    => $this->domain->id,
            'local_part'   => 'alice',
            'email'        => 'alice@storage-corp.com',
            'password'     => bcrypt('Secret123!'),
            'display_name' => 'Alice Admin',
            'quota_mb'     => 1024,
            'is_active'    => true,
        ]);

        $this->otherMailbox = Mailbox::create([
            'domain_id'    => $this->otherDomain->id,
            'local_part'   => 'bob',
            'email'        => 'bob@other-corp.com',
            'password'     => bcrypt('Secret123!'),
            'display_name' => 'Bob Foreign',
            'quota_mb'     => 1024,
            'is_active'    => true,
        ]);
    }

    protected function tearDown(): void
    {
        MailboxStorageService::restore();
        parent::tearDown();
    }

    /**
     * 1. Unauthenticated access is rejected across all storage endpoints.
     */
    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/mailboxes/storage')->assertStatus(401);
        $this->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage')->assertStatus(401);
    }

    /**
     * 2. Suspended tenant is denied access.
     */
    public function test_suspended_tenant_is_blocked(): void
    {
        $this->tenant->update(['status' => 'suspended']);

        $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/storage')
            ->assertStatus(403);

        $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage')
            ->assertStatus(403);
    }

    /**
     * 3. Strict tenant isolation: Tenant A cannot access Tenant B's mailbox storage.
     */
    public function test_cross_tenant_access_is_forbidden(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->otherMailbox->id . '/storage');

        $response->assertStatus(403);
    }

    /**
     * 4. Plan limit resolution & unmeasured telemetry on local environment.
     */
    public function test_mailbox_storage_reports_plan_limit_and_unmeasured_state_on_local(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage');

        $response->assertStatus(200)
            ->assertJsonPath('data.mailbox_id', $this->mailbox->id)
            ->assertJsonPath('data.email', 'alice@storage-corp.com')
            ->assertJsonPath('data.quota_mb', 1024)
            ->assertJsonPath('data.quota_formatted', '1 GB')
            ->assertJsonPath('data.plan_limit_mb', 2048)
            ->assertJsonPath('data.usage_bytes', null)
            ->assertJsonPath('data.usage_mb', null)
            ->assertJsonPath('data.usage_formatted', 'Unavailable')
            ->assertJsonPath('data.utilization_percent', null)
            ->assertJsonPath('data.status', 'unknown')
            ->assertJsonPath('data.is_measured', false)
            ->assertJsonPath('data.measurement_source', 'none');

        $this->assertNotEmpty($response->json('data.warning'));
    }

    /**
     * 5. Calculated status with measured telemetry: Normal (< 80%).
     */
    public function test_measured_telemetry_normal_status(): void
    {
        // 200 MB on 1024 MB quota (~19.5%)
        MailboxStorageService::fake(function (Mailbox $mb) {
            return [
                'bytes'       => 200 * 1048576,
                'source'      => 'mock_doveadm',
                'measured_at' => now()->toIso8601String(),
                'warning'     => null,
            ];
        });

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_measured', true)
            ->assertJsonPath('data.usage_mb', 200)
            ->assertJsonPath('data.status', 'normal')
            ->assertJsonPath('data.status_label', 'Normal')
            ->assertJsonPath('data.utilization_percent', 19.5);
    }

    /**
     * 6. Calculated status: Warning (80% - 89.9%).
     */
    public function test_measured_telemetry_warning_status(): void
    {
        // 850 MB on 1024 MB quota (~83.0%)
        MailboxStorageService::fake(function (Mailbox $mb) {
            return [
                'bytes'       => 850 * 1048576,
                'source'      => 'mock_doveadm',
                'measured_at' => now()->toIso8601String(),
            ];
        });

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'warning')
            ->assertJsonPath('data.status_label', 'Warning (80%+)')
            ->assertJsonPath('data.utilization_percent', 83);
    }

    /**
     * 7. Calculated status: Near Limit (90% - 99.9%).
     */
    public function test_measured_telemetry_near_limit_status(): void
    {
        // 950 MB on 1024 MB quota (~92.8%)
        MailboxStorageService::fake(function (Mailbox $mb) {
            return [
                'bytes'       => 950 * 1048576,
                'source'      => 'mock_doveadm',
                'measured_at' => now()->toIso8601String(),
            ];
        });

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'near_limit')
            ->assertJsonPath('data.status_label', 'Near Limit (90%+)')
            ->assertJsonPath('data.utilization_percent', 92.8);
    }

    /**
     * 8. Calculated status: Over Limit (>= 100%).
     */
    public function test_measured_telemetry_over_limit_status(): void
    {
        // 1100 MB on 1024 MB quota (~107.4%)
        MailboxStorageService::fake(function (Mailbox $mb) {
            return [
                'bytes'       => 1100 * 1048576,
                'source'      => 'mock_doveadm',
                'measured_at' => now()->toIso8601String(),
            ];
        });

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'over_limit')
            ->assertJsonPath('data.status_label', 'Over Limit (100%+)')
            ->assertJsonPath('data.remaining_bytes', 0)
            ->assertJsonPath('data.utilization_percent', 107.4);
    }

    /**
     * 9. Unlimited quota handling (-1) avoids division by zero.
     */
    public function test_unlimited_quota_handling(): void
    {
        $this->mailbox->update(['quota_mb' => -1]);

        MailboxStorageService::fake(function (Mailbox $mb) {
            return [
                'bytes'       => 500 * 1048576,
                'source'      => 'mock_doveadm',
                'measured_at' => now()->toIso8601String(),
            ];
        });

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id . '/storage');

        $response->assertStatus(200)
            ->assertJsonPath('data.quota_mb', -1)
            ->assertJsonPath('data.quota_formatted', 'Unlimited')
            ->assertJsonPath('data.usage_mb', 500)
            ->assertJsonPath('data.remaining_bytes', null)
            ->assertJsonPath('data.remaining_formatted', 'Unlimited')
            ->assertJsonPath('data.utilization_percent', 0)
            ->assertJsonPath('data.status', 'normal');
    }

    /**
     * 10. Tenant storage overview aggregates summary metrics and respects filters.
     */
    public function test_tenant_storage_overview_aggregates_metrics(): void
    {
        // Create second mailbox for tenant
        Mailbox::create([
            'domain_id'    => $this->domain->id,
            'local_part'   => 'charlie',
            'email'        => 'charlie@storage-corp.com',
            'password'     => bcrypt('Secret123!'),
            'display_name' => 'Charlie User',
            'quota_mb'     => 512,
            'is_active'    => true,
        ]);

        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/storage');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_mailboxes', 2)
            ->assertJsonPath('summary.active_mailboxes', 2)
            ->assertJsonPath('summary.total_quota_mb', 1536)
            ->assertJsonPath('summary.total_quota_formatted', '1.5 GB')
            ->assertJsonPath('summary.plan_storage_limit_per_mailbox_mb', 2048)
            ->assertJsonPath('summary.status_counts.unknown', 2)
            ->assertJsonCount(2, 'data');
    }

    /**
     * 11. Mailbox resource embedding includes storage details.
     */
    public function test_mailbox_resource_includes_storage_payload(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/' . $this->mailbox->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->mailbox->id)
            ->assertJsonPath('data.email', 'alice@storage-corp.com')
            ->assertJsonPath('data.storage.quota_mb', 1024)
            ->assertJsonPath('data.storage.status', 'unknown');
    }

    /**
     * 12. Tenant cannot set quota exceeding plan storage limit upon creation.
     */
    public function test_cannot_create_mailbox_exceeding_plan_storage_limit(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->postJson('/api/domains/' . $this->domain->id . '/mailboxes', [
                'local_part' => 'oversized',
                'password'   => 'StrongPassword123!',
                'quota_mb'   => 5000, // Plan limit is 2048 MB
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Mailbox storage quota cannot exceed plan limit of 2048 MB.');
    }

    /**
     * 13. Tenant cannot increase mailbox quota through update endpoint.
     */
    public function test_quota_is_immutable_on_tenant_update(): void
    {
        $response = $this->actingAs($this->tenant, 'sanctum')
            ->putJson('/api/mailboxes/' . $this->mailbox->id, [
                'display_name' => 'Alice Renamed',
                'quota_mb'     => 4000, // Attempting to sneakily increase quota
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.display_name', 'Alice Renamed')
            ->assertJsonPath('data.quota_mb', 1024); // Quota remains strictly 1024

        $this->assertEquals(1024, $this->mailbox->fresh()->quota_mb);
    }

    /**
     * 14. Invalid mailbox ID returns HTTP 404.
     */
    public function test_invalid_mailbox_id_returns_404(): void
    {
        $this->actingAs($this->tenant, 'sanctum')
            ->getJson('/api/mailboxes/999999/storage')
            ->assertStatus(404);
    }
}

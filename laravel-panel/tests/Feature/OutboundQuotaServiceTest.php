<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Plan;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\OutboundQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Carbon;
use Exception;

class OutboundQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private OutboundQuotaService $service;
    private User $tenant;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OutboundQuotaService();

        $plan = Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'price_monthly' => 10,
            'price_yearly' => 100,
            'daily_outbound_recipients' => 100,
            'mailbox_daily_outbound_recipients' => 50,
        ]);

        $this->tenant = User::factory()->create([
            'plan_id' => $plan->id,
        ]);

        $domain = Domain::create([
            'user_id' => $this->tenant->id,
            'domain_name' => 'example.com',
            'status' => 'active',
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $domain->id,
            'local_part' => 'user',
            'email' => 'user@example.com',
            'password' => 'secret',
            'quota_mb' => 1024,
            'is_active' => true,
        ]);
    }

    public function test_invalid_recipient_count_rejected()
    {
        $result = $this->service->consume($this->tenant, $this->mailbox, 0);
        $this->assertEquals('INVALID_REQUEST', $result['status']);

        $result = $this->service->consume($this->tenant, $this->mailbox, -5);
        $this->assertEquals('INVALID_REQUEST', $result['status']);
    }

    public function test_tenant_mismatch_rejected()
    {
        $otherTenant = User::factory()->create();
        $result = $this->service->consume($otherTenant, $this->mailbox, 5);
        $this->assertEquals('INVALID_REQUEST', $result['status']);
    }

    public function test_quota_disabled()
    {
        $this->tenant->plan->update(['daily_outbound_recipients' => 0]);
        $result = $this->service->consume($this->tenant, $this->mailbox, 5);
        $this->assertEquals('REJECTED_QUOTA', $result['status']);

        $this->tenant->plan->update(['daily_outbound_recipients' => 100, 'mailbox_daily_outbound_recipients' => 0]);
        $result = $this->service->consume($this->tenant, $this->mailbox, 5);
        $this->assertEquals('REJECTED_QUOTA', $result['status']);
    }

    public function test_unlimited_quotas_bypasses_redis()
    {
        $this->tenant->plan->update(['daily_outbound_recipients' => -1, 'mailbox_daily_outbound_recipients' => -1]);
        
        Redis::shouldReceive('eval')->never();

        $result = $this->service->consume($this->tenant, $this->mailbox, 5);
        
        $this->assertEquals('ALLOWED', $result['status']);
    }

    public function test_allowed_request()
    {
        Redis::shouldReceive('eval')
             ->once()
             ->withArgs(function ($script, $numkeys, $tenantKey, $mailboxKey, $requested, $tenantLimit, $mailboxLimit, $ttl) {
                 return $requested === 10 && $tenantLimit === 100 && $mailboxLimit === 50;
             })
             ->andReturn(1);

        $result = $this->service->consume($this->tenant, $this->mailbox, 10);
        $this->assertEquals('ALLOWED', $result['status']);
        $this->assertEquals(10, $result['consumed']);
    }

    public function test_rejected_request()
    {
        Redis::shouldReceive('eval')
             ->once()
             ->andReturn(0);

        $result = $this->service->consume($this->tenant, $this->mailbox, 60); // Exceeds mailbox limit of 50
        $this->assertEquals('REJECTED_QUOTA', $result['status']);
    }

    public function test_redis_failure_fails_open()
    {
        Redis::shouldReceive('eval')
             ->once()
             ->andThrow(new Exception('Redis is down'));

        $result = $this->service->consume($this->tenant, $this->mailbox, 5);
        
        $this->assertEquals('FAIL_OPEN_REDIS', $result['status']);
        $this->assertEquals(5, $result['consumed']);
    }
}

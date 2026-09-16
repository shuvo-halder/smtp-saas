<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\Policy\PolicyDecisionService;
use App\Services\Policy\PolicyRequest;
use App\Services\Policy\PolicyResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class PolicyDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    private PolicyDecisionService $service;
    private User $tenant;
    private Domain $domain;
    private Mailbox $mailbox;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PolicyDecisionService::class);

        $this->plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox' => 1024,
            'max_aliases_per_domain' => 10,
            'price_monthly' => 29.99,
            'price_yearly' => 299.99,
            'daily_outbound_recipients' => 500,
            'mailbox_daily_outbound_recipients' => 100,
        ]);

        $this->tenant = User::factory()->create([
            'status' => 'active',
            'plan_id' => $this->plan->id,
            'plan_expires_at' => now()->addDays(30),
        ]);

        $this->domain = Domain::create([
            'user_id' => $this->tenant->id,
            'domain_name' => 'example.com',
            'status' => 'active',
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $this->domain->id,
            'local_part' => 'sender',
            'email' => 'sender@example.com',
            'password' => 'secret',
            'quota_mb' => 1024,
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_request_bypasses_quota_and_returns_dunno()
    {
        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sender' => 'remote@external.org',
            'recipient_count' => '1',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertEquals(PolicyResponse::ACTION_DUNNO, $response->action);
        $this->assertEquals('ALLOWED', $response->status);
    }

    public function test_unknown_mailbox_returns_reject_auth()
    {
        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'nonexistent@example.com',
            'recipient_count' => '1',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertStringStartsWith('REJECT 554 5.7.1', $response->action);
        $this->assertEquals('REJECTED_AUTH', $response->status);
    }

    public function test_inactive_mailbox_returns_reject_suspended()
    {
        $this->mailbox->update(['is_active' => false]);

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '1',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertStringStartsWith('REJECT 554 5.7.1', $response->action);
        $this->assertEquals('REJECTED_SUSPENDED', $response->status);
    }

    public function test_inactive_domain_returns_reject_suspended()
    {
        $this->domain->update(['status' => 'suspended']);

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '1',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertStringStartsWith('REJECT 554 5.7.1', $response->action);
        $this->assertEquals('REJECTED_SUSPENDED', $response->status);
    }

    public function test_suspended_tenant_returns_reject_suspended()
    {
        $this->tenant->update(['status' => 'suspended']);

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '1',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertStringStartsWith('REJECT 554 5.7.1', $response->action);
        $this->assertEquals('REJECTED_SUSPENDED', $response->status);
    }

    public function test_expired_tenant_returns_reject_suspended()
    {
        $this->tenant->update(['plan_expires_at' => now()->subDay()]);

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '1',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertStringStartsWith('REJECT 554 5.7.1', $response->action);
        $this->assertEquals('REJECTED_SUSPENDED', $response->status);
    }

    public function test_allowed_request_under_quota_returns_dunno()
    {
        Redis::shouldReceive('eval')
            ->once()
            ->andReturn(1); // Lua returns 1 for allow

        Redis::shouldReceive('get')
            ->andReturn(null);

        Redis::shouldReceive('setex')
            ->once();

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '5',
            'instance' => 'tx12345',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertEquals(PolicyResponse::ACTION_DUNNO, $response->action);
        $this->assertEquals('ALLOWED', $response->status);
    }

    public function test_quota_exceeded_returns_reject_quota()
    {
        Redis::shouldReceive('eval')
            ->once()
            ->andReturn(0); // Lua returns 0 for reject

        Redis::shouldReceive('get')
            ->andReturn(null);

        Redis::shouldReceive('setex')
            ->once();

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '150',
            'instance' => 'tx12346',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertEquals(PolicyResponse::REJECT_QUOTA, $response->action);
        $this->assertEquals('REJECTED_QUOTA', $response->status);
    }

    public function test_redis_failure_fails_open_with_dunno()
    {
        Redis::shouldReceive('eval')
            ->once()
            ->andThrow(new \Exception('Redis connection dropped'));

        Redis::shouldReceive('get')
            ->andReturn(null);

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '2',
            'instance' => 'tx12347',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertEquals(PolicyResponse::ACTION_DUNNO, $response->action);
        $this->assertTrue($response->isFailOpen);
    }

    public function test_idempotent_instance_cache_replays_action_without_evaluating_quota()
    {
        // Redis returns cached action
        Redis::shouldReceive('get')
            ->once()
            ->with('outbound:policy:tx:replayed123')
            ->andReturn(PolicyResponse::ACTION_DUNNO);

        // eval should NOT be called because it was cached!
        Redis::shouldReceive('eval')->never();

        $request = PolicyRequest::fromAttributes([
            'request' => 'smtpd_access_policy',
            'protocol_state' => 'DATA',
            'sasl_username' => 'sender@example.com',
            'recipient_count' => '5',
            'instance' => 'replayed123',
        ]);

        $response = $this->service->evaluate($request);

        $this->assertEquals(PolicyResponse::ACTION_DUNNO, $response->action);
        $this->assertEquals('CACHED', $response->status);
    }
}

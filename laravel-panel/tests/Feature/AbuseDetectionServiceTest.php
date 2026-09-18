<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\TenantOutboundUsage;
use App\Models\User;
use App\Services\Abuse\AbuseAttributionService;
use App\Services\Abuse\AbuseDetectionService;
use App\Services\Abuse\BounceClassificationService;
use App\Services\Abuse\NormalizedMailEvent;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class AbuseDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AbuseDetectionService $service;
    private User $tenant;
    private Mailbox $mailbox;
    private string $todayUtc;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic-plan',
            'max_domains' => 2,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 500,
            'price_monthly' => 10,
            'price_yearly' => 100,
            'is_active' => true,
        ]);

        $this->tenant = User::create([
            'name' => 'Demo Tenant',
            'email' => 'owner@democorp.com',
            'password' => bcrypt('password'),
            'status' => 'active',
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $domain = Domain::create([
            'user_id' => $this->tenant->id,
            'domain_name' => 'democorp.com',
            'status' => 'active',
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $domain->id,
            'local_part' => 'info',
            'email' => 'info@democorp.com',
            'password' => 'secret_hash',
            'is_active' => true,
        ]);

        $this->todayUtc = Carbon::now('UTC')->format('Y-m-d');

        $this->service = new AbuseDetectionService(
            new BounceClassificationService(),
            new AbuseAttributionService()
        );

        Config::set('mail_abuse.min_recipients_for_rate_check', 20);
        Config::set('mail_abuse.hard_bounce_rate_threshold', 0.10);
        Config::set('mail_abuse.daily_hard_bounce_max', 50);
        Config::set('mail_abuse.consecutive_hard_bounces_max', 15);
    }

    public function test_qmgr_event_saves_queue_sender_correlation(): void
    {
        Redis::shouldReceive('setex')
            ->once()
            ->withArgs(function ($key, $ttl, $val) {
                $decoded = json_decode($val, true);
                return $key === 'outbound:abuse:qid:4Y1z9M2dZ1z3x4y'
                    && $ttl === 86400
                    && $decoded['sender'] === 'info@democorp.com';
            });

        $event = new NormalizedMailEvent(
            queueId: '4Y1z9M2dZ1z3x4y',
            daemon: 'postfix/qmgr',
            eventType: NormalizedMailEvent::TYPE_QMGR_FROM,
            sender: 'info@democorp.com'
        );

        $result = $this->service->processEvent($event);

        $this->assertTrue($result['processed']);
        $this->assertEquals('QUEUE_CORRELATED', $result['action']);
    }

    public function test_delivery_event_correlates_sender_and_increments_hard_bounces(): void
    {
        // 1. Retrieve sender from Queue ID
        Redis::shouldReceive('get')
            ->once()
            ->with('outbound:abuse:qid:QID8888')
            ->andReturn(json_encode(['sender' => 'info@democorp.com']));

        // 2. Increments for Tenant Daily Hard Bounce
        Redis::shouldReceive('incr')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn(1);

        Redis::shouldReceive('expire')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}", 172800);

        // 3. Increments for Mailbox Daily Hard Bounce
        Redis::shouldReceive('incr')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn(1);

        Redis::shouldReceive('expire')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:bounces:hard:daily:{$this->todayUtc}", 172800);

        // 4. Increments for Mailbox Consecutive Hard Bounces
        Redis::shouldReceive('incr')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn(1);

        Redis::shouldReceive('expire')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard", 172800);

        // 5. Cleanup Queue ID mapping on terminal delivery
        Redis::shouldReceive('del')
            ->once()
            ->with('outbound:abuse:qid:QID8888');

        // 6. Threshold evaluation getters
        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn('1');

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn('1');

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}")
            ->andReturn('50');

        $event = new NormalizedMailEvent(
            queueId: 'QID8888',
            daemon: 'postfix/smtp',
            eventType: NormalizedMailEvent::TYPE_DELIVERY_STATUS,
            recipient: 'baduser@remote.com',
            dsn: '5.1.1',
            status: 'bounced',
            smtpCode: 550
        );

        $result = $this->service->processEvent($event);

        $this->assertTrue($result['processed']);
        $this->assertEquals('EVALUATED', $result['action']);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_HARD_BOUNCE, $result['classification']);
        $this->assertEquals($this->tenant->id, $result['tenant_id']);
        $this->assertEquals($this->mailbox->id, $result['mailbox_id']);
    }

    public function test_delivery_success_resets_consecutive_hard_bounces(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->with('outbound:abuse:qid:QID9999')
            ->andReturn(json_encode(['sender' => 'info@democorp.com']));

        // Del consecutive hard counter
        Redis::shouldReceive('del')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard");

        // Cleanup Queue ID
        Redis::shouldReceive('del')
            ->once()
            ->with('outbound:abuse:qid:QID9999');

        // Threshold evaluation
        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn('0');

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn('0');

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}")
            ->andReturn('10');

        $event = new NormalizedMailEvent(
            queueId: 'QID9999',
            daemon: 'postfix/smtp',
            eventType: NormalizedMailEvent::TYPE_DELIVERY_STATUS,
            recipient: 'good@remote.com',
            dsn: '2.0.0',
            status: 'sent',
            smtpCode: 250
        );

        $result = $this->service->processEvent($event);

        $this->assertTrue($result['processed']);
        $this->assertEquals('EVALUATED', $result['action']);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_SUCCESS, $result['classification']);
    }

    public function test_rate_threshold_triggers_alert_when_minimum_sample_met(): void
    {
        // 100 attempted recipients in Step 14 ledger
        TenantOutboundUsage::create([
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 100,
        ]);

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn('15'); // 15 hard bounces / 100 attempts = 15% rate >= 10% threshold

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn('2');

        // Cooldown acquisition
        Redis::shouldReceive('set')
            ->once()
            ->with("outbound:abuse:alert:cooldown:{$this->tenant->id}:HIGH_HARD_BOUNCE_RATE:{$this->todayUtc}", '1', 'EX', 86400, 'NX')
            ->andReturn(true);

        $alerts = $this->service->evaluateThresholds($this->tenant->id, $this->mailbox->id, $this->todayUtc);

        $this->assertContains('HIGH_HARD_BOUNCE_RATE', $alerts);
    }

    public function test_rate_threshold_ignored_when_sample_below_minimum(): void
    {
        // Only 5 attempted recipients (below minimum 20)
        TenantOutboundUsage::create([
            'user_id' => $this->tenant->id,
            'usage_date' => $this->todayUtc,
            'recipient_count' => 5,
        ]);

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn('2'); // 40% rate, but sample is only 5 < 20

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn('2');

        $alerts = $this->service->evaluateThresholds($this->tenant->id, $this->mailbox->id, $this->todayUtc);

        $this->assertNotContains('HIGH_HARD_BOUNCE_RATE', $alerts);
    }

    public function test_consecutive_hard_bounce_alert_triggered(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn('15');

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn('16'); // 16 consecutive >= 15 threshold

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}")
            ->andReturn('0');

        // Cooldown acquisition for consecutive
        Redis::shouldReceive('set')
            ->once()
            ->with("outbound:abuse:alert:cooldown:{$this->tenant->id}:CONSECUTIVE_HARD_BOUNCES_EXCEEDED:{$this->todayUtc}", '1', 'EX', 86400, 'NX')
            ->andReturn(true);

        $alerts = $this->service->evaluateThresholds($this->tenant->id, $this->mailbox->id, $this->todayUtc);

        $this->assertContains('CONSECUTIVE_HARD_BOUNCES_EXCEEDED', $alerts);
    }

    public function test_alert_cooldown_suppresses_duplicate_alert(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn('60'); // Exceeds daily max 50

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn('0');

        Redis::shouldReceive('get')
            ->once()
            ->with("outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}")
            ->andReturn('0');

        // Cooldown already held in Redis (returns false/null)
        Redis::shouldReceive('set')
            ->once()
            ->with("outbound:abuse:alert:cooldown:{$this->tenant->id}:DAILY_HARD_BOUNCE_LIMIT_EXCEEDED:{$this->todayUtc}", '1', 'EX', 86400, 'NX')
            ->andReturn(false);

        $alerts = $this->service->evaluateThresholds($this->tenant->id, $this->mailbox->id, $this->todayUtc);

        // Suppressed by cooldown
        $this->assertEmpty($alerts);
    }

    public function test_redis_failure_fails_safely_without_crashing(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->with('outbound:abuse:qid:FAIL123')
            ->andThrow(new Exception('Redis connection lost'));

        $event = new NormalizedMailEvent(
            queueId: 'FAIL123',
            daemon: 'postfix/smtp',
            eventType: NormalizedMailEvent::TYPE_DELIVERY_STATUS,
            recipient: 'any@remote.com',
            status: 'bounced'
        );

        $result = $this->service->processEvent($event);

        $this->assertFalse($result['processed']);
        $this->assertEquals('UNATTRIBUTED_DELIVERY', $result['action']);
    }

    public function test_dry_run_does_not_mutate_redis(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->with('outbound:abuse:qid:DRY123')
            ->andReturn(json_encode(['sender' => 'info@democorp.com']));

        // In dry-run mode, NO incr, del, or set calls should be made
        Redis::shouldReceive('incr')->never();
        Redis::shouldReceive('del')->never();
        Redis::shouldReceive('set')->never();

        $event = new NormalizedMailEvent(
            queueId: 'DRY123',
            daemon: 'postfix/smtp',
            eventType: NormalizedMailEvent::TYPE_DELIVERY_STATUS,
            recipient: 'bad@remote.com',
            dsn: '5.1.1',
            status: 'bounced'
        );

        $result = $this->service->processEvent($event, dryRun: true);

        $this->assertTrue($result['processed']);
        $this->assertEquals('EVALUATED', $result['action']);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_HARD_BOUNCE, $result['classification']);
    }

    public function test_intermediate_filter_handoff_saves_alias_and_does_not_affect_bounce_metrics(): void
    {
        Redis::shouldReceive('setex')
            ->once()
            ->with('outbound:abuse:qid_alias:NEW_QID_123', 86400, 'OLD_QID_456');

        // Verify that consecutive bounce reset or bounce metric increments NEVER happen
        Redis::shouldReceive('incr')->never();
        Redis::shouldReceive('del')->never();

        $event = new NormalizedMailEvent(
            queueId: 'OLD_QID_456',
            daemon: 'postfix/smtp-amavis',
            eventType: NormalizedMailEvent::TYPE_INTERMEDIATE_FILTER_HANDOFF,
            recipient: 'any@remote.com',
            status: 'sent',
            reinjectedQueueId: 'NEW_QID_123'
        );

        $result = $this->service->processEvent($event);

        $this->assertTrue($result['processed']);
        $this->assertEquals('FILTER_HANDOFF_RECORDED', $result['action']);
        $this->assertNull($result['classification']);
        $this->assertEmpty($result['alerts']);
    }

    public function test_delivery_resolves_sender_via_queue_id_alias_fallback(): void
    {
        // 1. Direct QID lookup fails
        Redis::shouldReceive('get')
            ->once()
            ->with('outbound:abuse:qid:REINJECTED_QID')
            ->andReturn(null);

        // 2. Alias lookup succeeds, pointing to original QID (called on lookup and on terminal cleanup)
        Redis::shouldReceive('get')
            ->twice()
            ->with('outbound:abuse:qid_alias:REINJECTED_QID')
            ->andReturn('ORIGINAL_QID');

        // 3. Sender lookup on original QID succeeds
        Redis::shouldReceive('get')
            ->once()
            ->with('outbound:abuse:qid:ORIGINAL_QID')
            ->andReturn(json_encode(['sender' => 'info@democorp.com']));

        // 4. Mark delivery seen
        Redis::shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $val, $ex, $ttl, $nx) {
                return str_contains($key, 'outbound:abuse:seen:REINJECTED_QID:') && $nx === 'NX';
            })
            ->andReturn(true);

        // 5. Increments on hard bounce
        Redis::shouldReceive('incr')->times(3)->andReturn(1);
        Redis::shouldReceive('expire')->times(3)->andReturn(true);

        // 6. Terminal cleanup of both reinjected QID and original QID
        Redis::shouldReceive('del')->with('outbound:abuse:qid:REINJECTED_QID')->once();
        Redis::shouldReceive('del')->with('outbound:abuse:qid_alias:REINJECTED_QID')->once();
        Redis::shouldReceive('del')->with('outbound:abuse:qid:ORIGINAL_QID')->once();

        // 7. Threshold evaluation getters
        Redis::shouldReceive('get')
            ->with("outbound:abuse:tenant:{$this->tenant->id}:bounces:hard:daily:{$this->todayUtc}")
            ->andReturn('1');
        Redis::shouldReceive('get')
            ->with("outbound:abuse:mailbox:{$this->mailbox->id}:consecutive_hard")
            ->andReturn('1');
        Redis::shouldReceive('get')
            ->with("outbound:tenant:{$this->tenant->id}:recipients:daily:{$this->todayUtc}")
            ->andReturn('10');

        $event = new NormalizedMailEvent(
            queueId: 'REINJECTED_QID',
            daemon: 'postfix/smtp',
            eventType: NormalizedMailEvent::TYPE_DELIVERY_STATUS,
            recipient: 'target@remote.com',
            status: 'bounced',
            dsn: '5.1.1',
            smtpCode: 550
        );

        $result = $this->service->processEvent($event);

        $this->assertTrue($result['processed']);
        $this->assertEquals('EVALUATED', $result['action']);
        $this->assertEquals($this->tenant->id, $result['tenant_id']);
        $this->assertEquals($this->mailbox->id, $result['mailbox_id']);
        $this->assertEquals(BounceClassificationService::CLASSIFICATION_HARD_BOUNCE, $result['classification']);
    }

    public function test_repeated_deferred_events_for_same_qid_and_recipient_are_deduplicated(): void
    {
        // First occurrence: mark seen returns true -> incremented
        $this->assertTrue($this->service->markDeliveryEventSeen('QID_DEF', 'user@domain.com', 'SOFT_BOUNCE'));

        // Mock Redis returning false on duplicate NX set
        Redis::shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $val, $ex, $ttl, $nx) {
                return str_contains($key, 'outbound:abuse:seen:QID_DEF:') && $nx === 'NX';
            })
            ->andReturn(false);

        // Second occurrence returns false (deduplicated)
        $isSecondSeen = $this->service->markDeliveryEventSeen('QID_DEF', 'user@domain.com', 'SOFT_BOUNCE');
        $this->assertFalse($isSecondSeen);
    }

    public function test_deferred_events_for_different_qids_are_counted_independently(): void
    {
        Redis::shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $val, $ex, $ttl, $nx) {
                return str_contains($key, 'outbound:abuse:seen:QID_AAA:') && $nx === 'NX';
            })
            ->andReturn(true);

        Redis::shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $val, $ex, $ttl, $nx) {
                return str_contains($key, 'outbound:abuse:seen:QID_BBB:') && $nx === 'NX';
            })
            ->andReturn(true);

        $this->assertTrue($this->service->markDeliveryEventSeen('QID_AAA', 'same_user@domain.com', 'SOFT_BOUNCE'));
        $this->assertTrue($this->service->markDeliveryEventSeen('QID_BBB', 'same_user@domain.com', 'SOFT_BOUNCE'));
    }
}

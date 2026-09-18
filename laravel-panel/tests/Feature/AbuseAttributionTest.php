<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use App\Services\Abuse\AbuseAttributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbuseAttributionTest extends TestCase
{
    use RefreshDatabase;

    private AbuseAttributionService $attributionService;
    private User $tenant;
    private Domain $domain;
    private Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attributionService = new AbuseAttributionService();

        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 10,
            'storage_mb_per_mailbox' => 1024,
            'price_monthly' => 20,
            'price_yearly' => 200,
            'is_active' => true,
        ]);

        $this->tenant = User::create([
            'name' => 'Acme Corp',
            'email' => 'admin@acme.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        $this->domain = Domain::create([
            'user_id' => $this->tenant->id,
            'domain_name' => 'acme.com',
            'status' => 'active',
        ]);

        $this->mailbox = Mailbox::create([
            'domain_id' => $this->domain->id,
            'local_part' => 'sales',
            'email' => 'sales@acme.com',
            'password' => 'secret_hash',
            'is_active' => true,
        ]);
    }

    public function test_attributes_authenticated_tenant_mailbox(): void
    {
        $result = $this->attributionService->attribute('sales@acme.com');

        $this->assertEquals(AbuseAttributionService::TYPE_AUTHENTICATED_MAILBOX, $result['type']);
        $this->assertEquals($this->tenant->id, $result['tenant_id']);
        $this->assertEquals($this->mailbox->id, $result['mailbox_id']);
        $this->assertEquals($this->domain->id, $result['domain_id']);
        $this->assertTrue($this->attributionService->isValidTenantMailbox($result));
    }

    public function test_attributes_existing_domain_without_mailbox_as_domain_only(): void
    {
        $result = $this->attributionService->attribute('nonexistent_user@acme.com');

        $this->assertEquals(AbuseAttributionService::TYPE_DOMAIN_ONLY, $result['type']);
        $this->assertEquals($this->tenant->id, $result['tenant_id']);
        $this->assertNull($result['mailbox_id']);
        $this->assertEquals($this->domain->id, $result['domain_id']);
        // Domain-only attribution should not be treated as a valid tenant mailbox for abuse counters
        $this->assertFalse($this->attributionService->isValidTenantMailbox($result));
    }

    public function test_attributes_unregistered_sender_as_unknown_system(): void
    {
        $result = $this->attributionService->attribute('stranger@externaldomain.com');

        $this->assertEquals(AbuseAttributionService::TYPE_UNKNOWN_SYSTEM, $result['type']);
        $this->assertNull($result['tenant_id']);
        $this->assertNull($result['mailbox_id']);
        $this->assertFalse($this->attributionService->isValidTenantMailbox($result));
    }

    public function test_handles_soft_deleted_mailbox(): void
    {
        $this->mailbox->delete();

        $result = $this->attributionService->attribute('sales@acme.com');

        // Mailbox is deleted; falls back to domain-only
        $this->assertEquals(AbuseAttributionService::TYPE_DOMAIN_ONLY, $result['type']);
        $this->assertNull($result['mailbox_id']);
        $this->assertFalse($this->attributionService->isValidTenantMailbox($result));
    }

    public function test_handles_empty_or_invalid_email_strings(): void
    {
        $result = $this->attributionService->attribute('');
        $this->assertEquals(AbuseAttributionService::TYPE_UNKNOWN_SYSTEM, $result['type']);

        $result2 = $this->attributionService->attribute('not-an-email');
        $this->assertEquals(AbuseAttributionService::TYPE_UNKNOWN_SYSTEM, $result2['type']);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Plan;
use App\Models\Invoice;

class AdminPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::factory()->create([
            'is_admin' => true,
        ]);
        
        $this->user = User::factory()->create([
            'is_admin' => false,
        ]);
    }

    public function test_admin_can_list_plans()
    {
        Plan::create([
            'name' => 'Plan 1',
            'slug' => 'plan-1',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 1,
            'price_yearly' => 10,
        ]);
        Plan::create([
            'name' => 'Plan 2',
            'slug' => 'plan-2',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 1,
            'price_yearly' => 10,
        ]);
        Plan::create([
            'name' => 'Plan 3',
            'slug' => 'plan-3',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 1,
            'price_yearly' => 10,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/plans');
        
        $response->assertStatus(200)
                 ->assertJsonCount(3, 'data');
    }

    public function test_non_admin_cannot_list_plans()
    {
        $response = $this->actingAs($this->user)->getJson('/api/admin/plans');
        $response->assertStatus(403);
    }

    public function test_admin_can_create_plan()
    {
        $payload = [
            'name' => 'Ultra Plan',
            'slug' => 'ultra-plan',
            'max_domains' => 10,
            'max_mailboxes_per_domain' => 50,
            'storage_mb_per_mailbox' => 5120,
            'max_aliases_per_domain' => 100,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 200,
            'price_yearly' => 2000,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/plans', $payload);
        
        $response->assertStatus(201)
                 ->assertJsonPath('name', 'Ultra Plan');
                 
        $this->assertDatabaseHas('plans', ['slug' => 'ultra-plan']);
    }

    public function test_duplicate_slug_rejected()
    {
        Plan::create([
            'name' => 'Basic',
            'slug' => 'basic',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 1,
            'price_yearly' => 10,
        ]);

        $payload = [
            'name' => 'Another Basic',
            'slug' => 'basic',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 10,
            'price_yearly' => 100,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/plans', $payload);
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['slug']);
    }

    public function test_admin_can_update_plan()
    {
        $plan = Plan::create([
            'name' => 'Old Name',
            'slug' => 'old-slug',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 1,
            'price_yearly' => 10,
        ]);

        $payload = [
            'name' => 'New Name',
            'slug' => 'old-slug', // Same slug is fine
            'max_domains' => 2,
            'max_mailboxes_per_domain' => 2,
            'storage_mb_per_mailbox' => 2,
            'max_aliases_per_domain' => 2,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 20,
            'price_yearly' => 200,
            'is_active' => false,
        ];

        $response = $this->actingAs($this->admin)->putJson("/api/admin/plans/{$plan->id}", $payload);
        
        $response->assertStatus(200)
                 ->assertJsonPath('name', 'New Name');
                 
        $this->assertDatabaseHas('plans', ['name' => 'New Name', 'is_active' => false]);
    }

    public function test_admin_can_delete_unused_plan()
    {
        $plan = Plan::create([
            'name' => 'To Delete',
            'slug' => 'to-delete',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 1,
            'price_yearly' => 10,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/plans/{$plan->id}");
        
        $response->assertStatus(204);
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    public function test_referenced_plan_cannot_be_deleted()
    {
        $plan = Plan::create([
            'name' => 'In Use',
            'slug' => 'in-use',
            'max_domains' => 1,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1,
            'max_aliases_per_domain' => 1,
            'daily_outbound_recipients' => -1,
            'mailbox_daily_outbound_recipients' => -1,
            'price_monthly' => 1,
            'price_yearly' => 10,
        ]);
        
        // Reference plan via User
        User::factory()->create(['plan_id' => $plan->id]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/admin/plans/{$plan->id}");
        
        $response->assertStatus(422)
                 ->assertJsonPath('message', __('messages.plan_in_use'));
                 
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_admin_can_create_plan_with_quotas()
    {
        $payload = [
            'name' => 'Quota Plan',
            'slug' => 'quota-plan',
            'max_domains' => 10,
            'max_mailboxes_per_domain' => 50,
            'storage_mb_per_mailbox' => 5120,
            'max_aliases_per_domain' => 100,
            'daily_outbound_recipients' => 1000,
            'mailbox_daily_outbound_recipients' => 100,
            'price_monthly' => 200,
            'price_yearly' => 2000,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/plans', $payload);
        
        $response->assertStatus(201)
                 ->assertJsonPath('daily_outbound_recipients', 1000)
                 ->assertJsonPath('mailbox_daily_outbound_recipients', 100);
                 
        $this->assertDatabaseHas('plans', ['daily_outbound_recipients' => 1000]);
    }

    public function test_invalid_quotas_rejected()
    {
        $payload = [
            'name' => 'Invalid Plan',
            'slug' => 'invalid-plan',
            'max_domains' => 10,
            'max_mailboxes_per_domain' => 50,
            'storage_mb_per_mailbox' => 5120,
            'max_aliases_per_domain' => 100,
            'daily_outbound_recipients' => -2, // Invalid
            'mailbox_daily_outbound_recipients' => 1.5, // Invalid (decimal)
            'price_monthly' => 200,
            'price_yearly' => 2000,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/plans', $payload);
        
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['daily_outbound_recipients', 'mailbox_daily_outbound_recipients']);
    }
}

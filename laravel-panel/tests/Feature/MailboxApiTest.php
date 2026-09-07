<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Services\PostfixService;
use Mockery;

class MailboxApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mockPostfix = Mockery::mock(PostfixService::class);
        $mockPostfix->shouldReceive('addMailbox')->andReturn(true);
        $mockPostfix->shouldReceive('removeMailbox')->andReturn(true);
        $this->app->instance(PostfixService::class, $mockPostfix);
    }

    public function test_mailbox_creation_persists_password_hash()
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test', 'price_monthly' => 10, 'price_yearly' => 100,
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 1024,
        ]);

        $user = User::create([
            'name' => 'User 1', 'email' => 'user1@example.com', 'password' => Hash::make('password'),
            'plan_id' => $plan->id,
            'status' => 'active',
            'plan_expires_at' => now()->addDays(30),
        ]);

        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'testdomain.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/mailboxes", [
            'local_part' => 'alice',
            'password' => 'SecurePassword123!',
            'display_name' => 'Alice Test',
        ]);

        $response->assertStatus(201);

        $mailbox = Mailbox::where('email', 'alice@testdomain.com')->first();
        
        $this->assertNotNull($mailbox);
        $this->assertEquals('alice', $mailbox->local_part);
        
        $this->assertNotNull($mailbox->password);
        $this->assertNotEquals('SecurePassword123!', $mailbox->password);
        $this->assertStringStartsWith('$6$', $mailbox->password);

        $response->assertJsonMissing(['password' => $mailbox->password]);
        $response->assertJsonMissing(['password' => 'SecurePassword123!']);
    }

    public function test_tenant_cannot_create_mailbox_on_other_tenant_domain()
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test', 'price_monthly' => 10, 'price_yearly' => 100,
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 5,
            'storage_mb_per_mailbox' => 1024,
        ]);

        $user1 = User::create([
            'name' => 'User 1', 'email' => 'user1@example.com', 'password' => Hash::make('password'),
            'plan_id' => $plan->id,
            'status' => 'active',
            'plan_expires_at' => now()->addDays(30),
        ]);

        $domain1 = Domain::create([
            'user_id' => $user1->id,
            'domain_name' => 'tenant1.com',
            'status' => 'active',
        ]);

        $user2 = User::create([
            'name' => 'User 2', 'email' => 'user2@example.com', 'password' => Hash::make('password'),
            'plan_id' => $plan->id,
            'status' => 'active',
            'plan_expires_at' => now()->addDays(30),
        ]);

        $response = $this->actingAs($user2)->postJson("/api/domains/{$domain1->id}/mailboxes", [
            'local_part' => 'hacker',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertStatus(403);
    }

    public function test_mailbox_quota_validation()
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test', 'price_monthly' => 10, 'price_yearly' => 100,
            'max_domains' => 5,
            'max_mailboxes_per_domain' => 1,
            'storage_mb_per_mailbox' => 1024,
        ]);

        $user = User::create([
            'name' => 'User 1', 'email' => 'user1@example.com', 'password' => Hash::make('password'),
            'plan_id' => $plan->id,
            'status' => 'active',
            'plan_expires_at' => now()->addDays(30),
        ]);

        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'testdomain.com',
            'status' => 'active',
        ]);

        Mailbox::create([
            'domain_id' => $domain->id,
            'local_part' => 'first',
            'email' => 'first@testdomain.com',
            'password' => 'dummy',
        ]);

        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/mailboxes", [
            'local_part' => 'second',
            'password' => 'SecurePassword123!',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => __('api.mailbox_limit_reached')]);
    }
}

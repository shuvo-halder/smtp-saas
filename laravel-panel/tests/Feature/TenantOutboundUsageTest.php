<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Models\User;

class TenantOutboundUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_usage_ledger_can_be_created()
    {
        $user = User::factory()->create();

        DB::table('tenant_outbound_usage')->insert([
            'user_id' => $user->id,
            'usage_date' => '2026-09-08',
            'recipient_count' => 150,
        ]);

        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $user->id,
            'usage_date' => '2026-09-08',
            'recipient_count' => 150,
        ]);
    }

    public function test_tenant_usage_ledger_prevents_duplicate_daily_records()
    {
        $user = User::factory()->create();

        DB::table('tenant_outbound_usage')->insert([
            'user_id' => $user->id,
            'usage_date' => '2026-09-08',
            'recipient_count' => 150,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/(Duplicate entry|UNIQUE constraint failed)/');

        DB::table('tenant_outbound_usage')->insert([
            'user_id' => $user->id,
            'usage_date' => '2026-09-08',
            'recipient_count' => 50,
        ]);
    }

    public function test_tenant_usage_ledger_upsert_idempotency()
    {
        $user = User::factory()->create();

        // First insert
        DB::table('tenant_outbound_usage')->upsert([
            ['user_id' => $user->id, 'usage_date' => '2026-09-08', 'recipient_count' => 150]
        ], ['user_id', 'usage_date'], ['recipient_count']);

        // Update with upsert
        DB::table('tenant_outbound_usage')->upsert([
            ['user_id' => $user->id, 'usage_date' => '2026-09-08', 'recipient_count' => 300]
        ], ['user_id', 'usage_date'], ['recipient_count']);

        $this->assertDatabaseCount('tenant_outbound_usage', 1);
        $this->assertDatabaseHas('tenant_outbound_usage', [
            'user_id' => $user->id,
            'usage_date' => '2026-09-08',
            'recipient_count' => 300,
        ]);
    }
}

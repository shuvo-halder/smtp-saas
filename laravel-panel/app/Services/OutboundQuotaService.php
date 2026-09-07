<?php

namespace App\Services;

use App\Models\User;
use App\Models\Mailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Exception;

class OutboundQuotaService
{
    /**
     * Consumes the quota for a given tenant and mailbox.
     * 
     * @param User $tenant
     * @param Mailbox $mailbox
     * @param int $recipientCount
     * @return array
     */
    public function consume(User $tenant, Mailbox $mailbox, int $recipientCount): array
    {
        // 1. Validation
        if ($recipientCount <= 0) {
            return $this->buildResult('INVALID_REQUEST', 'Recipient count must be greater than zero.');
        }

        // Validate Mailbox ownership
        if ((int)$mailbox->domain->user_id !== (int)$tenant->id) {
            return $this->buildResult('INVALID_REQUEST', 'Mailbox does not belong to the given tenant.');
        }

        // 2. Resolve Limits
        $tenantLimit = $tenant->plan->daily_outbound_recipients ?? -1;
        $mailboxLimit = $tenant->plan->mailbox_daily_outbound_recipients ?? -1;

        // 0 means disabled
        if ($tenantLimit === 0) {
            return $this->buildResult('REJECTED_QUOTA', 'Tenant outbound quota is disabled.');
        }
        if ($mailboxLimit === 0) {
            return $this->buildResult('REJECTED_QUOTA', 'Mailbox outbound quota is disabled.');
        }

        // -1 means unlimited. If both are unlimited, we don't need to hit Redis.
        if ($tenantLimit === -1 && $mailboxLimit === -1) {
            return $this->buildResult('ALLOWED', 'Unlimited quota.');
        }

        // 3. Resolve Keys
        $date = Carbon::now('UTC')->format('Y-m-d');
        $tenantKey = $tenantLimit === -1 ? '' : "outbound:tenant:{$tenant->id}:recipients:daily:{$date}";
        $mailboxKey = $mailboxLimit === -1 ? '' : "outbound:mailbox:{$mailbox->id}:recipients:daily:{$date}";

        // 48 hours in seconds
        $ttl = 172800;

        // 4. Redis Atomic Execution
        try {
            // Lua script
            $script = $this->getLuaScript();
            
            // Execute
            $result = Redis::eval($script, 2, $tenantKey, $mailboxKey, $recipientCount, $tenantLimit, $mailboxLimit, $ttl);
            
            // Result is 1 for allowed, 0 for rejected
            if ($result === 1) {
                return $this->buildResult('ALLOWED', 'Quota consumed successfully.', $recipientCount);
            } else {
                return $this->buildResult('REJECTED_QUOTA', 'Quota limit exceeded.');
            }

        } catch (Exception $e) {
            // FAIL-OPEN behavior
            Log::error('OutboundQuotaService Redis failure (FAIL-OPEN).', [
                'tenant_id' => $tenant->id,
                'mailbox_id' => $mailbox->id,
                'recipient_count' => $recipientCount,
                'exception' => $e->getMessage(),
            ]);

            return $this->buildResult('FAIL_OPEN_REDIS', 'Redis unavailable. Failing open.', $recipientCount);
        }
    }

    private function getLuaScript(): string
    {
        return <<<LUA
-- Inputs
local tenantKey = KEYS[1]
local mailboxKey = KEYS[2]
local requested = tonumber(ARGV[1])
local tenantLimit = tonumber(ARGV[2])
local mailboxLimit = tonumber(ARGV[3])
local ttl = tonumber(ARGV[4])

-- Check Tenant
if tenantKey ~= '' and tenantLimit ~= -1 then
    local currentTenant = tonumber(redis.call('GET', tenantKey) or '0')
    if currentTenant + requested > tenantLimit then
        return 0 -- REJECT
    end
end

-- Check Mailbox
if mailboxKey ~= '' and mailboxLimit ~= -1 then
    local currentMailbox = tonumber(redis.call('GET', mailboxKey) or '0')
    if currentMailbox + requested > mailboxLimit then
        return 0 -- REJECT
    end
end

-- Both passed (or were unlimited). Apply increments and TTLs.
if tenantKey ~= '' and tenantLimit ~= -1 then
    local newTenant = redis.call('INCRBY', tenantKey, requested)
    if newTenant == requested then
        -- It was newly created, set TTL
        redis.call('EXPIRE', tenantKey, ttl)
    end
end

if mailboxKey ~= '' and mailboxLimit ~= -1 then
    local newMailbox = redis.call('INCRBY', mailboxKey, requested)
    if newMailbox == requested then
        -- It was newly created, set TTL
        redis.call('EXPIRE', mailboxKey, ttl)
    end
end

return 1 -- ALLOW
LUA;
    }

    private function buildResult(string $status, string $reason, int $consumed = 0): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'consumed' => $consumed,
        ];
    }
}

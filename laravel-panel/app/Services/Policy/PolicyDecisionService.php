<?php

namespace App\Services\Policy;

use App\Models\Mailbox;
use App\Services\OutboundQuotaService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class PolicyDecisionService
{
    public function __construct(
        private readonly OutboundQuotaService $quotaService
    ) {}

    /**
     * Evaluates a Postfix policy request and returns a structured PolicyResponse.
     */
    public function evaluate(PolicyRequest $request): PolicyResponse
    {
        // 1. Basic Protocol Validation
        if ($request->request !== null && $request->request !== 'smtpd_access_policy') {
            $this->log('warning', 'Invalid policy request type received', [
                'request' => $request->request,
                'client'  => $request->clientAddress,
            ]);
            return PolicyResponse::invalid('Unsupported request type');
        }

        // 2. Authentication Check
        // Outbound recipient quotas apply ONLY to authenticated SASL submissions.
        // Inbound mail or unauthenticated connections pass through (Postfix enforces relay checks).
        if (!$request->isAuthenticated()) {
            return PolicyResponse::dunno('Unauthenticated session, outbound quota not applicable');
        }

        $saslUsername = $request->saslUsername;
        $recipientCount = $request->recipientCount;
        $instance = $request->instance;

        // 3. Transaction Idempotency Check
        // If Postfix re-evaluates the same message transaction within 5 minutes, replay the decision.
        if ($instance !== null) {
            $cachedAction = $this->getCachedTransactionAction($instance);
            if ($cachedAction !== null) {
                $this->log('info', 'Replaying cached policy decision for transaction instance', [
                    'instance' => $instance,
                    'action'   => $cachedAction,
                ]);
                return new PolicyResponse($cachedAction, 'CACHED', 'Replayed cached decision');
            }
        }

        try {
            // 4. Resolve Identity Chain: Mailbox -> Domain -> Tenant (User) -> Plan
            $mailbox = Mailbox::with(['domain.user.plan'])
                ->where('email', $saslUsername)
                ->first();

            if (!$mailbox) {
                $this->log('warning', 'Policy rejection: SASL username does not match any existing mailbox', [
                    'sasl_username' => $saslUsername,
                    'client'        => $request->clientAddress,
                ]);
                return PolicyResponse::authFailed('Mailbox not found');
            }

            if (!$mailbox->is_active) {
                $this->log('warning', 'Policy rejection: Mailbox is disabled', [
                    'mailbox_id' => $mailbox->id,
                    'email'      => $mailbox->email,
                ]);
                return PolicyResponse::suspended('Mailbox is disabled');
            }

            $domain = $mailbox->domain;
            if (!$domain || $domain->status !== 'active') {
                $this->log('warning', 'Policy rejection: Domain is inactive or suspended', [
                    'mailbox_id'    => $mailbox->id,
                    'domain_id'     => $domain?->id,
                    'domain_status' => $domain?->status,
                ]);
                return PolicyResponse::suspended('Domain is inactive or suspended');
            }

            $tenant = $domain->user;
            if (!$tenant || !$tenant->isSubscriptionActive()) {
                $this->log('warning', 'Policy rejection: Tenant subscription is inactive or suspended', [
                    'tenant_id'     => $tenant?->id,
                    'tenant_status' => $tenant?->status,
                    'expires_at'    => $tenant?->plan_expires_at?->toIso8601String(),
                ]);
                return PolicyResponse::suspended('Tenant subscription is inactive or suspended');
            }

            if (!$tenant->plan) {
                $this->log('warning', 'Policy rejection: Tenant has no associated plan', [
                    'tenant_id' => $tenant->id,
                ]);
                return PolicyResponse::authFailed('No active plan assigned to tenant');
            }

            // 5. Atomic Quota Consumption via OutboundQuotaService
            $quotaResult = $this->quotaService->consume($tenant, $mailbox, $recipientCount);

            $response = match ($quotaResult['status']) {
                'ALLOWED' => PolicyResponse::dunno($quotaResult['reason'] ?? 'Quota allowed'),
                'REJECTED_QUOTA' => PolicyResponse::quotaExceeded($quotaResult['reason'] ?? 'Daily quota exceeded'),
                'FAIL_OPEN_REDIS' => PolicyResponse::failOpen($quotaResult['reason'] ?? 'Redis fail-open'),
                'INVALID_REQUEST' => PolicyResponse::invalid($quotaResult['reason'] ?? 'Invalid quota request'),
                default => PolicyResponse::failOpen('Unknown quota service response status'),
            };

            // 6. Cache Transaction Decision for Idempotency
            if ($instance !== null && !$response->isFailOpen) {
                $this->cacheTransactionAction($instance, $response->action);
            }

            // Log quota rejections
            if ($response->status === 'REJECTED_QUOTA') {
                $this->log('warning', 'Outbound quota exceeded for mailbox/tenant', [
                    'tenant_id'       => $tenant->id,
                    'mailbox_id'      => $mailbox->id,
                    'email'           => $mailbox->email,
                    'recipient_count' => $recipientCount,
                    'reason'          => $response->reason,
                ]);
            }

            return $response;

        } catch (Throwable $e) {
            // FAIL-OPEN: Log operational error and return DUNNO to keep mail flowing
            $this->log('error', 'PolicyDecisionService encountered unexpected exception; failing open', [
                'sasl_username'   => $saslUsername,
                'recipient_count' => $recipientCount,
                'exception'       => get_class($e),
                'message'         => $e->getMessage(),
            ]);

            return PolicyResponse::failOpen('Internal error, fail-open active');
        }
    }

    private function getCachedTransactionAction(string $instance): ?string
    {
        try {
            $key = "outbound:policy:tx:{$instance}";
            $cached = Redis::get($key);
            return $cached !== null && $cached !== false ? (string)$cached : null;
        } catch (Throwable $e) {
            // If Redis fails during idempotency check, proceed to normal evaluation
            return null;
        }
    }

    private function cacheTransactionAction(string $instance, string $action): void
    {
        try {
            $key = "outbound:policy:tx:{$instance}";
            // Cache for 300 seconds (5 minutes)
            Redis::setex($key, 300, $action);
        } catch (Throwable $e) {
            // Non-fatal if cache write fails
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('policy')->log($level, "[SMTP-POLICY] {$message}", $context);
        } catch (Throwable) {
            // Fallback to default logger if 'policy' channel not configured yet
            Log::log($level, "[SMTP-POLICY] {$message}", $context);
        }
    }
}

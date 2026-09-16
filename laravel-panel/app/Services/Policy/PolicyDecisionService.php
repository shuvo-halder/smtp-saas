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

        // Recipient count bounds validation (must be positive integer <= 100,000)
        if ($recipientCount <= 0 || $recipientCount > 100000) {
            $this->log('warning', 'Invalid recipient count received', [
                'recipient_count' => $recipientCount,
                'sasl_username'   => $saslUsername,
            ]);
            return PolicyResponse::invalid('Recipient count must be a positive integer within valid limits');
        }

        // 3. Transaction Idempotency Check
        // If Postfix re-evaluates the same message transaction within 5 minutes, replay the decision
        // only if the authenticated sender and recipient count match the cached transaction.
        if ($instance !== null) {
            $cachedAction = $this->getCachedTransactionAction($instance, $saslUsername, $recipientCount);
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
                $this->cacheTransactionAction($instance, $response->action, $saslUsername, $recipientCount);
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

    private function getCachedTransactionAction(string $instance, string $expectedUser, int $expectedRecipients): ?string
    {
        try {
            $key = "outbound:policy:tx:{$instance}";
            $cached = Redis::get($key);
            if ($cached === null || $cached === false) {
                return null;
            }

            $data = json_decode((string)$cached, true);
            if (is_array($data) && isset($data['action'])) {
                if (($data['sasl_username'] ?? null) === $expectedUser && ($data['recipient_count'] ?? null) === $expectedRecipients) {
                    return (string)$data['action'];
                }

                $this->log('warning', 'Transaction cache context mismatch for instance', [
                    'instance'            => $instance,
                    'cached_user'         => $data['sasl_username'] ?? null,
                    'expected_user'       => $expectedUser,
                    'cached_recipients'   => $data['recipient_count'] ?? null,
                    'expected_recipients' => $expectedRecipients,
                ]);
                return null;
            }

            // Plain string fallback
            return is_string($cached) ? (string)$cached : null;
        } catch (Throwable $e) {
            // If Redis fails during idempotency check, proceed to normal evaluation
            return null;
        }
    }

    private function cacheTransactionAction(string $instance, string $action, string $saslUsername, int $recipientCount): void
    {
        try {
            $key = "outbound:policy:tx:{$instance}";
            $payload = json_encode([
                'action'          => $action,
                'sasl_username'   => $saslUsername,
                'recipient_count' => $recipientCount,
            ]);
            // Cache for 300 seconds (5 minutes)
            Redis::setex($key, 300, $payload);
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\AuditService;
use App\Services\PostfixService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatchAllApiController extends Controller
{
    use AuthorizesRequests;

    /**
     * Get domain catch-all settings and routing diagnostics
     */
    public function show(Domain $domain, PostfixService $postfixService): JsonResponse
    {
        $this->authorize('view', $domain);

        $domain->load(['catchAllMailbox', 'mailboxes' => function ($query) {
            $query->where('is_active', true)->select('id', 'domain_id', 'email', 'local_part', 'display_name', 'is_active', 'is_catchall');
        }]);

        $routing = $postfixService->verifyCatchAllRouting($domain);

        return response()->json([
            'data' => [
                'domain_id'           => $domain->id,
                'domain_name'         => $domain->domain_name,
                'domain_status'       => $domain->status,
                'is_verified'         => $domain->status === 'active',
                'policy'              => $domain->catch_all_policy ?? 'disabled',
                'is_enabled'          => $domain->isCatchAllEnabled(),
                'mailbox'             => $domain->catchAllMailbox ? [
                    'id'           => $domain->catchAllMailbox->id,
                    'email'        => $domain->catchAllMailbox->email,
                    'local_part'   => $domain->catchAllMailbox->local_part,
                    'display_name' => $domain->catchAllMailbox->display_name,
                    'is_active'    => (bool) $domain->catchAllMailbox->is_active,
                ] : null,
                'routing'             => $routing,
                'available_mailboxes' => $domain->mailboxes->map(fn (Mailbox $mb) => [
                    'id'           => $mb->id,
                    'email'        => $mb->email,
                    'local_part'   => $mb->local_part,
                    'display_name' => $mb->display_name,
                    'is_active'    => (bool) $mb->is_active,
                ]),
                'updated_at'          => $domain->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Update domain catch-all policy and destination mailbox
     */
    public function update(
        Request $request,
        Domain $domain,
        PostfixService $postfixService,
        AuditService $auditService
    ): JsonResponse {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'policy'     => 'required|string|in:disabled,reject,mailbox,discard',
            'mailbox_id' => 'nullable|integer',
        ]);

        $policy = $validated['policy'];
        $mailboxId = $validated['mailbox_id'] ?? null;
        $user = $request->user();

        // When enabling catch-all (mailbox or discard), domain and subscription must be active
        if (in_array($policy, ['mailbox', 'discard'], true)) {
            if ($domain->status !== 'active') {
                return response()->json([
                    'message' => 'Cannot enable catch-all on an unverified or inactive domain. Complete DNS verification first.',
                    'errors'  => ['policy' => ['Domain must be active and verified to enable catch-all.']],
                ], 422);
            }

            if (! $user->isSubscriptionActive()) {
                return response()->json([
                    'message' => 'Cannot enable catch-all while tenant subscription is inactive or suspended.',
                    'errors'  => ['policy' => ['Active subscription required to enable catch-all.']],
                ], 422);
            }
        }

        // Validate destination mailbox when policy is 'mailbox'
        $targetMailbox = null;
        if ($policy === 'mailbox') {
            if (! $mailboxId) {
                return response()->json([
                    'message' => 'A valid destination mailbox is required when setting catch-all policy to mailbox.',
                    'errors'  => ['mailbox_id' => ['The mailbox_id field is required for mailbox policy.']],
                ], 422);
            }

            $targetMailbox = Mailbox::where('id', $mailboxId)
                ->where('domain_id', $domain->id)
                ->first();

            if (! $targetMailbox) {
                return response()->json([
                    'message' => 'The selected mailbox does not belong to this domain.',
                    'errors'  => ['mailbox_id' => ['Invalid destination mailbox. Mailbox must belong to the domain.']],
                ], 422);
            }

            if (! $targetMailbox->is_active) {
                return response()->json([
                    'message' => 'Cannot designate an inactive mailbox as the catch-all destination.',
                    'errors'  => ['mailbox_id' => ['The selected mailbox is inactive.']],
                ], 422);
            }
        } else {
            // Non-mailbox policies do not hold a mailbox destination
            $mailboxId = null;
        }

        $beforeState = [
            'catch_all_policy'     => $domain->catch_all_policy ?? 'disabled',
            'catch_all_mailbox_id' => $domain->catch_all_mailbox_id,
            'mailbox_email'        => $domain->catchAllMailbox?->email,
        ];

        // Execute atomic update
        DB::transaction(function () use ($domain, $policy, $mailboxId, $targetMailbox) {
            $domain->update([
                'catch_all_policy'     => $policy,
                'catch_all_mailbox_id' => $mailboxId,
            ]);

            if ($policy === 'mailbox' && $targetMailbox) {
                // Ensure target mailbox is marked catchall and any previous is cleared
                $domain->mailboxes()->where('id', '!=', $targetMailbox->id)->where('is_catchall', true)->update(['is_catchall' => false]);
                $targetMailbox->update(['is_catchall' => true]);
            } else {
                // Reset is_catchall flag on all mailboxes for this domain
                $domain->mailboxes()->where('is_catchall', true)->update(['is_catchall' => false]);
            }
        });

        // Synchronize Postfix routing rule
        if ($policy === 'disabled') {
            $postfixService->disableCatchAll($domain);
        } else {
            $postfixService->syncCatchAll($domain);
        }

        $freshDomain = $domain->fresh(['catchAllMailbox', 'mailboxes']);
        $routing = $postfixService->verifyCatchAllRouting($freshDomain);

        // Record immutable audit event
        $auditService->record(
            action: 'domain.catch_all_update',
            entityType: 'Domain',
            entityId: $domain->id,
            before: $beforeState,
            after: [
                'catch_all_policy'     => $freshDomain->catch_all_policy,
                'catch_all_mailbox_id' => $freshDomain->catch_all_mailbox_id,
                'mailbox_email'        => $freshDomain->catchAllMailbox?->email,
                'routing_status'       => $routing['status'],
                'outcome'              => 'success',
            ],
            actor: $user,
            reason: "Tenant updated catch-all policy to {$policy}",
            request: $request
        );

        return response()->json([
            'message' => __('messages.catch_all_updated') ?? 'Catch-all policy updated successfully.',
            'data'    => [
                'domain_id'           => $freshDomain->id,
                'domain_name'         => $freshDomain->domain_name,
                'domain_status'       => $freshDomain->status,
                'is_verified'         => $freshDomain->status === 'active',
                'policy'              => $freshDomain->catch_all_policy,
                'is_enabled'          => $freshDomain->isCatchAllEnabled(),
                'mailbox'             => $freshDomain->catchAllMailbox ? [
                    'id'           => $freshDomain->catchAllMailbox->id,
                    'email'        => $freshDomain->catchAllMailbox->email,
                    'local_part'   => $freshDomain->catchAllMailbox->local_part,
                    'display_name' => $freshDomain->catchAllMailbox->display_name,
                    'is_active'    => (bool) $freshDomain->catchAllMailbox->is_active,
                ] : null,
                'routing'             => $routing,
                'available_mailboxes' => $freshDomain->mailboxes->where('is_active', true)->map(fn (Mailbox $mb) => [
                    'id'           => $mb->id,
                    'email'        => $mb->email,
                    'local_part'   => $mb->local_part,
                    'display_name' => $mb->display_name,
                    'is_active'    => (bool) $mb->is_active,
                ])->values(),
                'updated_at'          => $freshDomain->updated_at?->toIso8601String(),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Services\AuditService;
use App\Services\MailboxRuleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MailboxRuleApiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected MailboxRuleService $ruleService,
        protected AuditService $auditService
    ) {}

    /**
     * Show forwarding and auto-reply rules for a mailbox
     */
    public function show(Request $request, Mailbox $mailbox)
    {
        $this->authorize('view', $mailbox);

        $rules = $this->ruleService->verifyMailboxRules($mailbox->load('domain'));

        return response()->json([
            'data' => $rules,
        ]);
    }

    /**
     * Update inbound email forwarding settings
     */
    public function updateForwarding(Request $request, Mailbox $mailbox)
    {
        $this->authorize('update', $mailbox);

        $domain = $mailbox->domain;
        if (!$domain || $domain->status !== 'active') {
            return response()->json([
                'message' => 'Cannot configure forwarding on an inactive or unverified domain.'
            ], 422);
        }

        $user = $request->user();
        if (!$user->isSubscriptionActive()) {
            return response()->json([
                'message' => 'Cannot configure mailbox rules while subscription is inactive or suspended.'
            ], 422);
        }

        $validated = $request->validate([
            'is_forwarding_enabled' => 'required|boolean',
            'destinations'          => 'required_if:is_forwarding_enabled,true|array|max:10',
            'destinations.*'        => 'required|string|email|max:255',
            'keep_copy'             => 'nullable|boolean',
        ]);

        $isEnabled = (bool) $validated['is_forwarding_enabled'];
        $destinations = [];
        $keepCopy = $request->has('keep_copy') ? $request->boolean('keep_copy') : true;

        if ($isEnabled) {
            $rawDests = $validated['destinations'] ?? [];
            $destinations = array_values(array_unique(array_map('strtolower', array_map('trim', $rawDests))));

            if (empty($destinations)) {
                return response()->json([
                    'message' => 'At least one valid destination email address is required when forwarding is enabled.'
                ], 422);
            }

            // Check for direct self-forwarding
            foreach ($destinations as $dest) {
                if ($dest === strtolower($mailbox->email)) {
                    return response()->json([
                        'message' => 'Self-forwarding loop detected: mailbox cannot forward to its own address.'
                    ], 422);
                }
            }

            // Check for multi-hop forwarding cycles
            $cycleCheck = $this->ruleService->detectForwardingCycle($mailbox->email, $destinations, $mailbox->id);
            if ($cycleCheck['has_cycle']) {
                return response()->json([
                    'message'    => $cycleCheck['error'],
                    'cycle_path' => $cycleCheck['cycle_path'],
                ], 422);
            }
        }

        $beforeState = [
            'is_forwarding_enabled'   => $mailbox->is_forwarding_enabled,
            'forwarding_destinations' => $mailbox->forwarding_destinations,
            'forwarding_keep_copy'    => $mailbox->forwarding_keep_copy,
        ];

        DB::transaction(function () use ($mailbox, $isEnabled, $destinations, $keepCopy) {
            $mailbox->is_forwarding_enabled = $isEnabled;
            if ($isEnabled) {
                $mailbox->setForwardingDestinationsList($destinations);
                $mailbox->forwarding_keep_copy = $keepCopy;
            } else {
                // If disabled, preserve or clear
                $mailbox->is_forwarding_enabled = false;
            }
            $mailbox->save();

            $this->ruleService->syncSieveScript($mailbox);
        });

        $afterState = [
            'is_forwarding_enabled'   => $mailbox->is_forwarding_enabled,
            'forwarding_destinations' => $mailbox->forwarding_destinations,
            'forwarding_keep_copy'    => $mailbox->forwarding_keep_copy,
            'outcome'                 => 'success',
        ];

        $this->auditService->record(
            action: 'mailbox.forwarding_update',
            entityType: 'Mailbox',
            entityId: $mailbox->id,
            before: $beforeState,
            after: $afterState,
            actor: $user,
            reason: 'Mailbox forwarding rules updated by tenant',
            request: $request
        );

        $rules = $this->ruleService->verifyMailboxRules($mailbox->fresh('domain'));

        return response()->json([
            'message' => $isEnabled ? 'Forwarding rules enabled and synchronized successfully.' : 'Forwarding rules disabled.',
            'data'    => $rules,
        ]);
    }

    /**
     * Update out-of-office auto-reply settings
     */
    public function updateAutoReply(Request $request, Mailbox $mailbox)
    {
        $this->authorize('update', $mailbox);

        $domain = $mailbox->domain;
        if (!$domain || $domain->status !== 'active') {
            return response()->json([
                'message' => 'Cannot configure auto-reply on an inactive or unverified domain.'
            ], 422);
        }

        $user = $request->user();
        if (!$user->isSubscriptionActive()) {
            return response()->json([
                'message' => 'Cannot configure mailbox rules while subscription is inactive or suspended.'
            ], 422);
        }

        $validated = $request->validate([
            'auto_reply_enabled' => 'required|boolean',
            'subject'            => 'required_if:auto_reply_enabled,true|nullable|string|max:255',
            'body'               => 'required_if:auto_reply_enabled,true|nullable|string|max:5000',
            'start_at'           => 'nullable|date',
            'end_at'             => 'nullable|date',
        ]);

        $isEnabled = (bool) $validated['auto_reply_enabled'];
        $subject = null;
        $body = null;
        $startAt = null;
        $endAt = null;

        if ($isEnabled) {
            $subject = trim((string) ($validated['subject'] ?? ''));
            $body = trim((string) ($validated['body'] ?? ''));

            if (empty($subject)) {
                return response()->json([
                    'message' => 'Subject is required when auto-reply is enabled.'
                ], 422);
            }

            if (empty($body)) {
                return response()->json([
                    'message' => 'Message body is required when auto-reply is enabled.'
                ], 422);
            }

            // Sanitize body: strip any executable scripts or HTML tags
            $body = strip_tags($body);

            if (!empty($validated['start_at'])) {
                $startAt = \Carbon\Carbon::parse($validated['start_at']);
            }

            if (!empty($validated['end_at'])) {
                $endAt = \Carbon\Carbon::parse($validated['end_at']);
            }

            if ($startAt && $endAt && $endAt->lte($startAt)) {
                return response()->json([
                    'message' => 'End date must be after the start date.'
                ], 422);
            }
        }

        $beforeState = [
            'auto_reply_enabled'  => $mailbox->auto_reply_enabled,
            'auto_reply_subject'  => $mailbox->auto_reply_subject,
            'auto_reply_start_at' => $mailbox->auto_reply_start_at?->toIso8601String(),
            'auto_reply_end_at'   => $mailbox->auto_reply_end_at?->toIso8601String(),
        ];

        DB::transaction(function () use ($mailbox, $isEnabled, $subject, $body, $startAt, $endAt) {
            $mailbox->auto_reply_enabled = $isEnabled;
            if ($isEnabled) {
                $mailbox->auto_reply_subject = $subject;
                $mailbox->auto_reply_body = $body;
                $mailbox->auto_reply_start_at = $startAt;
                $mailbox->auto_reply_end_at = $endAt;
            } else {
                $mailbox->auto_reply_enabled = false;
            }
            $mailbox->save();

            $this->ruleService->syncSieveScript($mailbox);
        });

        $afterState = [
            'auto_reply_enabled'  => $mailbox->auto_reply_enabled,
            'auto_reply_subject'  => $mailbox->auto_reply_subject,
            'auto_reply_start_at' => $mailbox->auto_reply_start_at?->toIso8601String(),
            'auto_reply_end_at'   => $mailbox->auto_reply_end_at?->toIso8601String(),
            'outcome'             => 'success',
        ];

        $this->auditService->record(
            action: 'mailbox.auto_reply_update',
            entityType: 'Mailbox',
            entityId: $mailbox->id,
            before: $beforeState,
            after: $afterState,
            actor: $user,
            reason: 'Mailbox out-of-office auto-reply rules updated by tenant',
            request: $request
        );

        $rules = $this->ruleService->verifyMailboxRules($mailbox->fresh('domain'));

        return response()->json([
            'message' => $isEnabled ? 'Out-of-office auto-reply enabled and Sieve script compiled successfully.' : 'Out-of-office auto-reply disabled.',
            'data'    => $rules,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MailboxResource;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Services\PostfixService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class MailboxApiController extends Controller
{
    use AuthorizesRequests;

    /**
     * Tenant-wide mailbox listing across all tenant domains
     */
    public function allMailboxes(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = $request->user()->mailboxes()->with('domain');

        if ($request->filled('domain_id')) {
            $query->where('mailboxes.domain_id', $request->query('domain_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('mailboxes.local_part', 'like', $search)
                  ->orWhere('mailboxes.email', 'like', $search)
                  ->orWhere('mailboxes.display_name', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if ($status === 'active') {
                $query->where('mailboxes.is_active', true);
            } elseif ($status === 'inactive' || $status === 'disabled') {
                $query->where('mailboxes.is_active', false);
            }
        }

        $mailboxes = $query->latest('mailboxes.id')->paginate($perPage);

        return MailboxResource::collection($mailboxes);
    }

    /**
     * Mailbox listing for a specific domain
     */
    public function index(Request $request, Domain $domain)
    {
        $this->authorize('view', $domain);

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = $domain->mailboxes()->with('domain');

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('local_part', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('display_name', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive' || $status === 'disabled') {
                $query->where('is_active', false);
            }
        }

        $mailboxes = $query->latest('id')->paginate($perPage);

        return MailboxResource::collection($mailboxes);
    }

    /**
     * View a single mailbox
     */
    public function show(Mailbox $mailbox)
    {
        $this->authorize('view', $mailbox);

        return new MailboxResource($mailbox->load('domain'));
    }

    /**
     * Create a new mailbox under a verified domain
     */
    public function store(Request $request, Domain $domain, PostfixService $postfixService)
    {
        $this->authorize('update', $domain);

        if ($domain->status !== 'active') {
            return response()->json([
                'message' => 'Cannot create mailbox on an unverified or inactive domain. Please verify domain DNS records first.'
            ], 422);
        }

        $user = $request->user();

        if (!$user->canAddMailbox($domain)) {
            return response()->json(['message' => __('api.mailbox_limit_reached')], 403);
        }

        $validated = $request->validate([
            'local_part'   => 'required|string|alpha_dash|max:64',
            'password'     => 'required|string|min:8',
            'display_name' => 'nullable|string|max:255',
            'quota_mb'     => 'nullable|integer|min:1',
        ]);

        $maxStorageMb = $user->plan?->storage_mb_per_mailbox ?? 0;
        if ($maxStorageMb > 0 && isset($validated['quota_mb']) && $validated['quota_mb'] > $maxStorageMb) {
            return response()->json([
                'message' => "Mailbox storage quota cannot exceed plan limit of {$maxStorageMb} MB."
            ], 422);
        }

        $email = strtolower($validated['local_part']) . '@' . $domain->domain_name;
        
        if (Mailbox::where('email', $email)->exists()) {
            return response()->json(['message' => __('api.email_exists')], 422);
        }

        $salt = \Illuminate\Support\Str::random(16);
        $dovecotPassword = crypt($validated['password'], '$6$' . $salt . '$');
        $quotaMb = $validated['quota_mb'] ?? ($maxStorageMb > 0 ? $maxStorageMb : 1024);

        $mailbox = \DB::transaction(function () use ($domain, $validated, $email, $quotaMb, $dovecotPassword, $postfixService) {
            $mb = $domain->mailboxes()->create([
                'local_part'   => strtolower($validated['local_part']),
                'email'        => $email,
                'password'     => $dovecotPassword, // Hashed specifically for Dovecot SHA512-CRYPT
                'display_name' => $validated['display_name'] ?? null,
                'quota_mb'     => $quotaMb,
                'is_active'    => true,
            ]);

            $postfixService->addMailbox($mb);

            return $mb;
        });

        app(\App\Services\AuditService::class)->record(
            action: 'mailbox.create',
            entityType: 'Mailbox',
            entityId: $mailbox->id,
            before: null,
            after: [
                'email'     => $mailbox->email,
                'quota_mb'  => $mailbox->quota_mb,
                'is_active' => $mailbox->is_active,
                'outcome'   => 'success',
            ],
            actor: $user,
            reason: 'Mailbox created by tenant',
            request: $request
        );

        return (new MailboxResource($mailbox->load('domain')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update mailbox display name (safe fields only)
     */
    public function update(Request $request, Mailbox $mailbox)
    {
        $this->authorize('update', $mailbox);

        $validated = $request->validate([
            'display_name' => 'nullable|string|max:255',
        ]);

        $beforeDisplayName = $mailbox->display_name;

        $mailbox->update([
            'display_name' => $validated['display_name'] ?? null,
        ]);

        app(\App\Services\AuditService::class)->record(
            action: 'mailbox.update',
            entityType: 'Mailbox',
            entityId: $mailbox->id,
            before: ['display_name' => $beforeDisplayName],
            after: [
                'display_name' => $mailbox->display_name,
                'outcome'      => 'success',
            ],
            actor: $request->user(),
            reason: 'Mailbox display name updated by tenant',
            request: $request
        );

        return new MailboxResource($mailbox->load('domain'));
    }

    /**
     * Change mailbox password
     */
    public function changePassword(Request $request, Mailbox $mailbox)
    {
        $this->authorize('update', $mailbox);

        $validated = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $salt = \Illuminate\Support\Str::random(16);
        $mailbox->update([
            'password' => crypt($validated['password'], '$6$' . $salt . '$'),
        ]);

        app(\App\Services\AuditService::class)->record(
            action: 'mailbox.change_password',
            entityType: 'Mailbox',
            entityId: $mailbox->id,
            before: null,
            after: [
                'email'          => $mailbox->email,
                'password_reset' => true,
                'outcome'        => 'success',
            ],
            actor: $request->user(),
            reason: 'Mailbox password changed by tenant',
            request: $request
        );

        return response()->json(['message' => __('messages.mailbox_password_changed')]);
    }

    /**
     * Toggle mailbox active status
     */
    public function toggle(Request $request, Mailbox $mailbox)
    {
        $this->authorize('update', $mailbox);

        $targetState = !$mailbox->is_active;

        // When enabling a mailbox, verify parent domain and tenant subscription are active
        if ($targetState === true) {
            if ($mailbox->domain?->status !== 'active') {
                return response()->json([
                    'message' => 'Cannot enable mailbox on an unverified or inactive domain.'
                ], 422);
            }

            if (!$request->user()->isSubscriptionActive()) {
                return response()->json([
                    'message' => 'Cannot enable mailbox while tenant subscription is inactive or suspended.'
                ], 422);
            }
        }

        $beforeState = ['is_active' => (bool) $mailbox->is_active];

        $mailbox->update([
            'is_active' => $targetState,
        ]);

        app(\App\Services\AuditService::class)->record(
            action: 'mailbox.toggle',
            entityType: 'Mailbox',
            entityId: $mailbox->id,
            before: $beforeState,
            after: [
                'is_active' => (bool) $mailbox->is_active,
                'outcome'   => 'success',
            ],
            actor: $request->user(),
            reason: 'Mailbox active status toggled by tenant',
            request: $request
        );

        return new MailboxResource($mailbox->load('domain'));
    }

    /**
     * Delete mailbox
     */
    public function destroy(Request $request, Mailbox $mailbox, PostfixService $postfixService)
    {
        $this->authorize('delete', $mailbox);

        $beforeState = [
            'email' => $mailbox->email,
        ];
        $mailboxId = $mailbox->id;

        $postfixService->removeMailbox($mailbox);
        $mailbox->delete();

        app(\App\Services\AuditService::class)->record(
            action: 'mailbox.delete',
            entityType: 'Mailbox',
            entityId: $mailboxId,
            before: $beforeState,
            after: [
                'deleted' => true,
                'outcome' => 'success',
            ],
            actor: $request->user(),
            reason: 'Mailbox deleted by tenant',
            request: $request
        );

        return response()->json(null, 204);
    }

    /**
     * Tenant mailbox storage overview and aggregated capacity telemetry (Step 31)
     */
    public function storageOverview(Request $request, \App\Services\MailboxStorageService $storageService)
    {
        $filters = [
            'page'      => $request->query('page', 1),
            'per_page'  => $request->query('per_page', 15),
            'domain_id' => $request->query('domain_id'),
            'status'    => $request->query('status'),
            'search'    => $request->query('search'),
        ];

        $overview = $storageService->getTenantStorageOverview($request->user(), $filters);

        return response()->json($overview);
    }

    /**
     * Single mailbox storage telemetry (Step 31)
     */
    public function mailboxStorage(Mailbox $mailbox, \App\Services\MailboxStorageService $storageService)
    {
        $this->authorize('view', $mailbox);

        $storage = $storageService->getMailboxStorage($mailbox);

        return response()->json([
            'data' => $storage,
        ]);
    }
}


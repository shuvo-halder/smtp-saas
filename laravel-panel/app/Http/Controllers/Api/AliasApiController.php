<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailAliasResource;
use App\Models\Domain;
use App\Models\EmailAlias;
use App\Models\Mailbox;
use App\Services\AuditService;
use App\Services\PostfixService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AliasApiController extends Controller
{
    use AuthorizesRequests;

    /**
     * Tenant-wide email aliases listing across all domains
     */
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = $request->user()->aliases()->with('domain');

        if ($request->filled('domain_id')) {
            $query->where('email_aliases.domain_id', $request->query('domain_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('email_aliases.source_email', 'like', $search)
                  ->orWhere('email_aliases.destination_email', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if ($status === 'active') {
                $query->where('email_aliases.is_active', true);
            } elseif ($status === 'inactive' || $status === 'disabled') {
                $query->where('email_aliases.is_active', false);
            }
        }

        $aliases = $query->latest('email_aliases.id')->paginate($perPage);

        return EmailAliasResource::collection($aliases);
    }

    /**
     * Aliases listing for a specific domain
     */
    public function domainAliases(Request $request, Domain $domain)
    {
        $this->authorize('view', $domain);

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = $domain->aliases()->with('domain');

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('source_email', 'like', $search)
                  ->orWhere('destination_email', 'like', $search);
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

        $aliases = $query->latest('id')->paginate($perPage);

        return EmailAliasResource::collection($aliases);
    }

    /**
     * Show a single alias
     */
    public function show(EmailAlias $alias)
    {
        $this->authorize('view', $alias);

        return new EmailAliasResource($alias->load('domain'));
    }

    /**
     * Store a new alias
     */
    public function store(Request $request, PostfixService $postfixService)
    {
        $validated = $request->validate([
            'domain_id'         => 'required|integer',
            'local_part'        => 'nullable|string|alpha_dash|max:64',
            'source_email'      => 'nullable|string|email|max:255',
            'destination_email' => 'required|string|email|max:255',
            'is_active'         => 'nullable|boolean',
        ]);

        if (empty($validated['local_part']) && empty($validated['source_email'])) {
            return response()->json([
                'message' => 'Either local_part or source_email must be provided.'
            ], 422);
        }

        $domain = $request->user()->domains()->find($validated['domain_id']);
        if (!$domain) {
            return response()->json([
                'message' => 'The selected domain does not exist or does not belong to your account.'
            ], 422);
        }

        if ($domain->status !== 'active') {
            return response()->json([
                'message' => 'Cannot create alias on an unverified or inactive domain. Please verify domain DNS records first.'
            ], 422);
        }

        $user = $request->user();

        if (!$user->isSubscriptionActive()) {
            return response()->json([
                'message' => 'Cannot create alias while subscription is inactive or suspended.'
            ], 422);
        }

        if (!$user->canAddAlias($domain)) {
            return response()->json([
                'message' => 'Alias limit reached for this domain under your current plan.'
            ], 403);
        }

        // Determine source email
        if (!empty($validated['local_part'])) {
            $sourceEmail = strtolower(trim($validated['local_part'])) . '@' . $domain->domain_name;
        } else {
            $sourceEmail = strtolower(trim($validated['source_email']));
            if (!str_ends_with($sourceEmail, '@' . $domain->domain_name)) {
                return response()->json([
                    'message' => "Source email must belong to domain @{$domain->domain_name}."
                ], 422);
            }
        }

        $destEmail = strtolower(trim($validated['destination_email']));

        // Check address collision with physical mailbox
        if (Mailbox::where('email', $sourceEmail)->exists()) {
            return response()->json([
                'message' => 'An active mailbox already exists with this email address.'
            ], 422);
        }

        // Check duplicate alias on domain
        if (EmailAlias::where('domain_id', $domain->id)->where('source_email', $sourceEmail)->exists()) {
            return response()->json([
                'message' => 'An alias with this source address already exists for this domain.'
            ], 422);
        }

        // Direct self-forwarding loop check
        if ($sourceEmail === $destEmail) {
            return response()->json([
                'message' => 'Self-forwarding loop detected: source and destination email cannot be identical.'
            ], 422);
        }

        // Circular forwarding chain loop check
        if ($this->detectForwardingCycle($sourceEmail, $destEmail)) {
            return response()->json([
                'message' => 'Forwarding cycle detected: forwarding to this address would create an infinite email loop.'
            ], 422);
        }

        $isActive = $request->has('is_active') ? $request->boolean('is_active') : true;

        $alias = DB::transaction(function () use ($domain, $sourceEmail, $destEmail, $isActive, $postfixService) {
            $created = $domain->aliases()->create([
                'source_email'      => $sourceEmail,
                'destination_email' => $destEmail,
                'is_active'         => $isActive,
            ]);

            $postfixService->syncAlias($created);

            return $created;
        });

        app(AuditService::class)->record(
            action: 'alias.create',
            entityType: 'EmailAlias',
            entityId: $alias->id,
            before: null,
            after: [
                'source_email'      => $alias->source_email,
                'destination_email' => $alias->destination_email,
                'domain_id'         => $alias->domain_id,
                'is_active'         => $alias->is_active,
                'outcome'           => 'success',
            ],
            actor: $user,
            reason: 'Email alias created by tenant',
            request: $request
        );

        return (new EmailAliasResource($alias->load('domain')))
            ->response()
            ->setStatusCode(201);
    }


    /**
     * Update an alias destination email or active status
     */
    public function update(Request $request, EmailAlias $alias, PostfixService $postfixService)
    {
        $this->authorize('update', $alias);

        $validated = $request->validate([
            'destination_email' => 'required|string|email|max:255',
            'is_active'         => 'nullable|boolean',
        ]);

        $newDestEmail = strtolower(trim($validated['destination_email']));
        $sourceEmail = strtolower(trim($alias->source_email));

        // Direct self-forwarding loop check
        if ($sourceEmail === $newDestEmail) {
            return response()->json([
                'message' => 'Self-forwarding loop detected: source and destination email cannot be identical.'
            ], 422);
        }

        // Circular forwarding chain loop check
        if ($this->detectForwardingCycle($sourceEmail, $newDestEmail, $alias->id)) {
            return response()->json([
                'message' => 'Forwarding cycle detected: forwarding to this address would create an infinite email loop.'
            ], 422);
        }

        $beforeState = [
            'destination_email' => $alias->destination_email,
            'is_active'         => $alias->is_active,
        ];

        $updateData = [
            'destination_email' => $newDestEmail,
        ];

        if ($request->has('is_active')) {
            $targetActive = $request->boolean('is_active');
            if ($targetActive && !$alias->is_active) {
                if ($alias->domain?->status !== 'active') {
                    return response()->json([
                        'message' => 'Cannot enable alias on an unverified or inactive domain.'
                    ], 422);
                }
                if (!$request->user()->isSubscriptionActive()) {
                    return response()->json([
                        'message' => 'Cannot enable alias while subscription is inactive or suspended.'
                    ], 422);
                }
            }
            $updateData['is_active'] = $targetActive;
        }

        $alias->update($updateData);
        $postfixService->syncAlias($alias);

        app(AuditService::class)->record(
            action: 'alias.update',
            entityType: 'EmailAlias',
            entityId: $alias->id,
            before: $beforeState,
            after: [
                'destination_email' => $alias->destination_email,
                'is_active'         => $alias->is_active,
                'outcome'           => 'success',
            ],
            actor: $request->user(),
            reason: 'Email alias updated by tenant',
            request: $request
        );

        return new EmailAliasResource($alias->load('domain'));
    }

    /**
     * Toggle alias active status
     */
    public function toggle(Request $request, EmailAlias $alias, PostfixService $postfixService)
    {
        $this->authorize('update', $alias);

        $targetState = !$alias->is_active;

        if ($targetState === true) {
            if ($alias->domain?->status !== 'active') {
                return response()->json([
                    'message' => 'Cannot enable alias on an unverified or inactive domain.'
                ], 422);
            }

            if (!$request->user()->isSubscriptionActive()) {
                return response()->json([
                    'message' => 'Cannot enable alias while tenant subscription is inactive or suspended.'
                ], 422);
            }
        }

        $beforeState = ['is_active' => (bool) $alias->is_active];

        $alias->update([
            'is_active' => $targetState,
        ]);

        $postfixService->syncAlias($alias);

        app(AuditService::class)->record(
            action: 'alias.toggle',
            entityType: 'EmailAlias',
            entityId: $alias->id,
            before: $beforeState,
            after: [
                'is_active' => (bool) $alias->is_active,
                'outcome'   => 'success',
            ],
            actor: $request->user(),
            reason: 'Email alias active status toggled by tenant',
            request: $request
        );

        return new EmailAliasResource($alias->load('domain'));
    }

    /**
     * Delete an alias
     */
    public function destroy(Request $request, EmailAlias $alias, PostfixService $postfixService)
    {
        $this->authorize('delete', $alias);

        $beforeState = [
            'source_email'      => $alias->source_email,
            'destination_email' => $alias->destination_email,
            'domain_id'         => $alias->domain_id,
        ];
        $aliasId = $alias->id;

        $postfixService->removeAlias($alias);
        $alias->delete();

        app(AuditService::class)->record(
            action: 'alias.delete',
            entityType: 'EmailAlias',
            entityId: $aliasId,
            before: $beforeState,
            after: [
                'deleted' => true,
                'outcome' => 'success',
            ],
            actor: $request->user(),
            reason: 'Email alias deleted by tenant',
            request: $request
        );

        return response()->json(null, 204);
    }


    /**
     * Detect circular forwarding cycles
     */
    protected function detectForwardingCycle(string $sourceEmail, string $targetEmail, ?int $ignoreAliasId = null): bool
    {
        $sourceEmail = strtolower(trim($sourceEmail));
        $current = strtolower(trim($targetEmail));

        if ($sourceEmail === $current) {
            return true;
        }

        $visited = [
            $sourceEmail => true,
            $current     => true,
        ];

        $maxHops = 30;
        $hops = 0;

        while ($hops < $maxHops) {
            $hops++;
            $query = EmailAlias::where('source_email', $current);
            if ($ignoreAliasId !== null) {
                $query->where('id', '!=', $ignoreAliasId);
            }
            $nextAlias = $query->first();

            if (!$nextAlias) {
                return false;
            }

            $next = strtolower(trim($nextAlias->destination_email));

            if (isset($visited[$next])) {
                return true;
            }

            $visited[$next] = true;
            $current = $next;
        }

        return true;
    }
}

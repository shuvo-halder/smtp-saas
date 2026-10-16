<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\Domain;
use App\Services\DnsVerificationService;
use App\Services\PostfixService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class DomainApiController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $query = $request->user()->domains()->withCount('mailboxes');

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->query('search')) . '%';
            $query->where('domain_name', 'like', $search);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $domains = $query->latest('id')->paginate($perPage);

        return DomainResource::collection($domains);
    }

    public function store(Request $request, PostfixService $postfixService)
    {
        if ($request->has('domain_name')) {
            $request->merge([
                'domain_name' => strtolower(trim((string) $request->input('domain_name'))),
            ]);
        }

        $validated = $request->validate([
            'domain_name' => 'required|string|regex:/^(?!:\/\/)(?=.{1,255}$)((.{1,63}\.){1,127}(?![0-9]*$)[a-z0-9-]+\.?)$/i|unique:domains,domain_name',
        ]);

        $user = $request->user();

        if (!$user->canAddDomain()) {
            return response()->json(['message' => __('api.domain_limit_reached')], 403);
        }

        $domain = \DB::transaction(function () use ($user, $validated, $postfixService) {
            $dom = $user->domains()->create([
                'domain_name' => strtolower($validated['domain_name']),
                'status' => 'pending',
            ]);

            $postfixService->addDomain($dom);

            return $dom;
        });

        app(\App\Services\AuditService::class)->record(
            action: 'domain.create',
            entityType: 'Domain',
            entityId: $domain->id,
            before: null,
            after: [
                'domain_name' => $domain->domain_name,
                'status'      => $domain->status,
                'outcome'     => 'success',
            ],
            actor: $user,
            reason: 'Domain added by tenant',
            request: $request
        );

        return (new DomainResource($domain))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Domain $domain)
    {
        $this->authorize('view', $domain);
        
        $domain->load(['mailboxes', 'aliases']);
        $domain->loadCount('mailboxes');

        return new DomainResource($domain);
    }

    public function verify(Domain $domain, DnsVerificationService $verificationService)
    {
        $this->authorize('update', $domain);
        
        $beforeState = [
            'status'        => $domain->status,
            'mx_verified'   => $domain->mx_verified,
            'spf_verified'  => $domain->spf_verified,
            'dkim_verified' => $domain->dkim_verified,
        ];

        $results = $verificationService->verifyAll($domain);
        $freshDomain = $domain->fresh();
        $isVerified = ($freshDomain->status === 'active');

        app(\App\Services\AuditService::class)->record(
            action: 'domain.dns_verify',
            entityType: 'Domain',
            entityId: $domain->id,
            before: $beforeState,
            after: [
                'status'        => $freshDomain->status,
                'mx_verified'   => $freshDomain->mx_verified,
                'spf_verified'  => $freshDomain->spf_verified,
                'dkim_verified' => $freshDomain->dkim_verified,
            ],
            actor: request()->user(),
            reason: 'Tenant triggered DNS verification check',
            request: request()
        );

        return response()->json([
            'verified' => $isVerified,
            'message' => $isVerified ? __('messages.domain_verified') : 'Domain DNS verification incomplete. Please ensure MX, SPF, and DKIM records are published.',
            'results' => $results,
            'domain' => new DomainResource($freshDomain),
        ]);
    }

    /**
     * Retrieve complete DNS setup wizard data and deliverability diagnostics.
     */
    public function dnsWizard(Domain $domain, DnsVerificationService $dnsService)
    {
        $this->authorize('view', $domain);

        $wizardData = $dnsService->getDnsWizardData($domain);

        return response()->json([
            'data' => $wizardData,
        ]);
    }

    /**
     * Trigger fresh DNS verification and return updated wizard diagnostics.
     */
    public function verifyDns(Domain $domain, DnsVerificationService $dnsService, Request $request)
    {
        $this->authorize('update', $domain);

        $beforeState = [
            'status'        => $domain->status,
            'mx_verified'   => $domain->mx_verified,
            'spf_verified'  => $domain->spf_verified,
            'dkim_verified' => $domain->dkim_verified,
        ];

        $results = $dnsService->verifyAll($domain);
        $freshDomain = $domain->fresh();
        $isVerified = ($freshDomain->status === 'active');
        $wizardData = $dnsService->getDnsWizardData($freshDomain, refresh: true);

        app(\App\Services\AuditService::class)->record(
            action: 'domain.dns_verify',
            entityType: 'Domain',
            entityId: $domain->id,
            before: $beforeState,
            after: [
                'status'        => $freshDomain->status,
                'mx_verified'   => $freshDomain->mx_verified,
                'spf_verified'  => $freshDomain->spf_verified,
                'dkim_verified' => $freshDomain->dkim_verified,
            ],
            actor: $request->user(),
            reason: 'Tenant triggered DNS setup wizard verification check',
            request: $request
        );

        return response()->json([
            'verified' => $isVerified,
            'message'  => $isVerified ? __('messages.domain_verified') : 'Domain DNS verification incomplete. Please ensure required records have propagated.',
            'results'  => $results,
            'domain'   => new DomainResource($freshDomain),
            'wizard'   => $wizardData,
        ]);
    }

    public function destroy(Domain $domain, PostfixService $postfixService)
    {
        $this->authorize('delete', $domain);

        $beforeState = [
            'domain_name' => $domain->domain_name,
            'status'      => $domain->status,
        ];
        $domainId = $domain->id;

        \DB::transaction(function () use ($domain, $postfixService) {
            $domain->mailboxes()->delete();
            $domain->aliases()->delete();
            $postfixService->removeDomain($domain);
            $domain->delete();
        });

        app(\App\Services\AuditService::class)->record(
            action: 'domain.delete',
            entityType: 'Domain',
            entityId: $domainId,
            before: $beforeState,
            after: [
                'deleted' => true,
                'outcome' => 'success',
            ],
            actor: request()->user(),
            reason: 'Domain deleted by tenant',
            request: request()
        );

        return response()->json(null, 204);
    }
}

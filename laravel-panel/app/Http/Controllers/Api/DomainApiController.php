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
        $domains = $request->user()->domains()->withCount('mailboxes')->paginate(15);
        return DomainResource::collection($domains);
    }

    public function store(Request $request, PostfixService $postfixService)
    {
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

        return new DomainResource($domain);
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
        
        $results = $verificationService->verifyAll($domain);

        return response()->json([
            'message' => __('messages.domain_verified'),
            'results' => $results,
            'domain' => new DomainResource($domain->fresh()),
        ]);
    }

    public function destroy(Domain $domain, PostfixService $postfixService)
    {
        $this->authorize('delete', $domain);

        $postfixService->removeDomain($domain);
        $domain->delete();

        return response()->json(null, 204);
    }
}

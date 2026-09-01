<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Http\Resources\InvoiceResource;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user()->load('plan');
        
        $domains = $user->domains()->withCount('mailboxes')->latest()->take(5)->get();
        $invoices = $user->invoices()->latest()->take(5)->get();

        return response()->json([
            'plan_info' => $user->plan ? [
                'name' => $user->plan->name,
                'expires_at' => $user->plan_expires_at,
                'max_domains' => $user->plan->max_domains,
                'max_mailboxes_per_domain' => $user->plan->max_mailboxes_per_domain,
            ] : null,
            'domain_count' => $user->domains()->count(),
            'mailbox_count' => $user->mailboxes()->count(),
            'recent_domains' => DomainResource::collection($domains),
            'recent_invoices' => InvoiceResource::collection($invoices),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanResource;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class BillingApiController extends Controller
{
    use AuthorizesRequests;

    public function plans()
    {
        $plans = Plan::where('is_active', true)->get();
        return PlanResource::collection($plans);
    }

    public function subscription(Request $request)
    {
        $user = $request->user()->load('plan');
        $plan = $user->plan;

        $now = now();
        $expiresAt = $user->plan_expires_at;
        $isActive = $user->isSubscriptionActive();

        $status = 'inactive';
        if ($user->status === 'suspended') {
            $status = 'suspended';
        } elseif ($plan) {
            if ($expiresAt && $expiresAt->isPast()) {
                $status = 'expired';
            } elseif ($isActive) {
                $status = 'active';
            }
        }

        $daysRemaining = null;
        $isExpiringSoon = false;
        if ($expiresAt) {
            $diffInSeconds = $now->diffInSeconds($expiresAt, false);
            $daysRemaining = $diffInSeconds > 0 ? (int) ceil($diffInSeconds / 86400) : 0;
            $isExpiringSoon = $isActive && $daysRemaining <= 7;
        }

        $todayUsage = \App\Models\TenantOutboundUsage::where('user_id', $user->id)
            ->where('usage_date', $now->toDateString())
            ->first();
        $outboundToday = $todayUsage ? (int) $todayUsage->recipient_count : 0;

        $domainsUsed = $user->domains()->count();
        $mailboxesUsed = $user->mailboxes()->count();

        $mailboxesLimit = -1;
        if ($plan) {
            if ($plan->max_mailboxes_per_domain !== -1 && $plan->max_domains !== -1) {
                $mailboxesLimit = $plan->max_mailboxes_per_domain * $plan->max_domains;
            } elseif ($plan->max_mailboxes_per_domain !== -1) {
                $mailboxesLimit = $plan->max_mailboxes_per_domain * max($domainsUsed, 1);
            }
        }

        return response()->json([
            'plan' => $plan ? new PlanResource($plan) : null,
            'subscription' => [
                'status' => $status,
                'is_active' => $isActive,
                'expires_at' => $expiresAt?->toIso8601String(),
                'days_remaining' => $daysRemaining,
                'is_expiring_soon' => $isExpiringSoon,
                'can_renew' => (bool) $plan,
            ],
            'usage' => [
                'domains_used' => $domainsUsed,
                'domains_limit' => $plan ? $plan->max_domains : 0,
                'mailboxes_used' => $mailboxesUsed,
                'mailboxes_limit' => $mailboxesLimit,
                'max_mailboxes_per_domain' => $plan ? $plan->max_mailboxes_per_domain : 0,
                'outbound_today' => $outboundToday,
                'outbound_daily_limit' => $plan ? $plan->daily_outbound_recipients : 0,
                'storage_mb_per_mailbox' => $plan ? $plan->storage_mb_per_mailbox : 0,
            ],
        ]);
    }

    public function invoices(Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);
        $status = $request->input('status');

        $query = $request->user()->invoices()->with('plan')->latest();

        if ($status && in_array($status, ['paid', 'pending', 'unpaid', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $invoices = $query->paginate($perPage);

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load('plan');

        return new InvoiceResource($invoice);
    }

    public function checkout(Request $request, BillingService $billingService)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        
        $paymentData = $billingService->initiatePayment($request->user(), $plan, $validated['billing_cycle']);

        return response()->json([
            'redirect_url' => $paymentData['redirect_url']
        ]);
    }

    public function downloadInvoice(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        // Assume there is a method or service to generate/download PDF
        // For API, we might return a base64 string or a temporary signed URL.
        // Assuming a method like return PDF binary or download response.
        // As a generic implementation:
        return response()->download(storage_path('app/invoices/' . $invoice->id . '.pdf'));
    }

    public function ipn(Request $request, BillingService $billingService)
    {
        $billingService->handleIpn($request->all());
        
        return response()->json(['message' => __('messages.ipn_processed')]);
    }

    public function success(Request $request)
    {
        // Typically SSLCommerz posts transaction details here.
        // IPN handles the actual DB update asynchronously.
        $frontendUrl = env('FRONTEND_URL', 'https://' . config('app.base_domain')) . '/billing/success';
        return redirect()->away($frontendUrl);
    }

    public function fail(Request $request)
    {
        $frontendUrl = env('FRONTEND_URL', 'https://' . config('app.base_domain')) . '/billing/fail';
        return redirect()->away($frontendUrl);
    }

    public function cancel(Request $request)
    {
        $frontendUrl = env('FRONTEND_URL', 'https://' . config('app.base_domain')) . '/billing/fail';
        return redirect()->away($frontendUrl);
    }
}

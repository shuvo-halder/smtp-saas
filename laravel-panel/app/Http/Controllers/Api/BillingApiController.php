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

    public function invoices(Request $request)
    {
        $invoices = $request->user()->invoices()->with('plan')->latest()->paginate(15);
        return InvoiceResource::collection($invoices);
    }

    public function checkout(Request $request, BillingService $billingService)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        
        $redirectUrl = $billingService->initiatePayment($request->user(), $plan, $validated['billing_cycle']);

        return response()->json([
            'redirect_url' => $redirectUrl
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

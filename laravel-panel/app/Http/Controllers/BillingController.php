<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing) {}

    /**
     * Show billing dashboard (current plan + invoice history)
     */
    public function index()
    {
        $user     = Auth::user()->load('plan');
        $invoices = Auth::user()->invoices()->with('plan')->paginate(10);
        $plans    = Plan::active()->get();

        return view('billing.index', compact('user', 'invoices', 'plans'));
    }

    /**
     * Show plan selection / upgrade page
     */
    public function plans()
    {
        $plans = Plan::active()->get();
        $currentPlan = Auth::user()->plan;

        return view('billing.plans', compact('plans', 'currentPlan'));
    }

    /**
     * Initiate payment for a plan
     */
    public function checkout(Request $request)
    {
        $request->validate([
            'plan_id'       => 'required|exists:plans,id',
            'billing_cycle' => 'required|in:monthly,yearly',
        ]);

        $plan = Plan::findOrFail($request->plan_id);
        $user = Auth::user();

        try {
            $result = $this->billing->initiatePayment($user, $plan, $request->billing_cycle);

            // Redirect to SSLCommerz payment page
            return redirect($result['redirect_url']);

        } catch (\Exception $e) {
            Log::error('Payment initiation failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            return back()->with('error', 'Payment শুরু করা সম্ভব হয়নি। আবার চেষ্টা করুন।');
        }
    }

    /**
     * SSLCommerz success callback
     */
    public function success(Request $request)
    {
        $tran_id = $request->input('tran_id');
        $invoice = \App\Models\Invoice::where('invoice_number', $tran_id)->first();

        if ($invoice && $invoice->status === 'paid') {
            return redirect()->route('billing.index')
                ->with('success', '🎉 Payment সফল! আপনার subscription active হয়েছে।');
        }

        // Wait for IPN (sometimes IPN arrives before success callback)
        return redirect()->route('billing.index')
            ->with('info', 'Payment processing হচ্ছে... কিছুক্ষণ পর refresh করুন।');
    }

    /**
     * SSLCommerz fail callback
     */
    public function fail(Request $request)
    {
        return redirect()->route('billing.plans')
            ->with('error', 'Payment ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
    }

    /**
     * SSLCommerz cancel callback
     */
    public function cancel(Request $request)
    {
        return redirect()->route('billing.plans')
            ->with('warning', 'Payment বাতিল করা হয়েছে।');
    }

    /**
     * SSLCommerz IPN (Instant Payment Notification) webhook
     */
    public function ipn(Request $request)
    {
        $success = $this->billing->handleIpn($request->all());

        return response()->json(['status' => $success ? 'ok' : 'failed']);
    }

    /**
     * Download invoice PDF
     */
    public function downloadInvoice(\App\Models\Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('billing.invoice-pdf', compact('invoice'));

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}

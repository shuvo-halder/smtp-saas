<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BillingService — Handles SSLCommerz payment gateway integration
 * for Bangladesh market. Supports monthly & yearly billing cycles.
 */
class BillingService
{
    private string $storeId;
    private string $storePass;
    private bool   $isSandbox;
    private string $baseUrl;

    public function __construct()
    {
        $this->storeId   = config('services.sslcommerz.store_id');
        $this->storePass = config('services.sslcommerz.store_pass');
        $this->isSandbox = config('services.sslcommerz.sandbox', false);
        $this->baseUrl   = $this->isSandbox
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';
    }

    /**
     * Create a new invoice and initiate SSLCommerz payment
     */
    public function initiatePayment(User $user, Plan $plan, string $billingCycle): array
    {
        $amount = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $tax    = round($amount * 0.00, 2);  // Adjust tax rate as needed
        $total  = $amount + $tax;

        // Create invoice
        $invoice = Invoice::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'billing_cycle'  => $billingCycle,
            'subtotal'       => $amount,
            'tax'            => $tax,
            'total'          => $total,
            'currency'       => 'BDT',
            'status'         => 'pending',
            'payment_gateway'=> 'sslcommerz',
            'due_date'       => now()->addDays(3),
        ]);

        // SSLCommerz payment initiation
        $postData = [
            'store_id'         => $this->storeId,
            'store_passwd'     => $this->storePass,
            'total_amount'     => $total,
            'currency'         => 'BDT',
            'tran_id'          => $invoice->invoice_number,
            'success_url'      => route('billing.success'),
            'fail_url'         => route('billing.fail'),
            'cancel_url'       => route('billing.cancel'),
            'ipn_url'          => route('billing.ipn'),

            // Customer info
            'cus_name'         => $user->name,
            'cus_email'        => $user->email,
            'cus_phone'        => $user->phone ?? '01700000000',
            'cus_add1'         => $user->address ?? 'Dhaka',
            'cus_country'      => 'Bangladesh',
            'cus_city'         => 'Dhaka',

            // Product info
            'product_name'     => "EmailSaaS {$plan->name} Plan ({$billingCycle})",
            'product_category' => 'Software',
            'product_profile'  => 'non-physical-goods',
            'shipping_method'  => 'NO',

            // Multi-card payment
            'multi_card_name'  => 'mastercard,visacard,bkash,nagad,rocket',
        ];

        $response = Http::asForm()
            ->post("{$this->baseUrl}/gwprocess/apiindex.php", $postData);

        if (! $response->successful()) {
            Log::error('SSLCommerz initiation failed', ['response' => $response->body()]);
            throw new \RuntimeException('Payment gateway error. Please try again.');
        }

        $data = $response->json();

        if ($data['status'] !== 'SUCCESS') {
            throw new \RuntimeException($data['failedreason'] ?? 'Payment initiation failed.');
        }

        $invoice->update(['transaction_id' => $data['sessionkey']]);

        return [
            'invoice'       => $invoice,
            'redirect_url'  => $data['GatewayPageURL'],
            'session_key'   => $data['sessionkey'],
        ];
    }

    /**
     * Handle SSLCommerz IPN (Instant Payment Notification) webhook
     */
    public function handleIpn(array $data): bool
    {
        // Validate IPN hash
        if (! $this->validateIpnHash($data)) {
            Log::warning('SSLCommerz IPN hash validation failed', $data);
            return false;
        }

        $invoice = Invoice::where('invoice_number', $data['tran_id'])->first();
        if (! $invoice) {
            Log::warning('Invoice not found for IPN', ['tran_id' => $data['tran_id']]);
            return false;
        }

        if ($data['status'] === 'VALID' || $data['status'] === 'VALIDATED') {
            // Security Check: Verify the amount paid matches the invoice total
            $paidAmount = (float) ($data['amount'] ?? 0);
            $invoiceTotal = (float) $invoice->total;
            
            if (abs($paidAmount - $invoiceTotal) > 0.01) {
                Log::error('SSLCommerz IPN Amount Mismatch', [
                    'expected' => $invoiceTotal,
                    'received' => $paidAmount,
                    'tran_id'  => $data['tran_id']
                ]);
                return false;
            }

            $this->markInvoicePaid($invoice, $data);
            return true;
        }

        if ($data['status'] === 'FAILED') {
            $invoice->update([
                'status'           => 'failed',
                'payment_response' => $data,
            ]);
        }

        return false;
    }

    /**
     * Mark invoice as paid and activate the subscription
     */
    public function markInvoicePaid(Invoice $invoice, array $gatewayData = []): void
    {
        $invoice->update([
            'status'                  => 'paid',
            'gateway_transaction_id'  => $gatewayData['bank_tran_id'] ?? null,
            'payment_response'        => $gatewayData,
            'paid_at'                 => now(),
            'period_start'            => now()->toDateString(),
            'period_end'              => $invoice->billing_cycle === 'yearly'
                                            ? now()->addYear()->toDateString()
                                            : now()->addMonth()->toDateString(),
        ]);

        // Activate user subscription
        $user = $invoice->user;
        $user->update([
            'plan_id'         => $invoice->plan_id,
            'status'          => 'active',
            'plan_expires_at' => $invoice->billing_cycle === 'yearly'
                                    ? now()->addYear()
                                    : now()->addMonth(),
        ]);

        Log::info('Invoice paid and subscription activated', [
            'invoice_id' => $invoice->id,
            'user_id'    => $user->id,
        ]);

        // Send confirmation email
        $user->notify(new \App\Notifications\PaymentConfirmed($invoice));
    }

    private function validateIpnHash(array $data): bool
    {
        if (empty($data['verify_sign']) || empty($data['verify_key'])) {
            return false;
        }

        $keys = explode(',', $data['verify_key']);
        $preSign = '';
        foreach ($keys as $key) {
            $preSign .= $key . '=' . ($data[$key] ?? '') . '&';
        }
        $preSign .= 'store_passwd=' . md5($this->storePass);

        return md5($preSign) === $data['verify_sign'];
    }
}

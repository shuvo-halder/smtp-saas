<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'billing_cycle' => $this->billing_cycle,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_gateway' => $this->payment_gateway,
            'paid_at' => $this->paid_at,
            'due_date' => $this->due_date,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'formatted_total' => $this->formattedTotal(),
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'created_at' => $this->created_at,
        ];
    }
}

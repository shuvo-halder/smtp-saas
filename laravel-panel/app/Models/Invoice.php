<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'invoice_number',
        'billing_cycle', 'subtotal', 'tax', 'total',
        'currency', 'status', 'payment_gateway',
        'transaction_id', 'gateway_transaction_id',
        'payment_response', 'paid_at', 'due_date',
        'period_start', 'period_end',
    ];

    protected function casts(): array
    {
        return [
            'payment_response' => 'array',
            'paid_at'          => 'datetime',
            'due_date'         => 'datetime',
            'period_start'     => 'date',
            'period_end'       => 'date',
            'subtotal'         => 'decimal:2',
            'tax'              => 'decimal:2',
            'total'            => 'decimal:2',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    public static function generateInvoiceNumber(): string
    {
        $year  = date('Y');
        $month = date('m');
        $last  = static::whereYear('created_at', $year)
                        ->whereMonth('created_at', $month)
                        ->count();
        return sprintf('INV-%s%s-%04d', $year, $month, $last + 1);
    }

    public function isPaid(): bool    { return $this->status === 'paid'; }
    public function isPending(): bool { return $this->status === 'pending'; }

    public function formattedTotal(): string
    {
        return $this->currency . ' ' . number_format($this->total, 2);
    }

    public function scopePaid($query)    { return $query->where('status', 'paid'); }
    public function scopePending($query) { return $query->where('status', 'pending'); }
}

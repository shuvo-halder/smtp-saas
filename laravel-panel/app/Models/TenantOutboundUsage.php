<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOutboundUsage extends Model
{
    protected $table = 'tenant_outbound_usage';

    protected $fillable = [
        'user_id',
        'usage_date',
        'recipient_count',
    ];

    protected function casts(): array
    {
        return [
            'usage_date'      => 'date:Y-m-d',
            'recipient_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

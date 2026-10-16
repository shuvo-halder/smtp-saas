<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAlias extends Model
{
    use HasFactory;

    protected $table = 'email_aliases';

    protected $fillable = [
        'domain_id',
        'source_email',
        'destination_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->whereHas('domain', function (Builder $q) use ($tenantId) {
            $q->where('user_id', $tenantId);
        });
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $term = '%' . trim($search) . '%';
        return $query->where(function (Builder $q) use ($term) {
            $q->where('source_email', 'like', $term)
              ->orWhere('destination_email', 'like', $term);
        });
    }

    public function scopeByStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        if ($status === 'active') {
            return $query->where('is_active', true);
        }

        if ($status === 'inactive' || $status === 'disabled') {
            return $query->where('is_active', false);
        }

        return $query;
    }
}

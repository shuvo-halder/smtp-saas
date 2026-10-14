<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ScheduledTaskRun extends Model
{
    use HasFactory;

    protected $table = 'scheduled_task_runs';

    protected $fillable = [
        'uuid',
        'task_name',
        'expression',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'failure_summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Scope query to successful executions.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope query to failed executions.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope query to recent executions.
     */
    public function scopeRecent(Builder $query, int $limit = 20): Builder
    {
        return $query->latest('started_at')->limit($limit);
    }

    /**
     * Scope query to a specific task name.
     */
    public function scopeForTask(Builder $query, string $taskName): Builder
    {
        return $query->where('task_name', $taskName);
    }
}

<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\JobType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Job extends Model
{
    use HasUuids;

    protected $table = 'backup_jobs';

    public $timestamps = false;

    protected $fillable = [
        'plan_id',
        'job_type',
        'status',
        'started_at',
        'finished_at',
        'log_path',
        'error_message',
        'progress',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'job_type' => JobType::class,
            'status' => JobStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'progress' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Job $job) {
            $job->created_at ??= now();
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BackupPlan::class, 'plan_id');
    }

    public function getPlanNameAttribute(): string
    {
        return $this->plan?->name ?? '';
    }

    /**
     * Check if this job has been cancelled (e.g. by user from the UI).
     * Queue jobs should call this periodically and abort if true.
     */
    public function isCancelled(): bool
    {
        return $this->fresh()?->status === \App\Enums\JobStatus::Cancelled;
    }
}

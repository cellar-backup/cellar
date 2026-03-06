<?php

namespace App\Models;

use App\Enums\EngineType;
use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupPlan extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'source_id',
        'repository_id',
        'engine',
        'status',
        'schedule_cron',
        'schedule_enabled',
        'next_run',
        'last_run',
        'retention_policy',
        'compression',
        'encryption',
        'pre_hook',
        'post_hook',
    ];

    protected function casts(): array
    {
        return [
            'engine' => EngineType::class,
            'status' => PlanStatus::class,
            'schedule_enabled' => 'boolean',
            'next_run' => 'datetime',
            'last_run' => 'datetime',
            'retention_policy' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Retention now lives on the Source model — no default needed here.
    }

    // ── Relationships ──────────────────────────────────────────

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class, 'plan_id');
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class, 'plan_id');
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class, 'backup_plan_id');
    }

    // ── Accessors ──────────────────────────────────────────────

    public function getSourceNameAttribute(): string
    {
        return $this->source?->display_label ?? '';
    }

    public function getSourceTypeAttribute(): string
    {
        return $this->source?->source_type?->value ?? '';
    }

    public function getRepositoryNameAttribute(): string
    {
        return $this->repository?->name ?? '';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Archive extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'plan_id',
        'archive_id',
        'timestamp',
        'size_original',
        'size_dedup',
        'size_compressed',
        'duration',
        'file_count',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'size_original' => 'integer',
            'size_dedup' => 'integer',
            'size_compressed' => 'integer',
            'duration' => 'integer',
            'file_count' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Archive $archive) {
            $archive->created_at ??= now();
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
}

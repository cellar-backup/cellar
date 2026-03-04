<?php

namespace App\Models;

use App\Enums\BackendType;
use App\Enums\RepoStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'description',
        'backend_type',
        'status',
        'is_default',
        'config',
        'capacity_bytes',
        'used_bytes',
        'last_checked',
    ];

    protected function casts(): array
    {
        return [
            'backend_type' => BackendType::class,
            'status' => RepoStatus::class,
            'is_default' => 'boolean',
            'config' => 'encrypted:array',
            'capacity_bytes' => 'integer',
            'used_bytes' => 'integer',
            'last_checked' => 'datetime',
        ];
    }

    public function backupPlans(): HasMany
    {
        return $this->hasMany(BackupPlan::class);
    }

    public function getPlanCountAttribute(): int
    {
        return $this->backupPlans()->count();
    }
}

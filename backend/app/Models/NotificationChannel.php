<?php

namespace App\Models;

use App\Enums\ChannelType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'channel_type',
        'config',
        'events_filter',
        'enabled',
        'backup_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'config' => 'encrypted:array',
            'events_filter' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function backupPlan(): BelongsTo
    {
        return $this->belongsTo(BackupPlan::class);
    }
}

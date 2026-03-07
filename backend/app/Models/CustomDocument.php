<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'version',
        'description',
        'backup_command',
        'restore_command',
        'health_check',
        'env_vars',
        'stream_to_engine',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'env_vars' => 'array',
            'stream_to_engine' => 'boolean',
        ];
    }
}

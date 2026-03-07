<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'type',
        'config',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_default' => 'boolean',
        ];
    }

    // ── Scopes ─────────────────────────────────────────────────

    public function scopeSchedule($query)
    {
        return $query->where('type', 'schedule');
    }

    public function scopeRetention($query)
    {
        return $query->where('type', 'retention');
    }

    // ── Helpers ─────────────────────────────────────────────────

    public static function defaultRetention(): ?self
    {
        return static::where('type', 'retention')->where('is_default', true)->first();
    }

    public static function defaultSchedule(): ?self
    {
        return static::where('type', 'schedule')->where('is_default', true)->first();
    }
}

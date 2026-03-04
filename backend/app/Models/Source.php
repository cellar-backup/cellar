<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'source_type',
        'host',
        'port',
        'username',
        'password',
        'database_name',
        'path',
        'enabled',
        'notes',
        'extra_config',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'port' => 'integer',
            'password' => 'encrypted',
            'enabled' => 'boolean',
            'extra_config' => 'array',
        ];
    }

    // ── Auto-fill helpers ──────────────────────────────────────

    protected static function booted(): void
    {
        static::saving(function (Source $source) {
            // Auto-fill default port
            if (is_null($source->port) && $source->source_type) {
                $source->port = $source->source_type->defaultPort();
            }

            // Auto-generate name
            if (empty($source->name)) {
                $source->name = $source->generateName();
            }
        });
    }

    public function generateName(): string
    {
        if ($this->getIsDatabase() && $this->database_name) {
            $label = $this->database_name;
            if ($this->host) {
                $label .= ' on '.$this->host;
            }

            return $label;
        }

        if ($this->path) {
            return basename($this->path) ?: $this->path;
        }

        return $this->source_type?->value ?? 'unnamed';
    }

    // ── Accessors ──────────────────────────────────────────────

    public function getIsDatabase(): bool
    {
        return $this->source_type?->isDatabase() ?? false;
    }

    public function getDisplayLabelAttribute(): string
    {
        return $this->name ?: $this->generateName();
    }

    // ── Relationships ──────────────────────────────────────────

    public function backupPlans(): HasMany
    {
        return $this->hasMany(BackupPlan::class);
    }
}

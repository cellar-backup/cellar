<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Process;

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
        'retention_policy',
        'is_reachable',
        'last_checked_at',
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
            'retention_policy' => 'array',
            'is_reachable' => 'boolean',
            'last_checked_at' => 'datetime',
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

            // Auto-fill default retention policy
            if (empty($source->retention_policy)) {
                $source->retention_policy = [
                    'keep_daily' => 7,
                    'keep_weekly' => 4,
                    'keep_monthly' => 6,
                ];
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

    // ── Connectivity ────────────────────────────────────────────

    /**
     * Test connectivity to this source. Returns true if reachable.
     */
    public function checkConnection(): bool
    {
        if (! $this->getIsDatabase()) {
            $path = $this->path;

            return ! empty($path) && file_exists($path) && is_readable($path);
        }

        $host = $this->host ?: 'localhost';
        $port = $this->port ?: $this->source_type->defaultPort() ?? 5432;
        $user = $this->username ?: ($this->source_type->value === 'postgresql' ? 'postgres' : 'root');

        $result = match ($this->source_type->value) {
            'postgresql' => Process::timeout(10)
                ->env(['PGPASSWORD' => $this->password ?? ''])
                ->run([
                    'psql', '-h', $host, '-p', (string) $port,
                    '-U', $user, '--no-password',
                    '-c', 'SELECT 1',
                    $this->database_name ?: 'postgres',
                ]),
            'mysql', 'mariadb' => Process::timeout(10)->run([
                'mysqladmin', 'ping',
                '-h', $host,
                '-P', (string) $port,
                '-u', $user,
                ...($this->password ? ['--password='.$this->password] : []),
            ]),
            default => null,
        };

        if ($result === null) {
            return false;
        }

        return $result->successful();
    }

    // ── Relationships ──────────────────────────────────────────

    public function backupPlans(): HasMany
    {
        return $this->hasMany(BackupPlan::class);
    }
}

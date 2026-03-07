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

        // If configured for kubectl exec, test via the pod instead of direct network
        $k8s = $this->extra_config['kubernetes'] ?? null;
        $dumpMethod = $k8s['dump_method'] ?? null;

        if ($dumpMethod === 'kubectl'
            && $k8s && ! empty($k8s['cluster_id']) && ! empty($k8s['namespace']) && ! empty($k8s['app_name'])) {
            return $this->checkConnectionViaKubectl($k8s);
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

    /**
     * Test connectivity by running a simple query inside the K8s pod via kubectl exec.
     */
    private function checkConnectionViaKubectl(array $k8s): bool
    {
        try {
            $cluster = RadarCluster::find($k8s['cluster_id']);
            if (! $cluster) {
                return false;
            }

            $kubectlPath = config('cellar.kubectl_path', '/usr/local/bin/kubectl');
            $tempKubeconfig = $cluster->writeKubeconfigTempFile();

            try {
                $kubectlConfig = [
                    'kubectl_path' => $kubectlPath,
                    'kubeconfig' => $tempKubeconfig,
                    'context' => $cluster->context,
                    'namespace' => $k8s['namespace'],
                    'pod' => null,
                ];

                $podName = \App\Services\DatabaseDumper::findKubectlPod($kubectlConfig, $k8s['app_name']);
                if (! $podName) {
                    return false;
                }

                $kubectlConfig['pod'] = $podName;

                $user = $this->username ?: ($this->source_type->value === 'postgresql' ? 'postgres' : 'root');
                $database = $this->database_name ?: ($this->source_type->value === 'postgresql' ? 'postgres' : '');

                $checkCmd = match ($this->source_type->value) {
                    'postgresql' => "PGPASSWORD=".escapeshellarg($this->password ?? '')
                        ." psql -U ".escapeshellarg($user)
                        ." -d ".escapeshellarg($database)
                        ." -c 'SELECT 1' --no-password -q",
                    'mysql', 'mariadb' => "mysqladmin ping"
                        ." -u ".escapeshellarg($user)
                        .($this->password ? " --password=".escapeshellarg($this->password) : ""),
                    default => null,
                };

                if ($checkCmd === null) {
                    return false;
                }

                // Build kubectl exec command
                $cmd = [$kubectlPath];
                if (! empty($tempKubeconfig)) {
                    $cmd[] = '--kubeconfig';
                    $cmd[] = $tempKubeconfig;
                }
                if (! empty($cluster->context)) {
                    $cmd[] = '--context';
                    $cmd[] = $cluster->context;
                }
                $cmd = array_merge($cmd, [
                    'exec', $podName,
                    '-n', $k8s['namespace'],
                    '--', 'sh', '-c', $checkCmd,
                ]);

                $result = Process::timeout(15)->run($cmd);

                return $result->successful();
            } finally {
                if (file_exists($tempKubeconfig)) {
                    @unlink($tempKubeconfig);
                }
            }
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Relationships ──────────────────────────────────────────

    public function backupPlans(): HasMany
    {
        return $this->hasMany(BackupPlan::class);
    }
}

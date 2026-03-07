<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RadarCluster extends Model
{
    use HasUuids;

    protected $table = 'radar_clusters';

    protected $fillable = [
        'name',
        'kubeconfig',
        'context',
        'default_namespace',
        'is_active',
        'last_scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'kubeconfig' => 'encrypted',
            'is_active' => 'boolean',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function ignores(): HasMany
    {
        return $this->hasMany(RadarIgnore::class, 'cluster_id');
    }

    /**
     * Write the kubeconfig content to a temporary file and return its path.
     * Caller is responsible for cleanup.
     */
    public function writeKubeconfigTempFile(): ?string
    {
        if (! $this->kubeconfig) {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'cellar-kubeconfig-');
        file_put_contents($tmpPath, $this->kubeconfig);
        chmod($tmpPath, 0600);

        return $tmpPath;
    }
}

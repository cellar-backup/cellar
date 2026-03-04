<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Discovers databases and PVCs in a Kubernetes cluster.
 *
 * Works in two modes:
 *  1. In-cluster  – uses the default ServiceAccount token
 *  2. Out-of-cluster – uses a kubeconfig file / explicit API server + token
 */
class KubernetesDiscovery
{
    /** Known database container image prefixes → SourceType mapping */
    private const IMAGE_MAP = [
        'postgres' => 'postgresql',
        'pgvector' => 'postgresql',
        'timescale' => 'postgresql',
        'mysql' => 'mysql',
        'mariadb' => 'mariadb',
        'mongo' => 'mongodb',
        'redis' => 'redis',
        'valkey' => 'redis',
        'keydb' => 'redis',
        'dragonfly' => 'redis',
    ];

    /** Known database port numbers → SourceType fallback */
    private const PORT_MAP = [
        5432 => 'postgresql',
        3306 => 'mysql',
        27017 => 'mongodb',
        6379 => 'redis',
    ];

    /** Well-known database StatefulSet labels / annotations */
    private const DB_LABELS = [
        'app.kubernetes.io/component' => ['database', 'db', 'postgresql', 'mysql', 'mariadb', 'mongodb', 'redis', 'primary', 'master'],
    ];

    private string $kubectlPath;

    private ?string $kubeconfig;

    private ?string $context;

    public function __construct(
        ?string $kubectlPath = null,
        ?string $kubeconfig = null,
        ?string $context = null,
    ) {
        $this->kubectlPath = $kubectlPath ?? config('cellar.kubectl_path', '/usr/local/bin/kubectl');
        $this->kubeconfig = $kubeconfig;
        $this->context = $context;
    }

    // ── Shell helpers ──────────────────────────────────────────

    private function kubectl(array $args, int $timeout = 30): array
    {
        $cmd = [$this->kubectlPath];

        if ($this->kubeconfig) {
            $cmd[] = '--kubeconfig';
            $cmd[] = $this->kubeconfig;
        }

        if ($this->context) {
            $cmd[] = '--context';
            $cmd[] = $this->context;
        }

        $cmd = array_merge($cmd, $args, ['-o', 'json']);

        $result = Process::timeout($timeout)->run($cmd);

        if (! $result->successful()) {
            throw new RuntimeException(
                'kubectl failed: '.$result->errorOutput()
            );
        }

        $decoded = json_decode($result->output(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse kubectl JSON: '.$result->output());
        }

        return $decoded;
    }

    // ── Public API ─────────────────────────────────────────────

    /**
     * Check if we can reach the cluster.
     */
    public function testConnection(): array
    {
        try {
            $data = $this->kubectl(['cluster-info', 'dump', '--output-directory=/dev/null']);
        } catch (\Throwable $e) {
            // Fallback — just try to list namespaces
            try {
                $data = $this->kubectl(['get', 'namespaces']);

                return [
                    'connected' => true,
                    'namespace_count' => count($data['items'] ?? []),
                ];
            } catch (\Throwable $inner) {
                return [
                    'connected' => false,
                    'error' => $inner->getMessage(),
                ];
            }
        }

        return ['connected' => true];
    }

    /**
     * List all namespaces.
     */
    public function getNamespaces(): array
    {
        $data = $this->kubectl(['get', 'namespaces']);

        return array_map(
            fn (array $ns) => $ns['metadata']['name'] ?? '',
            $data['items'] ?? [],
        );
    }

    /**
     * Discover databases and backup-eligible resources across all namespaces
     * (or a specific namespace).
     *
     * Returns an array of discovered resources with enough information to
     * create a Source record.
     */
    public function discover(?string $namespace = null): array
    {
        $resources = [];

        // 1. Scan pods for database containers
        $resources = array_merge(
            $resources,
            $this->discoverFromPods($namespace),
        );

        // 2. Scan services for database endpoints
        $resources = array_merge(
            $resources,
            $this->discoverFromServices($namespace),
        );

        // 3. Scan PVCs as potential directory backup targets
        $resources = array_merge(
            $resources,
            $this->discoverPVCs($namespace),
        );

        // Deduplicate by a composite key (namespace + name + type)
        $seen = [];
        $unique = [];
        foreach ($resources as $r) {
            $key = "{$r['namespace']}:{$r['name']}:{$r['source_type']}";
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $r;
            }
        }

        return $unique;
    }

    // ── Scanners ───────────────────────────────────────────────

    private function discoverFromPods(?string $namespace = null): array
    {
        $args = ['get', 'pods'];
        if ($namespace) {
            $args[] = '-n';
            $args[] = $namespace;
        } else {
            $args[] = '--all-namespaces';
        }

        $data = $this->kubectl($args);
        $found = [];

        foreach ($data['items'] ?? [] as $pod) {
            $ns = $pod['metadata']['namespace'] ?? 'default';
            $podName = $pod['metadata']['name'] ?? '';
            $labels = $pod['metadata']['labels'] ?? [];

            foreach ($pod['spec']['containers'] ?? [] as $container) {
                $image = $container['image'] ?? '';
                $sourceType = $this->detectSourceTypeFromImage($image);

                if (! $sourceType) {
                    continue;
                }

                // Extract port
                $port = null;
                foreach ($container['ports'] ?? [] as $p) {
                    $cp = $p['containerPort'] ?? 0;
                    if (isset(self::PORT_MAP[$cp])) {
                        $port = $cp;
                        break;
                    }
                }

                // Try to find a service name from labels
                $appName = $labels['app.kubernetes.io/name']
                    ?? $labels['app']
                    ?? $podName;

                $found[] = [
                    'kind' => 'Pod',
                    'namespace' => $ns,
                    'name' => $appName,
                    'pod_name' => $podName,
                    'source_type' => $sourceType,
                    'image' => $image,
                    'host' => "{$appName}.{$ns}.svc.cluster.local",
                    'port' => $port ?? $this->defaultPort($sourceType),
                    'labels' => $labels,
                ];
            }
        }

        return $found;
    }

    private function discoverFromServices(?string $namespace = null): array
    {
        $args = ['get', 'services'];
        if ($namespace) {
            $args[] = '-n';
            $args[] = $namespace;
        } else {
            $args[] = '--all-namespaces';
        }

        $data = $this->kubectl($args);
        $found = [];

        foreach ($data['items'] ?? [] as $svc) {
            $ns = $svc['metadata']['namespace'] ?? 'default';
            $svcName = $svc['metadata']['name'] ?? '';
            $labels = $svc['metadata']['labels'] ?? [];

            foreach ($svc['spec']['ports'] ?? [] as $portSpec) {
                $port = $portSpec['port'] ?? 0;

                if (isset(self::PORT_MAP[$port])) {
                    $sourceType = self::PORT_MAP[$port];

                    $found[] = [
                        'kind' => 'Service',
                        'namespace' => $ns,
                        'name' => $svcName,
                        'source_type' => $sourceType,
                        'image' => null,
                        'host' => "{$svcName}.{$ns}.svc.cluster.local",
                        'port' => $port,
                        'labels' => $labels,
                    ];
                }
            }
        }

        return $found;
    }

    private function discoverPVCs(?string $namespace = null): array
    {
        $args = ['get', 'pvc'];
        if ($namespace) {
            $args[] = '-n';
            $args[] = $namespace;
        } else {
            $args[] = '--all-namespaces';
        }

        $data = $this->kubectl($args);
        $found = [];

        foreach ($data['items'] ?? [] as $pvc) {
            $ns = $pvc['metadata']['namespace'] ?? 'default';
            $pvcName = $pvc['metadata']['name'] ?? '';
            $labels = $pvc['metadata']['labels'] ?? [];
            $capacity = $pvc['status']['capacity']['storage'] ?? '';
            $phase = $pvc['status']['phase'] ?? '';

            if ($phase !== 'Bound') {
                continue; // Only show bound PVCs
            }

            $found[] = [
                'kind' => 'PVC',
                'namespace' => $ns,
                'name' => $pvcName,
                'source_type' => 'directory',
                'image' => null,
                'host' => null,
                'port' => null,
                'capacity' => $capacity,
                'labels' => $labels,
            ];
        }

        return $found;
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function detectSourceTypeFromImage(string $image): ?string
    {
        $imageLower = strtolower(basename($image));

        foreach (self::IMAGE_MAP as $prefix => $sourceType) {
            if (str_contains($imageLower, $prefix)) {
                return $sourceType;
            }
        }

        return null;
    }

    private function defaultPort(string $sourceType): int
    {
        return match ($sourceType) {
            'postgresql' => 5432,
            'mysql', 'mariadb' => 3306,
            'mongodb' => 27017,
            'redis' => 6379,
            default => 0,
        };
    }
}

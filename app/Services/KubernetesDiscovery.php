<?php

namespace App\Services;

use App\Models\RadarCluster;
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

    private ?string $tempKubeconfigPath = null;

    public function __construct(
        ?string $kubectlPath = null,
        ?string $kubeconfig = null,
        ?string $context = null,
    ) {
        $this->kubectlPath = $kubectlPath ?? config('cellar.kubectl_path', '/usr/local/bin/kubectl');
        $this->kubeconfig = $kubeconfig;
        $this->context = $context;
    }

    /**
     * Create an instance from a RadarCluster model.
     * Writes kubeconfig content to a temp file if present.
     */
    public static function fromCluster(RadarCluster $cluster): self
    {
        $instance = new self(
            context: $cluster->context,
        );

        if ($cluster->kubeconfig) {
            $instance->tempKubeconfigPath = $cluster->writeKubeconfigTempFile();
            $instance->kubeconfig = $instance->tempKubeconfigPath;
        }

        return $instance;
    }

    public function __destruct()
    {
        // Cleanup temp kubeconfig file
        if ($this->tempKubeconfigPath && file_exists($this->tempKubeconfigPath)) {
            unlink($this->tempKubeconfigPath);
        }
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

        // 4. Discover credentials from Secrets
        // Build a set of known app names per namespace for name-based matching
        $appNames = []; // keyed by "ns" => ["appName1", "appName2", ...]
        foreach ($resources as $r) {
            $ns = $r['namespace'];
            $appNames[$ns][$r['name']] = true;
        }
        $secrets = $this->discoverSecrets($namespace, $appNames);

        // Group by composite key and merge Pod + Service into one entry
        // Normalize mysql/mariadb to same group key (they share port 3306)
        $groups = [];
        foreach ($resources as $r) {
            $groupType = in_array($r['source_type'], ['mysql', 'mariadb']) ? 'mysql_compat' : $r['source_type'];
            $key = "{$r['namespace']}:{$r['name']}:{$groupType}";

            $endpoint = [
                'kind' => $r['kind'],
                'resource_name' => $r['resource_name'] ?? $r['name'],
                'host' => $r['host'] ?? null,
                'port' => $r['port'] ?? null,
                'external_host' => $r['external_host'] ?? null,
                'external_port' => $r['external_port'] ?? null,
                'node_port' => $r['node_port'] ?? null,
                'service_type' => $r['service_type'] ?? null,
                'image' => $r['image'] ?? null,
            ];

            if (! isset($groups[$key])) {
                $groups[$key] = $r;
                $groups[$key]['endpoints'] = [$endpoint];
            } else {
                // Avoid duplicate endpoints (same kind + same resource_name)
                $isDuplicate = false;
                foreach ($groups[$key]['endpoints'] as $existing) {
                    if ($existing['kind'] === $endpoint['kind'] && $existing['resource_name'] === $endpoint['resource_name']) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if (! $isDuplicate) {
                    $groups[$key]['endpoints'][] = $endpoint;
                }

                // Prefer Service over Pod for top-level fields
                if ($r['kind'] === 'Service' && $groups[$key]['kind'] === 'Pod') {
                    $groups[$key]['kind'] = $r['kind'];
                    $groups[$key]['host'] = $r['host'];
                    $groups[$key]['port'] = $r['port'];
                    $groups[$key]['external_host'] = $r['external_host'] ?? null;
                    $groups[$key]['external_port'] = $r['external_port'] ?? null;
                    $groups[$key]['node_port'] = $r['node_port'] ?? null;
                    $groups[$key]['service_type'] = $r['service_type'] ?? null;
                }

                // Keep image from whichever has it (usually Pod)
                if (empty($groups[$key]['image']) && ! empty($r['image'])) {
                    $groups[$key]['image'] = $r['image'];
                }

                // Prefer more specific source_type (mariadb > mysql)
                if ($r['source_type'] === 'mariadb' && $groups[$key]['source_type'] === 'mysql') {
                    $groups[$key]['source_type'] = 'mariadb';
                } elseif ($groups[$key]['source_type'] === 'mariadb' && $r['source_type'] === 'mysql') {
                    // keep mariadb
                }

                // Merge labels
                $groups[$key]['labels'] = array_merge($groups[$key]['labels'] ?? [], $r['labels'] ?? []);

                // Merge env_credentials from pod (services don't have them)
                if (! empty($r['env_credentials']) && empty($groups[$key]['env_credentials'])) {
                    $groups[$key]['env_credentials'] = $r['env_credentials'];
                }
            }
        }

        // Sort endpoints: Service first, then Pod, then PVC (no sub-ordering — user picks)
        $kindOrder = ['Service' => 0, 'Pod' => 1, 'PVC' => 2];
        foreach ($groups as &$g) {
            usort($g['endpoints'], fn ($a, $b) => ($kindOrder[$a['kind']] ?? 9) <=> ($kindOrder[$b['kind']] ?? 9));

            // Attach discovered credentials
            $ns = $g['namespace'];
            $appName = $g['name'];
            $g['credentials'] = $secrets["{$ns}:{$appName}"] ?? [];

            // Enrich credentials using env-var analysis from pod containers.
            // When a pod has e.g. MYSQL_ROOT_PASSWORD referencing a secret key
            // we can infer the username is 'root' even if the secret has no username key.
            $envCreds = $g['env_credentials'] ?? [];
            if ($envCreds) {
                // Merge inline credential values (from plain env vars) into credentials
                foreach (['username', 'password', 'database'] as $credType) {
                    if (! empty($envCreds[$credType]['value'])) {
                        // Add as a synthetic credential entry if not already present
                        $alreadyHas = false;
                        foreach ($g['credentials'] as $c) {
                            $kl = strtolower($c['key']);
                            if ($credType === 'username' && (str_contains($kl, 'user') && ! str_contains($kl, 'password'))) {
                                $alreadyHas = true;
                                break;
                            }
                            if ($credType === 'password' && str_contains($kl, 'password')) {
                                $alreadyHas = true;
                                break;
                            }
                            if ($credType === 'database' && (str_contains($kl, 'database') || str_contains($kl, 'dbname'))) {
                                $alreadyHas = true;
                                break;
                            }
                        }
                        if (! $alreadyHas) {
                            $g['credentials'][] = [
                                'secret_name' => '_env',
                                'key' => $credType,
                                'value' => $envCreds[$credType]['value'],
                            ];
                        }
                    }
                }

                // If we have a password from secrets but no username, try to infer
                // from the env var name (e.g. MYSQL_ROOT_PASSWORD → root)
                $hasPassword = false;
                $hasUsername = false;
                foreach ($g['credentials'] as $c) {
                    $kl = strtolower($c['key']);
                    if (str_contains($kl, 'password')) {
                        $hasPassword = true;
                    }
                    if (str_contains($kl, 'user') && ! str_contains($kl, 'password')) {
                        $hasUsername = true;
                    }
                }
                if ($hasPassword && ! $hasUsername && ! empty($envCreds['username']['inferred'])) {
                    $g['credentials'][] = [
                        'secret_name' => '_inferred',
                        'key' => 'username',
                        'value' => $envCreds['username']['inferred'],
                    ];
                }
            }

            // Clean up internal field
            unset($g['env_credentials']);
        }
        unset($g);

        return array_values($groups);
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

                // Extract env-var based credentials (inline values + secretKeyRef mapping)
                $envCredentials = self::extractEnvCredentials($container['env'] ?? [], $sourceType);

                $found[] = [
                    'kind' => 'Pod',
                    'namespace' => $ns,
                    'name' => $appName,
                    'resource_name' => $podName,
                    'pod_name' => $podName,
                    'source_type' => $sourceType,
                    'image' => $image,
                    'host' => "{$appName}.{$ns}.svc.cluster.local",
                    'port' => $port ?? $this->defaultPort($sourceType),
                    'external_host' => null,
                    'external_port' => null,
                    'node_port' => null,
                    'service_type' => null,
                    'labels' => $labels,
                    'env_credentials' => $envCredentials,
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
            $selector = $svc['spec']['selector'] ?? [];
            $svcType = $svc['spec']['type'] ?? 'ClusterIP';

            // Use app labels for canonical name (same logic as pods) so
            // services and pods for the same app group together.
            $canonicalName = $labels['app.kubernetes.io/name']
                ?? $labels['app']
                ?? $selector['app.kubernetes.io/name']
                ?? $selector['app']
                ?? $svcName;

            foreach ($svc['spec']['ports'] ?? [] as $portSpec) {
                $port = $portSpec['port'] ?? 0;
                $nodePort = $portSpec['nodePort'] ?? null;

                // Determine source type: first by well-known port, then by name/label heuristic
                $sourceType = self::PORT_MAP[$port] ?? null;

                if (! $sourceType) {
                    // Try to infer from canonical name or service name (handles non-standard ports)
                    $sourceType = $this->detectSourceTypeFromName($canonicalName)
                        ?? $this->detectSourceTypeFromName($svcName);
                }

                if (! $sourceType) {
                    continue; // Not a recognized database service
                }

                // Refine mysql → mariadb if the service name or labels hint at MariaDB
                if ($sourceType === 'mysql') {
                    $nameHint = strtolower($canonicalName.' '.$svcName);
                    $labelHint = strtolower(implode(' ', array_values($labels)));
                    if (str_contains($nameHint, 'mariadb') || str_contains($labelHint, 'mariadb')) {
                        $sourceType = 'mariadb';
                    }
                }

                // Determine the best host/port for external access
                $internalHost = "{$svcName}.{$ns}.svc.cluster.local";
                $externalHost = null;
                $externalPort = null;

                if ($svcType === 'ExternalName') {
                    $externalHost = $svc['spec']['externalName'] ?? null;
                    $externalPort = $port;
                } elseif ($svcType === 'LoadBalancer') {
                    // Check for external IP from status
                    $ingress = $svc['status']['loadBalancer']['ingress'] ?? [];
                    if (! empty($ingress)) {
                        $externalHost = $ingress[0]['ip'] ?? $ingress[0]['hostname'] ?? null;
                        $externalPort = $port;
                    }
                    // Fallback to spec.externalIPs
                    if (! $externalHost) {
                        $extIPs = $svc['spec']['externalIPs'] ?? [];
                        if (! empty($extIPs)) {
                            $externalHost = $extIPs[0];
                            $externalPort = $port;
                        }
                    }
                } elseif ($svcType === 'NodePort' && $nodePort) {
                    $externalPort = $nodePort;
                    // Node IP needs to come from cluster nodes — use placeholder
                    $externalHost = null;
                }

                // Also check spec.externalIPs (available on any service type)
                if (! $externalHost) {
                    $extIPs = $svc['spec']['externalIPs'] ?? [];
                    if (! empty($extIPs)) {
                        $externalHost = $extIPs[0];
                        $externalPort = $externalPort ?? $port;
                    }
                }

                $found[] = [
                    'kind' => 'Service',
                    'namespace' => $ns,
                    'name' => $canonicalName,
                    'resource_name' => $svcName,
                    'source_type' => $sourceType,
                    'image' => null,
                    'host' => $internalHost,
                    'port' => $port,
                    'external_host' => $externalHost,
                    'external_port' => $externalPort ?? ($nodePort ?? $port),
                    'node_port' => $nodePort,
                    'service_type' => $svcType,
                    'labels' => $labels,
                ];
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
                'resource_name' => $pvcName,
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

    // ── Secret scanner ──────────────────────────────────────────

    /** Well-known secret data keys that hold DB credentials */
    private const CREDENTIAL_KEYS = [
        // Passwords
        'password', 'db-password', 'database-password',
        'mariadb-password', 'mariadb-root-password',
        'mysql-password', 'mysql-root-password',
        'postgres-password', 'postgresql-password',
        'mongodb-password', 'mongodb-root-password',
        'redis-password',
        // Usernames
        'username', 'db-username', 'database-username',
        'mariadb-user', 'mysql-user', 'postgres-user', 'mongodb-user',
        'user',
        // Database name
        'database', 'database-name', 'db-name', 'dbname',
        'mariadb-database', 'mysql-database', 'postgres-db', 'mongodb-database',
        // Connection strings (for display / reference)
        'uri', 'dsn', 'database-url', 'connection-string',
    ];

    /**
     * Discover database credentials from K8s Secrets.
     *
     * Matching strategy (in order):
     *  1. Label-based: secret has app.kubernetes.io/name or app label
     *  2. Name-based: secret name starts with a discovered app name (e.g. "mysql-credentials" → "mysql")
     *
     * Returns a map keyed by "namespace:appName" with arrays of
     * [{secret_name, key, value},...] for known credential keys.
     */
    private function discoverSecrets(?string $namespace = null, array $appNamesByNs = []): array
    {
        $args = ['get', 'secrets'];
        if ($namespace) {
            $args[] = '-n';
            $args[] = $namespace;
        } else {
            $args[] = '--all-namespaces';
        }

        try {
            $data = $this->kubectl($args);
        } catch (\Throwable) {
            // Secrets might be RBAC-restricted — silently skip
            return [];
        }

        $result = []; // keyed by "ns:appName"

        foreach ($data['items'] ?? [] as $secret) {
            $type = $secret['type'] ?? '';
            // Only inspect Opaque secrets (where credentials live)
            if ($type !== 'Opaque') {
                continue;
            }

            $ns = $secret['metadata']['namespace'] ?? 'default';
            $secretName = $secret['metadata']['name'] ?? '';
            $labels = $secret['metadata']['labels'] ?? [];
            $secretData = $secret['data'] ?? [];

            if (empty($secretData)) {
                continue;
            }

            // Determine which app this secret belongs to
            // Strategy 1: Label-based
            $appName = $labels['app.kubernetes.io/name']
                ?? $labels['app']
                ?? null;

            // Strategy 2: Name-based — match secret name against discovered app names
            if (! $appName && isset($appNamesByNs[$ns])) {
                foreach ($appNamesByNs[$ns] as $candidate => $_) {
                    if (str_starts_with($secretName, $candidate.'-') || $secretName === $candidate) {
                        $appName = $candidate;
                        break;
                    }
                }
            }

            if (! $appName) {
                continue; // Can't associate with a discovered resource
            }

            $mapKey = "{$ns}:{$appName}";

            foreach ($secretData as $key => $base64Value) {
                $keyLower = strtolower($key);
                if (in_array($keyLower, self::CREDENTIAL_KEYS, true)) {
                    $decoded = base64_decode($base64Value, true);
                    if ($decoded === false) {
                        continue;
                    }
                    $result[$mapKey][] = [
                        'secret_name' => $secretName,
                        'key' => $key,
                        'value' => $decoded,
                    ];
                }
            }
        }

        return $result;
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Extract credential info from container env vars.
     *
     * Inspects well-known env var names (MYSQL_ROOT_PASSWORD, MYSQL_USER,
     * POSTGRES_USER, etc.) to discover inline values and infer usernames
     * when a root password is configured but no explicit user is set.
     *
     * @param  array  $envVars  Container env var definitions from pod spec
     * @param  string  $sourceType  The detected source type (mysql, mariadb, postgresql, etc.)
     * @return array Keys: username, password, database — each with 'value' and/or 'inferred'
     */
    private static function extractEnvCredentials(array $envVars, string $sourceType): array
    {
        $result = [
            'username' => ['value' => null, 'inferred' => null],
            'password' => ['value' => null, 'inferred' => null],
            'database' => ['value' => null, 'inferred' => null],
        ];

        // Map of env var names → what they mean
        $envMap = [
            // MySQL / MariaDB
            'MYSQL_ROOT_PASSWORD' => ['type' => 'password', 'infer_user' => 'root'],
            'MARIADB_ROOT_PASSWORD' => ['type' => 'password', 'infer_user' => 'root'],
            'MYSQL_PASSWORD' => ['type' => 'password'],
            'MARIADB_PASSWORD' => ['type' => 'password'],
            'MYSQL_USER' => ['type' => 'username'],
            'MARIADB_USER' => ['type' => 'username'],
            'MYSQL_DATABASE' => ['type' => 'database'],
            'MARIADB_DATABASE' => ['type' => 'database'],
            // PostgreSQL
            'POSTGRES_PASSWORD' => ['type' => 'password', 'infer_user' => 'postgres'],
            'POSTGRES_USER' => ['type' => 'username'],
            'POSTGRES_DB' => ['type' => 'database'],
            'PGPASSWORD' => ['type' => 'password'],
            'PGUSER' => ['type' => 'username'],
            'PGDATABASE' => ['type' => 'database'],
        ];

        $inferredUser = null;
        $hasExplicitUser = false;

        foreach ($envVars as $env) {
            $name = $env['name'] ?? '';
            $value = $env['value'] ?? null; // inline value (plain text)

            $mapping = $envMap[$name] ?? null;
            if (! $mapping) {
                continue;
            }

            $credType = $mapping['type'];

            // If there's a plain-text value, capture it
            if ($value !== null && $value !== '') {
                $result[$credType]['value'] = $result[$credType]['value'] ?? $value;
            }

            // If this env var implies a specific username
            if (isset($mapping['infer_user'])) {
                $inferredUser = $mapping['infer_user'];
            }

            if ($credType === 'username') {
                $hasExplicitUser = true;
            }
        }

        // If we found a root password env but no explicit user env, infer the username
        if ($inferredUser && ! $hasExplicitUser) {
            $result['username']['inferred'] = $inferredUser;
        }

        return $result;
    }

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

    /**
     * Detect source type from a K8s resource name (service/canonical name).
     * Handles non-standard ports by matching name against IMAGE_MAP prefixes.
     */
    private function detectSourceTypeFromName(string $name): ?string
    {
        $nameLower = strtolower($name);

        foreach (self::IMAGE_MAP as $prefix => $sourceType) {
            if (str_starts_with($nameLower, $prefix) || str_contains($nameLower, "-{$prefix}") || str_contains($nameLower, "{$prefix}-")) {
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

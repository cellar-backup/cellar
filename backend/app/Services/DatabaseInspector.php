<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;

/**
 * Lists databases on discovered endpoints.
 *
 * Two strategies:
 *  1. Direct PDO — fast, works when host is reachable from the Cellar container
 *  2. kubectl exec — runs the query inside the DB pod, works for any cluster
 *
 * The public API tries direct first (with a short timeout) and falls back to
 * kubectl exec when the host is unreachable.
 */
class DatabaseInspector
{
    /** System databases to exclude per engine */
    private const SYSTEM_DBS = [
        'postgresql' => ['template0', 'template1', 'postgres'],
        'mysql' => ['information_schema', 'mysql', 'performance_schema', 'sys'],
        'mariadb' => ['information_schema', 'mysql', 'performance_schema', 'sys'],
        'mongodb' => ['admin', 'config', 'local'],
    ];

    /** DB CLI commands found inside common container images */
    private const KUBECTL_COMMANDS = [
        'postgresql' => 'psql -U %s -d postgres -t -A -c "SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname"',
        'mysql' => 'mysql -u %s %s -N -e "SHOW DATABASES"',
        'mariadb' => 'mysql -u %s %s -N -e "SHOW DATABASES"',
    ];

    /**
     * List databases — tries direct PDO first, falls back to kubectl exec.
     *
     * @return array{databases: string[], error: string|null}
     */
    public function listDatabases(
        string $sourceType,
        string $host,
        int $port,
        ?string $username = null,
        ?string $password = null,
        ?array $kubectlContext = null,
    ): array {
        // 1. Try direct PDO (fast, works for external hosts)
        if (! str_contains($host, '.svc.cluster.local')) {
            $direct = $this->listDirect($sourceType, $host, $port, $username, $password);
            if ($direct['error'] === null) {
                return $direct;
            }
        }

        // 2. Fall back to kubectl exec if we have cluster context
        if ($kubectlContext) {
            $kubectl = $this->listViaKubectl(
                sourceType: $sourceType,
                username: $username,
                password: $password,
                podName: $kubectlContext['pod_name'],
                namespace: $kubectlContext['namespace'],
                kubectlPath: $kubectlContext['kubectl_path'] ?? '/usr/local/bin/kubectl',
                kubeconfig: $kubectlContext['kubeconfig'] ?? null,
                context: $kubectlContext['context'] ?? null,
            );
            if ($kubectl['error'] === null || ! empty($kubectl['databases'])) {
                return $kubectl;
            }
        }

        // 3. Last resort: try direct even for cluster-local hosts
        $direct = $this->listDirect($sourceType, $host, $port, $username, $password);
        if ($direct['error'] !== null && $kubectlContext) {
            return ['databases' => [], 'error' => 'Could not reach the database. Make sure the pod is running and credentials are correct.'];
        }

        return $direct;
    }

    // ── Direct PDO ──────────────────────────────────────────────

    private function listDirect(string $sourceType, string $host, int $port, ?string $user, ?string $pass, int $timeout = 4): array
    {
        return match ($sourceType) {
            'postgresql' => $this->listPostgres($host, $port, $user, $pass, $timeout),
            'mysql', 'mariadb' => $this->listMysql($host, $port, $user, $pass, $timeout),
            default => ['databases' => [], 'error' => "Direct connection not supported for {$sourceType}."],
        };
    }

    private function listPostgres(string $host, int $port, ?string $user, ?string $pass, int $timeout): array
    {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname=postgres;connect_timeout={$timeout}";
            $pdo = new PDO($dsn, $user ?? 'postgres', $pass ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => $timeout,
            ]);

            $stmt = $pdo->query('SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname');
            $all = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return [
                'databases' => array_values(array_diff($all, self::SYSTEM_DBS['postgresql'])),
                'error' => null,
            ];
        } catch (PDOException $e) {
            return ['databases' => [], 'error' => $this->friendlyError($e)];
        }
    }

    private function listMysql(string $host, int $port, ?string $user, ?string $pass, int $timeout): array
    {
        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user ?? 'root', $pass ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => $timeout,
            ]);

            $stmt = $pdo->query('SHOW DATABASES');
            $all = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $engine = str_contains(strtolower($pdo->getAttribute(PDO::ATTR_SERVER_INFO) ?? ''), 'maria') ? 'mariadb' : 'mysql';

            return [
                'databases' => array_values(array_diff($all, self::SYSTEM_DBS[$engine])),
                'error' => null,
            ];
        } catch (PDOException $e) {
            return ['databases' => [], 'error' => $this->friendlyError($e)];
        }
    }

    // ── kubectl exec ────────────────────────────────────────────

    /**
     * Execute a DB listing command inside the pod via kubectl exec.
     */
    private function listViaKubectl(
        string $sourceType,
        ?string $username,
        ?string $password,
        string $podName,
        string $namespace,
        string $kubectlPath,
        ?string $kubeconfig,
        ?string $context,
    ): array {
        $cmd = $this->buildKubectlExecCommand(
            sourceType: $sourceType,
            username: $username,
            password: $password,
            podName: $podName,
            namespace: $namespace,
            kubectlPath: $kubectlPath,
            kubeconfig: $kubeconfig,
            context: $context,
        );

        if (! $cmd) {
            return ['databases' => [], 'error' => "kubectl exec not supported for {$sourceType}."];
        }

        try {
            $result = Process::timeout(15)->run($cmd);

            if (! $result->successful()) {
                $err = trim($result->errorOutput());
                if (str_contains($err, 'not found') || str_contains($err, 'does not exist')) {
                    return ['databases' => [], 'error' => "Pod '{$podName}' not found in namespace '{$namespace}'."];
                }
                if (str_contains($err, 'Access denied') || str_contains($err, 'authentication failed') || str_contains($err, 'password authentication failed')) {
                    return ['databases' => [], 'error' => 'Authentication failed — check your username and password.'];
                }

                return ['databases' => [], 'error' => 'Failed to query databases via kubectl exec: '.substr($err, 0, 200)];
            }

            $output = trim($result->output());
            $names = array_filter(array_map('trim', explode("\n", $output)), fn ($n) => $n !== '');
            $system = self::SYSTEM_DBS[$sourceType] ?? self::SYSTEM_DBS['mysql'] ?? [];
            $names = array_values(array_diff($names, $system));

            return ['databases' => $names, 'error' => null];
        } catch (\Throwable $e) {
            return ['databases' => [], 'error' => 'kubectl exec failed: '.$e->getMessage()];
        }
    }

    private function buildKubectlExecCommand(
        string $sourceType,
        ?string $username,
        ?string $password,
        string $podName,
        string $namespace,
        string $kubectlPath,
        ?string $kubeconfig,
        ?string $context,
    ): ?array {
        $base = [$kubectlPath];

        if ($kubeconfig) {
            $base[] = '--kubeconfig';
            $base[] = $kubeconfig;
        }
        if ($context) {
            $base[] = '--context';
            $base[] = $context;
        }

        $base = array_merge($base, ['exec', $podName, '-n', $namespace, '--']);

        $user = $username ?: match ($sourceType) {
            'postgresql' => 'postgres',
            'mysql', 'mariadb' => 'root',
            default => 'root',
        };

        switch ($sourceType) {
            case 'postgresql':
                // PGPASSWORD env + psql
                $innerCmd = sprintf(self::KUBECTL_COMMANDS['postgresql'], escapeshellarg($user));
                $envPrefix = $password ? 'PGPASSWORD='.escapeshellarg($password).' ' : '';

                return array_merge($base, ['sh', '-c', $envPrefix.$innerCmd]);

            case 'mysql':
            case 'mariadb':
                $passFlag = $password ? '-p'.escapeshellarg($password) : '';
                $innerCmd = sprintf(self::KUBECTL_COMMANDS[$sourceType], escapeshellarg($user), $passFlag);

                return array_merge($base, ['sh', '-c', $innerCmd]);

            case 'mongodb':
                $authArgs = $username
                    ? '-u '.escapeshellarg($username).' -p '.escapeshellarg($password ?? '').' --authenticationDatabase admin'
                    : '';
                $innerCmd = "mongosh {$authArgs} --quiet --eval \"db.adminCommand('listDatabases').databases.forEach(d => print(d.name))\"";

                return array_merge($base, ['sh', '-c', $innerCmd]);

            default:
                return null;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function friendlyError(PDOException $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'Connection refused')) {
            return 'Connection refused — check that the host and port are reachable from the Cellar container.';
        }
        if (str_contains($msg, 'Connection timed out') || str_contains($msg, 'timeout') || str_contains($msg, 'name resolution')) {
            return 'Connection timed out — the database may not be reachable from this network.';
        }
        if (str_contains($msg, 'Access denied') || str_contains($msg, 'authentication failed') || str_contains($msg, 'password authentication failed')) {
            return 'Authentication failed — check your username and password.';
        }

        return "Connection error: {$msg}";
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class DumpResult
{
    public function __construct(
        public bool $success,
        public string $dumpPath = '',
        public int $sizeBytes = 0,
        public string $message = '',
    ) {}
}

class DatabaseDumper
{
    /**
     * High-level dispatch: dump a database to a directory.
     */
    public static function dump(
        string $dbType,
        array $config,
        string $outputDir,
    ): DumpResult {
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return match ($dbType) {
            'postgresql' => self::dumpPostgresql($config, $outputDir),
            'mysql', 'mariadb' => self::dumpMysql($config, $outputDir),
            default => new DumpResult(false, message: "Unsupported database type: {$dbType}"),
        };
    }

    /**
     * Dump a database by running the dump command inside a K8s pod via kubectl exec.
     *
     * This bypasses network/auth restrictions (e.g. root@localhost only)
     * by running mysqldump/pg_dump inside the database container itself.
     *
     * @param  string  $dbType       Source type (postgresql, mysql, mariadb)
     * @param  array   $config       Database config (username, password, database_name, etc.)
     * @param  string  $outputDir    Local directory to write the dump file
     * @param  array   $kubectlConfig  {kubectl_path, kubeconfig, context, namespace, pod}
     */
    public static function dumpViaKubectl(
        string $dbType,
        array $config,
        string $outputDir,
        array $kubectlConfig,
    ): DumpResult {
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        return match ($dbType) {
            'postgresql' => self::dumpPostgresqlKubectl($config, $outputDir, $kubectlConfig),
            'mysql', 'mariadb' => self::dumpMysqlKubectl($config, $outputDir, $kubectlConfig),
            default => new DumpResult(false, message: "Unsupported database type for kubectl exec: {$dbType}"),
        };
    }

    /**
     * Find a running pod for a given app label in a namespace.
     *
     * @return string|null Pod name, or null if not found
     */
    public static function findKubectlPod(array $kubectlConfig, string $appName): ?string
    {
        $cmd = [$kubectlConfig['kubectl_path']];

        if (! empty($kubectlConfig['kubeconfig'])) {
            $cmd[] = '--kubeconfig';
            $cmd[] = $kubectlConfig['kubeconfig'];
        }
        if (! empty($kubectlConfig['context'])) {
            $cmd[] = '--context';
            $cmd[] = $kubectlConfig['context'];
        }

        $cmd = array_merge($cmd, [
            'get', 'pods',
            '-n', $kubectlConfig['namespace'],
            '-l', "app={$appName}",
            '--field-selector=status.phase=Running',
            '-o', 'jsonpath={.items[0].metadata.name}',
        ]);

        $result = Process::timeout(15)->run($cmd);

        if (! $result->successful() || empty(trim($result->output()))) {
            // Fallback: try app.kubernetes.io/name label
            $cmd[array_search("-l", $cmd) + 1] = "app.kubernetes.io/name={$appName}";
            $result = Process::timeout(15)->run($cmd);

            if (! $result->successful() || empty(trim($result->output()))) {
                return null;
            }
        }

        return trim($result->output()) ?: null;
    }

    private static function dumpPostgresql(array $c, string $outputDir): DumpResult
    {
        $host = $c['host'] ?? 'localhost';
        $port = (string) ($c['port'] ?? 5432);
        $user = $c['user'] ?? $c['username'] ?? '';
        $user = $user ?: 'postgres'; // default to postgres
        $password = $c['password'] ?? '';
        $database = $c['database'] ?? $c['database_name'] ?? 'postgres';
        $extra = $c['extra_args'] ?? [];

        $outFile = "{$outputDir}/{$database}.sql.gz";

        // Custom format (-Fc) for best borg dedup and compression
        $cmd = ['pg_dump', '-h', $host, '-p', $port, '-U', $user, '--no-password', '-Fc', '-f', $outFile];
        $cmd = array_merge($cmd, (array) $extra, [$database]);

        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => $password])
            ->run($cmd);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'pg_dump failed: '.$result->errorOutput());
        }

        return new DumpResult(
            success: true,
            dumpPath: $outFile,
            sizeBytes: file_exists($outFile) ? filesize($outFile) : 0,
            message: 'PostgreSQL dump completed.',
        );
    }

    private static function dumpMysql(array $c, string $outputDir): DumpResult
    {
        $host = $c['host'] ?? 'localhost';
        $port = (string) ($c['port'] ?? 3306);
        $user = $c['user'] ?? $c['username'] ?? '';
        $user = $user ?: 'root'; // default to root for MySQL
        $password = $c['password'] ?? '';
        $database = $c['database'] ?? $c['database_name'] ?? '';
        $extra = $c['extra_args'] ?? [];

        $outFile = "{$outputDir}/{$database}.sql";

        $cmd = [
            'mysqldump',
            '-h', $host,
            '-P', $port,
            '-u', $user,
            '--skip-ssl',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
        ];

        if ($password) {
            $cmd[] = "--password={$password}";
        }

        $cmd = array_merge($cmd, (array) $extra, [$database]);

        $result = Process::timeout(3600)->run($cmd);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'mysqldump failed: '.$result->errorOutput());
        }

        file_put_contents($outFile, $result->output());

        return new DumpResult(
            success: true,
            dumpPath: $outFile,
            sizeBytes: file_exists($outFile) ? filesize($outFile) : 0,
            message: 'MySQL dump completed.',
        );
    }

    // ── kubectl exec dump methods ──────────────────────────────

    /**
     * Build the kubectl exec prefix for running commands inside a K8s pod.
     */
    private static function kubectlExecPrefix(array $kc): array
    {
        $cmd = [$kc['kubectl_path']];

        if (! empty($kc['kubeconfig'])) {
            $cmd[] = '--kubeconfig';
            $cmd[] = $kc['kubeconfig'];
        }
        if (! empty($kc['context'])) {
            $cmd[] = '--context';
            $cmd[] = $kc['context'];
        }

        return array_merge($cmd, ['exec', $kc['pod'], '-n', $kc['namespace'], '--']);
    }

    private static function dumpMysqlKubectl(array $c, string $outputDir, array $kc): DumpResult
    {
        $user = $c['user'] ?? $c['username'] ?? '';
        $user = $user ?: 'root';
        $password = $c['password'] ?? '';
        $database = $c['database'] ?? $c['database_name'] ?? '';

        $outFile = "{$outputDir}/{$database}.sql";

        // Build the mysqldump command to run inside the pod
        $dumpCmd = [
            'mysqldump',
            '-u', $user,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
        ];

        if ($password) {
            $dumpCmd[] = "--password={$password}";
        }

        $dumpCmd[] = $database;

        // Prepend kubectl exec prefix
        $cmd = array_merge(self::kubectlExecPrefix($kc), $dumpCmd);

        $result = Process::timeout(3600)->run($cmd);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'mysqldump via kubectl exec failed: '.$result->errorOutput());
        }

        $output = $result->output();
        if (empty($output)) {
            return new DumpResult(false, message: 'mysqldump via kubectl exec returned empty output.');
        }

        file_put_contents($outFile, $output);

        return new DumpResult(
            success: true,
            dumpPath: $outFile,
            sizeBytes: file_exists($outFile) ? filesize($outFile) : 0,
            message: 'MySQL dump completed via kubectl exec (in-pod).',
        );
    }

    private static function dumpPostgresqlKubectl(array $c, string $outputDir, array $kc): DumpResult
    {
        $user = $c['user'] ?? $c['username'] ?? '';
        $user = $user ?: 'postgres';
        $password = $c['password'] ?? '';
        $database = $c['database'] ?? $c['database_name'] ?? 'postgres';

        $outFile = "{$outputDir}/{$database}.sql.gz";

        // PostgreSQL custom format (-Fc) outputs binary to stdout
        $dumpCmd = [
            'pg_dump',
            '-U', $user,
            '--no-password',
            '-Fc',
            $database,
        ];

        // Prepend kubectl exec, with PGPASSWORD env set inside the pod
        // We wrap in sh -c to set the env var
        $shellCmd = 'PGPASSWORD='.escapeshellarg($password)
            .' pg_dump -U '.escapeshellarg($user)
            .' --no-password -Fc '.escapeshellarg($database);

        $cmd = array_merge(self::kubectlExecPrefix($kc), ['sh', '-c', $shellCmd]);

        $result = Process::timeout(3600)->run($cmd);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'pg_dump via kubectl exec failed: '.$result->errorOutput());
        }

        $output = $result->output();
        if (empty($output)) {
            return new DumpResult(false, message: 'pg_dump via kubectl exec returned empty output.');
        }

        file_put_contents($outFile, $output);

        return new DumpResult(
            success: true,
            dumpPath: $outFile,
            sizeBytes: file_exists($outFile) ? filesize($outFile) : 0,
            message: 'PostgreSQL dump completed via kubectl exec (in-pod).',
        );
    }
}

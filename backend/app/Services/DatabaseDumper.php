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

    /**
     * Detect whether a PostgreSQL database has the TimescaleDB extension installed.
     */
    private static function hasTimescaleDb(string $host, string $port, string $user, string $password, string $database): bool
    {
        $result = Process::timeout(15)
            ->env(['PGPASSWORD' => $password])
            ->run([
                'psql', '-h', $host, '-p', $port, '-U', $user, '--no-password',
                '-tAc', "SELECT 1 FROM pg_extension WHERE extname='timescaledb'",
                $database,
            ]);

        return $result->successful() && str_contains(trim($result->output()), '1');
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

        $isTimescale = self::hasTimescaleDb($host, $port, $user, $password, $database);

        // Directory format (-Fd) with parallel jobs for large databases.
        // Borg handles compression, so we disable pg_dump's internal
        // compression (-Z 0) to avoid double-compressing and to speed up
        // the dump significantly.
        $outPath = "{$outputDir}/{$database}_dump";
        $cmd = [
            'pg_dump', '-h', $host, '-p', $port, '-U', $user, '--no-password',
            '-Fd',          // directory format (enables parallel)
            '-j', '4',      // 4 parallel worker jobs
            '-Z', '0',      // no internal compression (borg will compress)
        ];

        // Standard best-practice flags: avoid permission issues on restore
        $cmd[] = '--no-owner';
        $cmd[] = '--no-privileges';

        // Fail fast on lock contention instead of hanging
        $cmd[] = '--lock-wait-timeout=60000'; // 60 seconds in ms

        // TimescaleDB: exclude internal catalog schemas that contain circular
        // FK constraints (hypertable ↔ chunk) which cause pg_dump to hang.
        // These schemas are recreated by CREATE EXTENSION on restore.
        if ($isTimescale) {
            foreach ([
                '_timescaledb_catalog',
                '_timescaledb_cache',
                '_timescaledb_config',
                '_timescaledb_internal',
                'timescaledb_information',
                'timescaledb_experimental',
            ] as $schema) {
                $cmd[] = '-N';
                $cmd[] = $schema;
            }
        }

        $cmd[] = '-f';
        $cmd[] = $outPath;
        $cmd = array_merge($cmd, (array) $extra, [$database]);

        $result = Process::timeout(21600)
            ->env(['PGPASSWORD' => $password])
            ->run($cmd);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'pg_dump failed: '.$result->errorOutput());
        }

        // Calculate total dump size (directory contains multiple files)
        $totalSize = 0;
        if (is_dir($outPath)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outPath)) as $file) {
                if ($file->isFile()) {
                    $totalSize += $file->getSize();
                }
            }
        }

        return new DumpResult(
            success: true,
            dumpPath: $outPath,
            sizeBytes: $totalSize,
            message: $isTimescale
                ? 'PostgreSQL dump completed (TimescaleDB: internal schemas excluded, parallel directory format).'
                : 'PostgreSQL dump completed (parallel directory format).',
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

        // Redirect output directly to file instead of buffering in PHP memory
        $cmdParts = array_map('escapeshellarg', $cmd);
        $fullCmd = implode(' ', $cmdParts).' > '.escapeshellarg($outFile);

        $result = Process::timeout(21600)->run(['sh', '-c', $fullCmd]);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'mysqldump failed: '.$result->errorOutput());
        }

        if (! file_exists($outFile) || filesize($outFile) === 0) {
            return new DumpResult(false, message: 'mysqldump produced empty output.');
        }

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

        // Prepend kubectl exec prefix — stream output directly to local
        // file via shell redirect instead of buffering in PHP memory.
        $kubectlParts = array_map('escapeshellarg', self::kubectlExecPrefix($kc));
        $dumpParts = array_map('escapeshellarg', $dumpCmd);
        $fullCmd = implode(' ', $kubectlParts)
            .' '.implode(' ', $dumpParts)
            .' > '.escapeshellarg($outFile);

        $result = Process::timeout(21600)->run(['sh', '-c', $fullCmd]);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'mysqldump via kubectl exec failed: '.$result->errorOutput());
        }

        if (! file_exists($outFile) || filesize($outFile) === 0) {
            return new DumpResult(false, message: 'mysqldump via kubectl exec produced empty output.');
        }

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

        // Build pg_dump flags — detect TimescaleDB inside the pod
        $tsCheckCmd = 'PGPASSWORD='.escapeshellarg($password)
            .' psql -U '.escapeshellarg($user)
            .' -tAc '."\"SELECT 1 FROM pg_extension WHERE extname='timescaledb'\""
            .' '.escapeshellarg($database);

        $tsCheckFullCmd = array_merge(self::kubectlExecPrefix($kc), ['sh', '-c', $tsCheckCmd]);
        $tsResult = Process::timeout(15)->run($tsCheckFullCmd);
        $isTimescale = $tsResult->successful() && str_contains(trim($tsResult->output()), '1');

        $excludeSchemas = $isTimescale
            ? '-N _timescaledb_catalog -N _timescaledb_cache -N _timescaledb_config -N _timescaledb_internal -N timescaledb_information -N timescaledb_experimental'
            : '';

        // PostgreSQL custom format (-Fc) outputs binary to stdout.
        // Stream directly to local file via shell redirect instead of
        // buffering the entire dump in PHP memory (critical for large DBs).
        $shellCmd = 'PGPASSWORD='.escapeshellarg($password)
            .' pg_dump -U '.escapeshellarg($user)
            .' --no-password --no-owner --no-privileges --lock-wait-timeout=60000'
            .($excludeSchemas ? ' '.$excludeSchemas : '')
            .' -Fc '.escapeshellarg($database);

        $kubectlParts = array_map('escapeshellarg', self::kubectlExecPrefix($kc));
        $fullCmd = implode(' ', $kubectlParts)
            .' sh -c '.escapeshellarg($shellCmd)
            .' > '.escapeshellarg($outFile);

        $result = Process::timeout(21600)->run(['sh', '-c', $fullCmd]);

        if (! $result->successful()) {
            return new DumpResult(false, message: 'pg_dump via kubectl exec failed: '.$result->errorOutput());
        }

        if (! file_exists($outFile) || filesize($outFile) === 0) {
            return new DumpResult(false, message: 'pg_dump via kubectl exec produced empty output.');
        }

        return new DumpResult(
            success: true,
            dumpPath: $outFile,
            sizeBytes: file_exists($outFile) ? filesize($outFile) : 0,
            message: $isTimescale
                ? 'PostgreSQL dump completed via kubectl exec (TimescaleDB: internal schemas excluded).'
                : 'PostgreSQL dump completed via kubectl exec (in-pod).',
        );
    }
}

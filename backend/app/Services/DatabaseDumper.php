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
}

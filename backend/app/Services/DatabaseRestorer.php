<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class RestoreDbResult
{
    public function __construct(
        public bool $success,
        public string $message = '',
    ) {}
}

class DatabaseRestorer
{
    /**
     * Restore a database dump back into the source database.
     *
     * @param  string  $dbType       postgresql | mysql | mariadb
     * @param  array   $config       Connection config (host, port, username, password, database_name)
     * @param  string  $dumpPath     Path to the dump file to restore
     */
    public static function restore(
        string $dbType,
        array $config,
        string $dumpPath,
    ): RestoreDbResult {
        if (! file_exists($dumpPath)) {
            return new RestoreDbResult(false, "Dump file not found: {$dumpPath}");
        }

        return match ($dbType) {
            'postgresql' => self::restorePostgresql($config, $dumpPath),
            'mysql', 'mariadb' => self::restoreMysql($config, $dumpPath),
            default => new RestoreDbResult(false, "Unsupported database type: {$dbType}"),
        };
    }

    private static function restorePostgresql(array $c, string $dumpPath): RestoreDbResult
    {
        $host = $c['host'] ?? 'localhost';
        $port = (string) ($c['port'] ?? 5432);
        $user = $c['user'] ?? $c['username'] ?? 'postgres';
        $password = $c['password'] ?? '';
        $database = $c['database'] ?? $c['database_name'] ?? 'postgres';

        // pg_dump uses -Fc (custom format), so we use pg_restore.
        // NOTE: We intentionally omit --single-transaction because cross-version
        // restores can fail on version-specific SET commands (e.g. PG17's
        // transaction_timeout on a PG16 target), which would roll back everything.
        $cmd = [
            'pg_restore',
            '-h', $host,
            '-p', $port,
            '-U', $user,
            '--no-password',
            '-d', $database,
            '--clean',           // Drop existing objects before restoring
            '--if-exists',       // Don't error if objects don't exist yet
            $dumpPath,
        ];

        $result = Process::timeout(3600)
            ->env(['PGPASSWORD' => $password])
            ->run($cmd);

        if (! $result->successful()) {
            // pg_restore exit code 1 = warnings (e.g. "role does not exist"),
            // exit code >= 2 = real errors
            if ($result->exitCode() >= 2) {
                return new RestoreDbResult(false, 'pg_restore failed: '.$result->errorOutput());
            }
        }

        return new RestoreDbResult(
            success: true,
            message: 'PostgreSQL restore completed.',
        );
    }

    private static function restoreMysql(array $c, string $dumpPath): RestoreDbResult
    {
        $host = $c['host'] ?? 'localhost';
        $port = (string) ($c['port'] ?? 3306);
        $user = $c['user'] ?? $c['username'] ?? 'root';
        $password = $c['password'] ?? '';
        $database = $c['database'] ?? $c['database_name'] ?? '';

        $cmd = [
            'mysql',
            '-h', $host,
            '-P', $port,
            '-u', $user,
            $database,
        ];

        if ($password) {
            $cmd[] = "--password={$password}";
        }

        // Pipe the dump file into mysql via stdin
        $shellCmd = implode(' ', array_map('escapeshellarg', $cmd)).' < '.escapeshellarg($dumpPath);

        $result = Process::timeout(3600)->run(['sh', '-c', $shellCmd]);

        if (! $result->successful()) {
            return new RestoreDbResult(false, 'mysql restore failed: '.$result->errorOutput());
        }

        return new RestoreDbResult(
            success: true,
            message: 'MySQL restore completed.',
        );
    }
}

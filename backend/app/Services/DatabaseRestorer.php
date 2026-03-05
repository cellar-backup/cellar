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

        // Detect dump format: plain-text SQL (.sql) vs. custom format (.dump, .pg_dump, old .sql.gz custom)
        $isPlainSql = self::isPlainSqlDump($dumpPath);

        if ($isPlainSql) {
            // Use psql for plain-text SQL dumps
            $shellCmd = escapeshellarg('psql')
                .' -h '.escapeshellarg($host)
                .' -p '.escapeshellarg($port)
                .' -U '.escapeshellarg($user)
                .' -d '.escapeshellarg($database)
                .' -v ON_ERROR_STOP=1'
                .' -f '.escapeshellarg($dumpPath);

            $result = Process::timeout(3600)
                ->env(['PGPASSWORD' => $password])
                ->run(['sh', '-c', $shellCmd]);

            if (! $result->successful()) {
                return new RestoreDbResult(false, 'psql restore failed: '.$result->errorOutput());
            }
        } else {
            // Use pg_restore for custom-format dumps (legacy archives)
            $cmd = [
                'pg_restore',
                '-h', $host,
                '-p', $port,
                '-U', $user,
                '--no-password',
                '-d', $database,
                '--clean',
                '--if-exists',
                $dumpPath,
            ];

            $result = Process::timeout(3600)
                ->env(['PGPASSWORD' => $password])
                ->run($cmd);

            if (! $result->successful() && $result->exitCode() >= 2) {
                return new RestoreDbResult(false, 'pg_restore failed: '.$result->errorOutput());
            }
        }

        return new RestoreDbResult(
            success: true,
            message: 'PostgreSQL restore completed.',
        );
    }

    /**
     * Detect whether a dump file is plain-text SQL by reading its first bytes.
     */
    private static function isPlainSqlDump(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if (! $fh) {
            return false;
        }
        $header = fread($fh, 5);
        fclose($fh);

        // pg_dump custom format starts with "PGDMP"
        if ($header === 'PGDMP') {
            return false;
        }

        return true;
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
            '--skip-ssl',
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

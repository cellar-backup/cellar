<?php

namespace App\Services;

use PDO;
use PDOException;

/**
 * Connects to discovered database endpoints and lists their databases.
 *
 * Supports PostgreSQL (PDO), MySQL / MariaDB (PDO), and MongoDB (shell).
 * Used by Radar's import review to let users pick which databases to backup.
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

    /**
     * List user databases on the target server.
     *
     * @return array{databases: string[], error: string|null}
     */
    public function listDatabases(
        string $sourceType,
        string $host,
        int $port,
        ?string $username = null,
        ?string $password = null,
        int $timeout = 5,
    ): array {
        return match ($sourceType) {
            'postgresql' => $this->listPostgres($host, $port, $username, $password, $timeout),
            'mysql', 'mariadb' => $this->listMysql($host, $port, $username, $password, $timeout),
            'mongodb' => $this->listMongo($host, $port, $username, $password, $timeout),
            default => ['databases' => [], 'error' => "Database listing not supported for {$sourceType}."],
        };
    }

    // ── PostgreSQL ──────────────────────────────────────────────

    private function listPostgres(string $host, int $port, ?string $user, ?string $pass, int $timeout): array
    {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname=postgres;connect_timeout={$timeout}";
            $pdo = new PDO($dsn, $user ?? 'postgres', $pass ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => $timeout,
            ]);

            $stmt = $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname");
            $all = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return [
                'databases' => array_values(array_diff($all, self::SYSTEM_DBS['postgresql'])),
                'error' => null,
            ];
        } catch (PDOException $e) {
            return ['databases' => [], 'error' => $this->friendlyError($e)];
        }
    }

    // ── MySQL / MariaDB ─────────────────────────────────────────

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

            $sourceType = str_contains(strtolower($pdo->getAttribute(PDO::ATTR_SERVER_INFO) ?? ''), 'maria') ? 'mariadb' : 'mysql';

            return [
                'databases' => array_values(array_diff($all, self::SYSTEM_DBS[$sourceType] ?? self::SYSTEM_DBS['mysql'])),
                'error' => null,
            ];
        } catch (PDOException $e) {
            return ['databases' => [], 'error' => $this->friendlyError($e)];
        }
    }

    // ── MongoDB (via mongosh CLI) ───────────────────────────────

    private function listMongo(string $host, int $port, ?string $user, ?string $pass, int $timeout): array
    {
        // If mongosh is available, use it
        $mongosh = trim(shell_exec('which mongosh 2>/dev/null') ?? '');
        if (empty($mongosh)) {
            return ['databases' => [], 'error' => 'mongosh not available — enter database name manually.'];
        }

        $uri = $user
            ? sprintf('mongodb://%s:%s@%s:%d', urlencode($user), urlencode($pass ?? ''), $host, $port)
            : sprintf('mongodb://%s:%d', $host, $port);

        $cmd = sprintf(
            '%s %s --quiet --eval %s 2>&1',
            escapeshellarg($mongosh),
            escapeshellarg($uri),
            escapeshellarg("db.adminCommand('listDatabases').databases.map(d => d.name).join('\\n')"),
        );

        $result = \Illuminate\Support\Facades\Process::timeout($timeout + 2)->run($cmd);

        if (! $result->successful()) {
            return ['databases' => [], 'error' => 'Failed to connect to MongoDB.'];
        }

        $names = array_filter(array_map('trim', explode("\n", trim($result->output()))));
        $names = array_values(array_diff($names, self::SYSTEM_DBS['mongodb']));

        return ['databases' => $names, 'error' => null];
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function friendlyError(PDOException $e): string
    {
        $msg = $e->getMessage();

        if (str_contains($msg, 'Connection refused')) {
            return 'Connection refused — check that the host and port are reachable from the Cellar container.';
        }
        if (str_contains($msg, 'Connection timed out') || str_contains($msg, 'timeout')) {
            return 'Connection timed out — the database may not be reachable from this network.';
        }
        if (str_contains($msg, 'Access denied') || str_contains($msg, 'authentication failed') || str_contains($msg, 'password authentication failed')) {
            return 'Authentication failed — check your username and password.';
        }

        return "Connection error: {$msg}";
    }
}

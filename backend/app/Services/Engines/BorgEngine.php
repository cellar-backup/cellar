<?php

namespace App\Services\Engines;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class BorgError extends RuntimeException {}

class BorgEngine implements BackupEngine
{
    public function __construct(
        private string $borgPath = '/usr/bin/borg',
        private ?string $passphrase = null,
    ) {}

    // ── Internals ──────────────────────────────────────────────

    private function env(string $repoPath): array
    {
        return [
            'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
            'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes',
            'BORG_PASSPHRASE' => $this->passphrase ?? '',
        ];
    }

    /**
     * Run a borg command, return decoded JSON or raw output.
     */
    private function run(
        array $args,
        string $repoPath,
        bool $captureJson = false,
        bool $check = true,
        int $timeout = 3600,
    ): array|string {
        $cmd = array_merge([$this->borgPath], $args);

        $result = Process::timeout($timeout)
            ->env($this->env($repoPath))
            ->run($cmd);

        $exitCode = $result->exitCode();

        // 0 = success, 1 = warning (allowed), 2+ = error
        if ($check && $exitCode >= 2) {
            throw new BorgError(
                "Borg command failed (exit {$exitCode}): {$result->errorOutput()}"
            );
        }

        if ($captureJson) {
            $decoded = json_decode($result->output(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BorgError('Failed to parse borg JSON output: '.$result->output());
            }

            return $decoded;
        }

        return $result->output();
    }

    // ── Public API ─────────────────────────────────────────────

    public function initialize(string $repoPath, string $encryption = 'none'): bool
    {
        $this->run(
            ['init', '--encryption', $encryption, $repoPath],
            $repoPath,
            check: false,
        );

        return is_dir($repoPath);
    }

    public function backup(
        string $repoPath,
        array $sourcePaths,
        string $archiveName,
        array $excludePatterns = [],
        string $compression = 'lz4',
    ): BackupResult {
        $args = ['create', '--stats', '--json', '--compression', $compression];

        foreach ($excludePatterns as $pattern) {
            $args[] = '--exclude';
            $args[] = $pattern;
        }

        $args[] = "{$repoPath}::{$archiveName}";
        $args = array_merge($args, $sourcePaths);

        $data = $this->run($args, $repoPath, captureJson: true);

        $stats = $data['archive']['stats'] ?? [];

        return new BackupResult(
            success: true,
            archiveId: $data['archive']['name'] ?? $archiveName,
            sizeOriginal: $stats['original_size'] ?? 0,
            sizeDedup: $stats['deduplicated_size'] ?? 0,
            sizeCompressed: $stats['compressed_size'] ?? 0,
            fileCount: $stats['nfiles'] ?? 0,
            durationSeconds: $data['archive']['duration'] ?? 0.0,
            message: 'Backup completed successfully.',
        );
    }

    public function restore(
        string $repoPath,
        string $archiveId,
        string $targetPath,
        array $includePatterns = [],
    ): RestoreResult {
        if (! is_dir($targetPath)) {
            mkdir($targetPath, 0755, true);
        }

        $args = ['extract', "{$repoPath}::{$archiveId}"];
        $args = array_merge($args, $includePatterns);

        $start = microtime(true);
        $this->run($args, $repoPath);
        $duration = microtime(true) - $start;

        return new RestoreResult(
            success: true,
            durationSeconds: $duration,
            message: 'Restore completed.',
        );
    }

    public function listArchives(string $repoPath): array
    {
        $data = $this->run(
            ['list', '--json', $repoPath],
            $repoPath,
            captureJson: true,
        );

        return array_map(
            fn (array $a) => new ArchiveInfo(
                archiveId: $a['name'] ?? $a['archive'] ?? '',
                name: $a['name'] ?? '',
                timestamp: $a['start'] ?? $a['time'] ?? '',
            ),
            $data['archives'] ?? [],
        );
    }

    public function getArchiveInfo(string $repoPath, string $archiveId): ArchiveInfo
    {
        $data = $this->run(
            ['info', '--json', "{$repoPath}::{$archiveId}"],
            $repoPath,
            captureJson: true,
        );

        $archive = $data['archives'][0] ?? [];
        $stats = $archive['stats'] ?? [];

        return new ArchiveInfo(
            archiveId: $archive['name'] ?? $archiveId,
            name: $archive['name'] ?? $archiveId,
            timestamp: $archive['start'] ?? '',
            sizeOriginal: $stats['original_size'] ?? 0,
            sizeDedup: $stats['deduplicated_size'] ?? 0,
            sizeCompressed: $stats['compressed_size'] ?? 0,
            fileCount: $stats['nfiles'] ?? 0,
            durationSeconds: $archive['duration'] ?? 0.0,
        );
    }

    public function getRepoInfo(string $repoPath): RepoInfo
    {
        $data = $this->run(
            ['info', '--json', $repoPath],
            $repoPath,
            captureJson: true,
        );

        $stats = $data['cache']['stats'] ?? [];

        return new RepoInfo(
            location: $repoPath,
            totalSize: $stats['total_size'] ?? 0,
            totalCsize: $stats['total_csize'] ?? 0,
            uniqueSize: $stats['unique_size'] ?? 0,
            uniqueCsize: $stats['unique_csize'] ?? 0,
            archiveCount: count($data['archives'] ?? []),
        );
    }

    public function prune(string $repoPath, array $keepPolicy, bool $dryRun = false): PruneResult
    {
        $args = ['prune', '--stats', '--list'];

        if ($dryRun) {
            $args[] = '--dry-run';
        }

        $policyMap = [
            'keep_hourly' => '--keep-hourly',
            'keep_daily' => '--keep-daily',
            'keep_weekly' => '--keep-weekly',
            'keep_monthly' => '--keep-monthly',
            'keep_yearly' => '--keep-yearly',
            'keep_last' => '--keep-last',
        ];

        foreach ($policyMap as $key => $flag) {
            if (isset($keepPolicy[$key])) {
                $args[] = $flag;
                $args[] = (string) $keepPolicy[$key];
            }
        }

        $args[] = $repoPath;

        $output = $this->run($args, $repoPath);

        return new PruneResult(
            success: true,
            dryRun: $dryRun,
            message: is_string($output) ? $output : 'Prune completed.',
        );
    }

    public function verify(string $repoPath, ?string $archiveId = null): bool
    {
        $target = $archiveId ? "{$repoPath}::{$archiveId}" : $repoPath;

        try {
            $this->run(['check', $target], $repoPath, check: true);

            return true;
        } catch (BorgError) {
            return false;
        }
    }

    public function deleteArchive(string $repoPath, string $archiveId): bool
    {
        try {
            $this->run(['delete', "{$repoPath}::{$archiveId}"], $repoPath, check: true);

            return true;
        } catch (BorgError) {
            return false;
        }
    }
}

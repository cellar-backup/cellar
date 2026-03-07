<?php

namespace App\Services\Engines;

/**
 * Data transfer objects for engine results.
 */
class BackupResult
{
    public function __construct(
        public bool $success,
        public string $archiveId = '',
        public int $sizeOriginal = 0,
        public int $sizeDedup = 0,
        public int $sizeCompressed = 0,
        public int $fileCount = 0,
        public float $durationSeconds = 0.0,
        public string $message = '',
    ) {}
}

class RestoreResult
{
    public function __construct(
        public bool $success,
        public int $filesRestored = 0,
        public float $durationSeconds = 0.0,
        public string $message = '',
    ) {}
}

class PruneResult
{
    public function __construct(
        public bool $success,
        public array $pruned = [],
        public array $kept = [],
        public int $freedBytes = 0,
        public bool $dryRun = false,
        public string $message = '',
    ) {}
}

class ArchiveInfo
{
    public function __construct(
        public string $archiveId,
        public string $name,
        public string $timestamp,
        public int $sizeOriginal = 0,
        public int $sizeDedup = 0,
        public int $sizeCompressed = 0,
        public int $fileCount = 0,
        public float $durationSeconds = 0.0,
    ) {}
}

class RepoInfo
{
    public function __construct(
        public string $location,
        public int $totalSize = 0,
        public int $totalCsize = 0,
        public int $uniqueSize = 0,
        public int $uniqueCsize = 0,
        public int $archiveCount = 0,
    ) {}
}

/**
 * Contract that all backup engines must implement.
 */
interface BackupEngine
{
    public function initialize(string $repoPath, string $encryption = 'none'): bool;

    public function backup(
        string $repoPath,
        array $sourcePaths,
        string $archiveName,
        array $excludePatterns = [],
        string $compression = 'lz4',
    ): BackupResult;

    public function restore(
        string $repoPath,
        string $archiveId,
        string $targetPath,
        array $includePatterns = [],
    ): RestoreResult;

    /** @return ArchiveInfo[] */
    public function listArchives(string $repoPath): array;

    public function getArchiveInfo(string $repoPath, string $archiveId): ArchiveInfo;

    public function getRepoInfo(string $repoPath): RepoInfo;

    public function prune(string $repoPath, array $keepPolicy, bool $dryRun = false): PruneResult;

    public function verify(string $repoPath, ?string $archiveId = null): bool;

    public function deleteArchive(string $repoPath, string $archiveId): bool;
}

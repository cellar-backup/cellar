<?php

namespace App\Jobs;

use App\Models\Archive;
use App\Models\Job;
use App\Services\DatabaseRestorer;
use App\Services\Engines\BorgEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RunRestore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    /**
     * @param  string  $archiveRecordId  UUID of the Archive model record
     */
    public function __construct(
        public string $archiveRecordId,
    ) {}

    public function handle(): void
    {
        $archive = Archive::with('plan.source', 'plan.repository')->findOrFail($this->archiveRecordId);
        $plan = $archive->plan;
        $source = $plan->source;
        $repo = $plan->repository;

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'restore',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $tmpDir = sys_get_temp_dir().'/cellar_restore_'.Str::random(8);

        try {
            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
            $repoPath = rtrim($repo->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            // 1. Extract archive to temp directory
            mkdir($tmpDir, 0755, true);
            $result = $engine->restore($repoPath, $archive->archive_id, $tmpDir);

            if (! $result->success) {
                throw new \RuntimeException('Borg extract failed: '.$result->message);
            }

            // 2. Find the dump file in the extracted content
            $dumpFile = self::findDumpFile($tmpDir);
            if (! $dumpFile) {
                throw new \RuntimeException('No database dump file found in archive.');
            }

            // 3. Restore dump into the source database
            if ($source->getIsDatabase()) {
                $restoreResult = DatabaseRestorer::restore(
                    $source->source_type->value,
                    [
                        'host' => $source->host,
                        'port' => $source->port,
                        'username' => $source->username,
                        'password' => $source->password,
                        'database_name' => $source->database_name,
                    ],
                    $dumpFile,
                );

                if (! $restoreResult->success) {
                    throw new \RuntimeException('Database restore failed: '.$restoreResult->message);
                }
            }

            $job->update([
                'status' => 'success',
                'finished_at' => now(),
                'metadata' => [
                    'archive_id' => $archive->archive_id,
                    'dump_file' => basename($dumpFile),
                    'duration' => $result->durationSeconds,
                ],
            ]);

        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => Str::limit($e->getMessage(), 2000),
            ]);

            throw $e;
        } finally {
            if (is_dir($tmpDir)) {
                self::removeDirectory($tmpDir);
            }
        }
    }

    /**
     * Recursively search the extracted directory for a dump file.
     */
    public static function findDumpFile(string $dir): ?string
    {
        $extensions = ['.sql.gz', '.dump', '.sql', '.pg_dump'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            foreach ($extensions as $ext) {
                if (str_ends_with($name, $ext)) {
                    return $file->getPathname();
                }
            }
        }

        // Fallback: return the first file found
        $fallback = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($fallback as $file) {
            if ($file->isFile()) {
                return $file->getPathname();
            }
        }

        return null;
    }

    public static function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}

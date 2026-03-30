<?php

namespace App\Jobs;

use App\Models\Archive;
use App\Models\Job;
use App\Services\DatabaseRestorer;
use App\Services\Engines\BorgEngine;
use App\Services\JobLogger;
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

    public function __construct(
        public string $archiveRecordId,
        public ?string $jobId = null,
    ) {}

    public function handle(): void
    {
        $archive = Archive::with('plan.source', 'plan.repository')->findOrFail($this->archiveRecordId);
        $plan = $archive->plan;
        $source = $plan->source;
        $repo = $plan->repository;

        // Use pre-created job record (from controller) or create one (legacy)
        $job = $this->jobId
            ? Job::findOrFail($this->jobId)
            : Job::create([
                'plan_id' => $plan->id,
                'job_type' => 'restore',
                'status' => 'pending',
                'progress' => 0,
            ]);

        if ($job->isCancelled()) {
            return;
        }

        $job->update([
            'status' => 'running',
            'started_at' => now(),
            'progress' => 5,
        ]);

        $tmpDir = sys_get_temp_dir().'/cellar_restore_'.Str::random(8);
        $log = new JobLogger($job);

        try {
            $log->section('Initializing restore');
            $log->line("Plan: {$plan->name}");
            $log->line("Archive: {$archive->archive_id}");
            $log->line("Source: {$source->display_label}");

            $engine = new BorgEngine(
                borgPath: config('cellar.borg_path', '/usr/bin/borg'),
                passphrase: config('cellar.borg_passphrase'),
            );
            $repoPath = rtrim($repo->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            // 1. Extract archive to temp directory
            mkdir($tmpDir, 0755, true);
            $job->update(['progress' => 15]);

            if ($job->isCancelled()) {
                $log->line('Job cancelled by user.');
                $log->close();

                return;
            }

            $log->section('Extracting archive');
            $result = $engine->restore($repoPath, $archive->archive_id, $tmpDir);

            if (! $result->success) {
                $log->line("FAILED: {$result->message}");
                throw new \RuntimeException('Borg extract failed: '.$result->message);
            }

            $log->line("Extraction completed in {$result->durationSeconds}s");
            $job->update(['progress' => 50]);

            if ($job->isCancelled()) {
                $log->line('Job cancelled by user.');
                $log->close();

                return;
            }

            // 2. Find the dump file in the extracted content
            $dumpFile = self::findDumpFile($tmpDir);
            if (! $dumpFile) {
                $log->line('No database dump file found in archive.');
                throw new \RuntimeException('No database dump file found in archive.');
            }
            $log->line('Found dump file: '.basename($dumpFile));

            // 3. Restore dump into the source database
            $job->update(['progress' => 60]);

            if ($job->isCancelled()) {
                $log->line('Job cancelled by user.');
                $log->close();

                return;
            }

            if ($source->getIsDatabase()) {
                $log->section('Database restore');
                $log->line("Restoring to {$source->source_type->value}: {$source->database_name}");

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
                    $log->line("FAILED: {$restoreResult->message}");
                    throw new \RuntimeException('Database restore failed: '.$restoreResult->message);
                }
                $log->line('Restore completed successfully.');
            }

            $job->update([
                'status' => 'success',
                'finished_at' => now(),
                'progress' => 100,
                'metadata' => [
                    'archive_id' => $archive->archive_id,
                    'dump_file' => basename($dumpFile),
                    'duration' => $result->durationSeconds,
                ],
            ]);
            $log->section('Completed');
            $log->line('Restore finished successfully.');
            $log->close();

        } catch (\Throwable $e) {
            $log->error($e);
            $log->close();

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

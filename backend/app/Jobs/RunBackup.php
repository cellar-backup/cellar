<?php

namespace App\Jobs;

use App\Models\Archive;
use App\Models\BackupPlan;
use App\Models\Job;
use App\Services\DatabaseDumper;
use App\Services\Engines\BorgEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RunBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    public function __construct(public string $planId) {}

    public function handle(): void
    {
        $plan = BackupPlan::with(['source', 'repository'])->findOrFail($this->planId);
        $source = $plan->source;
        $repo = $plan->repository;

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'backup',
            'status' => 'running',
            'started_at' => now(),
            'progress' => 5,
        ]);

        $plan->update(['status' => 'running']);

        $tmpDir = null;

        try {
            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
            $repoPath = rtrim($repo->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            // Ensure repo is initialized
            if (! is_dir($repoPath)) {
                $engine->initialize($repoPath);
            }

            $job->update(['progress' => 10]);

            // Prepare source
            if ($source->getIsDatabase()) {
                $tmpDir = sys_get_temp_dir().'/cellar_dump_'.Str::random(8);
                mkdir($tmpDir, 0755, true);

                $job->update(['progress' => 15]);

                $dumpResult = DatabaseDumper::dump(
                    $source->source_type->value,
                    [
                        'host' => $source->host,
                        'port' => $source->port,
                        'username' => $source->username,
                        'password' => $source->password,
                        'database_name' => $source->database_name,
                    ],
                    $tmpDir,
                );

                if (! $dumpResult->success) {
                    throw new \RuntimeException('Database dump failed: '.$dumpResult->message);
                }

                $sourcePaths = [$tmpDir];
                $job->update(['progress' => 30]);
            } else {
                $sourcePaths = [$source->path];
                $job->update(['progress' => 30]);
            }

            // Build archive name
            $safeName = Str::slug($source->display_label, '_');
            $archiveName = $safeName.'-'.now()->format('Ymd\THis');

            // Run backup
            $result = $engine->backup(
                $repoPath,
                $sourcePaths,
                $archiveName,
                compression: $plan->compression ?? 'lz4',
            );

            $job->update(['progress' => 85]);

            // Create archive record
            Archive::create([
                'plan_id' => $plan->id,
                'archive_id' => $result->archiveId,
                'timestamp' => now(),
                'size_original' => $result->sizeOriginal,
                'size_dedup' => $result->sizeDedup,
                'size_compressed' => $result->sizeCompressed,
                'duration' => (int) $result->durationSeconds,
                'file_count' => $result->fileCount,
                'metadata' => ['message' => $result->message],
            ]);

            $job->update([
                'status' => 'success',
                'finished_at' => now(),
                'progress' => 100,
                'metadata' => [
                    'archive_id' => $result->archiveId,
                    'size_original' => $result->sizeOriginal,
                    'size_dedup' => $result->sizeDedup,
                    'duration' => $result->durationSeconds,
                ],
            ]);

            $plan->update(['status' => 'healthy', 'last_run' => now()]);

        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => Str::limit($e->getMessage(), 2000),
            ]);

            $plan->update(['status' => 'failed']);

            throw $e;
        } finally {
            // Cleanup temp dump directory
            if ($tmpDir && is_dir($tmpDir)) {
                $this->removeDirectory($tmpDir);
            }
        }
    }

    private function removeDirectory(string $dir): void
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

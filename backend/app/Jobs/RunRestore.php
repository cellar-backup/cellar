<?php

namespace App\Jobs;

use App\Models\BackupPlan;
use App\Models\Job;
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

    public function __construct(
        public string $planId,
        public string $archiveId,
        public string $targetPath,
    ) {}

    public function handle(): void
    {
        $plan = BackupPlan::with('repository')->findOrFail($this->planId);

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'restore',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
            $repoPath = rtrim($plan->repository->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            $result = $engine->restore($repoPath, $this->archiveId, $this->targetPath);

            if (! $result->success) {
                throw new \RuntimeException('Restore failed: '.$result->message);
            }

            $job->update([
                'status' => 'success',
                'finished_at' => now(),
                'metadata' => [
                    'archive_id' => $this->archiveId,
                    'target_path' => $this->targetPath,
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
        }
    }
}

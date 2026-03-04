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

class RunVerify implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public string $planId,
        public ?string $archiveId = null,
    ) {}

    public function handle(): void
    {
        $plan = BackupPlan::with('repository')->findOrFail($this->planId);

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'verify',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
            $repoPath = rtrim($plan->repository->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            $passed = $engine->verify($repoPath, $this->archiveId);

            $job->update([
                'status' => $passed ? 'success' : 'failed',
                'finished_at' => now(),
                'error_message' => $passed ? '' : 'Verification check failed.',
                'metadata' => [
                    'archive_id' => $this->archiveId,
                    'passed' => $passed,
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

<?php

namespace App\Jobs;

use App\Models\BackupPlan;
use App\Models\Job;
use App\Services\Engines\BorgEngine;
use App\Services\JobLogger;
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
        public ?string $jobId = null,
    ) {}

    public function handle(): void
    {
        $plan = BackupPlan::with('repository')->findOrFail($this->planId);

        // Use pre-created job record (from controller) or create one (legacy/scheduler)
        $job = $this->jobId
            ? Job::findOrFail($this->jobId)
            : Job::create([
                'plan_id' => $plan->id,
                'job_type' => 'verify',
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

        $log = new JobLogger($job);

        try {
            $log->section('Initializing verification');
            $log->line("Plan: {$plan->name}");
            if ($this->archiveId) {
                $log->line("Archive: {$this->archiveId}");
            } else {
                $log->line('Verifying entire repository.');
            }

            $engine = new BorgEngine(
                borgPath: config('cellar.borg_path', '/usr/bin/borg'),
                passphrase: config('cellar.borg_passphrase'),
            );
            $repoPath = rtrim($plan->repository->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            $job->update(['progress' => 20]);

            if ($job->isCancelled()) {
                $log->line('Job cancelled by user.');
                $log->close();
                return;
            }

            $log->section('Running borg check');
            $passed = $engine->verify($repoPath, $this->archiveId);

            $log->line($passed ? 'Verification PASSED.' : 'Verification FAILED.');

            $job->update([
                'status' => $passed ? 'success' : 'failed',
                'finished_at' => now(),
                'progress' => 100,
                'error_message' => $passed ? '' : 'Verification check failed.',
                'metadata' => [
                    'archive_id' => $this->archiveId,
                    'passed' => $passed,
                ],
            ]);
            $log->section('Completed');
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
        }
    }
}

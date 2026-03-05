<?php

namespace App\Jobs;

use App\Models\Archive;
use App\Models\BackupPlan;
use App\Models\Job;
use App\Services\Engines\BorgEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RunPrune implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public string $planId,
        public bool $dryRun = false,
    ) {}

    public function handle(): void
    {
        $plan = BackupPlan::with('repository')->findOrFail($this->planId);

        if (empty($plan->retention_policy)) {
            return; // Nothing to prune
        }

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'prune',
            'status' => 'running',
            'started_at' => now(),
            'progress' => 5,
        ]);

        try {
            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
            $repoPath = rtrim($plan->repository->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            $job->update(['progress' => 20]);
            $result = $engine->prune($repoPath, $plan->retention_policy, $this->dryRun);

            $job->update(['progress' => 60]);

            // If not dry run, reconcile DB archives with what's actually in the repo
            if (! $this->dryRun) {
                $currentArchives = $engine->listArchives($repoPath);
                $currentIds = collect($currentArchives)->pluck('archiveId')->all();

                Archive::where('plan_id', $plan->id)
                    ->whereNotIn('archive_id', $currentIds)
                    ->delete();
            }

            $job->update([
                'status' => 'success',
                'finished_at' => now(),
                'progress' => 100,
                'metadata' => [
                    'pruned' => $result->pruned,
                    'kept' => $result->kept,
                    'dry_run' => $this->dryRun,
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

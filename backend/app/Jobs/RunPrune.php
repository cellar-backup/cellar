<?php

namespace App\Jobs;

use App\Models\Archive;
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

class RunPrune implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public string $planId,
        public bool $dryRun = false,
        public ?string $jobId = null,
    ) {}

    public function handle(): void
    {
        $plan = BackupPlan::with(['repository', 'source'])->findOrFail($this->planId);

        // Retention lives on the source; fall back to plan for backward compat
        $retentionPolicy = $plan->source?->retention_policy ?? $plan->retention_policy ?? [];

        if (empty($retentionPolicy)) {
            return; // Nothing to prune
        }

        // Use pre-created job record (from controller) or create one (legacy/scheduler)
        $job = $this->jobId
            ? Job::findOrFail($this->jobId)
            : Job::create([
                'plan_id' => $plan->id,
                'job_type' => 'prune',
                'status' => 'pending',
                'progress' => 0,
            ]);

        if ($job->isCancelled()) {
            return;
        }

        if (! ($plan->source?->enabled ?? true)) {
            $job->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_message' => 'Source is disabled.',
            ]);
            return;
        }

        $job->update([
            'status' => 'running',
            'started_at' => now(),
            'progress' => 5,
        ]);

        $log = new JobLogger($job);

        try {
            $log->section('Initializing prune');
            $log->line("Plan: {$plan->name}");
            $log->line('Retention policy: '.json_encode($retentionPolicy));
            $log->line('Dry run: '.($this->dryRun ? 'yes' : 'no'));

            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
            $repoPath = rtrim($plan->repository->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

            $job->update(['progress' => 20]);

            if ($job->isCancelled()) {
                $log->line('Job cancelled by user.');
                $log->close();
                return;
            }

            $log->section('Running borg prune');
            $result = $engine->prune($repoPath, $retentionPolicy, $this->dryRun);
            $log->line($result->message);

            $job->update(['progress' => 60]);

            if ($job->isCancelled()) {
                $log->line('Job cancelled by user.');
                $log->close();
                return;
            }

            // If not dry run, reconcile DB archives with what's actually in the repo
            if (! $this->dryRun) {
                $log->section('Reconciling archives');
                $currentArchives = $engine->listArchives($repoPath);
                $currentIds = collect($currentArchives)->pluck('archiveId')->all();
                $log->line('Archives remaining in repo: '.count($currentIds));

                $deleted = Archive::where('plan_id', $plan->id)
                    ->where('keep_forever', false)
                    ->whereNotIn('archive_id', $currentIds)
                    ->delete();
                $log->line("Removed {$deleted} archive records from database.");

                // Never delete keep_forever archives from DB
                $kept = Archive::where('plan_id', $plan->id)
                    ->where('keep_forever', true)
                    ->whereNotIn('archive_id', $currentIds)
                    ->count();
                if ($kept > 0) {
                    $log->line("WARNING: {$kept} keep-forever archive(s) were pruned from borg but preserved in database.");
                }
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
            $log->section('Completed');
            $log->line('Prune finished successfully.');
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

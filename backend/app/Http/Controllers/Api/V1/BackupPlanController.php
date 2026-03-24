<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunBackup;
use App\Jobs\RunPrune;
use App\Jobs\RunRestore;
use App\Jobs\RunVerify;
use App\Models\BackupPlan;
use App\Models\Job;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = BackupPlan::with(['source', 'repository'])
            ->orderByDesc('updated_at')
            ->get();

        // Fetch running jobs for all plans in one query
        $runningJobs = Job::where('status', 'running')
            ->whereIn('plan_id', $plans->pluck('id'))
            ->get()
            ->keyBy('plan_id');

        $result = $plans->map(function (BackupPlan $p) use ($runningJobs) {
            $runningJob = $runningJobs->get($p->id);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'source_name' => $p->source_name,
                'source_type' => $p->source_type,
                'repository_name' => $p->repository_name,
                'engine' => $p->engine,
                'status' => $p->status,
                'schedule_cron' => $p->schedule_cron,
                'schedule_enabled' => $p->schedule_enabled,
                'last_run' => $p->last_run,
                'next_run' => $p->next_run,
                'created_at' => $p->created_at,
                'running_job' => $runningJob ? [
                    'id' => $runningJob->id,
                    'job_type' => $runningJob->job_type,
                    'progress' => $runningJob->progress,
                    'started_at' => $runningJob->started_at,
                ] : null,
            ];
        });

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'source_id' => 'required|uuid|exists:sources,id',
            'repository_id' => 'required|uuid|exists:repositories,id',
            'engine' => 'nullable|string|max:20',
            'schedule_cron' => 'nullable|string|max:100',
            'schedule_enabled' => 'nullable|boolean',
            'retention_policy' => 'nullable|array',
            'compression' => 'nullable|string|max:20',
            'encryption' => 'nullable|string|max:20',
            'pre_hook' => 'nullable|string',
            'post_hook' => 'nullable|string',
        ]);

        $plan = BackupPlan::create($data);

        return response()->json($plan->load(['source', 'repository']), 201);
    }

    public function show(BackupPlan $plan): JsonResponse
    {
        $plan->load(['source', 'repository']);

        return response()->json(array_merge($plan->toArray(), [
            'source_name' => $plan->source_name,
            'source_type' => $plan->source_type,
            'repository_name' => $plan->repository_name,
        ]));
    }

    public function update(Request $request, BackupPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'source_id' => 'sometimes|uuid|exists:sources,id',
            'repository_id' => 'sometimes|uuid|exists:repositories,id',
            'engine' => 'nullable|string|max:20',
            'schedule_cron' => 'nullable|string|max:100',
            'schedule_enabled' => 'nullable|boolean',
            'retention_policy' => 'nullable|array',
            'compression' => 'nullable|string|max:20',
            'encryption' => 'nullable|string|max:20',
            'pre_hook' => 'nullable|string',
            'post_hook' => 'nullable|string',
        ]);

        $plan->update($data);

        return response()->json($plan->fresh(['source', 'repository']));
    }

    public function destroy(BackupPlan $plan): JsonResponse
    {
        $plan->delete();

        return response()->json(null, 204);
    }

    // ── Custom actions ─────────────────────────────────────────

    public function backup(BackupPlan $plan): JsonResponse
    {
        if ($plan->source && ! $plan->source->enabled) {
            return response()->json(['detail' => 'Source is disabled. Enable the source before running a backup.'], 422);
        }

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'backup',
            'status' => 'pending',
            'progress' => 0,
        ]);

        RunBackup::dispatch($plan->id, $job->id);

        return response()->json([
            'detail' => 'Backup job queued.',
            'job_id' => $job->id,
        ], 202);
    }

    public function restore(Request $request, BackupPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'archive_id' => 'required|string|exists:archives,id',
        ]);

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'restore',
            'status' => 'pending',
            'progress' => 0,
        ]);

        RunRestore::dispatch($data['archive_id'], $job->id);

        return response()->json([
            'detail' => 'Restore job queued.',
            'job_id' => $job->id,
        ], 202);
    }

    public function prune(Request $request, BackupPlan $plan): JsonResponse
    {
        $dryRun = $request->boolean('dry_run', false);

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'prune',
            'status' => 'pending',
            'progress' => 0,
        ]);

        RunPrune::dispatch($plan->id, $dryRun, $job->id);

        return response()->json([
            'detail' => $dryRun ? 'Prune dry-run job queued.' : 'Prune job queued.',
            'job_id' => $job->id,
        ], 202);
    }

    public function verify(Request $request, BackupPlan $plan): JsonResponse
    {
        $archiveId = $request->input('archive_id');

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'verify',
            'status' => 'pending',
            'progress' => 0,
        ]);

        RunVerify::dispatch($plan->id, $archiveId, $job->id);

        return response()->json([
            'detail' => 'Verify job queued.',
            'job_id' => $job->id,
        ], 202);
    }

    // ── Toggle schedule_enabled ────────────────────────────────

    public function toggle(BackupPlan $plan): JsonResponse
    {
        $plan->update(['schedule_enabled' => ! $plan->schedule_enabled]);

        return response()->json([
            'schedule_enabled' => $plan->schedule_enabled,
            'message' => $plan->schedule_enabled ? 'Policy enabled.' : 'Policy paused.',
        ]);
    }
}

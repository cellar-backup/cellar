<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunBackup;
use App\Jobs\RunPrune;
use App\Jobs\RunRestore;
use App\Jobs\RunVerify;
use App\Models\BackupPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = BackupPlan::with(['source', 'repository'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (BackupPlan $p) => [
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
            ]);

        return response()->json($plans);
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
        RunBackup::dispatch($plan->id);

        return response()->json([
            'detail' => 'Backup job queued.',
        ], 202);
    }

    public function restore(Request $request, BackupPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'archive_id' => 'required|string',
            'target_path' => 'required|string',
        ]);

        RunRestore::dispatch($plan->id, $data['archive_id'], $data['target_path']);

        return response()->json([
            'detail' => 'Restore job queued.',
        ], 202);
    }

    public function prune(Request $request, BackupPlan $plan): JsonResponse
    {
        $dryRun = $request->boolean('dry_run', false);

        RunPrune::dispatch($plan->id, $dryRun);

        return response()->json([
            'detail' => $dryRun ? 'Prune dry-run job queued.' : 'Prune job queued.',
        ], 202);
    }

    public function verify(Request $request, BackupPlan $plan): JsonResponse
    {
        $archiveId = $request->input('archive_id');

        RunVerify::dispatch($plan->id, $archiveId);

        return response()->json([
            'detail' => 'Verify job queued.',
        ], 202);
    }
}

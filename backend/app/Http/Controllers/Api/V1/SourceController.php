<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Archive;
use App\Models\BackupPlan;
use App\Models\Job;
use App\Models\Repository;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = Source::withCount('backupPlans')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Source $s) {
                $planIds = $s->backupPlans()->pluck('id');
                $archiveCount = Archive::whereIn('plan_id', $planIds)->count();
                $lastArchive = Archive::whereIn('plan_id', $planIds)->max('timestamp');

                // Last backup job status (across all plans for this source)
                $lastJob = Job::whereIn('plan_id', $planIds)
                    ->where('job_type', 'backup')
                    ->orderByDesc('created_at')
                    ->first(['status', 'finished_at', 'created_at']);

                $data = $s->toArray();
                $data['display_label'] = $s->display_label;
                $data['is_database'] = $s->getIsDatabase();
                $data['policy_count'] = $s->backup_plans_count;
                $data['archive_count'] = $archiveCount;
                $data['last_archive_at'] = $lastArchive;
                $data['retention_policy'] = $s->retention_policy;
                $data['last_job_status'] = $lastJob?->status?->value;
                $data['last_job_at'] = $lastJob?->finished_at ?? $lastJob?->created_at;

                return $data;
            });

        return response()->json($sources);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type' => 'required|string|max:20',
            'name' => 'nullable|string|max:255',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:500',
            'database_name' => 'nullable|string|max:255',
            'path' => 'nullable|string|max:1000',
            'notes' => 'nullable|string',
            'extra_config' => 'nullable|array',
            'retention_policy' => 'nullable|array',
        ]);

        $source = Source::create($data);

        return response()->json($source, 201);
    }

    public function show(Source $source): JsonResponse
    {
        return response()->json(array_merge($source->toArray(), [
            'display_label' => $source->display_label,
            'is_database' => $source->getIsDatabase(),
        ]));
    }

    public function update(Request $request, Source $source): JsonResponse
    {
        $data = $request->validate([
            'source_type' => 'sometimes|string|max:20',
            'name' => 'nullable|string|max:255',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:500',
            'database_name' => 'nullable|string|max:255',
            'path' => 'nullable|string|max:1000',
            'notes' => 'nullable|string',
            'enabled' => 'nullable|boolean',
            'extra_config' => 'nullable|array',
            'retention_policy' => 'nullable|array',
        ]);

        // Laravel's ConvertEmptyStringsToNull middleware turns '' into null,
        // but these columns are NOT NULL with default '' in SQLite.
        foreach (['name', 'host', 'username', 'password', 'database_name', 'path', 'notes'] as $field) {
            if (array_key_exists($field, $data) && is_null($data[$field])) {
                $data[$field] = '';
            }
        }

        $source->update($data);

        return response()->json($source->fresh());
    }

    public function destroy(Source $source): JsonResponse
    {
        $source->delete();

        return response()->json(null, 204);
    }

    /**
     * Quick-add wizard: create Source + BackupPlan in one call.
     */
    public function quickAdd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type' => 'required|string|max:20',
            'name' => 'nullable|string|max:255',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:500',
            'database_name' => 'nullable|string|max:255',
            'path' => 'nullable|string|max:1000',
            'schedule' => 'nullable|string|max:100',
        ]);

        $schedule = $data['schedule'] ?? '0 2 * * *';
        unset($data['schedule']);

        $source = Source::create($data);

        // Ensure a default repository exists
        $repo = Repository::where('is_default', true)->first();
        if (! $repo) {
            $repo = Repository::create([
                'name' => 'Default Local',
                'backend_type' => 'local',
                'is_default' => true,
                'config' => ['path' => '/data/repositories'],
            ]);
        }

        $plan = BackupPlan::create([
            'name' => 'Backup: '.$source->display_label,
            'source_id' => $source->id,
            'repository_id' => $repo->id,
            'schedule_cron' => $schedule,
        ]);

        return response()->json([
            'source' => $source,
            'backup_plan' => $plan,
            'message' => "Source and backup plan created. First backup scheduled at: {$schedule}",
        ], 201);
    }

    /**
     * Test source connectivity (database connection or filesystem path).
     */
    public function testConnection(Source $source): JsonResponse
    {
        try {
            $ok = $source->checkConnection();
        } catch (\Throwable $e) {
            $ok = false;
        }

        $source->update([
            'is_reachable' => $ok,
            'last_checked_at' => now(),
        ]);

        $message = $ok ? 'Connection successful.' : 'Connection failed.';

        return response()->json([
            'status' => $ok ? 'ok' : 'error',
            'message' => $message,
        ], $ok ? 200 : 422);
    }

    // ── Retention ─────────────────────────────────────────────

    public function updateRetention(Request $request, Source $source): JsonResponse
    {
        $data = $request->validate([
            'retention_policy' => 'required|array',
        ]);

        $source->update(['retention_policy' => $data['retention_policy']]);

        return response()->json([
            'retention_policy' => $source->retention_policy,
            'message' => 'Retention policy updated.',
        ]);
    }

    /**
     * Update the dump method for a Kubernetes-sourced database.
     *
     * 'direct'  — dump via psql/mysqldump over the network (LB / external IP).
     * 'kubectl' — dump via kubectl exec inside the database pod.
     */
    public function updateDumpMethod(Request $request, Source $source): JsonResponse
    {
        $data = $request->validate([
            'dump_method' => 'required|string|in:direct,kubectl',
        ]);

        $extra = $source->extra_config ?? [];
        $extra['kubernetes'] = array_merge($extra['kubernetes'] ?? [], [
            'dump_method' => $data['dump_method'],
        ]);
        $source->update(['extra_config' => $extra]);

        return response()->json([
            'extra_config' => $source->extra_config,
            'message' => 'Dump method updated.',
        ]);
    }

    // ── Toggle enabled ─────────────────────────────────────────

    public function toggle(Source $source): JsonResponse
    {
        $source->update(['enabled' => ! $source->enabled]);

        return response()->json([
            'enabled' => $source->enabled,
            'message' => $source->enabled ? 'Source enabled.' : 'Source disabled — policies will not execute.',
        ]);
    }

    // ── Policies (backup plans for this source) ────────────────

    public function policies(Source $source): JsonResponse
    {
        $plans = BackupPlan::with('repository')
            ->where('source_id', $source->id)
            ->orderByDesc('updated_at')
            ->get();

        $runningJobs = Job::where('status', 'running')
            ->whereIn('plan_id', $plans->pluck('id'))
            ->get()
            ->keyBy('plan_id');

        $result = $plans->map(function (BackupPlan $p) use ($runningJobs) {
            $runningJob = $runningJobs->get($p->id);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'repository_name' => $p->repository_name,
                'engine' => $p->engine,
                'status' => $p->status,
                'schedule_cron' => $p->schedule_cron,
                'schedule_enabled' => $p->schedule_enabled,
                'retention_policy' => $p->retention_policy,
                'last_run' => $p->last_run,
                'next_run' => $p->next_run,
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

    // ── Archives (timeline) ────────────────────────────────────

    public function archives(Source $source): JsonResponse
    {
        $planIds = $source->backupPlans()->pluck('id');

        $archives = Archive::with('plan')
            ->whereIn('plan_id', $planIds)
            ->orderByDesc('timestamp')
            ->get()
            ->map(function (Archive $a) {
                $data = $a->toArray();
                $data['plan_name'] = $a->plan_name;

                return $data;
            });

        return response()->json($archives);
    }
}

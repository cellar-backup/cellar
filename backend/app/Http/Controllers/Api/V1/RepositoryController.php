<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Archive;
use App\Models\BackupPlan;
use App\Models\Repository;
use App\Models\Source;
use App\Services\Engines\BorgEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RepositoryController extends Controller
{
    public function index(): JsonResponse
    {
        $repos = Repository::orderBy('name')
            ->get()
            ->map(fn (Repository $r) => array_merge($r->toArray(), [
                'plan_count' => $r->plan_count,
            ]));

        return response()->json($repos);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:repositories',
            'description' => 'nullable|string',
            'backend_type' => 'required|string|max:20',
            'config' => 'nullable|array',
        ]);

        $repo = Repository::create($data);

        return response()->json($repo, 201);
    }

    public function show(Repository $repository): JsonResponse
    {
        return response()->json(array_merge($repository->toArray(), [
            'plan_count' => $repository->plan_count,
        ]));
    }

    public function update(Request $request, Repository $repository): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255|unique:repositories,name,'.$repository->id,
            'description' => 'nullable|string',
            'backend_type' => 'sometimes|string|max:20',
            'config' => 'nullable|array',
        ]);

        $repository->update($data);

        return response()->json($repository->fresh());
    }

    public function destroy(Repository $repository): JsonResponse
    {
        $repository->delete();

        return response()->json(null, 204);
    }

    /**
     * Test repository connectivity and update status.
     */
    public function test(Repository $repository): JsonResponse
    {
        if ($repository->backend_type->value === 'local') {
            $path = $repository->config['path'] ?? '/data/repositories';

            if (is_dir($path)) {
                $disk = disk_total_space($path);
                $free = disk_free_space($path);
                $repository->update([
                    'status' => 'online',
                    'capacity_bytes' => $disk ?: null,
                    'used_bytes' => $disk && $free ? $disk - $free : 0,
                    'last_checked' => now(),
                ]);

                return response()->json(['status' => 'online', 'message' => 'Repository is accessible.']);
            }

            $repository->update(['status' => 'offline', 'last_checked' => now()]);

            return response()->json(['status' => 'offline', 'message' => 'Path not found.'], 422);
        }

        // For remote backends — stub for now
        $repository->update(['last_checked' => now()]);

        return response()->json(['status' => 'unknown', 'message' => 'Remote backend check not implemented yet.']);
    }

    /**
     * Import an existing borg repository.
     *
     * Scans the repo, creates a BackupPlan + Source (type=directory) as an
     * anchor, then imports every archive found in the borg repo into the
     * archives table.
     */
    public function import(Request $request, Repository $repository): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string|max:2000',
            'name' => 'nullable|string|max:255',
        ]);

        $repoPath = $data['path'];

        // Verify the path contains a valid borg repo (has a README + data dir)
        if (! is_dir($repoPath) || ! is_file("{$repoPath}/README")) {
            return response()->json([
                'status' => 'error',
                'message' => 'Path does not appear to be a valid borg repository.',
            ], 422);
        }

        try {
            $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));

            // Get repo info
            $repoInfo = $engine->getRepoInfo($repoPath);

            // Get all archives
            $archives = $engine->listArchives($repoPath);

            // Create a directory-type source pointing at the repo path
            $source = Source::create([
                'source_type' => 'directory',
                'name' => $data['name'] ?? 'Imported: '.basename($repoPath),
                'path' => $repoPath,
                'notes' => 'Auto-created from borg repo import.',
                'enabled' => true,
            ]);

            // Create a plan referencing this repo + source
            $planName = $data['name'] ?? 'Imported: '.basename($repoPath);

            $plan = BackupPlan::create([
                'name' => $planName,
                'source_id' => $source->id,
                'repository_id' => $repository->id,
                'schedule_enabled' => false,           // Don't auto-schedule imported repos
                'status' => 'idle',
            ]);

            // Update repo config to include this path mapping
            $config = $repository->config ?? [];
            $config['imported_paths'][$plan->id] = $repoPath;
            $repository->update([
                'config' => $config,
                'status' => 'online',
                'last_checked' => now(),
            ]);

            // Import each archive with detailed info
            $imported = 0;
            foreach ($archives as $archiveBasic) {
                try {
                    $info = $engine->getArchiveInfo($repoPath, $archiveBasic->archiveId);

                    Archive::create([
                        'plan_id' => $plan->id,
                        'archive_id' => $info->archiveId,
                        'timestamp' => $info->timestamp ? Carbon::parse($info->timestamp) : now(),
                        'size_original' => $info->sizeOriginal,
                        'size_dedup' => $info->sizeDedup,
                        'size_compressed' => $info->sizeCompressed,
                        'file_count' => $info->fileCount,
                        'duration' => (int) $info->durationSeconds,
                        'metadata' => ['imported' => true, 'source_repo' => $repoPath],
                    ]);

                    $imported++;
                } catch (\Throwable $e) {
                    // Skip individual archives that fail, continue importing
                    continue;
                }
            }

            return response()->json([
                'status' => 'ok',
                'message' => "Imported {$imported} archives from borg repository.",
                'repository' => $repository->fresh(),
                'plan' => $plan->load('source'),
                'archive_count' => $imported,
                'repo_info' => [
                    'total_size' => $repoInfo->totalSize,
                    'unique_size' => $repoInfo->uniqueSize,
                    'archive_count' => $repoInfo->archiveCount,
                ],
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to import repository: '.$e->getMessage(),
            ], 500);
        }
    }
}

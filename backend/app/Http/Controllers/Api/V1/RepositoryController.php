<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}

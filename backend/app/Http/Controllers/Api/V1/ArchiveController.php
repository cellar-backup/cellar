<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunRestore;
use App\Models\Archive;
use App\Services\Engines\BorgEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ArchiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Archive::with('plan')->orderByDesc('timestamp');

        if ($request->has('plan')) {
            $query->where('plan_id', $request->input('plan'));
        }

        $archives = $query->paginate($request->integer('per_page', 25));

        $archives->getCollection()->transform(function (Archive $a) {
            $data = $a->toArray();
            $data['plan_name'] = $a->plan_name;

            return $data;
        });

        return response()->json($archives);
    }

    public function show(Archive $archive): JsonResponse
    {
        return response()->json(array_merge($archive->toArray(), [
            'plan_name' => $archive->plan_name,
        ]));
    }

    public function destroy(Archive $archive): JsonResponse
    {
        $archive->delete();

        return response()->json(null, 204);
    }

    // ── Restore ────────────────────────────────────────────────

    /**
     * POST /archives/{archive}/restore
     *
     * Queue a job that extracts the borg archive and restores
     * the database dump back into the original source.
     */
    public function restore(Archive $archive): JsonResponse
    {
        RunRestore::dispatch($archive->id);

        return response()->json([
            'detail' => 'Restore job queued.',
        ], 202);
    }

    // ── Export / Download ──────────────────────────────────────

    /**
     * GET /archives/{archive}/download
     *
     * Extract the archive to a temp directory and stream the
     * dump file back to the browser as a download.
     */
    public function download(Archive $archive): BinaryFileResponse|JsonResponse
    {
        $archive->load('plan.repository');
        $plan = $archive->plan;
        $repo = $plan->repository;

        $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
        $repoPath = rtrim($repo->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

        $tmpDir = sys_get_temp_dir().'/cellar_export_'.Str::random(8);
        mkdir($tmpDir, 0755, true);

        try {
            $result = $engine->restore($repoPath, $archive->archive_id, $tmpDir);

            if (! $result->success) {
                $this->cleanupDir($tmpDir);

                return response()->json([
                    'detail' => 'Failed to extract archive: '.$result->message,
                ], 500);
            }

            $dumpFile = RunRestore::findDumpFile($tmpDir);

            if (! $dumpFile) {
                $this->cleanupDir($tmpDir);

                return response()->json([
                    'detail' => 'No downloadable file found in archive.',
                ], 404);
            }

            $filename = $archive->archive_id.'--'.basename($dumpFile);

            // Schedule temp dir cleanup after response is sent
            $cleanDir = $tmpDir;
            app()->terminating(function () use ($cleanDir) {
                if (is_dir($cleanDir)) {
                    RunRestore::removeDirectory($cleanDir);
                }
            });

            return response()->download($dumpFile, $filename, [
                'Content-Type' => 'application/octet-stream',
            ]);

        } catch (\Throwable $e) {
            $this->cleanupDir($tmpDir);

            return response()->json([
                'detail' => 'Export failed: '.Str::limit($e->getMessage(), 500),
            ], 500);
        }
    }

    private function cleanupDir(string $dir): void
    {
        if (is_dir($dir)) {
            RunRestore::removeDirectory($dir);
        }
    }
}

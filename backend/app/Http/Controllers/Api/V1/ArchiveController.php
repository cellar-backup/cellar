<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RunRestore;
use App\Models\Archive;
use App\Models\Job;
use App\Services\Engines\BorgEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
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

    // ── Update (tags, notes, keep_forever) ─────────────────────

    public function update(Archive $archive, Request $request): JsonResponse
    {
        $data = $request->validate([
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'notes' => 'nullable|string|max:2000',
            'keep_forever' => 'nullable|boolean',
        ]);

        $archive->update($data);

        return response()->json(array_merge($archive->fresh()->toArray(), [
            'plan_name' => $archive->plan_name,
        ]));
    }

    // ── Keep Forever ───────────────────────────────────────────

    /**
     * PATCH /archives/{archive}/keep-forever
     *
     * Toggle the keep_forever flag on an archive.
     */
    public function keepForever(Archive $archive, Request $request): JsonResponse
    {
        $data = $request->validate([
            'keep_forever' => 'required|boolean',
        ]);

        $archive->update(['keep_forever' => $data['keep_forever']]);

        return response()->json(array_merge($archive->fresh()->toArray(), [
            'plan_name' => $archive->plan_name,
        ]));
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
        $job = Job::create([
            'plan_id' => $archive->plan_id,
            'job_type' => 'restore',
            'status' => 'pending',
            'progress' => 0,
        ]);

        RunRestore::dispatch($archive->id, $job->id);

        return response()->json([
            'detail' => 'Restore job queued.',
            'job_id' => $job->id,
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

        $job = Job::create([
            'plan_id' => $plan->id,
            'job_type' => 'export',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $engine = new BorgEngine(config('cellar.borg_path', '/usr/bin/borg'));
        $repoPath = rtrim($repo->config['path'] ?? '/data/repositories', '/').'/'.$plan->id;

        $tmpDir = sys_get_temp_dir().'/cellar_export_'.Str::random(8);
        mkdir($tmpDir, 0755, true);

        try {
            $result = $engine->restore($repoPath, $archive->archive_id, $tmpDir);

            if (! $result->success) {
                $this->cleanupDir($tmpDir);
                $job->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => 'Failed to extract archive: '.$result->message,
                ]);

                return response()->json([
                    'detail' => 'Failed to extract archive: '.$result->message,
                ], 500);
            }

            $dumpFile = RunRestore::findDumpFile($tmpDir);

            if (! $dumpFile) {
                $this->cleanupDir($tmpDir);
                $job->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => 'No downloadable file found in archive.',
                ]);

                return response()->json([
                    'detail' => 'No downloadable file found in archive.',
                ], 404);
            }

            // Convert to plain SQL if the dump is in custom format (PGDMP)
            $exportFile = $this->convertToPlainSql($dumpFile, $tmpDir);

            $filename = $archive->archive_id.'.sql';
            $fileSize = filesize($exportFile);

            $job->update([
                'status' => 'success',
                'finished_at' => now(),
                'metadata' => [
                    'archive_id' => $archive->archive_id,
                    'filename' => $filename,
                    'size_bytes' => $fileSize,
                ],
            ]);

            // Schedule temp dir cleanup after response is sent
            $cleanDir = $tmpDir;
            app()->terminating(function () use ($cleanDir) {
                if (is_dir($cleanDir)) {
                    RunRestore::removeDirectory($cleanDir);
                }
            });

            return response()->download($exportFile, $filename, [
                'Content-Type' => 'application/sql',
            ]);

        } catch (\Throwable $e) {
            $this->cleanupDir($tmpDir);
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => Str::limit($e->getMessage(), 2000),
            ]);

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

    /**
     * If the dump is in PostgreSQL custom format (PGDMP), convert it to
     * plain-text SQL using pg_restore -f. Otherwise return the file as-is.
     */
    private function convertToPlainSql(string $dumpPath, string $tmpDir): string
    {
        $fh = fopen($dumpPath, 'rb');
        $header = $fh ? fread($fh, 5) : '';
        if ($fh) {
            fclose($fh);
        }

        // Already plain SQL — nothing to convert
        if ($header !== 'PGDMP') {
            return $dumpPath;
        }

        $sqlPath = $tmpDir.'/export.sql';

        $result = Process::timeout(3600)->run([
            'pg_restore', '-f', $sqlPath, $dumpPath,
        ]);

        // pg_restore exit 0 = success, 1 = warnings (acceptable)
        if ($result->exitCode() >= 2) {
            throw new \RuntimeException(
                'Failed to convert dump to SQL: '.$result->errorOutput()
            );
        }

        return $sqlPath;
    }
}

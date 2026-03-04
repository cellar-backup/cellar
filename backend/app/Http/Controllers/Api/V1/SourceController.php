<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupPlan;
use App\Models\Repository;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class SourceController extends Controller
{
    public function index(): JsonResponse
    {
        $sources = Source::orderByDesc('created_at')->get()->map(function (Source $s) {
            $data = $s->toArray();
            $data['display_label'] = $s->display_label;
            $data['is_database'] = $s->getIsDatabase();

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
            'extra_config' => 'nullable|array',
        ]);

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
        if (! $source->getIsDatabase()) {
            // Filesystem validation for directory / docker_volume / sqlite
            $path = $source->path;

            if (empty($path)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No path configured for this source.',
                ], 422);
            }

            if (! file_exists($path)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Path not found: {$path}",
                ], 422);
            }

            $isDir = is_dir($path);
            $readable = is_readable($path);

            if (! $readable) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Path exists but is not readable: {$path}",
                ], 422);
            }

            return response()->json([
                'status' => 'ok',
                'message' => $isDir
                    ? 'Directory is accessible and readable.'
                    : 'File is accessible and readable.',
            ]);
        }

        $host = $source->host ?: 'localhost';
        $port = $source->port ?: $source->source_type->defaultPort() ?? 5432;

        $result = match ($source->source_type->value) {
            'postgresql' => Process::timeout(10)->run([
                'pg_isready', '-h', $host, '-p', (string) $port,
            ]),
            'mysql', 'mariadb' => Process::timeout(10)->run([
                'mysqladmin', 'ping',
                '-h', $host,
                '-P', (string) $port,
                '-u', $source->username ?: 'root',
                ...($source->password ? ['--password='.$source->password] : []),
            ]),
            default => null,
        };

        if ($result === null) {
            return response()->json([
                'status' => 'unsupported',
                'message' => "Connection test not implemented for {$source->source_type->value}.",
            ]);
        }

        $ok = $result->successful();

        return response()->json([
            'status' => $ok ? 'ok' : 'error',
            'message' => $ok
                ? 'Connection successful.'
                : 'Connection failed: '.$result->errorOutput(),
        ], $ok ? 200 : 422);
    }
}

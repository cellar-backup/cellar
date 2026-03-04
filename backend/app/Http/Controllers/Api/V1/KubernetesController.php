<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RadarIgnore;
use App\Models\Source;
use App\Services\KubernetesDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KubernetesController extends Controller
{
    /**
     * Test connectivity to a Kubernetes cluster.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kubeconfig_path' => 'nullable|string|max:1000',
            'context' => 'nullable|string|max:255',
        ]);

        $discovery = new KubernetesDiscovery(
            kubeconfig: $data['kubeconfig_path'] ?? null,
            context: $data['context'] ?? null,
        );

        $result = $discovery->testConnection();

        return response()->json($result);
    }

    /**
     * Discover databases and backup-eligible resources in the cluster.
     *
     * Filters out already-ignored resources and resources that already
     * have a matching Source record.
     */
    public function discover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kubeconfig_path' => 'nullable|string|max:1000',
            'context' => 'nullable|string|max:255',
            'namespace' => 'nullable|string|max:255',
        ]);

        $discovery = new KubernetesDiscovery(
            kubeconfig: $data['kubeconfig_path'] ?? null,
            context: $data['context'] ?? null,
        );

        try {
            $resources = $discovery->discover($data['namespace'] ?? null);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Discovery failed: '.$e->getMessage(),
            ], 500);
        }

        // Load ignore list
        $ignoreKeys = RadarIgnore::pluck('resource_key')->toArray();

        // Load existing sources to mark already-added resources
        $existingSources = Source::all();
        $existingHosts = $existingSources->pluck('host')->filter()->map(fn ($h) => strtolower($h))->toArray();

        // Filter & annotate
        $filtered = [];
        foreach ($resources as $r) {
            $key = "{$r['namespace']}:{$r['name']}:{$r['source_type']}";

            // Skip ignored
            if (in_array($key, $ignoreKeys)) {
                continue;
            }

            // Check if already added as a source
            $r['already_added'] = false;
            $host = strtolower($r['host'] ?? '');
            if ($host && in_array($host, $existingHosts)) {
                $r['already_added'] = true;
            }

            $r['resource_key'] = $key;
            $filtered[] = $r;
        }

        return response()->json([
            'status' => 'ok',
            'resources' => $filtered,
            'total' => count($filtered),
        ]);
    }

    /**
     * List all namespaces in the cluster.
     */
    public function namespaces(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kubeconfig_path' => 'nullable|string|max:1000',
            'context' => 'nullable|string|max:255',
        ]);

        $discovery = new KubernetesDiscovery(
            kubeconfig: $data['kubeconfig_path'] ?? null,
            context: $data['context'] ?? null,
        );

        try {
            $namespaces = $discovery->getNamespaces();
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list namespaces: '.$e->getMessage(),
            ], 500);
        }

        return response()->json($namespaces);
    }

    /**
     * Import selected discovered resources as Sources (+ BackupPlans via quick-add).
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resources' => 'required|array|min:1',
            'resources.*.source_type' => 'required|string|max:20',
            'resources.*.name' => 'required|string|max:255',
            'resources.*.namespace' => 'required|string|max:255',
            'resources.*.host' => 'nullable|string|max:500',
            'resources.*.port' => 'nullable|integer',
            'resources.*.kind' => 'nullable|string|max:50',
        ]);

        $created = [];

        foreach ($data['resources'] as $r) {
            $source = Source::create([
                'source_type' => $r['source_type'],
                'name' => "{$r['name']} ({$r['namespace']})",
                'host' => $r['host'] ?? null,
                'port' => $r['port'] ?? null,
                'notes' => "Discovered by Radar in namespace {$r['namespace']} ({$r['kind']} resource)",
                'enabled' => true,
            ]);

            $created[] = $source;
        }

        return response()->json([
            'status' => 'ok',
            'message' => count($created).' sources created from Kubernetes discovery.',
            'sources' => $created,
        ], 201);
    }

    /**
     * Ignore a discovered resource so it doesn't show up again.
     */
    public function ignore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resource_key' => 'required|string|max:500',
            'namespace' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'kind' => 'nullable|string|max:50',
            'source_type' => 'nullable|string|max:20',
            'reason' => 'nullable|string|max:500',
        ]);

        $ignore = RadarIgnore::updateOrCreate(
            ['resource_key' => $data['resource_key']],
            $data,
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Resource ignored.',
            'ignore' => $ignore,
        ]);
    }

    /**
     * List all ignored resources.
     */
    public function ignored(): JsonResponse
    {
        $ignores = RadarIgnore::orderByDesc('created_at')->get();

        return response()->json($ignores);
    }

    /**
     * Un-ignore a resource.
     */
    public function unignore(RadarIgnore $radarIgnore): JsonResponse
    {
        $radarIgnore->delete();

        return response()->json(null, 204);
    }
}

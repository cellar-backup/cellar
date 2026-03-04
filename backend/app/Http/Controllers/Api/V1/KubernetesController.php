<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RadarCluster;
use App\Models\RadarIgnore;
use App\Models\Source;
use App\Services\KubernetesDiscovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KubernetesController extends Controller
{
    // ── Cluster CRUD ───────────────────────────────────────────

    /**
     * List all saved clusters.
     */
    public function clusters(): JsonResponse
    {
        $clusters = RadarCluster::orderBy('name')
            ->get()
            ->map(fn (RadarCluster $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'context' => $c->context,
                'default_namespace' => $c->default_namespace,
                'has_kubeconfig' => (bool) $c->kubeconfig,
                'is_active' => $c->is_active,
                'last_scanned_at' => $c->last_scanned_at?->toISOString(),
                'created_at' => $c->created_at->toISOString(),
            ]);

        return response()->json($clusters);
    }

    /**
     * Create a new cluster configuration.
     * Accepts optional kubeconfig file upload.
     */
    public function storeCluster(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'kubeconfig' => 'nullable|file|max:512',  // max 512 KB
            'context' => 'nullable|string|max:255',
            'default_namespace' => 'nullable|string|max:255',
        ]);

        $kubeconfigContent = null;
        if ($request->hasFile('kubeconfig')) {
            $kubeconfigContent = $request->file('kubeconfig')->get();

            // Basic validation: must look like YAML with apiVersion
            if (! str_contains($kubeconfigContent, 'apiVersion')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid kubeconfig file — must be a valid Kubernetes configuration YAML.',
                ], 422);
            }
        }

        $cluster = RadarCluster::create([
            'name' => $data['name'],
            'kubeconfig' => $kubeconfigContent,
            'context' => $data['context'] ?? null,
            'default_namespace' => $data['default_namespace'] ?? null,
        ]);

        return response()->json([
            'id' => $cluster->id,
            'name' => $cluster->name,
            'context' => $cluster->context,
            'default_namespace' => $cluster->default_namespace,
            'has_kubeconfig' => (bool) $cluster->kubeconfig,
            'is_active' => $cluster->is_active,
            'last_scanned_at' => null,
            'created_at' => $cluster->created_at->toISOString(),
        ], 201);
    }

    /**
     * Update a cluster configuration.
     */
    public function updateCluster(Request $request, RadarCluster $cluster): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'kubeconfig' => 'nullable|file|max:512',
            'context' => 'nullable|string|max:255',
            'default_namespace' => 'nullable|string|max:255',
            'clear_kubeconfig' => 'nullable|boolean',
        ]);

        if (isset($data['name'])) {
            $cluster->name = $data['name'];
        }

        if ($request->hasFile('kubeconfig')) {
            $kubeconfigContent = $request->file('kubeconfig')->get();
            if (! str_contains($kubeconfigContent, 'apiVersion')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid kubeconfig file.',
                ], 422);
            }
            $cluster->kubeconfig = $kubeconfigContent;
        } elseif ($request->boolean('clear_kubeconfig')) {
            $cluster->kubeconfig = null;
        }

        if (array_key_exists('context', $data)) {
            $cluster->context = $data['context'];
        }
        if (array_key_exists('default_namespace', $data)) {
            $cluster->default_namespace = $data['default_namespace'];
        }

        $cluster->save();

        return response()->json([
            'id' => $cluster->id,
            'name' => $cluster->name,
            'context' => $cluster->context,
            'default_namespace' => $cluster->default_namespace,
            'has_kubeconfig' => (bool) $cluster->kubeconfig,
            'is_active' => $cluster->is_active,
            'last_scanned_at' => $cluster->last_scanned_at?->toISOString(),
            'created_at' => $cluster->created_at->toISOString(),
        ]);
    }

    /**
     * Delete a cluster and its ignore list.
     */
    public function destroyCluster(RadarCluster $cluster): JsonResponse
    {
        $cluster->ignores()->delete();
        $cluster->delete();

        return response()->json(null, 204);
    }

    // ── Discovery (cluster-scoped) ─────────────────────────────

    private function resolveDiscovery(RadarCluster $cluster): KubernetesDiscovery
    {
        return KubernetesDiscovery::fromCluster($cluster);
    }

    /**
     * Test connectivity to a specific cluster.
     */
    public function test(RadarCluster $cluster): JsonResponse
    {
        $discovery = $this->resolveDiscovery($cluster);
        $result = $discovery->testConnection();

        return response()->json($result);
    }

    /**
     * Discover databases and backup-eligible resources in a cluster.
     */
    public function discover(Request $request, RadarCluster $cluster): JsonResponse
    {
        $data = $request->validate([
            'namespace' => 'nullable|string|max:255',
        ]);

        $discovery = $this->resolveDiscovery($cluster);

        try {
            $namespace = $data['namespace'] ?? $cluster->default_namespace;
            $resources = $discovery->discover($namespace);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Discovery failed: '.$e->getMessage(),
            ], 500);
        }

        // Update last scanned timestamp
        $cluster->update(['last_scanned_at' => now()]);

        // Load ignore list for this cluster
        $ignoreKeys = RadarIgnore::where('cluster_id', $cluster->id)
            ->pluck('resource_key')
            ->toArray();

        // Load existing sources to mark already-added resources
        $existingSources = Source::all();
        $existingHosts = $existingSources->pluck('host')->filter()->map(fn ($h) => strtolower($h))->toArray();

        // Filter & annotate
        $filtered = [];
        foreach ($resources as $r) {
            $key = "{$r['namespace']}:{$r['name']}:{$r['source_type']}";

            if (in_array($key, $ignoreKeys)) {
                continue;
            }

            $r['already_added'] = false;
            $hostsToCheck = [strtolower($r['host'] ?? '')];
            foreach ($r['endpoints'] ?? [] as $ep) {
                $hostsToCheck[] = strtolower($ep['host'] ?? '');
            }
            foreach ($hostsToCheck as $h) {
                if ($h && in_array($h, $existingHosts)) {
                    $r['already_added'] = true;
                    break;
                }
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
     * List namespaces in a cluster.
     */
    public function namespaces(RadarCluster $cluster): JsonResponse
    {
        $discovery = $this->resolveDiscovery($cluster);

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
     * Import selected discovered resources as Sources.
     */
    public function import(Request $request, RadarCluster $cluster): JsonResponse
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
                'notes' => "Discovered by Radar from cluster \"{$cluster->name}\" in namespace {$r['namespace']} ({$r['kind']} resource)",
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
     * Ignore a discovered resource (scoped to cluster).
     */
    public function ignore(Request $request, RadarCluster $cluster): JsonResponse
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
            [
                'resource_key' => $data['resource_key'],
                'cluster_id' => $cluster->id,
            ],
            array_merge($data, ['cluster_id' => $cluster->id]),
        );

        return response()->json([
            'status' => 'ok',
            'message' => 'Resource ignored.',
            'ignore' => $ignore,
        ]);
    }

    /**
     * List ignored resources for a cluster.
     */
    public function ignored(RadarCluster $cluster): JsonResponse
    {
        $ignores = RadarIgnore::where('cluster_id', $cluster->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($ignores);
    }

    /**
     * Un-ignore a resource.
     */
    public function unignore(RadarCluster $cluster, RadarIgnore $radarIgnore): JsonResponse
    {
        $radarIgnore->delete();

        return response()->json(null, 204);
    }
}

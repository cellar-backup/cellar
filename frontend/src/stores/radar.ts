import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/lib/api";

export interface RadarCluster {
  id: string;
  name: string;
  context: string | null;
  default_namespace: string | null;
  has_kubeconfig: boolean;
  is_active: boolean;
  last_scanned_at: string | null;
  created_at: string;
}

export interface ResourceEndpoint {
  kind: string;
  resource_name: string;
  host: string | null;
  port: number | null;
  external_host: string | null;
  external_port: number | null;
  node_port: number | null;
  service_type: string | null;
  image: string | null;
}

export interface DiscoveredCredential {
  secret_name: string;
  key: string;
  value: string;
}

export interface DiscoveredResource {
  kind: string;
  namespace: string;
  name: string;
  source_type: string;
  image: string | null;
  host: string | null;
  port: number | null;
  external_host: string | null;
  external_port: number | null;
  node_port: number | null;
  service_type: string | null;
  endpoints: ResourceEndpoint[];
  credentials: DiscoveredCredential[];
  capacity?: string;
  labels: Record<string, string>;
  already_added: boolean;
  resource_key: string;
}

export interface ImportOverride {
  resource_key: string;
  host: string;
  port: number | null;
  username?: string;
  password?: string;
  database_name?: string;
  dump_method?: "direct" | "kubectl";
}

export interface RadarIgnoreEntry {
  id: string;
  resource_key: string;
  namespace: string;
  name: string;
  kind: string | null;
  source_type: string | null;
  reason: string | null;
  created_at: string;
}

export const useRadarStore = defineStore("radar", () => {
  // ── Cluster state ──
  const clusters = ref<RadarCluster[]>([]);
  const activeClusterId = ref<string | null>(null);
  const clustersLoading = ref(false);

  const activeCluster = computed(() =>
    clusters.value.find((c) => c.id === activeClusterId.value),
  );

  // ── Discovery state ──
  const resources = ref<DiscoveredResource[]>([]);
  const ignored = ref<RadarIgnoreEntry[]>([]);
  const loading = ref(false);
  const connected = ref<boolean | null>(null);
  const error = ref<string | null>(null);
  const namespace = ref("");

  // ── Cluster CRUD ──

  async function fetchClusters() {
    clustersLoading.value = true;
    try {
      const { data } = await api.get("/kubernetes/clusters");
      clusters.value = data;

      // Auto-select first cluster if none selected
      if (!activeClusterId.value && clusters.value.length > 0) {
        activeClusterId.value = clusters.value[0].id;
      }
    } finally {
      clustersLoading.value = false;
    }
  }

  async function createCluster(
    name: string,
    kubeconfigFile?: File,
    context?: string,
    defaultNamespace?: string,
  ): Promise<RadarCluster> {
    const form = new FormData();
    form.append("name", name);
    if (kubeconfigFile) form.append("kubeconfig", kubeconfigFile);
    if (context) form.append("context", context);
    if (defaultNamespace) form.append("default_namespace", defaultNamespace);

    const { data } = await api.post("/kubernetes/clusters", form, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    clusters.value.push(data);
    activeClusterId.value = data.id;
    return data;
  }

  async function updateCluster(
    id: string,
    name: string,
    kubeconfigFile?: File,
    context?: string,
    defaultNamespace?: string,
    clearKubeconfig?: boolean,
  ): Promise<RadarCluster> {
    const form = new FormData();
    form.append("name", name);
    form.append("_method", "PUT"); // Laravel method spoofing for FormData
    if (kubeconfigFile) form.append("kubeconfig", kubeconfigFile);
    if (context) form.append("context", context);
    if (defaultNamespace) form.append("default_namespace", defaultNamespace);
    if (clearKubeconfig) form.append("clear_kubeconfig", "1");

    const { data } = await api.post(`/kubernetes/clusters/${id}`, form, {
      headers: { "Content-Type": "multipart/form-data" },
    });

    const idx = clusters.value.findIndex((c) => c.id === id);
    if (idx !== -1) clusters.value[idx] = data;
    return data;
  }

  async function deleteCluster(id: string) {
    await api.delete(`/kubernetes/clusters/${id}`);
    clusters.value = clusters.value.filter((c) => c.id !== id);
    if (activeClusterId.value === id) {
      activeClusterId.value = clusters.value[0]?.id ?? null;
    }
    // Clear discovery state if active cluster was deleted
    resources.value = [];
    ignored.value = [];
    connected.value = null;
    error.value = null;
  }

  function selectCluster(id: string) {
    activeClusterId.value = id;
    // Reset discovery state when switching clusters
    resources.value = [];
    ignored.value = [];
    connected.value = null;
    error.value = null;
    namespace.value = "";
  }

  // ── Discovery (cluster-scoped) ──

  async function testConnection(): Promise<boolean> {
    if (!activeClusterId.value) {
      error.value = "No cluster selected.";
      return false;
    }

    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.post(
        `/kubernetes/clusters/${activeClusterId.value}/test`,
      );
      connected.value = data.connected ?? false;
      if (!data.connected) {
        error.value = data.error ?? "Could not connect to cluster.";
      }
      return data.connected;
    } catch (e: unknown) {
      connected.value = false;
      error.value =
        e instanceof Error ? e.message : "Failed to test connection.";
      return false;
    } finally {
      loading.value = false;
    }
  }

  async function discover() {
    if (!activeClusterId.value) return;

    loading.value = true;
    error.value = null;
    try {
      const payload: Record<string, string> = {};
      if (namespace.value) payload.namespace = namespace.value;

      const { data } = await api.post(
        `/kubernetes/clusters/${activeClusterId.value}/discover`,
        payload,
      );
      resources.value = data.resources ?? [];
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : "Discovery failed.";
    } finally {
      loading.value = false;
    }
  }

  async function importResources(
    selected: DiscoveredResource[],
    overrides?: ImportOverride[],
  ): Promise<{ message: string; count: number }> {
    if (!activeClusterId.value) throw new Error("No cluster selected.");

    // Build payload — resources and overrides are parallel arrays (1:1 by index)
    const payload = selected.map((r, i) => {
      const ov = overrides?.[i];
      return {
        source_type: r.source_type,
        name: r.name,
        namespace: r.namespace,
        host: ov?.host ?? r.host,
        port: ov?.port ?? r.port,
        kind: r.kind,
        username: ov?.username ?? undefined,
        password: ov?.password ?? undefined,
        database_name: ov?.database_name ?? undefined,
        dump_method: ov?.dump_method ?? undefined,
      };
    });

    const { data } = await api.post(
      `/kubernetes/clusters/${activeClusterId.value}/import`,
      { resources: payload },
    );

    await discover();

    return { message: data.message, count: data.sources?.length ?? 0 };
  }

  async function ignoreResource(resource: DiscoveredResource, reason?: string) {
    if (!activeClusterId.value) return;

    await api.post(`/kubernetes/clusters/${activeClusterId.value}/ignore`, {
      resource_key: resource.resource_key,
      namespace: resource.namespace,
      name: resource.name,
      kind: resource.kind,
      source_type: resource.source_type,
      reason: reason ?? null,
    });

    resources.value = resources.value.filter(
      (r) => r.resource_key !== resource.resource_key,
    );
  }

  async function fetchIgnored() {
    if (!activeClusterId.value) return;
    const { data } = await api.get(
      `/kubernetes/clusters/${activeClusterId.value}/ignored`,
    );
    ignored.value = Array.isArray(data) ? data : [];
  }

  async function unignore(id: string) {
    if (!activeClusterId.value) return;
    await api.delete(
      `/kubernetes/clusters/${activeClusterId.value}/ignored/${id}`,
    );
    ignored.value = ignored.value.filter((i) => i.id !== id);
  }

  /**
   * List databases on a discovered endpoint.
   * Uses kubectl exec fallback when pod info is provided.
   * Returns { databases: string[], error: string|null }
   */
  async function listDatabases(
    sourceType: string,
    host: string,
    port: number,
    username?: string,
    password?: string,
    podName?: string,
    podNamespace?: string,
  ): Promise<{ databases: string[]; error: string | null }> {
    if (!activeClusterId.value) {
      return { databases: [], error: "No cluster selected." };
    }
    try {
      const { data } = await api.post(
        `/kubernetes/clusters/${activeClusterId.value}/list-databases`,
        {
          source_type: sourceType,
          host,
          port,
          username: username || undefined,
          password: password || undefined,
          pod_name: podName || undefined,
          namespace: podNamespace || undefined,
        },
      );
      return data;
    } catch {
      return { databases: [], error: "Failed to connect to database." };
    }
  }

  return {
    // Cluster state
    clusters,
    activeClusterId,
    activeCluster,
    clustersLoading,
    // Discovery state
    resources,
    ignored,
    loading,
    connected,
    error,
    namespace,
    // Cluster actions
    fetchClusters,
    createCluster,
    updateCluster,
    deleteCluster,
    selectCluster,
    // Discovery actions
    testConnection,
    discover,
    importResources,
    ignoreResource,
    fetchIgnored,
    unignore,
    listDatabases,
  };
});

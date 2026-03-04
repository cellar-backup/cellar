import { defineStore } from "pinia";
import { ref } from "vue";
import api from "@/lib/api";

export interface DiscoveredResource {
  kind: string;
  namespace: string;
  name: string;
  source_type: string;
  image: string | null;
  host: string | null;
  port: number | null;
  capacity?: string;
  labels: Record<string, string>;
  already_added: boolean;
  resource_key: string;
}

export interface RadarIgnore {
  id: string;
  resource_key: string;
  namespace: string;
  name: string;
  kind: string | null;
  source_type: string | null;
  reason: string | null;
  created_at: string;
}

export interface RadarConfig {
  kubeconfigPath: string;
  context: string;
  namespace: string;
}

export const useRadarStore = defineStore("radar", () => {
  const resources = ref<DiscoveredResource[]>([]);
  const ignored = ref<RadarIgnore[]>([]);
  const loading = ref(false);
  const connected = ref<boolean | null>(null);
  const error = ref<string | null>(null);

  const config = ref<RadarConfig>({
    kubeconfigPath: "",
    context: "",
    namespace: "",
  });

  function configPayload() {
    const p: Record<string, string> = {};
    if (config.value.kubeconfigPath)
      p.kubeconfig_path = config.value.kubeconfigPath;
    if (config.value.context) p.context = config.value.context;
    if (config.value.namespace) p.namespace = config.value.namespace;
    return p;
  }

  async function testConnection(): Promise<boolean> {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.post("/kubernetes/test", configPayload());
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
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.post("/kubernetes/discover", configPayload());
      resources.value = data.resources ?? [];
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : "Discovery failed.";
    } finally {
      loading.value = false;
    }
  }

  async function importResources(
    selected: DiscoveredResource[],
  ): Promise<{ message: string; count: number }> {
    const payload = selected.map((r) => ({
      source_type: r.source_type,
      name: r.name,
      namespace: r.namespace,
      host: r.host,
      port: r.port,
      kind: r.kind,
    }));

    const { data } = await api.post("/kubernetes/import", {
      resources: payload,
    });

    // Re-run discovery to update already_added flags
    await discover();

    return { message: data.message, count: data.sources?.length ?? 0 };
  }

  async function ignoreResource(resource: DiscoveredResource, reason?: string) {
    await api.post("/kubernetes/ignore", {
      resource_key: resource.resource_key,
      namespace: resource.namespace,
      name: resource.name,
      kind: resource.kind,
      source_type: resource.source_type,
      reason: reason ?? null,
    });

    // Remove from discovered list
    resources.value = resources.value.filter(
      (r) => r.resource_key !== resource.resource_key,
    );
  }

  async function fetchIgnored() {
    const { data } = await api.get("/kubernetes/ignored");
    ignored.value = Array.isArray(data) ? data : [];
  }

  async function unignore(id: string) {
    await api.delete(`/kubernetes/ignored/${id}`);
    ignored.value = ignored.value.filter((i) => i.id !== id);
  }

  return {
    resources,
    ignored,
    loading,
    connected,
    error,
    config,
    testConnection,
    discover,
    importResources,
    ignoreResource,
    fetchIgnored,
    unignore,
  };
});

import { defineStore } from "pinia";
import { ref } from "vue";
import api from "@/lib/api";

export interface Source {
  id: string;
  name: string;
  source_type: string;
  host: string;
  port: number | null;
  username: string;
  database_name: string;
  path: string;
  enabled: boolean;
  display_label: string;
  is_database: boolean;
  created_at: string;
  updated_at: string;
}

export interface QuickAddPayload {
  source_type: string;
  host?: string;
  port?: number | null;
  username?: string;
  password?: string;
  database_name?: string;
  path?: string;
  name?: string;
  schedule?: string;
}

export interface QuickAddResult {
  source: Source;
  backup_plan: {
    id: string;
    name: string;
    schedule: string;
    retention: Record<string, number>;
    repository: string;
  };
  message: string;
}

export const useSourcesStore = defineStore("sources", () => {
  const sources = ref<Source[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function fetchSources() {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.get("/sources/");
      sources.value = data.data ?? data.results ?? data;
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : "Failed to fetch sources";
    } finally {
      loading.value = false;
    }
  }

  async function quickAdd(payload: QuickAddPayload): Promise<QuickAddResult> {
    const { data } = await api.post("/sources/quick-add/", payload);
    // Refresh the list
    await fetchSources();
    return data;
  }

  async function testConnection(sourceId: string) {
    const { data } = await api.post(`/sources/${sourceId}/test-connection/`);
    return data;
  }

  async function deleteSource(sourceId: string) {
    await api.delete(`/sources/${sourceId}/`);
    sources.value = sources.value.filter((s) => s.id !== sourceId);
  }

  return {
    sources,
    loading,
    error,
    fetchSources,
    quickAdd,
    testConnection,
    deleteSource,
  };
});

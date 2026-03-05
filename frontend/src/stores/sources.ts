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
  notes: string;
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
    schedule_cron: string;
    retention_policy: Record<string, number>;
    repository_id: string;
  };
  message: string;
}

export const useSourcesStore = defineStore("sources", () => {
  const sources = ref<Source[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  /**
   * Patch an existing reactive array IN-PLACE.
   * Same logic as plans store — avoids replacing the array reference.
   */
  function patchArray<T extends { id: string }>(
    target: T[],
    incoming: T[],
  ): void {
    const incomingMap = new Map(incoming.map((item) => [item.id, item]));

    for (let i = target.length - 1; i >= 0; i--) {
      const existing = target[i];
      const updated = incomingMap.get(existing.id);
      if (updated) {
        for (const key of Object.keys(updated) as (keyof T)[]) {
          if (JSON.stringify(existing[key]) !== JSON.stringify(updated[key])) {
            (existing as Record<string, unknown>)[key as string] = updated[key];
          }
        }
        incomingMap.delete(existing.id);
      } else {
        target.splice(i, 1);
      }
    }

    for (const newItem of incomingMap.values()) {
      target.push(newItem);
    }
  }

  async function fetchSources() {
    // Only show loading skeleton on first load
    if (sources.value.length === 0) {
      loading.value = true;
    }
    error.value = null;
    try {
      const { data } = await api.get("/sources");
      const incoming: Source[] = Array.isArray(data)
        ? data
        : (data.data ?? data);
      patchArray(sources.value, incoming);
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : "Failed to fetch sources";
    } finally {
      loading.value = false;
    }
  }

  async function quickAdd(payload: QuickAddPayload): Promise<QuickAddResult> {
    const { data } = await api.post("/sources/quick-add", payload);
    await fetchSources();
    return data;
  }

  async function getSource(sourceId: string): Promise<Source> {
    const { data } = await api.get(`/sources/${sourceId}`);
    return data;
  }

  async function updateSource(
    sourceId: string,
    payload: Record<string, unknown>,
  ): Promise<Source> {
    const { data } = await api.put(`/sources/${sourceId}`, payload);
    // Patch local item in-place
    const existing = sources.value.find((s) => s.id === sourceId);
    if (existing) {
      for (const key of Object.keys(data) as (keyof Source)[]) {
        if (
          JSON.stringify(existing[key]) !== JSON.stringify(data[key as string])
        ) {
          (existing as Record<string, unknown>)[key as string] =
            data[key as string];
        }
      }
    }
    return data;
  }

  async function testConnection(sourceId: string) {
    const { data } = await api.post(`/sources/${sourceId}/test-connection`);
    return data;
  }

  async function deleteSource(sourceId: string) {
    await api.delete(`/sources/${sourceId}`);
    const idx = sources.value.findIndex((s) => s.id === sourceId);
    if (idx !== -1) {
      sources.value.splice(idx, 1);
    }
  }

  return {
    sources,
    loading,
    error,
    fetchSources,
    quickAdd,
    getSource,
    updateSource,
    testConnection,
    deleteSource,
  };
});

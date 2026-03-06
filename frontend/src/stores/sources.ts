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
  policy_count: number;
  archive_count: number;
  last_archive_at: string | null;
  retention_policy: Record<string, number> | null;
  created_at: string;
  updated_at: string;
}

export interface Policy {
  id: string;
  name: string;
  repository_name: string;
  engine: string;
  status: string;
  schedule_cron: string;
  schedule_enabled: boolean;
  retention_policy: Record<string, number>;
  last_run: string | null;
  next_run: string | null;
  running_job: {
    id: string;
    job_type: string;
    progress: number;
    started_at: string | null;
  } | null;
}

export interface SourceArchive {
  id: string;
  plan_id: string;
  plan_name: string;
  archive_id: string;
  timestamp: string;
  size_original: number;
  size_dedup: number;
  size_compressed: number;
  file_count: number;
  keep_forever: boolean;
  tags: string[] | null;
  notes: string;
  created_at: string;
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

  async function toggleSource(sourceId: string): Promise<void> {
    const source = sources.value.find((s) => s.id === sourceId);
    if (source) source.enabled = !source.enabled; // optimistic
    try {
      await api.patch(`/sources/${sourceId}/toggle`);
    } catch {
      if (source) source.enabled = !source.enabled; // revert
    }
  }

  async function fetchPolicies(sourceId: string): Promise<Policy[]> {
    const { data } = await api.get(`/sources/${sourceId}/policies`);
    return Array.isArray(data) ? data : (data.data ?? data);
  }

  async function fetchSourceArchives(
    sourceId: string,
  ): Promise<SourceArchive[]> {
    const { data } = await api.get(`/sources/${sourceId}/archives`);
    return Array.isArray(data) ? data : (data.data ?? data);
  }

  async function togglePolicy(policyId: string): Promise<void> {
    await api.patch(`/plans/${policyId}/toggle`);
  }

  async function updatePolicy(
    policyId: string,
    payload: Record<string, unknown>,
  ): Promise<void> {
    await api.put(`/plans/${policyId}`, payload);
  }

  async function deletePolicy(policyId: string): Promise<void> {
    await api.delete(`/plans/${policyId}`);
    await fetchSources(); // refresh counts
  }

  async function triggerBackup(planId: string) {
    const { data } = await api.post(`/plans/${planId}/backup`);
    return data;
  }

  async function triggerPrune(planId: string) {
    const { data } = await api.post(`/plans/${planId}/prune`);
    return data;
  }

  async function triggerVerify(planId: string) {
    const { data } = await api.post(`/plans/${planId}/verify`);
    return data;
  }

  async function updateArchive(
    archiveId: string,
    payload: { tags?: string[] | null; notes?: string; keep_forever?: boolean },
  ) {
    const { data } = await api.patch(`/archives/${archiveId}`, payload);
    return data;
  }

  async function deleteArchive(archiveId: string): Promise<void> {
    await api.delete(`/archives/${archiveId}`);
  }

  async function restoreArchive(archiveId: string) {
    const { data } = await api.post(`/archives/${archiveId}/restore`);
    return data;
  }

  async function downloadArchive(archiveId: string) {
    const response = await api.get(`/archives/${archiveId}/download`, {
      responseType: "blob",
    });
    const disposition = response.headers["content-disposition"] ?? "";
    const match = disposition.match(/filename="?([^";\n]+)"?/);
    const filename = match?.[1] ?? `archive-${archiveId}.dump`;
    const url = URL.createObjectURL(response.data);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  async function toggleKeepForever(
    archiveId: string,
    keepForever: boolean,
  ): Promise<void> {
    await api.patch(`/archives/${archiveId}/keep-forever`, {
      keep_forever: keepForever,
    });
  }

  async function updateRetention(
    sourceId: string,
    retentionPolicy: Record<string, number>,
  ): Promise<void> {
    const { data } = await api.patch(`/sources/${sourceId}/retention`, {
      retention_policy: retentionPolicy,
    });
    const source = sources.value.find((s) => s.id === sourceId);
    if (source) {
      source.retention_policy = data.retention_policy;
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
    toggleSource,
    fetchPolicies,
    fetchSourceArchives,
    togglePolicy,
    updatePolicy,
    deletePolicy,
    triggerBackup,
    triggerPrune,
    triggerVerify,
    updateArchive,
    deleteArchive,
    restoreArchive,
    downloadArchive,
    toggleKeepForever,
    updateRetention,
  };
});

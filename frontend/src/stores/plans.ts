import { defineStore } from "pinia";
import { ref } from "vue";
import api from "@/lib/api";

export interface BackupPlan {
  id: string;
  name: string;
  source_name: string;
  source_type: string;
  repository_name: string;
  engine: string;
  status: string;
  schedule_cron: string;
  schedule_enabled: boolean;
  last_run: string | null;
  next_run: string | null;
  created_at: string;
}

export interface Job {
  id: string;
  plan: string;
  plan_name: string;
  job_type: string;
  status: string;
  started_at: string | null;
  finished_at: string | null;
  error_message: string;
  metadata: Record<string, unknown>;
  created_at: string;
}

export interface Archive {
  id: string;
  plan: string;
  plan_name: string;
  archive_id: string;
  timestamp: string;
  size_original: number;
  size_dedup: number;
  size_compressed: number;
  file_count: number;
  created_at: string;
}

export interface Repository {
  id: string;
  name: string;
  backend_type: string;
  status: string;
  plan_count: number;
}

export interface ImportResult {
  status: string;
  message: string;
  archive_count: number;
  plan: BackupPlan;
  repo_info: {
    total_size: number;
    unique_size: number;
    archive_count: number;
  };
}

export const usePlansStore = defineStore("plans", () => {
  const plans = ref<BackupPlan[]>([]);
  const jobs = ref<Job[]>([]);
  const archives = ref<Archive[]>([]);
  const repositories = ref<Repository[]>([]);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function fetchPlans() {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.get("/plans");
      plans.value = Array.isArray(data) ? data : (data.data ?? data);
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : "Failed to fetch plans";
    } finally {
      loading.value = false;
    }
  }

  async function fetchJobs() {
    try {
      const { data } = await api.get("/jobs");
      jobs.value = Array.isArray(data) ? data : (data.data ?? data);
    } catch {
      /* silent */
    }
  }

  async function fetchArchives() {
    try {
      const { data } = await api.get("/archives");
      archives.value = Array.isArray(data) ? data : (data.data ?? data);
    } catch {
      /* silent */
    }
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

  async function triggerRestore(archiveId: string) {
    const { data } = await api.post(`/archives/${archiveId}/restore`);
    return data;
  }

  async function fetchRepositories() {
    try {
      const { data } = await api.get("/repositories");
      repositories.value = Array.isArray(data) ? data : (data.data ?? data);
    } catch {
      /* silent */
    }
  }

  async function importRepository(
    repoId: string,
    path: string,
    name?: string,
  ): Promise<ImportResult> {
    const { data } = await api.post(`/repositories/${repoId}/import`, {
      path,
      name,
    });
    // Refresh plans & archives after import
    await Promise.all([fetchPlans(), fetchArchives()]);
    return data;
  }

  async function downloadArchive(archiveId: string) {
    const response = await api.get(`/archives/${archiveId}/download`, {
      responseType: "blob",
    });

    // Extract filename from Content-Disposition header or fallback
    const disposition = response.headers["content-disposition"] ?? "";
    const match = disposition.match(/filename="?([^";\n]+)"?/);
    const filename = match?.[1] ?? `archive-${archiveId}.dump`;

    // Create a temporary download link
    const url = URL.createObjectURL(response.data);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  return {
    plans,
    jobs,
    archives,
    repositories,
    loading,
    error,
    fetchPlans,
    fetchJobs,
    fetchArchives,
    fetchRepositories,
    importRepository,
    triggerBackup,
    triggerPrune,
    triggerVerify,
    triggerRestore,
    downloadArchive,
  };
});

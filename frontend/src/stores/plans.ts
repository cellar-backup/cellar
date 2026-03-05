import { defineStore } from "pinia";
import { ref, computed } from "vue";
import api from "@/lib/api";

export interface RunningJob {
  id: string;
  job_type: string;
  progress: number;
  started_at: string | null;
}

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
  running_job: RunningJob | null;
}

export interface Job {
  id: string;
  plan: string;
  plan_name: string;
  job_type: string;
  status: string;
  progress: number;
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
  keep_forever: boolean;
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

  // Polling
  let pollTimer: ReturnType<typeof setInterval> | null = null;
  const POLL_INTERVAL = 3000;

  const hasRunningJobs = computed(
    () =>
      plans.value.some((p: BackupPlan) => p.status === "running") ||
      jobs.value.some((j: Job) => j.status === "running"),
  );

  function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(async () => {
      if (hasRunningJobs.value) {
        await Promise.all([fetchPlans(), fetchJobs()]);
      } else {
        stopPolling();
      }
    }, POLL_INTERVAL);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function ensurePolling() {
    if (hasRunningJobs.value) {
      startPolling();
    }
  }

  async function fetchPlans() {
    loading.value = true;
    error.value = null;
    try {
      const { data } = await api.get("/plans");
      plans.value = Array.isArray(data) ? data : (data.data ?? data);
      ensurePolling();
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
      ensurePolling();
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
    // Re-fetch immediately so running state + progress appear
    setTimeout(() => fetchPlans(), 500);
    startPolling();
    return data;
  }

  async function triggerPrune(planId: string) {
    const { data } = await api.post(`/plans/${planId}/prune`);
    setTimeout(() => fetchPlans(), 500);
    startPolling();
    return data;
  }

  async function triggerVerify(planId: string) {
    const { data } = await api.post(`/plans/${planId}/verify`);
    setTimeout(() => fetchPlans(), 500);
    startPolling();
    return data;
  }

  async function triggerRestore(archiveId: string) {
    const { data } = await api.post(`/archives/${archiveId}/restore`);
    setTimeout(() => fetchPlans(), 500);
    startPolling();
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

  async function fetchJobLog(
    jobId: string,
  ): Promise<{ content: string | null; message?: string }> {
    const { data } = await api.get(`/jobs/${jobId}/log`);
    return data;
  }

  async function toggleKeepForever(
    archiveId: string,
    keepForever: boolean,
  ): Promise<void> {
    await api.patch(`/archives/${archiveId}/keep-forever`, {
      keep_forever: keepForever,
    });
    await fetchArchives();
  }

  return {
    plans,
    jobs,
    archives,
    repositories,
    loading,
    error,
    hasRunningJobs,
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
    fetchJobLog,
    toggleKeepForever,
    startPolling,
    stopPolling,
  };
});

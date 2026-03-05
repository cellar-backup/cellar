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

  const hasRunningJobs = computed(
    () =>
      plans.value.some((p: BackupPlan) => p.status === "running") ||
      jobs.value.some((j: Job) => j.status === "running"),
  );

  /**
   * Patch an existing reactive array IN-PLACE so Vue's reactivity system
   * only triggers updates for rows whose properties actually changed.
   *
   * - Existing items: shallow-patch changed fields
   * - New items: splice into the array
   * - Removed items: splice out of the array
   *
   * This avoids replacing the array reference (which would cause a full
   * re-render of every list item).
   */
  function patchArray<T extends { id: string }>(
    target: T[],
    incoming: T[],
  ): void {
    const incomingMap = new Map(incoming.map((item) => [item.id, item]));

    // Update or remove existing items (iterate backwards for safe splicing)
    for (let i = target.length - 1; i >= 0; i--) {
      const existing = target[i];
      const updated = incomingMap.get(existing.id);
      if (updated) {
        // Patch changed fields in-place on the reactive proxy
        for (const key of Object.keys(updated) as (keyof T)[]) {
          if (JSON.stringify(existing[key]) !== JSON.stringify(updated[key])) {
            (existing as Record<string, unknown>)[key as string] = updated[key];
          }
        }
        incomingMap.delete(existing.id);
      } else {
        // Item no longer in response — remove it
        target.splice(i, 1);
      }
    }

    // Append genuinely new items
    for (const newItem of incomingMap.values()) {
      target.push(newItem);
    }
  }

  async function fetchPlans() {
    // Only show loading skeleton on first load (empty list)
    if (plans.value.length === 0) {
      loading.value = true;
    }
    error.value = null;
    try {
      const { data } = await api.get("/plans");
      const incoming: BackupPlan[] = Array.isArray(data)
        ? data
        : (data.data ?? data);
      patchArray(plans.value, incoming);
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : "Failed to fetch plans";
    } finally {
      loading.value = false;
    }
  }

  async function fetchJobs() {
    try {
      const { data } = await api.get("/jobs");
      const incoming: Job[] = Array.isArray(data) ? data : (data.data ?? data);
      patchArray(jobs.value, incoming);
    } catch {
      /* silent */
    }
  }

  async function fetchArchives() {
    try {
      const { data } = await api.get("/archives");
      const incoming: Archive[] = Array.isArray(data)
        ? data
        : (data.data ?? data);
      patchArray(archives.value, incoming);
    } catch {
      /* silent */
    }
  }

  /**
   * Handle a real-time job event from the WebSocket channel.
   *
   * For running jobs: patch the plan's running_job progress in-place.
   * For terminal statuses: do one final API fetch to get the complete state.
   */
  function handleJobEvent(event: {
    jobId: string;
    planId: string;
    status: string;
    progress: number;
    jobType: string;
    startedAt: string | null;
    finishedAt: string | null;
    errorMessage: string | null;
  }) {
    const plan = plans.value.find((p) => p.id === event.planId);

    if (event.status === "running") {
      // Patch running_job progress in-place — no API call needed
      if (plan) {
        if (plan.running_job) {
          plan.running_job.progress = event.progress;
        } else {
          plan.running_job = {
            id: event.jobId,
            job_type: event.jobType,
            progress: event.progress,
            started_at: event.startedAt,
          };
          plan.status = "running";
        }
      }

      // Also patch the jobs list if loaded
      const job = jobs.value.find((j) => j.id === event.jobId);
      if (job) {
        job.progress = event.progress;
        job.status = event.status;
      }
    } else {
      // Terminal status (success, failed) — fetch full state from API
      fetchPlans();
      fetchJobs();
      fetchArchives();
    }
  }

  async function triggerBackup(planId: string) {
    const { data } = await api.post(`/plans/${planId}/backup`);
    // Optimistically show running state immediately
    const plan = plans.value.find((p) => p.id === planId);
    if (plan) {
      plan.status = "running";
      plan.running_job = {
        id: "_pending",
        job_type: "backup",
        progress: 0,
        started_at: new Date().toISOString(),
      };
    }
    return data;
  }

  async function triggerPrune(planId: string) {
    const { data } = await api.post(`/plans/${planId}/prune`);
    const plan = plans.value.find((p) => p.id === planId);
    if (plan) {
      plan.status = "running";
      plan.running_job = {
        id: "_pending",
        job_type: "prune",
        progress: 0,
        started_at: new Date().toISOString(),
      };
    }
    return data;
  }

  async function triggerVerify(planId: string) {
    const { data } = await api.post(`/plans/${planId}/verify`);
    const plan = plans.value.find((p) => p.id === planId);
    if (plan) {
      plan.status = "running";
      plan.running_job = {
        id: "_pending",
        job_type: "verify",
        progress: 0,
        started_at: new Date().toISOString(),
      };
    }
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
    handleJobEvent,
  };
});

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
  source_name: string;
  source_id: string;
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
  tags: string[];
  notes: string | null;
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

  /** Jobs sorted: running/pending first, then by created_at desc. */
  const sortedJobs = computed(() => {
    const active = ["running", "pending"];
    return [...jobs.value].sort((a, b) => {
      const aActive = active.includes(a.status) ? 0 : 1;
      const bActive = active.includes(b.status) ? 0 : 1;
      if (aActive !== bActive) return aActive - bActive;
      return (
        new Date(b.created_at).getTime() - new Date(a.created_at).getTime()
      );
    });
  });

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
    planName?: string;
    sourceName?: string;
    sourceId?: string;
    createdAt?: string;
  }) {
    const plan = plans.value.find((p) => p.id === event.planId);

    if (event.status === "running" || event.status === "pending") {
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

      // Also patch the jobs list if loaded, or insert a new row
      const job = jobs.value.find((j) => j.id === event.jobId);
      if (job) {
        job.progress = event.progress;
        job.status = event.status;
      } else {
        // New job not yet in the list — add it so JobsView shows it immediately
        jobs.value.unshift({
          id: event.jobId,
          plan: event.planId,
          plan_name: event.planName ?? "",
          source_name: event.sourceName ?? "",
          source_id: event.sourceId ?? "",
          job_type: event.jobType,
          status: event.status,
          progress: event.progress,
          started_at: event.startedAt,
          finished_at: event.finishedAt,
          error_message: event.errorMessage ?? "",
          metadata: {},
          created_at: event.createdAt ?? new Date().toISOString(),
        });
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
    // Use the real job_id from the API response
    const plan = plans.value.find((p) => p.id === planId);
    if (plan) {
      plan.status = "running";
      plan.running_job = {
        id: data.job_id ?? "_pending",
        job_type: "backup",
        progress: 0,
        started_at: new Date().toISOString(),
      };
    }
    // Add to jobs list immediately so it appears as "pending"
    if (data.job_id) {
      jobs.value.unshift({
        id: data.job_id,
        plan: planId,
        plan_name: plan?.name ?? "",
        source_name: plan?.source_name ?? "",
        source_id: "",
        job_type: "backup",
        status: "pending",
        progress: 0,
        started_at: null,
        finished_at: null,
        error_message: "",
        metadata: {},
        created_at: new Date().toISOString(),
      });
    }
    return data;
  }

  async function triggerPrune(planId: string) {
    const { data } = await api.post(`/plans/${planId}/prune`);
    const plan = plans.value.find((p) => p.id === planId);
    if (plan) {
      plan.status = "running";
      plan.running_job = {
        id: data.job_id ?? "_pending",
        job_type: "prune",
        progress: 0,
        started_at: new Date().toISOString(),
      };
    }
    if (data.job_id) {
      jobs.value.unshift({
        id: data.job_id,
        plan: planId,
        plan_name: plan?.name ?? "",
        source_name: plan?.source_name ?? "",
        source_id: "",
        job_type: "prune",
        status: "pending",
        progress: 0,
        started_at: null,
        finished_at: null,
        error_message: "",
        metadata: {},
        created_at: new Date().toISOString(),
      });
    }
    return data;
  }

  async function triggerVerify(planId: string) {
    const { data } = await api.post(`/plans/${planId}/verify`);
    const plan = plans.value.find((p) => p.id === planId);
    if (plan) {
      plan.status = "running";
      plan.running_job = {
        id: data.job_id ?? "_pending",
        job_type: "verify",
        progress: 0,
        started_at: new Date().toISOString(),
      };
    }
    if (data.job_id) {
      jobs.value.unshift({
        id: data.job_id,
        plan: planId,
        plan_name: plan?.name ?? "",
        source_name: plan?.source_name ?? "",
        source_id: "",
        job_type: "verify",
        status: "pending",
        progress: 0,
        started_at: null,
        finished_at: null,
        error_message: "",
        metadata: {},
        created_at: new Date().toISOString(),
      });
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
  ): Promise<{ content: string | null; status?: string; message?: string }> {
    const { data } = await api.get(`/jobs/${jobId}/log`);

    // Update job status from the log response — acts as a fallback
    // when WebSocket events are missed (e.g. job failed while modal open)
    if (data.status) {
      const job = jobs.value.find((j) => j.id === jobId);
      if (job && job.status !== data.status) {
        job.status = data.status;
        // For terminal statuses, refresh full state
        if (data.status === "success" || data.status === "failed" || data.status === "cancelled") {
          fetchPlans();
          fetchJobs();
          fetchArchives();
        }
      }
    }

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

  async function cancelJob(jobId: string): Promise<void> {
    await api.post(`/jobs/${jobId}/cancel`);
    // Optimistically clear running state
    const job = jobs.value.find((j) => j.id === jobId);
    if (job) {
      job.status = "cancelled";
    }
    const plan = plans.value.find((p) => p.running_job?.id === jobId);
    if (plan) {
      plan.running_job = null;
      plan.status = "idle";
    }
    await Promise.all([fetchPlans(), fetchJobs()]);
  }

  async function deleteArchive(archiveId: string): Promise<void> {
    await api.delete(`/archives/${archiveId}`);
    archives.value = archives.value.filter((a) => a.id !== archiveId);
  }

  async function updateArchiveTags(
    archiveId: string,
    tags: string[],
  ): Promise<void> {
    const { data } = await api.put(`/archives/${archiveId}`, { tags });
    const arc = archives.value.find((a) => a.id === archiveId);
    if (arc) {
      arc.tags = data.tags ?? tags;
    }
  }

  return {
    plans,
    jobs,
    archives,
    repositories,
    loading,
    error,
    hasRunningJobs,
    sortedJobs,
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
    cancelJob,
    deleteArchive,
    updateArchiveTags,
    handleJobEvent,
  };
});

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

export const usePlansStore = defineStore("plans", () => {
  const plans = ref<BackupPlan[]>([]);
  const jobs = ref<Job[]>([]);
  const archives = ref<Archive[]>([]);
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

  return {
    plans,
    jobs,
    archives,
    loading,
    error,
    fetchPlans,
    fetchJobs,
    fetchArchives,
    triggerBackup,
    triggerPrune,
    triggerVerify,
  };
});

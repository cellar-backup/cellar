<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { usePlansStore, type Job } from "@/stores/plans";
import { useSourcesStore } from "@/stores/sources";
import { useConfirm } from "@/composables/useConfirm";
import { useRoute, useRouter } from "vue-router";
import JobLogModal from "@/components/JobLogModal.vue";

const store = usePlansStore();
const sourcesStore = useSourcesStore();
const { confirm } = useConfirm();

const now = ref(Date.now());
let tickTimer: ReturnType<typeof setInterval> | null = null;

const logJobId = ref<string | null>(null);
const logJobLabel = ref("");

const route = useRoute();
const router = useRouter();

onMounted(async () => {
  await Promise.all([store.fetchJobs(), sourcesStore.fetchSources()]);
  tickTimer = setInterval(() => {
    now.value = Date.now();
  }, 1000);

  const logParam = route.query.log as string | undefined;
  if (logParam) {
    const job = store.jobs.find((j: Job) => j.id === logParam);
    if (job) openLog(job.id, resolveSourceName(job));
    router.replace({ path: route.path, query: {} });
  }
});

onUnmounted(() => {
  if (tickTimer) clearInterval(tickTimer);
});

function openLog(jobId: string, label: string) {
  logJobId.value = jobId;
  logJobLabel.value = label;
}

function closeLog() {
  logJobId.value = null;
  logJobLabel.value = "";
}

function typeLabel(type: string) {
  const map: Record<string, string> = { backup: "Backup", prune: "Prune", verify: "Verify", restore: "Restore", export: "Export" };
  return map[type] ?? type;
}

function statusLabel(status: string) {
  const map: Record<string, string> = { pending: "Queued", running: "Running", success: "Success", failed: "Failed", cancelled: "Cancelled" };
  return map[status] ?? status;
}

function statusDotClass(status: string) {
  if (status === "success") return "status-success";
  if (status === "failed") return "status-failed";
  if (status === "running") return "status-running";
  if (status === "cancelled") return "status-warn";
  return "status-muted";
}

function fmtDate(dateStr: string | null) {
  if (!dateStr) return "—";
  const d = new Date(dateStr);
  return d.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit", hour12: false }) +
    " · " + d.toLocaleDateString("en-US", { month: "short", day: "numeric" });
}

function duration(start: string | null, end: string | null) {
  if (!start) return "—";
  const endMs = end ? new Date(end).getTime() : now.value;
  const ms = endMs - new Date(start).getTime();
  const secs = Math.max(0, Math.round(ms / 1000));
  if (secs < 60) return `${secs}s`;
  const mins = Math.floor(secs / 60);
  const rem = secs % 60;
  return `${mins}m ${rem}s`;
}

async function confirmCancel(jobId: string, jobType: string, status: string) {
  const action = status === "pending" ? "Cancel the queued" : "Cancel the running";
  if (!(await confirm({
    title: "Cancel Job",
    message: `${action} ${typeLabel(jobType)} job? This action cannot be undone.`,
    confirmLabel: "Cancel Job",
    variant: "warning",
  }))) return;
  await store.cancelJob(jobId);
}

function resolveSourceName(job: Job): string {
  if (job.source_id) {
    const src = sourcesStore.sources.find((s) => s.id === job.source_id);
    if (src) return src.display_label;
  }
  return job.source_name || job.plan_name || "—";
}
</script>

<template>
  <div class="jobs-page">
    <!-- Header -->
    <header class="jobs-header">
      <div class="header-title-block">
        <div class="header-title">Jobs</div>
        <div class="header-breadcrumb">all operations</div>
      </div>
    </header>

    <!-- Content -->
    <div class="jobs-content">
      <!-- Loading -->
      <div v-if="store.loading" class="empty-msg">
        <div class="spinner" /> Loading jobs…
      </div>

      <!-- Empty -->
      <div v-else-if="store.jobs.length === 0" class="empty-msg">
        No jobs recorded yet. Trigger a backup to see job history here.
      </div>

      <!-- Table -->
      <div v-else class="jobs-table-wrap">
        <div class="jobs-table-header">
          <div>Status</div>
          <div>Type</div>
          <div>Database</div>
          <div>Started</div>
          <div>Duration</div>
          <div>Progress</div>
          <div></div>
        </div>
        <TransitionGroup name="job-row">
          <div
            v-for="(job, idx) in store.sortedJobs"
            :key="job.id"
            class="jobs-table-row"
            :style="{ '--idx': idx }"
          >
            <!-- Status -->
            <div class="job-status-cell">
              <span class="job-status-dot" :class="statusDotClass(job.status)" />
              <span class="job-status-label">{{ statusLabel(job.status) }}</span>
            </div>

            <!-- Type -->
            <div class="job-type">{{ typeLabel(job.job_type) }}</div>

            <!-- Source / Database -->
            <div class="job-source">{{ resolveSourceName(job) }}</div>

            <!-- Started -->
            <div class="job-mono">{{ fmtDate(job.started_at) }}</div>

            <!-- Duration -->
            <div class="job-mono">{{ duration(job.started_at, job.finished_at) }}</div>

            <!-- Progress -->
            <div class="job-progress-cell">
              <template v-if="job.status === 'running'">
                <div class="progress-bar">
                  <div class="progress-fill" :style="{ width: `${job.progress ?? 0}%` }" />
                </div>
                <span class="progress-text">{{ job.progress ?? 0 }}%</span>
              </template>
              <span v-else-if="job.status === 'success'" class="progress-done">100%</span>
              <span v-else class="job-mono">—</span>
            </div>

            <!-- Actions -->
            <div class="job-actions">
              <button
                class="tl-action-btn"
                title="View log"
                @click="openLog(job.id, `${typeLabel(job.job_type)} — ${resolveSourceName(job)}`)"
              >
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M3 2.5h10a1 1 0 011 1v9a1 1 0 01-1 1H3a1 1 0 01-1-1v-9a1 1 0 011-1z" />
                  <path d="M5 6h6M5 9h4" />
                </svg>
              </button>
              <button
                v-if="job.status === 'running' || job.status === 'pending'"
                class="tl-action-btn danger"
                title="Cancel job"
                @click="confirmCancel(job.id, job.job_type, job.status)"
              >
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                  <path d="M4 4l8 8M12 4l-8 8" />
                </svg>
              </button>
            </div>
          </div>
        </TransitionGroup>
      </div>
    </div>

    <JobLogModal :job-id="logJobId" :label="logJobLabel" @close="closeLog" />
  </div>
</template>

<style scoped>
.jobs-page {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.jobs-header {
  height: 60px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  padding: 0 32px;
  background: var(--color-background);
  flex-shrink: 0;
}
.header-title-block {
  display: flex;
  align-items: baseline;
  gap: 12px;
}
.header-title {
  font-family: var(--font-display);
  font-size: 22px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.header-breadcrumb {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
  letter-spacing: 0.04em;
}

.jobs-content {
  flex: 1;
  overflow-y: auto;
  padding: 24px 32px 80px;
}

.empty-msg {
  text-align: center;
  padding: 80px 20px;
  color: var(--color-text-faint);
  font-family: var(--font-mono);
  font-size: 13px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}
.spinner {
  width: 14px;
  height: 14px;
  border: 2px solid var(--color-wine-soft);
  border-top-color: var(--color-wine);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

/* Table */
.jobs-table-wrap {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
  background: var(--color-surface);
}

.jobs-table-header {
  display: grid;
  grid-template-columns: 110px 80px 1fr 160px 90px 120px 70px;
  gap: 16px;
  padding: 10px 20px;
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
  background: var(--color-surface-raised);
  border-bottom: 1px solid var(--color-border);
}

.jobs-table-row {
  display: grid;
  grid-template-columns: 110px 80px 1fr 160px 90px 120px 70px;
  gap: 16px;
  padding: 14px 20px;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  font-size: 13px;
  transition: background var(--duration-fast) var(--ease-out);
  animation: fade-up calc(0.3s * var(--motion-scale, 1) + 0.001s) var(--ease-out) both;
  animation-delay: calc(var(--idx, 0) * 20ms);
}
.jobs-table-row:last-child { border-bottom: none; }
.jobs-table-row:hover { background: var(--color-background); }

.job-status-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.job-status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.job-status-dot.status-success { background: var(--color-success); }
.job-status-dot.status-failed { background: var(--color-danger); }
.job-status-dot.status-running {
  background: var(--color-wine);
  animation: pulse-dot 2s var(--ease-in-out) infinite;
}
.job-status-dot.status-warn { background: var(--color-warning); }
.job-status-dot.status-muted { background: var(--color-text-faint); }

.job-status-label {
  font-size: 12.5px;
  color: var(--color-text-muted);
}

.job-type {
  font-weight: 500;
  color: var(--color-text-primary);
}

.job-source {
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.job-mono {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--color-text-muted);
  font-feature-settings: "tnum";
}

.job-progress-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.progress-bar {
  flex: 1;
  height: 4px;
  border-radius: 2px;
  background: var(--color-wine-soft);
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  border-radius: 2px;
  background: var(--color-wine);
  transition: width 0.5s var(--ease-out);
}
.progress-text {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-wine);
  min-width: 32px;
  text-align: right;
}
.progress-done {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-success);
}

.job-actions {
  display: flex;
  gap: 4px;
  justify-content: flex-end;
}
.tl-action-btn {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  display: grid;
  place-items: center;
  color: var(--color-text-muted);
  transition: all var(--duration-fast) var(--ease-out);
}
.tl-action-btn:hover {
  background: var(--color-wine-soft);
  color: var(--color-wine);
}
.tl-action-btn.danger:hover {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}

/* Transitions */
.job-row-enter-active,
.job-row-leave-active {
  transition: all 0.3s ease;
}
.job-row-enter-from { opacity: 0; }
.job-row-leave-to { opacity: 0; }
.job-row-move { transition: transform 0.3s ease; }
</style>

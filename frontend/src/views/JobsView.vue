<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { usePlansStore, type Job } from "@/stores/plans";
import { useSourcesStore } from "@/stores/sources";
import { useConfirm } from "@/composables/useConfirm";
import { useRoute, useRouter } from "vue-router";
import JobLogModal from "@/components/JobLogModal.vue";
import {
  Clock,
  CircleCheck,
  CircleX,
  Loader2,
  FileText,
  Ban,
} from "lucide-vue-next";

const store = usePlansStore();
const sourcesStore = useSourcesStore();
const { confirm } = useConfirm();

// Live elapsed time ticker
const now = ref(Date.now());
let tickTimer: ReturnType<typeof setInterval> | null = null;

// Log viewer state
const logJobId = ref<string | null>(null);
const logJobLabel = ref("");

const route = useRoute();
const router = useRouter();

onMounted(async () => {
  await Promise.all([store.fetchJobs(), sourcesStore.fetchSources()]);
  tickTimer = setInterval(() => {
    now.value = Date.now();
  }, 1000);

  // Deep-link: open log viewer if ?log=<jobId> is present
  const logParam = route.query.log as string | undefined;
  if (logParam) {
    const job = store.jobs.find((j: Job) => j.id === logParam);
    if (job) {
      openLog(job.id, resolveSourceName(job));
    }
    // Clean up the query param
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

function statusIcon(status: string) {
  switch (status) {
    case "success":
      return CircleCheck;
    case "failed":
      return CircleX;
    case "running":
      return Loader2;
    case "cancelled":
      return Ban;
    case "pending":
      return Clock;
    default:
      return Clock;
  }
}

function statusClass(status: string) {
  switch (status) {
    case "success":
      return "text-success";
    case "failed":
      return "text-danger";
    case "running":
      return "text-info";
    case "cancelled":
      return "text-warning";
    case "pending":
      return "text-text-muted";
    default:
      return "text-text-muted";
  }
}

function badgeClass(status: string) {
  switch (status) {
    case "success":
      return "bg-success/10 text-success";
    case "failed":
      return "bg-danger/10 text-danger";
    case "running":
      return "bg-info/10 text-info";
    case "cancelled":
      return "bg-warning/10 text-warning";
    case "pending":
      return "bg-surface-raised text-text-muted";
    default:
      return "bg-surface-raised text-text-muted";
  }
}

function typeLabel(type: string) {
  switch (type) {
    case "backup":
      return "Backup";
    case "prune":
      return "Prune";
    case "verify":
      return "Verify";
    case "restore":
      return "Restore";
    case "export":
      return "Export";
    default:
      return type;
  }
}

function statusLabel(status: string) {
  switch (status) {
    case "pending":
      return "Queued";
    case "running":
      return "Running";
    case "success":
      return "Success";
    case "failed":
      return "Failed";
    case "cancelled":
      return "Cancelled";
    default:
      return status;
  }
}

function fmtDate(dateStr: string | null) {
  if (!dateStr) return "—";
  return new Date(dateStr).toLocaleString();
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
  const action =
    status === "pending" ? "Cancel the queued" : "Cancel the running";
  if (
    !(await confirm({
      title: "Cancel Job",
      message:
        action +
        " " +
        typeLabel(jobType) +
        " job? This action cannot be undone.",
      confirmLabel: "Cancel Job",
      variant: "warning",
    }))
  )
    return;
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
  <div class="p-6">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-semibold text-text-primary">Jobs</h1>
      <p class="mt-1 text-text-muted">
        History of all backup, restore, export, prune, and verify operations.
      </p>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="mt-8 text-text-muted">Loading jobs…</div>

    <!-- Empty -->
    <div
      v-else-if="store.jobs.length === 0"
      class="mt-8 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <p class="text-text-muted">No jobs recorded yet.</p>
      <p class="mt-1 text-sm text-text-muted">
        Trigger a backup from the Plans page to see job history here.
      </p>
    </div>

    <!-- Table -->
    <div
      v-else
      class="mt-6 overflow-x-auto rounded-xl border border-border bg-surface"
    >
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs text-text-muted">
            <th class="px-5 py-3 font-medium">Status</th>
            <th class="px-5 py-3 font-medium">Type</th>
            <th class="px-5 py-3 font-medium">Source</th>
            <th class="px-5 py-3 font-medium">Started</th>
            <th class="px-5 py-3 font-medium">Duration</th>
            <th class="px-5 py-3 font-medium">Progress</th>
            <th class="px-5 py-3 font-medium">Message</th>
            <th class="px-5 py-3 font-medium w-16"></th>
          </tr>
        </thead>
        <TransitionGroup name="table-row" tag="tbody">
          <tr
            v-for="job in store.sortedJobs"
            :key="job.id"
            class="border-b border-border last:border-none transition-all duration-300"
          >
            <td class="px-5 py-3">
              <span
                class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="badgeClass(job.status)"
              >
                <component
                  :is="statusIcon(job.status)"
                  class="h-3.5 w-3.5"
                  :class="[
                    statusClass(job.status),
                    job.status === 'running' ? 'animate-spin' : '',
                  ]"
                />
                {{ statusLabel(job.status) }}
              </span>
            </td>
            <td class="px-5 py-3 text-text-primary">
              {{ typeLabel(job.job_type) }}
            </td>
            <td class="px-5 py-3 text-text-muted">
              {{ resolveSourceName(job) }}
            </td>
            <td class="px-5 py-3 text-text-muted">
              {{ fmtDate(job.started_at) }}
            </td>
            <td class="px-5 py-3 text-text-muted tabular-nums">
              {{ duration(job.started_at, job.finished_at) }}
            </td>
            <td class="px-5 py-3">
              <div
                v-if="job.status === 'running'"
                class="flex items-center gap-2 min-w-[120px]"
              >
                <div
                  class="h-1.5 flex-1 overflow-hidden rounded-full bg-info/10"
                >
                  <div
                    class="h-full rounded-full bg-info transition-all duration-500 ease-out"
                    :style="{ width: (job.progress ?? 0) + '%' }"
                  />
                </div>
                <span class="text-xs text-info tabular-nums whitespace-nowrap"
                  >{{ job.progress ?? 0 }}%</span
                >
              </div>
              <span
                v-else-if="job.status === 'success'"
                class="text-xs text-success"
                >100%</span
              >
              <span
                v-else-if="job.status === 'pending'"
                class="text-xs text-text-muted italic"
                >Queued</span
              >
              <span v-else class="text-xs text-text-muted">—</span>
            </td>
            <td class="px-5 py-3 max-w-xs truncate text-text-muted">
              {{ job.error_message || "—" }}
            </td>
            <td class="px-5 py-3">
              <div class="flex items-center gap-1">
                <button
                  class="rounded p-1 text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
                  title="View log"
                  @click="
                    openLog(
                      job.id,
                      `${typeLabel(job.job_type)} — ${resolveSourceName(job)}`,
                    )
                  "
                >
                  <FileText class="h-4 w-4" />
                </button>
                <button
                  v-if="job.status === 'running' || job.status === 'pending'"
                  class="rounded p-1 text-text-muted hover:text-danger hover:bg-danger/10 transition-colors"
                  title="Cancel job"
                  @click="confirmCancel(job.id, job.job_type, job.status)"
                >
                  <Ban class="h-4 w-4" />
                </button>
              </div>
            </td>
          </tr>
        </TransitionGroup>
      </table>
    </div>

    <!-- ======== Log Viewer Modal ======== -->
    <JobLogModal :job-id="logJobId" :label="logJobLabel" @close="closeLog" />
  </div>
</template>

<style scoped>
.table-row-enter-active,
.table-row-leave-active {
  transition: all 0.3s ease;
}
.table-row-enter-from {
  opacity: 0;
}
.table-row-leave-to {
  opacity: 0;
}
.table-row-move {
  transition: transform 0.3s ease;
}
</style>

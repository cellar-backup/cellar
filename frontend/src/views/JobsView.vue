<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { usePlansStore } from "@/stores/plans";
import {
  Clock,
  CircleCheck,
  CircleX,
  Loader2,
  FileText,
  X,
} from "lucide-vue-next";

const store = usePlansStore();

// Live elapsed time ticker
const now = ref(Date.now());
let tickTimer: ReturnType<typeof setInterval> | null = null;

// Log viewer state
const showLog = ref(false);
const logContent = ref<string | null>(null);
const logLoading = ref(false);
const logJobId = ref<string | null>(null);
const logJobLabel = ref("");

onMounted(() => {
  store.fetchJobs();
  tickTimer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});

onUnmounted(() => {
  store.stopPolling();
  if (tickTimer) clearInterval(tickTimer);
});

async function openLog(jobId: string, label: string) {
  logJobId.value = jobId;
  logJobLabel.value = label;
  logContent.value = null;
  logLoading.value = true;
  showLog.value = true;

  try {
    const result = await store.fetchJobLog(jobId);
    logContent.value = result.content;
  } catch {
    logContent.value = "Failed to load log.";
  } finally {
    logLoading.value = false;
  }
}

function closeLog() {
  showLog.value = false;
  logContent.value = null;
  logJobId.value = null;
}

function statusIcon(status: string) {
  switch (status) {
    case "success":
      return CircleCheck;
    case "failed":
      return CircleX;
    case "running":
      return Loader2;
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
            <th class="px-5 py-3 font-medium">Plan</th>
            <th class="px-5 py-3 font-medium">Started</th>
            <th class="px-5 py-3 font-medium">Duration</th>
            <th class="px-5 py-3 font-medium">Progress</th>
            <th class="px-5 py-3 font-medium">Message</th>
            <th class="px-5 py-3 font-medium w-16"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="job in store.jobs"
            :key="job.id"
            class="border-b border-border last:border-none"
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
                {{ job.status }}
              </span>
            </td>
            <td class="px-5 py-3 text-text-primary">
              {{ typeLabel(job.job_type) }}
            </td>
            <td class="px-5 py-3 text-text-muted">
              {{ job.plan_name ?? "—" }}
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
              <span v-else class="text-xs text-text-muted">—</span>
            </td>
            <td class="px-5 py-3 max-w-xs truncate text-text-muted">
              {{ job.error_message || "—" }}
            </td>
            <td class="px-5 py-3">
              <button
                class="rounded p-1 text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
                title="View log"
                @click="
                  openLog(
                    job.id,
                    `${typeLabel(job.job_type)} — ${job.plan_name ?? 'Unknown'}`,
                  )
                "
              >
                <FileText class="h-4 w-4" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ======== Log Viewer Modal ======== -->
    <Teleport to="body">
      <div
        v-if="showLog"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closeLog"
      >
        <div
          class="w-full max-w-3xl max-h-[80vh] flex flex-col rounded-2xl border border-border bg-surface shadow-xl"
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between border-b border-border px-6 py-4"
          >
            <div class="flex items-center gap-3">
              <FileText class="h-5 w-5 text-text-muted" />
              <div>
                <h2 class="text-sm font-semibold text-text-primary">Job Log</h2>
                <p class="text-xs text-text-muted">{{ logJobLabel }}</p>
              </div>
            </div>
            <button
              class="rounded-lg p-1 text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
              @click="closeLog"
            >
              <X class="h-5 w-5" />
            </button>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-auto p-6">
            <div
              v-if="logLoading"
              class="flex items-center gap-2 text-text-muted"
            >
              <Loader2 class="h-4 w-4 animate-spin" />
              Loading log…
            </div>
            <pre
              v-else-if="logContent"
              class="whitespace-pre-wrap break-words text-xs font-mono leading-relaxed text-text-primary bg-surface-raised rounded-lg p-4 max-h-[60vh] overflow-auto"
              >{{ logContent }}</pre
            >
            <p v-else class="text-sm text-text-muted">
              No log available for this job.
            </p>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

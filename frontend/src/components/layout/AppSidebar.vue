<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { usePlansStore, type Job } from "@/stores/plans";
import { useSourcesStore } from "@/stores/sources";
import { useConfirm } from "@/composables/useConfirm";
import JobLogModal from "@/components/JobLogModal.vue";
import echo from "@/lib/echo";
import {
  LayoutDashboard,
  Database,
  ScrollText,
  Radar,
  Settings,
  ChevronLeft,
  LogOut,
  Wine,
  CircleCheck,
  CircleX,
  Loader2,
  Clock,
  FileText,
  Ban,
  HardDriveDownload,
  Scissors,
  ShieldCheck,
  RotateCcw,
} from "lucide-vue-next";

const collapsed = ref(false);
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const plansStore = usePlansStore();
const sourcesStore = useSourcesStore();
const { confirm } = useConfirm();

const navItems = [
  { label: "Dashboard", icon: LayoutDashboard, to: "/" },
  { label: "Sources", icon: Database, to: "/sources" },
  { label: "Jobs", icon: ScrollText, to: "/jobs" },
  { label: "Radar", icon: Radar, to: "/radar" },
  { label: "Settings", icon: Settings, to: "/settings" },
];

function isActive(to: string) {
  if (to === "/") return route.path === "/";
  return route.path.startsWith(to);
}

function handleLogout() {
  auth.logout();
  router.push("/login");
}

// ── Mini jobs list ──────────────────────────────────────────

const now = ref(Date.now());
let tickTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
  plansStore.fetchJobs();
  sourcesStore.fetchSources();

  // Subscribe to WebSocket for real-time job updates
  echo
    .channel("jobs")
    .listen(
      ".job.updated",
      (event: {
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
      }) => {
        plansStore.handleJobEvent(event);
      },
    );

  tickTimer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});

onUnmounted(() => {
  echo.leaveChannel("jobs");
  if (tickTimer) clearInterval(tickTimer);
});

const recentJobs = computed(() =>
  plansStore.sortedJobs.filter((j) => j.status !== "pending").slice(0, 5),
);

function jobStatusIcon(status: string) {
  if (status === "running") return Loader2;
  if (status === "success") return CircleCheck;
  if (status === "failed") return CircleX;
  return Clock;
}

function jobStatusColor(status: string) {
  if (status === "running") return "text-info";
  if (status === "success") return "text-success";
  if (status === "failed") return "text-danger";
  return "text-text-muted";
}

function jobTypeLabel(type: string) {
  const map: Record<string, string> = {
    backup: "Backup",
    prune: "Prune",
    verify: "Verify",
    restore: "Restore",
  };
  return map[type] ?? type;
}

function jobTypeIcon(type: string) {
  if (type === "backup") return HardDriveDownload;
  if (type === "prune") return Scissors;
  if (type === "verify") return ShieldCheck;
  if (type === "restore") return RotateCcw;
  return HardDriveDownload;
}

function jobTypeColor(type: string) {
  if (type === "backup") return "text-info";
  if (type === "prune") return "text-warning";
  if (type === "verify") return "text-accent";
  if (type === "restore") return "text-success";
  return "text-text-muted";
}

function timeAgo(iso: string | null) {
  if (!iso) return "";
  const diff = now.value - new Date(iso).getTime();
  if (diff < 0) return "just now";
  const s = Math.floor(diff / 1000);
  if (s < 60) return `${s}s ago`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.floor(h / 24);
  return `${d}d ago`;
}

function elapsed(job: Job) {
  if (!job.started_at) return "";
  const start = new Date(job.started_at).getTime();
  const end = job.finished_at ? new Date(job.finished_at).getTime() : now.value;
  const s = Math.max(0, Math.floor((end - start) / 1000));
  if (s < 60) return `${s}s`;
  const m = Math.floor(s / 60);
  const rs = s % 60;
  return `${m}m ${rs}s`;
}

function resolveSourceName(job: Job): string {
  if (job.source_id) {
    const src = sourcesStore.sources.find((s) => s.id === job.source_id);
    if (src) return src.display_label;
  }
  return job.source_name || job.plan_name || "Job";
}

const logJobId = ref<string | null>(null);
const logJobLabel = ref("");

function openJobLog(job: Job) {
  logJobId.value = job.id;
  logJobLabel.value = resolveSourceName(job);
}

function closeJobLog() {
  logJobId.value = null;
  logJobLabel.value = "";
}

async function cancelJob(job: Job) {
  const typeMap: Record<string, string> = {
    backup: "Backup",
    prune: "Prune",
    verify: "Verify",
    restore: "Restore",
  };
  const label = typeMap[job.job_type] ?? job.job_type;
  if (
    !(await confirm({
      title: "Cancel Job",
      message: `Cancel the ${job.status === "pending" ? "queued" : "running"} ${label} job? This action cannot be undone.`,
      confirmLabel: "Cancel Job",
      variant: "warning",
    }))
  )
    return;
  await plansStore.cancelJob(job.id);
}
</script>

<template>
  <aside
    class="flex h-full flex-col border-r border-border bg-surface transition-all duration-200"
    :class="collapsed ? 'w-16' : 'w-60'"
  >
    <!-- Logo -->
    <div class="flex h-14 items-center gap-3 px-4">
      <div
        class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-white"
      >
        <Wine class="h-4 w-4" />
      </div>
      <span v-if="!collapsed" class="text-lg font-semibold text-text-primary">
        Cellar
      </span>
    </div>

    <!-- Nav -->
    <nav class="mt-2 flex-1 space-y-1 px-2">
      <RouterLink
        v-for="item in navItems"
        :key="item.to"
        :to="item.to"
        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors"
        :class="
          isActive(item.to)
            ? 'bg-primary/10 text-primary'
            : 'text-text-muted hover:bg-surface-raised hover:text-text-primary'
        "
      >
        <component :is="item.icon" class="h-5 w-5 shrink-0" />
        <span v-if="!collapsed">{{ item.label }}</span>
      </RouterLink>
    </nav>

    <!-- Mini Jobs List (expanded only) -->
    <div
      v-if="!collapsed && recentJobs.length > 0"
      class="border-t border-border px-2 py-2"
    >
      <div class="flex items-center justify-between px-2 mb-1.5">
        <span
          class="text-[10px] font-semibold uppercase tracking-wider text-text-muted"
          >Recent Jobs</span
        >
        <RouterLink
          to="/jobs"
          class="text-[10px] text-primary hover:text-primary/80 transition-colors"
          >View all</RouterLink
        >
      </div>
      <div class="space-y-0.5">
        <div
          v-for="job in recentJobs"
          :key="job.id"
          class="group flex items-center gap-1.5 rounded-md px-2 py-1.5 transition-colors hover:bg-surface-raised cursor-default"
        >
          <!-- Status icon -->
          <component
            :is="jobStatusIcon(job.status)"
            class="h-3.5 w-3.5 shrink-0"
            :class="[
              jobStatusColor(job.status),
              job.status === 'running' ? 'animate-spin' : '',
            ]"
          />
          <!-- Job type icon -->
          <component
            :is="jobTypeIcon(job.job_type)"
            class="h-3 w-3 shrink-0"
            :class="jobTypeColor(job.job_type)"
            :title="jobTypeLabel(job.job_type)"
          />
          <!-- Info -->
          <div class="min-w-0 flex-1">
            <span
              class="block truncate text-[11px] font-medium text-text-primary leading-tight"
            >
              {{ resolveSourceName(job) }}
            </span>
            <!-- Progress bar for running jobs -->
            <div
              v-if="job.status === 'running'"
              class="mt-0.5 flex items-center gap-1.5"
            >
              <div
                class="h-1 flex-1 rounded-full bg-surface-raised overflow-hidden"
              >
                <div
                  class="h-full rounded-full bg-info transition-all duration-300"
                  :style="{ width: `${job.progress}%` }"
                />
              </div>
              <span class="text-[9px] tabular-nums text-info font-medium"
                >{{ job.progress }}%</span
              >
            </div>
            <!-- Time for completed/failed -->
            <div v-else class="mt-0.5 flex items-center gap-1.5">
              <span class="text-[10px] text-text-muted">
                {{ timeAgo(job.finished_at || job.started_at) }}
              </span>
              <span
                v-if="job.started_at"
                class="text-[10px] text-text-muted opacity-60"
              >
                {{ elapsed(job) }}
              </span>
            </div>
          </div>
          <!-- Action buttons (always visible) -->
          <div class="shrink-0 flex items-center gap-0.5">
            <button
              class="rounded p-1 text-text-muted hover:text-text-primary hover:bg-surface transition-colors"
              title="View log"
              @click.stop="openJobLog(job)"
            >
              <FileText class="h-3.5 w-3.5" />
            </button>
            <button
              v-if="job.status === 'running' || job.status === 'pending'"
              class="rounded p-1 text-text-muted hover:text-danger hover:bg-danger/10 transition-colors"
              title="Cancel job"
              @click.stop="cancelJob(job)"
            >
              <Ban class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- User / Logout -->
    <div class="border-t border-border px-2 py-2">
      <button
        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
        @click="handleLogout"
      >
        <LogOut class="h-5 w-5 shrink-0" />
        <span v-if="!collapsed">Sign out</span>
      </button>
    </div>

    <!-- Collapse toggle -->
    <button
      class="flex h-10 items-center justify-center border-t border-border text-text-muted hover:text-text-primary transition-colors"
      @click="collapsed = !collapsed"
    >
      <ChevronLeft
        class="h-4 w-4 transition-transform"
        :class="{ 'rotate-180': collapsed }"
      />
    </button>
  </aside>

  <!-- Log viewer modal (in-place, no navigation) -->
  <JobLogModal :job-id="logJobId" :label="logJobLabel" @close="closeJobLog" />
</template>

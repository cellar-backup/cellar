<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { usePlansStore, type Job } from "@/stores/plans";
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
} from "lucide-vue-next";

const collapsed = ref(false);
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const plansStore = usePlansStore();

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

const recentJobs = computed(() => plansStore.jobs.slice(0, 5));

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
        <span class="text-[10px] font-semibold uppercase tracking-wider text-text-muted">Recent Jobs</span>
        <RouterLink
          to="/jobs"
          class="text-[10px] text-primary hover:text-primary/80 transition-colors"
        >View all</RouterLink>
      </div>
      <div class="space-y-0.5">
        <RouterLink
          v-for="job in recentJobs"
          :key="job.id"
          to="/jobs"
          class="group flex items-center gap-2 rounded-md px-2 py-1.5 transition-colors hover:bg-surface-raised"
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
          <!-- Info -->
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1">
              <span class="truncate text-[11px] font-medium text-text-primary leading-tight">
                {{ job.plan_name || 'Job' }}
              </span>
              <span class="shrink-0 text-[10px] text-text-muted">{{ jobTypeLabel(job.job_type) }}</span>
            </div>
            <!-- Progress bar for running jobs -->
            <div v-if="job.status === 'running'" class="mt-0.5 flex items-center gap-1.5">
              <div class="h-1 flex-1 rounded-full bg-surface-raised overflow-hidden">
                <div
                  class="h-full rounded-full bg-info transition-all duration-300"
                  :style="{ width: `${job.progress}%` }"
                />
              </div>
              <span class="text-[9px] tabular-nums text-info font-medium">{{ job.progress }}%</span>
            </div>
            <!-- Time for completed/failed -->
            <div v-else class="mt-0.5 flex items-center gap-1.5">
              <span class="text-[10px] text-text-muted">
                {{ timeAgo(job.finished_at || job.started_at) }}
              </span>
              <span v-if="job.started_at" class="text-[10px] text-text-muted opacity-60">
                {{ elapsed(job) }}
              </span>
            </div>
          </div>
        </RouterLink>
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
</template>

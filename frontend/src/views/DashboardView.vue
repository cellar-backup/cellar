<script setup lang="ts">
import { onMounted, computed } from "vue";
import { useRouter } from "vue-router";
import { useSourcesStore } from "@/stores/sources";
import { usePlansStore } from "@/stores/plans";
import { formatBytes } from "@/lib/utils";
import {
  Database,
  ClipboardList,
  Archive,
  HardDrive,
  CircleCheck,
  CircleX,
  Clock,
  Play,
} from "lucide-vue-next";

const router = useRouter();
const sourcesStore = useSourcesStore();
const plansStore = usePlansStore();

onMounted(() => {
  sourcesStore.fetchSources();
  plansStore.fetchPlans();
  plansStore.fetchJobs();
  plansStore.fetchArchives();
});

const totalSources = computed(() => sourcesStore.sources.length);
const totalPlans = computed(() => plansStore.plans.length);
const totalArchives = computed(() => plansStore.archives.length);
const totalStorageUsed = computed(() =>
  plansStore.archives.reduce((sum, a) => sum + a.size_dedup, 0),
);
const recentJobs = computed(() => plansStore.jobs.slice(0, 8));

const stats = computed(() => [
  { label: "Sources", value: totalSources.value, icon: Database },
  { label: "Backup Plans", value: totalPlans.value, icon: ClipboardList },
  { label: "Archives", value: totalArchives.value, icon: Archive },
  {
    label: "Storage Used",
    value: formatBytes(totalStorageUsed.value),
    icon: HardDrive,
  },
]);

function statusIcon(status: string) {
  switch (status) {
    case "success":
      return CircleCheck;
    case "failed":
      return CircleX;
    case "running":
      return Play;
    default:
      return Clock;
  }
}

function statusColor(status: string) {
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

function timeAgo(dateStr: string | null) {
  if (!dateStr) return "—";
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return "just now";
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  return `${days}d ago`;
}
</script>

<template>
  <div class="p-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-text-primary">Dashboard</h1>
        <p class="mt-1 text-text-muted">
          Overview of your backup infrastructure.
        </p>
      </div>
      <button
        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
        @click="router.push('/sources')"
      >
        + Add Source
      </button>
    </div>

    <!-- Stat cards -->
    <TransitionGroup
      name="list-item"
      tag="div"
      class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4"
    >
      <div
        v-for="stat in stats"
        :key="stat.label"
        class="rounded-xl border border-border bg-surface p-5 transition-all duration-300"
      >
        <div class="flex items-center gap-3">
          <div
            class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10"
          >
            <component :is="stat.icon" class="h-4 w-4 text-primary" />
          </div>
          <div>
            <p class="text-sm text-text-muted">{{ stat.label }}</p>
            <p class="text-xl font-semibold text-text-primary">
              {{ stat.value }}
            </p>
          </div>
        </div>
      </div>
    </TransitionGroup>

    <!-- Empty state -->
    <div
      v-if="totalSources === 0 && !sourcesStore.loading"
      class="mt-8 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <Database class="mx-auto h-12 w-12 text-text-muted" />
      <p class="mt-3 text-text-primary font-medium">Welcome to Cellar!</p>
      <p class="mt-1 text-sm text-text-muted">
        Add your first database source to start backing up in seconds.
      </p>
      <button
        class="mt-4 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
        @click="router.push('/sources')"
      >
        + Add Source
      </button>
    </div>

    <!-- Recent Jobs -->
    <div v-if="recentJobs.length > 0" class="mt-8">
      <h2 class="text-lg font-medium text-text-primary">Recent Jobs</h2>
      <div
        class="mt-3 rounded-xl border border-border bg-surface overflow-hidden"
      >
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-border text-text-muted">
              <th class="px-4 py-3 text-left font-medium">Status</th>
              <th class="px-4 py-3 text-left font-medium">Plan</th>
              <th class="px-4 py-3 text-left font-medium">Type</th>
              <th class="px-4 py-3 text-left font-medium">When</th>
            </tr>
          </thead>
          <TransitionGroup name="table-row" tag="tbody">
            <tr
              v-for="job in recentJobs"
              :key="job.id"
              class="border-b border-border last:border-0 transition-all duration-300"
            >
              <td class="px-4 py-3">
                <component
                  :is="statusIcon(job.status)"
                  class="h-4 w-4"
                  :class="statusColor(job.status)"
                />
              </td>
              <td class="px-4 py-3 text-text-primary">
                {{ job.plan_name }}
              </td>
              <td class="px-4 py-3 text-text-muted capitalize">
                {{ job.job_type }}
              </td>
              <td class="px-4 py-3 text-text-muted">
                {{ timeAgo(job.started_at) }}
              </td>
            </tr>
          </TransitionGroup>
        </table>
      </div>
    </div>

    <!-- Backup Plans summary -->
    <div v-if="plansStore.plans.length > 0" class="mt-8">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-text-primary">Backup Plans</h2>
        <button
          class="text-sm text-primary hover:underline"
          @click="router.push('/plans')"
        >
          View all &rarr;
        </button>
      </div>
      <TransitionGroup
        name="list-item"
        tag="div"
        class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
      >
        <div
          v-for="plan in plansStore.plans.slice(0, 6)"
          :key="plan.id"
          class="rounded-xl border border-border bg-surface p-4 transition-all duration-300"
        >
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-medium text-text-primary truncate">
              {{ plan.name }}
            </h3>
            <span
              class="rounded-full px-2 py-0.5 text-xs font-medium"
              :class="{
                'bg-success/10 text-success': plan.status === 'healthy',
                'bg-danger/10 text-danger': plan.status === 'failed',
                'bg-info/10 text-info': plan.status === 'running',
                'bg-surface-raised text-text-muted':
                  plan.status === 'idle' || plan.status === 'warning',
              }"
            >
              {{ plan.status }}
            </span>
          </div>
          <p class="mt-1 text-xs text-text-muted">
            {{ plan.source_type }} · {{ plan.schedule_cron }}
          </p>
          <p class="mt-0.5 text-xs text-text-muted">
            Last run: {{ plan.last_run ? timeAgo(plan.last_run) : "never" }}
          </p>
        </div>
      </TransitionGroup>
    </div>
  </div>
</template>

<style scoped>
.list-item-enter-active,
.list-item-leave-active {
  transition: all 0.3s ease;
}
.list-item-enter-from {
  opacity: 0;
  transform: translateY(-10px);
}
.list-item-leave-to {
  opacity: 0;
  transform: translateY(10px);
}
.list-item-move {
  transition: transform 0.3s ease;
}

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

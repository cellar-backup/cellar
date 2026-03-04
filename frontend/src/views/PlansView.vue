<script setup lang="ts">
import { onMounted, ref } from "vue";
import { usePlansStore } from "@/stores/plans";
import {
  Play,
  Scissors,
  ShieldCheck,
  CircleCheck,
  CircleX,
  Clock,
  Loader2,
} from "lucide-vue-next";

const store = usePlansStore();

onMounted(() => {
  store.fetchPlans();
});

// ---- Action state ----
const actionLoading = ref<string | null>(null);
const actionMessage = ref<{ planId: string; text: string; ok: boolean } | null>(
  null,
);

async function runBackup(planId: string) {
  actionLoading.value = planId;
  actionMessage.value = null;
  try {
    const data = await store.triggerBackup(planId);
    actionMessage.value = { planId, text: data.detail, ok: true };
  } catch {
    actionMessage.value = {
      planId,
      text: "Failed to trigger backup.",
      ok: false,
    };
  } finally {
    actionLoading.value = null;
  }
}

async function runPrune(planId: string) {
  actionLoading.value = planId;
  actionMessage.value = null;
  try {
    const data = await store.triggerPrune(planId);
    actionMessage.value = { planId, text: data.detail, ok: true };
  } catch {
    actionMessage.value = {
      planId,
      text: "Failed to trigger prune.",
      ok: false,
    };
  } finally {
    actionLoading.value = null;
  }
}

async function runVerify(planId: string) {
  actionLoading.value = planId;
  actionMessage.value = null;
  try {
    const data = await store.triggerVerify(planId);
    actionMessage.value = { planId, text: data.detail, ok: true };
  } catch {
    actionMessage.value = {
      planId,
      text: "Failed to trigger verify.",
      ok: false,
    };
  } finally {
    actionLoading.value = null;
  }
}

function statusBadgeClass(status: string) {
  switch (status) {
    case "healthy":
      return "bg-success/10 text-success";
    case "failed":
      return "bg-danger/10 text-danger";
    case "running":
      return "bg-info/10 text-info";
    case "warning":
      return "bg-warning/10 text-warning";
    default:
      return "bg-surface-raised text-text-muted";
  }
}

function statusIcon(status: string) {
  switch (status) {
    case "healthy":
      return CircleCheck;
    case "failed":
      return CircleX;
    case "running":
      return Loader2;
    default:
      return Clock;
  }
}

function timeAgo(dateStr: string | null) {
  if (!dateStr) return "never";
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
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-text-primary">Backup Plans</h1>
        <p class="mt-1 text-text-muted">
          Manage schedules, trigger backups, and monitor status.
        </p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="mt-8 text-text-muted">Loading plans…</div>

    <!-- Empty state -->
    <div
      v-else-if="store.plans.length === 0"
      class="mt-8 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <p class="text-text-muted">No backup plans yet.</p>
      <p class="mt-1 text-sm text-text-muted">
        Add a source to automatically create a backup plan.
      </p>
    </div>

    <!-- Plan list -->
    <div v-else class="mt-6 space-y-4">
      <div
        v-for="plan in store.plans"
        :key="plan.id"
        class="rounded-xl border border-border bg-surface p-5"
      >
        <div class="flex items-start justify-between">
          <div class="flex items-center gap-3">
            <component
              :is="statusIcon(plan.status)"
              class="h-5 w-5"
              :class="{
                'text-success': plan.status === 'healthy',
                'text-danger': plan.status === 'failed',
                'text-info animate-spin': plan.status === 'running',
                'text-text-muted':
                  plan.status === 'idle' || plan.status === 'warning',
              }"
            />
            <div>
              <h3 class="font-medium text-text-primary">{{ plan.name }}</h3>
              <p class="mt-0.5 text-sm text-text-muted">
                {{ plan.source_type }} · {{ plan.engine }} ·
                <span class="font-mono">{{ plan.schedule_cron }}</span>
              </p>
            </div>
          </div>

          <span
            class="rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="statusBadgeClass(plan.status)"
          >
            {{ plan.status }}
          </span>
        </div>

        <!-- Meta row -->
        <div class="mt-3 flex items-center gap-6 text-xs text-text-muted">
          <span>Repository: {{ plan.repository_name }}</span>
          <span>Last run: {{ timeAgo(plan.last_run) }}</span>
          <span v-if="plan.schedule_enabled" class="text-success">
            Scheduled
          </span>
          <span v-else class="text-warning"> Paused </span>
        </div>

        <!-- Action message -->
        <div
          v-if="actionMessage?.planId === plan.id"
          class="mt-3 rounded-lg px-3 py-2 text-sm"
          :class="
            actionMessage.ok
              ? 'bg-success/10 text-success'
              : 'bg-danger/10 text-danger'
          "
        >
          {{ actionMessage.text }}
        </div>

        <!-- Actions -->
        <div class="mt-4 flex items-center gap-2">
          <button
            :disabled="actionLoading === plan.id"
            class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
            @click="runBackup(plan.id)"
          >
            <Play class="h-3.5 w-3.5" />
            Run Backup
          </button>
          <button
            :disabled="actionLoading === plan.id"
            class="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
            @click="runPrune(plan.id)"
          >
            <Scissors class="h-3.5 w-3.5" />
            Prune
          </button>
          <button
            :disabled="actionLoading === plan.id"
            class="flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
            @click="runVerify(plan.id)"
          >
            <ShieldCheck class="h-3.5 w-3.5" />
            Verify
          </button>

          <Loader2
            v-if="actionLoading === plan.id"
            class="ml-2 h-4 w-4 animate-spin text-text-muted"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from "vue";
import { usePlansStore } from "@/stores/plans";
import {
  Play,
  Scissors,
  ShieldCheck,
  CircleCheck,
  CircleX,
  Clock,
  Loader2,
  Import,
} from "lucide-vue-next";

const store = usePlansStore();

// Live elapsed time ticker
const now = ref(Date.now());
let tickTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
  store.fetchPlans();
  tickTimer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});

onUnmounted(() => {
  store.stopPolling();
  if (tickTimer) clearInterval(tickTimer);
});

function elapsed(startedAt: string | null) {
  if (!startedAt) return "";
  const ms = now.value - new Date(startedAt).getTime();
  const secs = Math.max(0, Math.round(ms / 1000));
  if (secs < 60) return `${secs}s`;
  const mins = Math.floor(secs / 60);
  const rem = secs % 60;
  return `${mins}m ${rem}s`;
}

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

// ---- Import repository modal ----
const showImport = ref(false);
const importLoading = ref(false);
const importError = ref("");
const importResult = ref<{
  message: string;
  archiveCount: number;
} | null>(null);

const importForm = ref({
  path: "",
  name: "",
  repositoryId: "",
});

async function openImport() {
  showImport.value = true;
  importError.value = "";
  importResult.value = null;
  importForm.value = { path: "", name: "", repositoryId: "" };
  // Fetch repositories for the dropdown
  await store.fetchRepositories();
  // Auto-select default repo if available
  if (store.repositories.length > 0 && !importForm.value.repositoryId) {
    importForm.value.repositoryId = store.repositories[0].id;
  }
}

async function submitImport() {
  importLoading.value = true;
  importError.value = "";
  try {
    const result = await store.importRepository(
      importForm.value.repositoryId,
      importForm.value.path,
      importForm.value.name || undefined,
    );
    importResult.value = {
      message: result.message,
      archiveCount: result.archive_count,
    };
  } catch (e: unknown) {
    if (e && typeof e === "object" && "response" in e) {
      const resp = (e as { response: { data: { message?: string } } }).response;
      importError.value = resp.data?.message ?? "Failed to import repository.";
    } else {
      importError.value = "Failed to import repository.";
    }
  } finally {
    importLoading.value = false;
  }
}

function closeImport() {
  showImport.value = false;
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
      <button
        class="flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
        @click="openImport"
      >
        <Import class="h-4 w-4" />
        Import Borg Repo
      </button>
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

        <!-- Progress bar (running jobs) -->
        <div v-if="plan.running_job" class="mt-3 space-y-1.5">
          <div class="flex items-center justify-between text-xs">
            <span class="text-info font-medium capitalize">
              {{ plan.running_job.job_type }} in progress…
            </span>
            <span class="text-text-muted tabular-nums">
              {{ plan.running_job.progress }}% ·
              {{ elapsed(plan.running_job.started_at) }}
            </span>
          </div>
          <div class="h-1.5 w-full overflow-hidden rounded-full bg-info/10">
            <div
              class="h-full rounded-full bg-info transition-all duration-500 ease-out"
              :style="{ width: plan.running_job.progress + '%' }"
            />
          </div>
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

    <!-- ======== Import Borg Repo Modal ======== -->
    <Teleport to="body">
      <div
        v-if="showImport"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closeImport"
      >
        <div
          class="w-full max-w-lg rounded-2xl border border-border bg-surface p-6 shadow-xl"
        >
          <!-- Success state -->
          <template v-if="importResult">
            <div class="text-center py-4">
              <CircleCheck class="mx-auto h-14 w-14 text-success" />
              <h2 class="mt-4 text-lg font-semibold text-text-primary">
                Repository Imported!
              </h2>
              <p class="mt-2 text-sm text-text-muted">
                {{ importResult.message }}
              </p>
              <div
                class="mt-4 rounded-lg bg-surface-raised px-4 py-3 text-sm text-text-primary"
              >
                <span class="text-text-muted">Archives imported:</span>
                {{ importResult.archiveCount }}
              </div>
              <button
                class="mt-6 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
                @click="closeImport"
              >
                Done
              </button>
            </div>
          </template>

          <!-- Import form -->
          <template v-else>
            <div class="flex items-center gap-3 mb-5">
              <div
                class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
              >
                <Import class="h-5 w-5" />
              </div>
              <div>
                <h2 class="text-lg font-semibold text-text-primary">
                  Import Borg Repository
                </h2>
                <p class="text-sm text-text-muted">
                  Point to an existing borg repo to import its archives.
                </p>
              </div>
            </div>

            <form class="space-y-4" @submit.prevent="submitImport">
              <!-- Target repository -->
              <div>
                <label class="mb-1 block text-sm font-medium text-text-primary">
                  Target Repository
                </label>
                <select
                  v-model="importForm.repositoryId"
                  required
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                  <option value="" disabled>Select a repository…</option>
                  <option
                    v-for="repo in store.repositories"
                    :key="repo.id"
                    :value="repo.id"
                  >
                    {{ repo.name }} ({{ repo.backend_type }})
                  </option>
                </select>
              </div>

              <!-- Borg repo path -->
              <div>
                <label class="mb-1 block text-sm font-medium text-text-primary">
                  Borg Repository Path
                </label>
                <input
                  v-model="importForm.path"
                  type="text"
                  required
                  placeholder="/data/repositories/my-existing-repo"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
                <p class="mt-1 text-xs text-text-muted">
                  Path to an existing borg repository on the container
                  filesystem.
                </p>
              </div>

              <!-- Name (optional) -->
              <div>
                <label class="mb-1 block text-sm font-medium text-text-primary">
                  Name
                  <span class="font-normal text-text-muted">(optional)</span>
                </label>
                <input
                  v-model="importForm.name"
                  type="text"
                  placeholder="Auto-generated from path"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
              </div>

              <!-- Error -->
              <div
                v-if="importError"
                class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
              >
                {{ importError }}
              </div>

              <!-- Actions -->
              <div class="flex items-center justify-end gap-2 pt-2">
                <button
                  type="button"
                  class="rounded-lg border border-border px-4 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised transition-colors"
                  @click="closeImport"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  :disabled="importLoading"
                  class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                >
                  <Loader2 v-if="importLoading" class="h-4 w-4 animate-spin" />
                  {{ importLoading ? "Importing…" : "Import Repository" }}
                </button>
              </div>
            </form>
          </template>
        </div>
      </div>
    </Teleport>
  </div>
</template>

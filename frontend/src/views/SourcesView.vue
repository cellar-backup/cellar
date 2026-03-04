<script setup lang="ts">
import { ref, computed } from "vue";
import {
  Database,
  Server,
  CircleCheck,
  CircleX,
  Loader2,
  Trash2,
  Pencil,
} from "lucide-vue-next";
import { useRouter } from "vue-router";
import { useSourcesStore, type QuickAddPayload } from "@/stores/sources";

const router = useRouter();

const DB_TYPES = [
  { value: "postgresql", label: "PostgreSQL", defaultPort: 5432 },
  { value: "mysql", label: "MySQL", defaultPort: 3306 },
  { value: "mariadb", label: "MariaDB", defaultPort: 3306 },
  { value: "mongodb", label: "MongoDB", defaultPort: 27017 },
  { value: "redis", label: "Redis", defaultPort: 6379 },
] as const;

const store = useSourcesStore();
store.fetchSources();

// ---- Wizard state ----
const showWizard = ref(false);
const wizardStep = ref<"type" | "details" | "done">("type");
const wizardLoading = ref(false);
const wizardError = ref("");
const wizardResult = ref<{ message: string; planName: string } | null>(null);

const form = ref<QuickAddPayload>({
  source_type: "",
  host: "",
  port: null,
  username: "",
  password: "",
  database_name: "",
});

const selectedType = computed(
  () => DB_TYPES.find((t) => t.value === form.value.source_type) ?? null,
);

function openWizard() {
  showWizard.value = true;
  wizardStep.value = "type";
  wizardError.value = "";
  wizardResult.value = null;
  form.value = {
    source_type: "",
    host: "",
    port: null,
    username: "",
    password: "",
    database_name: "",
  };
}

function selectType(type: string, defaultPort: number) {
  form.value.source_type = type;
  form.value.port = defaultPort;
  wizardStep.value = "details";
}

async function submitWizard() {
  wizardLoading.value = true;
  wizardError.value = "";
  try {
    const result = await store.quickAdd(form.value);
    wizardResult.value = {
      message: result.message,
      planName: result.backup_plan.name,
    };
    wizardStep.value = "done";
  } catch (e: unknown) {
    if (e && typeof e === "object" && "response" in e) {
      const resp = (e as { response: { data: unknown } }).response;
      wizardError.value =
        typeof resp.data === "string" ? resp.data : JSON.stringify(resp.data);
    } else {
      wizardError.value = "Something went wrong. Please try again.";
    }
  } finally {
    wizardLoading.value = false;
  }
}

function closeWizard() {
  showWizard.value = false;
}

// ---- Connection test ----
const testing = ref<string | null>(null);
const testResults = ref<Record<string, { ok: boolean; detail: string }>>({});

async function testConnection(sourceId: string) {
  testing.value = sourceId;
  try {
    const result = await store.testConnection(sourceId);
    testResults.value[sourceId] = {
      ok: result.status === "ok",
      detail: result.message,
    };
  } catch {
    testResults.value[sourceId] = {
      ok: false,
      detail: "Test failed — check container logs.",
    };
  } finally {
    testing.value = null;
  }
}

// ---- Delete ----
async function deleteSource(id: string, name: string) {
  if (
    !confirm(
      `Delete source "${name}"? Associated backup plans will also be removed.`,
    )
  )
    return;
  await store.deleteSource(id);
}

function sourceIcon(type: string) {
  return type === "directory" || type === "docker_volume" ? Server : Database;
}
</script>

<template>
  <div class="p-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-text-primary">Sources</h1>
        <p class="mt-1 text-text-muted">
          Databases and directories you want to back up.
        </p>
      </div>
      <button
        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
        @click="openWizard"
      >
        + Add Source
      </button>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="mt-8 text-text-muted">
      Loading sources…
    </div>

    <!-- Empty state -->
    <div
      v-else-if="store.sources.length === 0"
      class="mt-8 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <Database class="mx-auto h-12 w-12 text-text-muted" />
      <p class="mt-3 text-text-primary font-medium">No sources yet</p>
      <p class="mt-1 text-sm text-text-muted">
        Click "Add Source" to back up your first database in seconds.
      </p>
      <button
        class="mt-4 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
        @click="openWizard"
      >
        + Add Source
      </button>
    </div>

    <!-- Source list -->
    <div v-else class="mt-6 space-y-3">
      <div
        v-for="source in store.sources"
        :key="source.id"
        class="flex items-center justify-between rounded-xl border border-border bg-surface p-4 hover:border-primary/30 transition-colors cursor-pointer"
        @click="router.push(`/sources/${source.id}`)"
      >
        <div class="flex items-center gap-4">
          <div
            class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
          >
            <component :is="sourceIcon(source.source_type)" class="h-5 w-5" />
          </div>
          <div>
            <h3 class="font-medium text-text-primary">{{ source.name }}</h3>
            <p class="mt-0.5 text-sm text-text-muted">
              {{ source.display_label }}
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <!-- Test result -->
          <span
            v-if="testResults[source.id]"
            class="text-xs"
            :class="testResults[source.id].ok ? 'text-success' : 'text-danger'"
          >
            <CircleCheck
              v-if="testResults[source.id].ok"
              class="inline h-4 w-4"
            />
            <CircleX v-else class="inline h-4 w-4" />
            {{ testResults[source.id].ok ? "Connected" : "Failed" }}
          </span>

          <button
            v-if="source.is_database"
            :disabled="testing === source.id"
            class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
            @click.stop="testConnection(source.id)"
          >
            <Loader2
              v-if="testing === source.id"
              class="inline h-3.5 w-3.5 animate-spin"
            />
            <span v-else>Test</span>
          </button>

          <button
            class="rounded-lg p-1.5 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
            title="Edit source"
            @click.stop="router.push(`/sources/${source.id}`)"
          >
            <Pencil class="h-4 w-4" />
          </button>

          <button
            class="rounded-lg p-1.5 text-text-muted hover:bg-danger/10 hover:text-danger transition-colors"
            @click.stop="deleteSource(source.id, source.name)"
          >
            <Trash2 class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- ======== Quick-Add Wizard Modal ======== -->
    <Teleport to="body">
      <div
        v-if="showWizard"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closeWizard"
      >
        <div
          class="w-full max-w-lg rounded-2xl border border-border bg-surface p-6 shadow-xl"
        >
          <!-- Step 1: Choose type -->
          <template v-if="wizardStep === 'type'">
            <h2 class="text-lg font-semibold text-text-primary">
              What do you want to back up?
            </h2>
            <p class="mt-1 text-sm text-text-muted">
              Select your database type to get started.
            </p>

            <div class="mt-5 grid grid-cols-2 gap-3">
              <button
                v-for="db in DB_TYPES"
                :key="db.value"
                class="flex items-center gap-3 rounded-xl border border-border bg-surface-raised p-4 text-left hover:border-primary/50 transition-colors"
                @click="selectType(db.value, db.defaultPort)"
              >
                <Database class="h-6 w-6 text-primary" />
                <span class="text-sm font-medium text-text-primary">
                  {{ db.label }}
                </span>
              </button>
            </div>

            <button
              class="mt-4 text-sm text-text-muted hover:text-text-primary transition-colors"
              @click="closeWizard"
            >
              Cancel
            </button>
          </template>

          <!-- Step 2: Connection details -->
          <template v-if="wizardStep === 'details'">
            <h2 class="text-lg font-semibold text-text-primary">
              {{ selectedType?.label }} Connection
            </h2>
            <p class="mt-1 text-sm text-text-muted">
              Enter your database connection details.
            </p>

            <form class="mt-5 space-y-4" @submit.prevent="submitWizard">
              <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                  >
                    Host / IP
                  </label>
                  <input
                    v-model="form.host"
                    type="text"
                    required
                    placeholder="192.168.1.100"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                  >
                    Port
                  </label>
                  <input
                    v-model.number="form.port"
                    type="number"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </div>

              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                  >
                    Username
                  </label>
                  <input
                    v-model="form.username"
                    type="text"
                    placeholder="postgres"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                  >
                    Password
                  </label>
                  <input
                    v-model="form.password"
                    type="password"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </div>

              <div v-if="form.source_type !== 'redis'">
                <label class="mb-1 block text-sm font-medium text-text-primary">
                  Database Name
                </label>
                <input
                  v-model="form.database_name"
                  type="text"
                  required
                  placeholder="myapp_production"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
              </div>

              <div
                v-if="wizardError"
                class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
              >
                {{ wizardError }}
              </div>

              <div class="flex items-center justify-between pt-2">
                <button
                  type="button"
                  class="text-sm text-text-muted hover:text-text-primary transition-colors"
                  @click="wizardStep = 'type'"
                >
                  &larr; Back
                </button>
                <button
                  type="submit"
                  :disabled="wizardLoading"
                  class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                >
                  <Loader2
                    v-if="wizardLoading"
                    class="inline h-4 w-4 animate-spin mr-1"
                  />
                  {{ wizardLoading ? "Adding…" : "Add & Schedule Backup" }}
                </button>
              </div>
            </form>
          </template>

          <!-- Step 3: Success -->
          <template v-if="wizardStep === 'done'">
            <div class="text-center py-4">
              <CircleCheck class="mx-auto h-14 w-14 text-success" />
              <h2 class="mt-4 text-lg font-semibold text-text-primary">
                Source Added!
              </h2>
              <p class="mt-2 text-sm text-text-muted">
                {{ wizardResult?.message }}
              </p>
              <div
                class="mt-4 rounded-lg bg-surface-raised px-4 py-3 text-sm text-text-primary"
              >
                <span class="text-text-muted">Backup plan:</span>
                {{ wizardResult?.planName }}
              </div>
              <button
                class="mt-6 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
                @click="closeWizard"
              >
                Done
              </button>
            </div>
          </template>
        </div>
      </div>
    </Teleport>
  </div>
</template>

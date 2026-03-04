<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  ArrowLeft,
  Database,
  Server,
  Save,
  Loader2,
  CircleCheck,
  CircleX,
  Trash2,
} from "lucide-vue-next";
import { useSourcesStore } from "@/stores/sources";

const DB_TYPES = [
  { value: "postgresql", label: "PostgreSQL", defaultPort: 5432 },
  { value: "mysql", label: "MySQL", defaultPort: 3306 },
  { value: "mariadb", label: "MariaDB", defaultPort: 3306 },
  { value: "mongodb", label: "MongoDB", defaultPort: 27017 },
  { value: "redis", label: "Redis", defaultPort: 6379 },
  { value: "sqlite", label: "SQLite", defaultPort: null },
  { value: "directory", label: "Directory", defaultPort: null },
  { value: "docker_volume", label: "Docker Volume", defaultPort: null },
] as const;

const route = useRoute();
const router = useRouter();
const store = useSourcesStore();

const loading = ref(true);
const saving = ref(false);
const saveSuccess = ref(false);
const saveError = ref("");
const testing = ref(false);
const testResult = ref<{ ok: boolean; detail: string } | null>(null);

const form = ref({
  name: "",
  source_type: "",
  host: "",
  port: null as number | null,
  username: "",
  password: "",
  database_name: "",
  path: "",
  notes: "",
  enabled: true,
});

const sourceId = computed(() => route.params.id as string);

const typeInfo = computed(
  () => DB_TYPES.find((t) => t.value === form.value.source_type) ?? null,
);

const isDatabase = computed(() => {
  const t = form.value.source_type;
  return t !== "directory" && t !== "docker_volume" && t !== "sqlite";
});

const sourceIcon = computed(() =>
  form.value.source_type === "directory" ||
  form.value.source_type === "docker_volume"
    ? Server
    : Database,
);

onMounted(async () => {
  try {
    const source = await store.getSource(sourceId.value);
    form.value = {
      name: source.name ?? "",
      source_type: source.source_type ?? "",
      host: source.host ?? "",
      port: source.port ?? null,
      username: source.username ?? "",
      password: "", // never pre-filled from backend (hidden attribute)
      database_name: source.database_name ?? "",
      path: source.path ?? "",
      notes: source.notes ?? "",
      enabled: source.enabled ?? true,
    };
  } catch {
    router.push("/sources");
  } finally {
    loading.value = false;
  }
});

async function save() {
  saving.value = true;
  saveError.value = "";
  saveSuccess.value = false;
  try {
    // Only send password if user typed something
    const payload: Record<string, unknown> = { ...form.value };
    if (!payload.password) {
      delete payload.password;
    }
    await store.updateSource(sourceId.value, payload);
    saveSuccess.value = true;
    setTimeout(() => (saveSuccess.value = false), 3000);
  } catch (e: unknown) {
    if (e && typeof e === "object" && "response" in e) {
      const resp = (e as { response: { data: unknown } }).response;
      const data = resp.data;
      if (data && typeof data === "object" && "errors" in data) {
        const errors = (data as { errors: Record<string, string[]> }).errors;
        saveError.value = Object.values(errors).flat().join(", ");
      } else {
        saveError.value =
          typeof data === "string" ? data : "Failed to save changes.";
      }
    } else {
      saveError.value = "Failed to save changes.";
    }
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  testResult.value = null;
  try {
    const result = await store.testConnection(sourceId.value);
    testResult.value = {
      ok: result.status === "ok",
      detail: result.message,
    };
  } catch {
    testResult.value = {
      ok: false,
      detail: "Test failed — check container logs.",
    };
  } finally {
    testing.value = false;
  }
}

async function deleteSource() {
  if (
    !confirm(
      `Delete source "${form.value.name}"? Associated backup plans will also be removed.`,
    )
  )
    return;
  await store.deleteSource(sourceId.value);
  router.push("/sources");
}
</script>

<template>
  <div class="p-6">
    <!-- Back + title -->
    <div class="flex items-center gap-3">
      <button
        class="rounded-lg p-1.5 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
        @click="router.push('/sources')"
      >
        <ArrowLeft class="h-5 w-5" />
      </button>
      <div>
        <h1 class="text-2xl font-semibold text-text-primary">Edit Source</h1>
        <p class="mt-0.5 text-text-muted text-sm">
          Update connection details for this source.
        </p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="mt-8 text-text-muted">Loading source…</div>

    <!-- Form -->
    <form v-else class="mt-6 max-w-2xl space-y-6" @submit.prevent="save">
      <!-- Type badge (read-only) -->
      <div>
        <label class="mb-1 block text-sm font-medium text-text-primary">
          Type
        </label>
        <div class="flex items-center gap-2">
          <div
            class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary"
          >
            <component :is="sourceIcon" class="h-4 w-4" />
          </div>
          <span class="text-sm font-medium text-text-primary">
            {{ typeInfo?.label ?? form.source_type }}
          </span>
        </div>
      </div>

      <!-- Name -->
      <div>
        <label class="mb-1 block text-sm font-medium text-text-primary">
          Name
        </label>
        <input
          v-model="form.name"
          type="text"
          placeholder="Auto-generated if blank"
          class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </div>

      <!-- Host / Port (database types only) -->
      <div v-if="isDatabase" class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="mb-1 block text-sm font-medium text-text-primary">
            Host / IP
          </label>
          <input
            v-model="form.host"
            type="text"
            placeholder="192.168.1.100"
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-text-primary">
            Port
          </label>
          <input
            v-model.number="form.port"
            type="number"
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>
      </div>

      <!-- Username / Password (database types only) -->
      <div v-if="isDatabase" class="grid grid-cols-2 gap-3">
        <div>
          <label class="mb-1 block text-sm font-medium text-text-primary">
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
          <label class="mb-1 block text-sm font-medium text-text-primary">
            Password
          </label>
          <input
            v-model="form.password"
            type="password"
            placeholder="Leave blank to keep current"
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>
      </div>

      <!-- Database Name (database types, not redis) -->
      <div v-if="isDatabase && form.source_type !== 'redis'">
        <label class="mb-1 block text-sm font-medium text-text-primary">
          Database Name
        </label>
        <input
          v-model="form.database_name"
          type="text"
          placeholder="myapp_production"
          class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </div>

      <!-- Path (for directory / docker_volume / sqlite) -->
      <div v-if="!isDatabase || form.source_type === 'sqlite'">
        <label class="mb-1 block text-sm font-medium text-text-primary">
          Path
        </label>
        <input
          v-model="form.path"
          type="text"
          placeholder="/data/myapp"
          class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
        />
      </div>

      <!-- Notes -->
      <div>
        <label class="mb-1 block text-sm font-medium text-text-primary">
          Notes
        </label>
        <textarea
          v-model="form.notes"
          rows="2"
          placeholder="Optional notes about this source"
          class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary resize-y"
        />
      </div>

      <!-- Enabled toggle -->
      <label class="flex items-center gap-3 cursor-pointer">
        <input
          v-model="form.enabled"
          type="checkbox"
          class="h-4 w-4 rounded border-border accent-primary"
        />
        <span class="text-sm font-medium text-text-primary"> Enabled </span>
      </label>

      <!-- Error / Success feedback -->
      <div
        v-if="saveError"
        class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
      >
        {{ saveError }}
      </div>
      <div
        v-if="saveSuccess"
        class="flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2 text-sm text-success"
      >
        <CircleCheck class="h-4 w-4" />
        Changes saved.
      </div>

      <!-- Actions -->
      <div
        class="flex items-center justify-between border-t border-border pt-4"
      >
        <div class="flex items-center gap-2">
          <!-- Test Connection -->
          <button
            v-if="isDatabase"
            type="button"
            :disabled="testing"
            class="rounded-lg border border-border px-4 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
            @click="testConnection"
          >
            <Loader2 v-if="testing" class="inline h-4 w-4 animate-spin mr-1" />
            {{ testing ? "Testing…" : "Test Connection" }}
          </button>

          <span
            v-if="testResult"
            class="text-xs ml-1"
            :class="testResult.ok ? 'text-success' : 'text-danger'"
          >
            <CircleCheck v-if="testResult.ok" class="inline h-4 w-4" />
            <CircleX v-else class="inline h-4 w-4" />
            {{ testResult.detail }}
          </span>
        </div>

        <div class="flex items-center gap-2">
          <button
            type="button"
            class="rounded-lg p-2 text-text-muted hover:bg-danger/10 hover:text-danger transition-colors"
            title="Delete source"
            @click="deleteSource"
          >
            <Trash2 class="h-4 w-4" />
          </button>

          <button
            type="submit"
            :disabled="saving"
            class="flex items-center gap-2 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
          >
            <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
            <Save v-else class="h-4 w-4" />
            {{ saving ? "Saving…" : "Save Changes" }}
          </button>
        </div>
      </div>
    </form>
  </div>
</template>

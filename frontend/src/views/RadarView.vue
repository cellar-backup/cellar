<script setup lang="ts">
import { ref, computed } from "vue";
import { useRadarStore, type DiscoveredResource } from "@/stores/radar";
import {
  Radar,
  Database,
  FolderOpen,
  CircleCheck,
  CircleX,
  Loader2,
  EyeOff,
  Eye,
  Import,
  Wifi,
  WifiOff,
  Trash2,
} from "lucide-vue-next";

const store = useRadarStore();

// ---- Selection state ----
const selected = ref<Set<string>>(new Set());
const importLoading = ref(false);
const importMessage = ref<{ text: string; ok: boolean } | null>(null);
const showIgnored = ref(false);

const allSelected = computed(
  () =>
    selectable.value.length > 0 &&
    selectable.value.every((r) => selected.value.has(r.resource_key)),
);

const selectable = computed(() =>
  store.resources.filter((r) => !r.already_added),
);

function toggleAll() {
  if (allSelected.value) {
    selected.value.clear();
  } else {
    selectable.value.forEach((r) => selected.value.add(r.resource_key));
  }
}

function toggleResource(key: string) {
  if (selected.value.has(key)) {
    selected.value.delete(key);
  } else {
    selected.value.add(key);
  }
}

// ---- Actions ----
async function runScan() {
  importMessage.value = null;
  const ok = await store.testConnection();
  if (ok) {
    await store.discover();
  }
}

async function importSelected() {
  const toImport = store.resources.filter((r) =>
    selected.value.has(r.resource_key),
  );
  if (toImport.length === 0) return;

  importLoading.value = true;
  importMessage.value = null;
  try {
    const result = await store.importResources(toImport);
    importMessage.value = { text: result.message, ok: true };
    selected.value.clear();
  } catch {
    importMessage.value = {
      text: "Failed to import selected resources.",
      ok: false,
    };
  } finally {
    importLoading.value = false;
  }
}

async function ignore(resource: DiscoveredResource) {
  selected.value.delete(resource.resource_key);
  await store.ignoreResource(resource);
}

async function toggleIgnoredPanel() {
  showIgnored.value = !showIgnored.value;
  if (showIgnored.value) {
    await store.fetchIgnored();
  }
}

function sourceTypeLabel(type: string) {
  const map: Record<string, string> = {
    postgresql: "PostgreSQL",
    mysql: "MySQL",
    mariadb: "MariaDB",
    mongodb: "MongoDB",
    redis: "Redis",
    directory: "Directory / PVC",
  };
  return map[type] ?? type;
}

function sourceIcon(type: string) {
  return type === "directory" ? FolderOpen : Database;
}

function kindBadgeClass(kind: string) {
  switch (kind) {
    case "Service":
      return "bg-info/10 text-info";
    case "Pod":
      return "bg-warning/10 text-warning";
    case "PVC":
      return "bg-primary/10 text-primary";
    default:
      return "bg-surface-raised text-text-muted";
  }
}
</script>

<template>
  <div class="p-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-text-primary">Radar</h1>
        <p class="mt-1 text-text-muted">
          Discover databases and volumes in your Kubernetes cluster.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button
          class="flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised transition-colors"
          @click="toggleIgnoredPanel"
        >
          <component :is="showIgnored ? Eye : EyeOff" class="h-4 w-4" />
          {{ showIgnored ? "Hide Ignored" : "Ignored" }}
          <span
            v-if="store.ignored.length > 0 && !showIgnored"
            class="rounded-full bg-surface-raised px-1.5 py-0.5 text-xs"
          >
            {{ store.ignored.length }}
          </span>
        </button>
      </div>
    </div>

    <!-- Connection config -->
    <div class="mt-6 rounded-xl border border-border bg-surface p-5">
      <h2 class="text-sm font-semibold text-text-primary mb-3">
        Cluster Connection
      </h2>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="mb-1 block text-xs font-medium text-text-muted">
            Kubeconfig Path
            <span class="font-normal">(optional)</span>
          </label>
          <input
            v-model="store.config.kubeconfigPath"
            type="text"
            placeholder="Auto-detect (in-cluster)"
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-text-muted">
            Context <span class="font-normal">(optional)</span>
          </label>
          <input
            v-model="store.config.context"
            type="text"
            placeholder="current-context"
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-text-muted">
            Namespace <span class="font-normal">(optional)</span>
          </label>
          <input
            v-model="store.config.namespace"
            type="text"
            placeholder="All namespaces"
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>
      </div>

      <div class="mt-4 flex items-center gap-3">
        <button
          :disabled="store.loading"
          class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
          @click="runScan"
        >
          <Loader2 v-if="store.loading" class="h-4 w-4 animate-spin" />
          <Radar v-else class="h-4 w-4" />
          {{ store.loading ? "Scanning…" : "Scan Cluster" }}
        </button>

        <!-- Connection status -->
        <span
          v-if="store.connected !== null"
          class="flex items-center gap-1.5 text-sm"
          :class="store.connected ? 'text-success' : 'text-danger'"
        >
          <Wifi v-if="store.connected" class="h-4 w-4" />
          <WifiOff v-else class="h-4 w-4" />
          {{ store.connected ? "Connected" : "Not connected" }}
        </span>
      </div>

      <!-- Error -->
      <div
        v-if="store.error"
        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
      >
        {{ store.error }}
      </div>
    </div>

    <!-- Import message -->
    <div
      v-if="importMessage"
      class="mt-4 rounded-lg px-4 py-3 text-sm"
      :class="
        importMessage.ok
          ? 'bg-success/10 text-success'
          : 'bg-danger/10 text-danger'
      "
    >
      <CircleCheck v-if="importMessage.ok" class="inline h-4 w-4 mr-1" />
      <CircleX v-else class="inline h-4 w-4 mr-1" />
      {{ importMessage.text }}
    </div>

    <!-- Ignored panel -->
    <div
      v-if="showIgnored && store.ignored.length > 0"
      class="mt-4 rounded-xl border border-border bg-surface p-5"
    >
      <h2 class="text-sm font-semibold text-text-primary mb-3">
        Ignored Resources
      </h2>
      <div class="space-y-2">
        <div
          v-for="item in store.ignored"
          :key="item.id"
          class="flex items-center justify-between rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm"
        >
          <div>
            <span class="text-text-primary font-medium">{{ item.name }}</span>
            <span class="text-text-muted ml-2">{{ item.namespace }}</span>
            <span v-if="item.source_type" class="text-text-muted ml-2">
              · {{ item.source_type }}
            </span>
          </div>
          <button
            class="rounded p-1 text-text-muted hover:bg-danger/10 hover:text-danger transition-colors"
            title="Stop ignoring"
            @click="store.unignore(item.id)"
          >
            <Trash2 class="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div
      v-if="
        !store.loading &&
        store.resources.length === 0 &&
        store.connected !== null
      "
      class="mt-8 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <Radar class="mx-auto h-12 w-12 text-text-muted" />
      <p class="mt-3 text-text-primary font-medium">No resources found</p>
      <p class="mt-1 text-sm text-text-muted">
        No databases or PVCs were discovered in the cluster. Try scanning a
        different namespace.
      </p>
    </div>

    <!-- Discovery results -->
    <div v-if="store.resources.length > 0" class="mt-6">
      <!-- Action bar -->
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
          <label class="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              :checked="allSelected"
              class="h-4 w-4 rounded border-border accent-primary"
              @change="toggleAll"
            />
            <span class="text-sm font-medium text-text-primary">
              Select all ({{ selectable.length }})
            </span>
          </label>
        </div>

        <button
          v-if="selected.size > 0"
          :disabled="importLoading"
          class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
          @click="importSelected"
        >
          <Loader2 v-if="importLoading" class="h-4 w-4 animate-spin" />
          <Import v-else class="h-4 w-4" />
          {{
            importLoading
              ? "Importing…"
              : `Import ${selected.size} Source${selected.size > 1 ? "s" : ""}`
          }}
        </button>
      </div>

      <!-- Resource list -->
      <div class="space-y-2">
        <div
          v-for="resource in store.resources"
          :key="resource.resource_key"
          class="flex items-center gap-3 rounded-xl border border-border bg-surface p-4 transition-colors"
          :class="{
            'opacity-50': resource.already_added,
            'hover:border-primary/30': !resource.already_added,
          }"
        >
          <!-- Checkbox -->
          <input
            v-if="!resource.already_added"
            type="checkbox"
            :checked="selected.has(resource.resource_key)"
            class="h-4 w-4 rounded border-border accent-primary shrink-0"
            @change="toggleResource(resource.resource_key)"
          />
          <div v-else class="w-4 shrink-0">
            <CircleCheck class="h-4 w-4 text-success" />
          </div>

          <!-- Icon -->
          <div
            class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary shrink-0"
          >
            <component :is="sourceIcon(resource.source_type)" class="h-5 w-5" />
          </div>

          <!-- Info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <h3 class="font-medium text-text-primary truncate">
                {{ resource.name }}
              </h3>
              <span
                class="rounded-full px-2 py-0.5 text-xs font-medium"
                :class="kindBadgeClass(resource.kind)"
              >
                {{ resource.kind }}
              </span>
              <span
                v-if="resource.already_added"
                class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
              >
                Already added
              </span>
            </div>
            <p class="mt-0.5 text-sm text-text-muted truncate">
              <span class="font-mono">{{ resource.namespace }}</span>
              <span v-if="resource.host" class="ml-2">
                {{ resource.host
                }}{{ resource.port ? `:${resource.port}` : "" }}
              </span>
              <span v-if="resource.capacity" class="ml-2">
                · {{ resource.capacity }}
              </span>
            </p>
            <p
              v-if="resource.image"
              class="mt-0.5 text-xs text-text-muted truncate"
            >
              {{ resource.image }}
            </p>
          </div>

          <!-- Type + actions -->
          <div class="flex items-center gap-2 shrink-0">
            <span
              class="rounded-full bg-surface-raised px-2.5 py-0.5 text-xs font-medium text-text-muted"
            >
              {{ sourceTypeLabel(resource.source_type) }}
            </span>
            <button
              v-if="!resource.already_added"
              class="rounded p-1.5 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
              title="Ignore this resource"
              @click="ignore(resource)"
            >
              <EyeOff class="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

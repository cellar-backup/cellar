<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import {
  useRadarStore,
  type DiscoveredResource,
  type ResourceEndpoint,
  type RadarCluster,
  type ImportOverride,
} from "@/stores/radar";
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
  Plus,
  Upload,
  Server,
  Pencil,
  X,
  KeyRound,
} from "lucide-vue-next";

const store = useRadarStore();

// ── Cluster form state ──
const showClusterForm = ref(false);
const editingCluster = ref<RadarCluster | null>(null);
const clusterForm = ref({
  name: "",
  context: "",
  defaultNamespace: "",
});
const kubeconfigFile = ref<File | null>(null);
const clearKubeconfig = ref(false);
const clusterFormLoading = ref(false);
const clusterFormError = ref<string | null>(null);

// ── Selection state ──
const selected = ref<Set<string>>(new Set());
const importLoading = ref(false);
const importMessage = ref<{ text: string; ok: boolean } | null>(null);
const showIgnored = ref(false);

// ── Import review modal state ──
const showImportReview = ref(false);
const importReviewItems = ref<
  Array<{
    resource: DiscoveredResource;
    endpointIndex: number;
    host: string;
    port: number | null;
    username: string;
    password: string;
    database_name: string;
    detectedDatabases: string[] | null;
    dbDetectLoading: boolean;
    dbDetectError: string | null;
  }>
>([]);

// ── Endpoint selection (for Pod/Service toggle) ──
const selectedEndpoints = ref<Record<string, number>>({});

function activeEndpoint(r: DiscoveredResource): ResourceEndpoint {
  const idx = selectedEndpoints.value[r.resource_key] ?? 0;
  return (
    r.endpoints?.[idx] ??
    r.endpoints?.[0] ?? {
      kind: r.kind,
      resource_name: r.name,
      host: r.host,
      port: r.port,
      external_host: r.external_host,
      external_port: r.external_port,
      node_port: r.node_port,
      service_type: r.service_type,
      image: r.image,
    }
  );
}

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

// ── Cluster form actions ──

function openAddCluster() {
  editingCluster.value = null;
  clusterForm.value = { name: "", context: "", defaultNamespace: "" };
  kubeconfigFile.value = null;
  clearKubeconfig.value = false;
  clusterFormError.value = null;
  showClusterForm.value = true;
}

function openEditCluster(cluster: RadarCluster) {
  editingCluster.value = cluster;
  clusterForm.value = {
    name: cluster.name,
    context: cluster.context ?? "",
    defaultNamespace: cluster.default_namespace ?? "",
  };
  kubeconfigFile.value = null;
  clearKubeconfig.value = false;
  clusterFormError.value = null;
  showClusterForm.value = true;
}

function cancelClusterForm() {
  showClusterForm.value = false;
  editingCluster.value = null;
}

function onKubeconfigSelected(event: Event) {
  const input = event.target as HTMLInputElement;
  if (input.files && input.files.length > 0) {
    kubeconfigFile.value = input.files[0];
    clearKubeconfig.value = false;
  }
}

async function submitClusterForm() {
  if (!clusterForm.value.name.trim()) {
    clusterFormError.value = "Cluster name is required.";
    return;
  }

  clusterFormLoading.value = true;
  clusterFormError.value = null;

  try {
    if (editingCluster.value) {
      await store.updateCluster(
        editingCluster.value.id,
        clusterForm.value.name,
        kubeconfigFile.value ?? undefined,
        clusterForm.value.context || undefined,
        clusterForm.value.defaultNamespace || undefined,
        clearKubeconfig.value,
      );
    } else {
      await store.createCluster(
        clusterForm.value.name,
        kubeconfigFile.value ?? undefined,
        clusterForm.value.context || undefined,
        clusterForm.value.defaultNamespace || undefined,
      );
    }
    showClusterForm.value = false;
    editingCluster.value = null;
  } catch (e: unknown) {
    clusterFormError.value =
      e instanceof Error ? e.message : "Failed to save cluster.";
  } finally {
    clusterFormLoading.value = false;
  }
}

async function confirmDeleteCluster(cluster: RadarCluster) {
  if (!confirm(`Delete cluster "${cluster.name}" and its ignore list?`)) return;
  await store.deleteCluster(cluster.id);
}

// ── Discovery actions ──

async function runScan() {
  importMessage.value = null;
  selected.value.clear();
  selectedEndpoints.value = {};
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

  // Pre-populate import review with best available host/port and credentials
  importReviewItems.value = toImport.map((r) => {
    const creds = r.credentials ?? [];
    // Auto-detect credentials from discovered secrets
    const passwordCred = creds.find((c) =>
      c.key.toLowerCase().includes("password"),
    );
    const usernameCred = creds.find(
      (c) =>
        c.key.toLowerCase().includes("user") &&
        !c.key.toLowerCase().includes("password"),
    );
    const dbNameCred = creds.find(
      (c) =>
        c.key.toLowerCase().includes("database") ||
        c.key.toLowerCase().includes("dbname") ||
        c.key.toLowerCase().includes("db-name"),
    );

    return {
      resource: r,
      endpointIndex: selectedEndpoints.value[r.resource_key] ?? 0,
      host: bestHost(r),
      port: bestPort(r),
      username: usernameCred?.value ?? "",
      password: passwordCred?.value ?? "",
      database_name: dbNameCred?.value ?? "",
      detectedDatabases: null,
      dbDetectLoading: false,
      dbDetectError: null,
    };
  });
  showImportReview.value = true;
}

async function detectDatabases(item: (typeof importReviewItems.value)[0]) {
  if (!item.host || !item.port) return;
  if (item.resource.source_type === "directory" || item.resource.source_type === "redis") return;

  item.dbDetectLoading = true;
  item.dbDetectError = null;
  item.detectedDatabases = null;

  const result = await store.listDatabases(
    item.resource.source_type,
    item.host,
    item.port,
    item.username || undefined,
    item.password || undefined,
  );

  item.dbDetectLoading = false;
  if (result.error) {
    item.dbDetectError = result.error;
    item.detectedDatabases = [];
  } else {
    item.detectedDatabases = result.databases;
    // Auto-select first if database_name is empty
    if (!item.database_name && result.databases.length > 0) {
      item.database_name = result.databases[0];
    }
  }
}

function bestHost(r: DiscoveredResource): string {
  const ep = activeEndpoint(r);
  return ep.external_host || ep.host || r.host || "";
}

function bestPort(r: DiscoveredResource): number | null {
  const ep = activeEndpoint(r);
  if (ep.external_host) return ep.external_port ?? ep.port;
  return ep.port ?? r.port;
}

function cancelImportReview() {
  showImportReview.value = false;
  importReviewItems.value = [];
}

function switchReviewEndpoint(
  item: (typeof importReviewItems.value)[0],
  idx: number,
) {
  item.endpointIndex = idx;
  const ep = item.resource.endpoints?.[idx];
  if (ep) {
    item.host = ep.external_host || ep.host || "";
    item.port = ep.external_host
      ? (ep.external_port ?? ep.port)
      : (ep.port ?? null);
  }
}

async function confirmImport() {
  importLoading.value = true;
  importMessage.value = null;
  showImportReview.value = false;

  const resources = importReviewItems.value.map((item) => item.resource);
  const overrides: ImportOverride[] = importReviewItems.value.map((item) => ({
    resource_key: item.resource.resource_key,
    host: item.host,
    port: item.port,
    username: item.username || undefined,
    password: item.password || undefined,
    database_name: item.database_name || undefined,
  }));

  try {
    const result = await store.importResources(resources, overrides);
    importMessage.value = { text: result.message, ok: true };
    selected.value.clear();
  } catch {
    importMessage.value = {
      text: "Failed to import selected resources.",
      ok: false,
    };
  } finally {
    importLoading.value = false;
    importReviewItems.value = [];
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

// ── Init ──
onMounted(() => {
  store.fetchClusters();
});
</script>

<template>
  <div class="p-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-text-primary">Radar</h1>
        <p class="mt-1 text-text-muted">
          Discover databases and volumes in your Kubernetes clusters.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button
          v-if="store.activeClusterId"
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

    <!-- ── Cluster Selector Bar ── -->
    <div class="mt-6 rounded-xl border border-border bg-surface p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-text-primary">Clusters</h2>
        <button
          class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors"
          @click="openAddCluster"
        >
          <Plus class="h-3.5 w-3.5" />
          Add Cluster
        </button>
      </div>

      <!-- Empty state -->
      <div
        v-if="store.clusters.length === 0 && !store.clustersLoading"
        class="rounded-lg border border-dashed border-border p-8 text-center"
      >
        <Server class="mx-auto h-10 w-10 text-text-muted" />
        <p class="mt-2 text-sm font-medium text-text-primary">
          No clusters configured
        </p>
        <p class="mt-1 text-xs text-text-muted">
          Add a Kubernetes cluster to start discovering databases and volumes.
        </p>
        <button
          class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors"
          @click="openAddCluster"
        >
          <Plus class="h-3.5 w-3.5" />
          Add Your First Cluster
        </button>
      </div>

      <!-- Cluster pills -->
      <div
        v-if="store.clusters.length > 0"
        class="flex flex-wrap items-center gap-2"
      >
        <button
          v-for="cluster in store.clusters"
          :key="cluster.id"
          class="group flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
          :class="
            store.activeClusterId === cluster.id
              ? 'border-primary bg-primary/10 text-primary'
              : 'border-border text-text-muted hover:border-primary/30 hover:bg-surface-raised'
          "
          @click="store.selectCluster(cluster.id)"
        >
          <Server class="h-4 w-4" />
          {{ cluster.name }}
          <span
            v-if="cluster.has_kubeconfig"
            class="rounded bg-success/10 px-1 py-0.5 text-[10px] font-medium text-success"
          >
            kubeconfig
          </span>
          <span
            v-if="cluster.last_scanned_at"
            class="text-[10px] text-text-muted"
          >
            scanned
            {{
              new Date(cluster.last_scanned_at).toLocaleDateString(undefined, {
                month: "short",
                day: "numeric",
              })
            }}
          </span>

          <!-- Edit / Delete on hover -->
          <span
            class="ml-1 flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
          >
            <span
              class="rounded p-0.5 hover:bg-primary/20 cursor-pointer"
              title="Edit"
              @click.stop="openEditCluster(cluster)"
            >
              <Pencil class="h-3 w-3" />
            </span>
            <span
              class="rounded p-0.5 hover:bg-danger/20 text-danger cursor-pointer"
              title="Delete"
              @click.stop="confirmDeleteCluster(cluster)"
            >
              <Trash2 class="h-3 w-3" />
            </span>
          </span>
        </button>
      </div>
    </div>

    <!-- ── Add/Edit Cluster Modal ── -->
    <div
      v-if="showClusterForm"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="cancelClusterForm"
    >
      <div
        class="w-full max-w-lg rounded-xl border border-border bg-surface p-6 shadow-lg"
      >
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-text-primary">
            {{ editingCluster ? "Edit Cluster" : "Add Cluster" }}
          </h3>
          <button
            class="rounded p-1 text-text-muted hover:bg-surface-raised"
            @click="cancelClusterForm"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="space-y-4">
          <!-- Name -->
          <div>
            <label class="mb-1 block text-sm font-medium text-text-primary">
              Cluster Name
            </label>
            <input
              v-model="clusterForm.name"
              type="text"
              placeholder="e.g. production, staging"
              class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>

          <!-- Kubeconfig upload -->
          <div>
            <label class="mb-1 block text-sm font-medium text-text-primary">
              Kubeconfig File
              <span class="font-normal text-text-muted">
                (optional — leave empty for in-cluster auto-detect)
              </span>
            </label>
            <div class="flex items-center gap-2">
              <label
                class="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-border px-4 py-2.5 text-sm text-text-muted hover:border-primary/50 hover:bg-surface-raised transition-colors"
              >
                <Upload class="h-4 w-4" />
                {{
                  kubeconfigFile
                    ? kubeconfigFile.name
                    : "Choose kubeconfig file…"
                }}
                <input
                  type="file"
                  class="hidden"
                  @change="onKubeconfigSelected"
                />
              </label>
              <span
                v-if="editingCluster?.has_kubeconfig && !kubeconfigFile"
                class="flex items-center gap-1 text-xs text-success"
              >
                <CircleCheck class="h-3.5 w-3.5" />
                Uploaded
              </span>
            </div>
            <div
              v-if="editingCluster?.has_kubeconfig && !kubeconfigFile"
              class="mt-1.5"
            >
              <label
                class="flex items-center gap-1.5 text-xs text-text-muted cursor-pointer"
              >
                <input
                  v-model="clearKubeconfig"
                  type="checkbox"
                  class="h-3.5 w-3.5 rounded border-border accent-danger"
                />
                Remove existing kubeconfig (switch to in-cluster)
              </label>
            </div>
          </div>

          <!-- Context -->
          <div>
            <label class="mb-1 block text-sm font-medium text-text-primary">
              Context
              <span class="font-normal text-text-muted">(optional)</span>
            </label>
            <input
              v-model="clusterForm.context"
              type="text"
              placeholder="current-context"
              class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>

          <!-- Default namespace -->
          <div>
            <label class="mb-1 block text-sm font-medium text-text-primary">
              Default Namespace
              <span class="font-normal text-text-muted">(optional)</span>
            </label>
            <input
              v-model="clusterForm.defaultNamespace"
              type="text"
              placeholder="All namespaces"
              class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            />
          </div>

          <!-- Error -->
          <div
            v-if="clusterFormError"
            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
          >
            {{ clusterFormError }}
          </div>

          <!-- Actions -->
          <div class="flex justify-end gap-2 pt-2">
            <button
              class="rounded-lg border border-border px-4 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised transition-colors"
              @click="cancelClusterForm"
            >
              Cancel
            </button>
            <button
              :disabled="clusterFormLoading"
              class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
              @click="submitClusterForm"
            >
              <Loader2 v-if="clusterFormLoading" class="h-4 w-4 animate-spin" />
              {{ editingCluster ? "Save Changes" : "Add Cluster" }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Scan Controls (only when cluster selected) ── -->
    <div
      v-if="store.activeClusterId"
      class="mt-4 rounded-xl border border-border bg-surface p-4"
    >
      <div class="flex items-center gap-3">
        <!-- Namespace override -->
        <div class="flex-1 max-w-xs">
          <input
            v-model="store.namespace"
            type="text"
            placeholder="Namespace filter (all)"
            class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>

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

    <!-- ── Import Review Modal ── -->
    <div
      v-if="showImportReview"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click.self="cancelImportReview"
    >
      <div
        class="w-full max-w-2xl rounded-xl border border-border bg-surface p-6 shadow-lg max-h-[80vh] flex flex-col"
      >
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-semibold text-text-primary">
            Review Import — {{ importReviewItems.length }} Source{{
              importReviewItems.length > 1 ? "s" : ""
            }}
          </h3>
          <button
            class="rounded p-1 text-text-muted hover:bg-surface-raised"
            @click="cancelImportReview"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <p class="text-sm text-text-muted mb-4">
          Choose an endpoint and verify connection details for each source.
          Credentials from Kubernetes Secrets are pre-filled when detected.
        </p>

        <div class="flex-1 overflow-y-auto space-y-4">
          <div
            v-for="item in importReviewItems"
            :key="item.resource.resource_key"
            class="rounded-lg border border-border bg-surface-raised p-4"
          >
            <!-- Header: name + type -->
            <div class="flex items-center gap-2 mb-3">
              <component
                :is="sourceIcon(item.resource.source_type)"
                class="h-4 w-4 text-primary"
              />
              <span class="font-medium text-text-primary text-sm">
                {{ item.resource.name }}
              </span>
              <span class="text-xs text-text-muted">
                {{ sourceTypeLabel(item.resource.source_type) }}
              </span>
              <span class="text-xs text-text-muted font-mono">
                {{ item.resource.namespace }}
              </span>
            </div>

            <!-- Endpoint selector (when multiple) -->
            <div
              v-if="
                item.resource.endpoints && item.resource.endpoints.length > 1
              "
              class="mb-3"
            >
              <label class="block text-xs font-medium text-text-muted mb-1.5">
                Endpoint
              </label>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="(ep, idx) in item.resource.endpoints"
                  :key="idx"
                  class="flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors"
                  :class="
                    item.endpointIndex === idx
                      ? 'border-primary bg-primary/10 text-primary'
                      : 'border-border text-text-muted hover:border-primary/30'
                  "
                  @click="switchReviewEndpoint(item, idx)"
                >
                  <span
                    class="rounded-full px-1.5 py-0.5 text-[10px]"
                    :class="kindBadgeClass(ep.kind)"
                  >
                    {{ ep.kind }}
                  </span>
                  <span class="font-mono">{{ ep.resource_name }}</span>
                  <span
                    v-if="ep.service_type && ep.service_type !== 'ClusterIP'"
                    class="text-[10px] text-info"
                  >
                    {{ ep.service_type }}
                  </span>
                  <span
                    v-if="ep.external_host"
                    class="text-[10px] text-success"
                  >
                    external
                  </span>
                </button>
              </div>
            </div>

            <!-- Host + Port -->
            <div class="grid grid-cols-[1fr_100px] gap-2">
              <div>
                <label class="block text-xs font-medium text-text-muted mb-0.5">
                  Host
                </label>
                <input
                  v-model="item.host"
                  type="text"
                  :placeholder="item.resource.host ?? 'host'"
                  class="w-full rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-mono"
                  :class="{
                    'border-warning/50':
                      item.host.endsWith('.svc.cluster.local'),
                  }"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-text-muted mb-0.5">
                  Port
                </label>
                <input
                  v-model.number="item.port"
                  type="number"
                  :placeholder="String(item.resource.port ?? '')"
                  class="w-full rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-mono"
                />
              </div>
            </div>

            <!-- Credentials (only for databases, not PVCs) -->
            <div
              v-if="item.resource.source_type !== 'directory'"
              class="mt-3 grid grid-cols-2 gap-2"
            >
              <div>
                <label class="block text-xs font-medium text-text-muted mb-0.5">
                  Username
                </label>
                <input
                  v-model="item.username"
                  type="text"
                  placeholder="optional"
                  class="w-full rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-mono"
                />
              </div>
              <div>
                <label class="block text-xs font-medium text-text-muted mb-0.5">
                  Password
                </label>
                <input
                  v-model="item.password"
                  type="password"
                  placeholder="optional"
                  class="w-full rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-mono"
                />
              </div>
            </div>

            <!-- Database selection (not for PVC / Redis) -->
            <div
              v-if="item.resource.source_type !== 'directory' && item.resource.source_type !== 'redis'"
              class="mt-3"
            >
              <div class="flex items-center gap-2 mb-1">
                <label class="block text-xs font-medium text-text-muted">
                  Database
                </label>
                <button
                  :disabled="item.dbDetectLoading || !item.host || !item.port"
                  class="flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-medium transition-colors"
                  :class="item.dbDetectLoading
                    ? 'text-text-muted cursor-wait'
                    : 'text-primary hover:bg-primary/10 cursor-pointer'"
                  @click="detectDatabases(item)"
                >
                  <Loader2 v-if="item.dbDetectLoading" class="h-3 w-3 animate-spin" />
                  <Database v-else class="h-3 w-3" />
                  {{ item.dbDetectLoading ? 'Detecting…' : 'Detect databases' }}
                </button>
              </div>

              <!-- Detected databases list -->
              <div
                v-if="item.detectedDatabases && item.detectedDatabases.length > 0"
                class="flex flex-wrap gap-1.5 mb-1.5"
              >
                <button
                  v-for="db in item.detectedDatabases"
                  :key="db"
                  class="rounded-lg border px-2.5 py-1 text-xs font-mono font-medium transition-colors"
                  :class="item.database_name === db
                    ? 'border-primary bg-primary/10 text-primary'
                    : 'border-border text-text-muted hover:border-primary/30'"
                  @click="item.database_name = db"
                >
                  {{ db }}
                </button>
              </div>

              <!-- Detection error -->
              <p
                v-if="item.dbDetectError"
                class="text-xs text-warning mb-1"
              >
                {{ item.dbDetectError }}
              </p>

              <!-- Manual input (always available as fallback) -->
              <input
                v-model="item.database_name"
                type="text"
                placeholder="Database name (or detect above)"
                class="w-full rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary font-mono"
              />
            </div>

            <!-- Secrets hint -->
            <div
              v-if="
                item.resource.credentials &&
                item.resource.credentials.length > 0
              "
              class="mt-2 flex items-center gap-1.5 text-xs text-text-muted"
            >
              <KeyRound class="h-3 w-3 text-success" />
              Credentials auto-filled from secret
              <span class="font-mono text-text-primary">
                {{ item.resource.credentials[0].secret_name }}
              </span>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-4 border-t border-border mt-4">
          <button
            class="rounded-lg border border-border px-4 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised transition-colors"
            @click="cancelImportReview"
          >
            Cancel
          </button>
          <button
            :disabled="importLoading"
            class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
            @click="confirmImport"
          >
            <Loader2 v-if="importLoading" class="h-4 w-4 animate-spin" />
            <Import v-else class="h-4 w-4" />
            {{
              importLoading
                ? "Importing…"
                : `Import ${importReviewItems.length} Source${importReviewItems.length > 1 ? "s" : ""}`
            }}
          </button>
        </div>
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
        store.activeClusterId &&
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
              <!-- Endpoint toggle (clickable when multiple) -->
              <template
                v-if="resource.endpoints && resource.endpoints.length > 1"
              >
                <button
                  v-for="(ep, idx) in resource.endpoints"
                  :key="idx"
                  class="rounded-full px-2 py-0.5 text-xs font-medium transition-colors cursor-pointer"
                  :class="
                    (selectedEndpoints[resource.resource_key] ?? 0) === idx
                      ? kindBadgeClass(ep.kind)
                      : 'bg-surface-raised text-text-muted hover:opacity-80'
                  "
                  :title="`${ep.kind}: ${ep.resource_name}`"
                  @click.stop="selectedEndpoints[resource.resource_key] = idx"
                >
                  {{ ep.kind }}<template v-if="ep.service_type && ep.kind === 'Service'">·{{ ep.service_type === 'LoadBalancer' ? 'LB' : ep.service_type === 'NodePort' ? 'NP' : ep.service_type === 'ExternalName' ? 'Ext' : 'CIP' }}</template>
                </button>
              </template>
              <span
                v-else
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
              <span
                v-if="
                  activeEndpoint(resource).service_type &&
                  activeEndpoint(resource).service_type !== 'ClusterIP'
                "
                class="rounded-full bg-info/10 px-2 py-0.5 text-xs font-medium text-info"
              >
                {{ activeEndpoint(resource).service_type }}
              </span>
              <span
                v-if="resource.credentials && resource.credentials.length > 0"
                class="flex items-center gap-0.5 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                title="Credentials found in Kubernetes Secrets"
              >
                <KeyRound class="h-3 w-3" />
                Secret
              </span>
            </div>
            <p class="mt-0.5 text-sm text-text-muted truncate">
              <span class="font-mono">{{ resource.namespace }}</span>
              <span v-if="activeEndpoint(resource).host" class="ml-2 font-mono">
                {{ activeEndpoint(resource).host
                }}{{
                  activeEndpoint(resource).port
                    ? `:${activeEndpoint(resource).port}`
                    : ""
                }}
              </span>
              <span v-if="resource.capacity" class="ml-2">
                · {{ resource.capacity }}
              </span>
            </p>
            <p
              v-if="
                activeEndpoint(resource).external_host ||
                activeEndpoint(resource).node_port
              "
              class="mt-0.5 text-xs truncate"
            >
              <span
                v-if="activeEndpoint(resource).external_host"
                class="text-success font-mono"
              >
                External: {{ activeEndpoint(resource).external_host
                }}{{
                  activeEndpoint(resource).external_port
                    ? `:${activeEndpoint(resource).external_port}`
                    : ""
                }}
              </span>
              <span
                v-else-if="activeEndpoint(resource).node_port"
                class="text-info font-mono"
              >
                NodePort: {{ activeEndpoint(resource).node_port }}
              </span>
            </p>
            <p
              v-if="resource.image || activeEndpoint(resource).image"
              class="mt-0.5 text-xs text-text-muted truncate"
            >
              {{ resource.image || activeEndpoint(resource).image }}
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

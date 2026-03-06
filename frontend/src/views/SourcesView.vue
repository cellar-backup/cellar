<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from "vue";
import {
  Database,
  FolderOpen,
  Container,
  CircleCheck,
  CircleX,
  Loader2,
  Trash2,
  Pencil,
  Save,
  ShieldCheck,
  ClipboardList,
  Play,
  Scissors,
  Pin,
  PinOff,
  RotateCcw,
  Download,
  Tag,
  MessageSquare,
  X,
  ChevronRight,
  Archive,
  FileText,
  Square,
  CalendarClock,
  Timer,
  Server,
} from "lucide-vue-next";
import {
  useSourcesStore,
  type QuickAddPayload,
  type Policy,
  type SourceArchive,
} from "@/stores/sources";
import { usePlansStore } from "@/stores/plans";
import { useJobsChannel } from "@/composables/useJobsChannel";
import { useConfirm } from "@/composables/useConfirm";

const DB_TYPES = [
  { value: "postgresql", label: "PostgreSQL", defaultPort: 5432, isDb: true },
  { value: "mysql", label: "MySQL", defaultPort: 3306, isDb: true },
  { value: "mariadb", label: "MariaDB", defaultPort: 3306, isDb: true },
  { value: "mongodb", label: "MongoDB", defaultPort: 27017, isDb: true },
  { value: "redis", label: "Redis", defaultPort: 6379, isDb: true },
] as const;

const FS_TYPES = [
  { value: "directory", label: "Directory", defaultPort: null, isDb: false },
  {
    value: "docker_volume",
    label: "Docker Volume",
    defaultPort: null,
    isDb: false,
  },
] as const;

const ALL_TYPES = [...DB_TYPES, ...FS_TYPES];
const store = useSourcesStore();
const plansStore = usePlansStore();
const { confirm } = useConfirm();
useJobsChannel();
onMounted(() => {
  store.fetchSources();
});

// Live elapsed time ticker
const now = ref(Date.now());
let tickTimer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
  tickTimer = setInterval(() => {
    now.value = Date.now();
  }, 1000);
});
onUnmounted(() => {
  if (tickTimer) clearInterval(tickTimer);
  stopLogPolling();
});

function elapsed(startedAt: string | null) {
  if (!startedAt) return "";
  const ms = now.value - new Date(startedAt).getTime();
  const secs = Math.max(0, Math.round(ms / 1000));
  if (secs < 60) return secs + "s";
  const mins = Math.floor(secs / 60);
  const rem = secs % 60;
  return mins + "m " + rem + "s";
}

function sourceIcon(type: string) {
  if (type === "directory") return FolderOpen;
  if (type === "docker_volume") return Container;
  return Database;
}

function timeAgo(dateStr: string | null) {
  if (!dateStr) return "never";
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return "just now";
  if (mins < 60) return mins + "m ago";
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return hrs + "h ago";
  const days = Math.floor(hrs / 24);
  return days + "d ago";
}

function fmtSize(bytes: number | null) {
  if (!bytes) return "\u2014";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let i = 0;
  let size = bytes;
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024;
    i++;
  }
  return size.toFixed(1) + " " + units[i];
}

function fmtTime(dateStr: string | null) {
  if (!dateStr) return "";
  return new Date(dateStr).toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function fmtRetention(policy: Record<string, number> | null) {
  if (!policy) return "Default";
  const parts: string[] = [];
  if (policy.keep_daily)
    parts.push(
      "1 per day \u00D7 " +
        policy.keep_daily +
        (policy.keep_daily === 1 ? " day" : " days"),
    );
  if (policy.keep_weekly)
    parts.push(
      "1 per week \u00D7 " +
        policy.keep_weekly +
        (policy.keep_weekly === 1 ? " week" : " weeks"),
    );
  if (policy.keep_monthly)
    parts.push(
      "1 per month \u00D7 " +
        policy.keep_monthly +
        (policy.keep_monthly === 1 ? " month" : " months"),
    );
  if (policy.keep_yearly)
    parts.push(
      "1 per year \u00D7 " +
        policy.keep_yearly +
        (policy.keep_yearly === 1 ? " year" : " years"),
    );
  return parts.join(", ") || "Custom";
}

const RETENTION_PRESETS = [
  {
    label: "Minimal",
    desc: "~5 backups kept",
    detail: "Best daily from last 3 days + best weekly from last 2 weeks",
    policy: { keep_daily: 3, keep_weekly: 2 },
  },
  {
    label: "Standard",
    desc: "~17 backups kept",
    detail:
      "Best daily \u00D7 7 days + best weekly \u00D7 4 weeks + best monthly \u00D7 6 months",
    policy: { keep_daily: 7, keep_weekly: 4, keep_monthly: 6 },
  },
  {
    label: "Extended",
    desc: "~36 backups kept",
    detail:
      "Best daily \u00D7 14 days + best weekly \u00D7 8 weeks + best monthly \u00D7 12 months + best yearly \u00D7 2 years",
    policy: {
      keep_daily: 14,
      keep_weekly: 8,
      keep_monthly: 12,
      keep_yearly: 2,
    },
  },
  {
    label: "Archival",
    desc: "~71 backups kept",
    detail:
      "Best daily \u00D7 30 + best weekly \u00D7 12 + best monthly \u00D7 24 + best yearly \u00D7 5",
    policy: {
      keep_daily: 30,
      keep_weekly: 12,
      keep_monthly: 24,
      keep_yearly: 5,
    },
  },
] as const;

// -- Connection test --
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
    testResults.value[sourceId] = { ok: false, detail: "Test failed." };
  } finally {
    testing.value = null;
  }
}

// -- Quick-Add Wizard --
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
  path: "",
});
const selectedType = computed(
  () => ALL_TYPES.find((t) => t.value === form.value.source_type) ?? null,
);
const wizardIsDatabase = computed(() => selectedType.value?.isDb ?? true);

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
    path: "",
  };
}
function selectType(type: string, defaultPort: number | null) {
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

// -- Edit Source Modal --
const showEdit = ref(false);
const editSourceId = ref<string | null>(null);
const editLoading = ref(false);
const editSaving = ref(false);
const editSuccess = ref(false);
const editError = ref("");
const editTestResult = ref<{ ok: boolean; detail: string } | null>(null);
const editForm = ref({
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
const editIsDatabase = computed(() => {
  const t = editForm.value.source_type;
  return t !== "directory" && t !== "docker_volume" && t !== "sqlite";
});

async function openEdit(sourceId: string) {
  showEdit.value = true;
  editSourceId.value = sourceId;
  editLoading.value = true;
  editError.value = "";
  editSuccess.value = false;
  editTestResult.value = null;
  try {
    const source = await store.getSource(sourceId);
    editForm.value = {
      name: source.name ?? "",
      source_type: source.source_type ?? "",
      host: source.host ?? "",
      port: source.port ?? null,
      username: source.username ?? "",
      password: "",
      database_name: source.database_name ?? "",
      path: source.path ?? "",
      notes: source.notes ?? "",
      enabled: source.enabled ?? true,
    };
  } catch {
    showEdit.value = false;
  } finally {
    editLoading.value = false;
  }
}
function closeEdit() {
  showEdit.value = false;
  editSourceId.value = null;
}

async function saveEdit() {
  if (!editSourceId.value) return;
  editSaving.value = true;
  editError.value = "";
  editSuccess.value = false;
  try {
    const payload: Record<string, unknown> = { ...editForm.value };
    if (!payload.password) delete payload.password;
    await store.updateSource(editSourceId.value, payload);
    await store.fetchSources();
    closeEdit();
  } catch (e: unknown) {
    if (e && typeof e === "object" && "response" in e) {
      const resp = (e as { response: { data: unknown } }).response;
      const data = resp.data;
      if (data && typeof data === "object" && "errors" in data) {
        const errors = (data as { errors: Record<string, string[]> }).errors;
        editError.value = Object.values(errors).flat().join(", ");
      } else {
        editError.value =
          typeof data === "string" ? data : "Failed to save changes.";
      }
    } else {
      editError.value = "Failed to save changes.";
    }
  } finally {
    editSaving.value = false;
  }
}

async function editTestConnection() {
  if (!editSourceId.value) return;
  editTestResult.value = null;
  testing.value = editSourceId.value;
  try {
    const result = await store.testConnection(editSourceId.value);
    editTestResult.value = {
      ok: result.status === "ok",
      detail: result.message,
    };
  } catch {
    editTestResult.value = { ok: false, detail: "Test failed." };
  } finally {
    testing.value = null;
  }
}

async function editDeleteSource() {
  if (!editSourceId.value) return;
  const name = editForm.value.name;
  const id = editSourceId.value;
  if (
    !(await confirm({
      title: "Delete Source",
      message: `Delete source \u201c${name}\u201d? This cannot be undone.`,
      confirmLabel: "Delete",
      variant: "danger",
    }))
  )
    return;
  await store.deleteSource(id);
  closeEdit();
}

// == SCHEDULE MODAL (was Policies) ==
const showPolicies = ref(false);
const policiesSourceId = ref<string | null>(null);
const policiesSourceName = ref("");
const policies = ref<Policy[]>([]);
const policiesLoading = ref(false);
const policyActionMsg = ref<{ id: string; text: string; ok: boolean } | null>(
  null,
);
const editingPolicy = ref<string | null>(null);
const policyForm = ref({
  schedule_cron: "",
});
const policySaving = ref(false);

// Friendly schedule builder state
const scheduleMode = ref<"simple" | "cron">("simple");
const scheduleFrequency = ref<"daily" | "weekly" | "monthly">("daily");
const scheduleTime = ref("02:00");
const scheduleDays = ref<number[]>([]); // 0=Sun..6=Sat
const scheduleMonthDay = ref(1);

function buildCronFromSimple(): string {
  const [h, m] = scheduleTime.value.split(":").map(Number);
  if (scheduleFrequency.value === "daily") {
    return `${m} ${h} * * *`;
  }
  if (scheduleFrequency.value === "weekly") {
    const days =
      scheduleDays.value.length > 0 ? scheduleDays.value.sort().join(",") : "1";
    return `${m} ${h} * * ${days}`;
  }
  // monthly
  return `${m} ${h} ${scheduleMonthDay.value} * *`;
}

function parseSimpleFromCron(cron: string) {
  const parts = cron.trim().split(/\s+/);
  if (parts.length !== 5) {
    scheduleMode.value = "cron";
    return;
  }
  const [min, hour, dom, , dow] = parts;
  // Try to detect simple patterns
  if (dom === "*" && dow === "*") {
    scheduleMode.value = "simple";
    scheduleFrequency.value = "daily";
    scheduleTime.value = `${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
    return;
  }
  if (dom === "*" && dow !== "*") {
    scheduleMode.value = "simple";
    scheduleFrequency.value = "weekly";
    scheduleTime.value = `${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
    scheduleDays.value = dow
      .split(",")
      .map(Number)
      .filter((n) => !isNaN(n));
    return;
  }
  if (dom !== "*" && dow === "*") {
    scheduleMode.value = "simple";
    scheduleFrequency.value = "monthly";
    scheduleTime.value = `${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
    scheduleMonthDay.value = parseInt(dom, 10) || 1;
    return;
  }
  // Complex cron, fall back to advanced
  scheduleMode.value = "cron";
}

function describeSchedule(cron: string): string {
  const parts = cron.trim().split(/\s+/);
  if (parts.length !== 5) return cron;
  const [min, hour, dom, , dow] = parts;
  const time = `${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
  const dayNames = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  if (dom === "*" && dow === "*") return `Daily at ${time}`;
  if (dom === "*" && dow !== "*") {
    const days = dow
      .split(",")
      .map((d) => dayNames[parseInt(d, 10)] ?? d)
      .join(", ");
    return `${days} at ${time}`;
  }
  if (dom !== "*" && dow === "*") return `Monthly on day ${dom} at ${time}`;
  return cron;
}

async function openPolicies(sourceId: string, sourceName: string) {
  showPolicies.value = true;
  policiesSourceId.value = sourceId;
  policiesSourceName.value = sourceName;
  policiesLoading.value = true;
  policyActionMsg.value = null;
  editingPolicy.value = null;
  try {
    policies.value = await store.fetchPolicies(sourceId);
  } catch {
    policies.value = [];
  } finally {
    policiesLoading.value = false;
  }
}
function closePolicies() {
  showPolicies.value = false;
  policiesSourceId.value = null;
  editingPolicy.value = null;
}

async function refreshPolicies() {
  if (!policiesSourceId.value) return;
  try {
    policies.value = await store.fetchPolicies(policiesSourceId.value);
  } catch {}
}

async function togglePolicy(policyId: string) {
  const p = policies.value.find((pol) => pol.id === policyId);
  if (p) p.schedule_enabled = !p.schedule_enabled;
  try {
    await store.togglePolicy(policyId);
  } catch {
    if (p) p.schedule_enabled = !p.schedule_enabled;
  }
}

function startEditPolicy(policy: Policy) {
  editingPolicy.value = policy.id;
  policyForm.value = {
    schedule_cron: policy.schedule_cron,
  };
  parseSimpleFromCron(policy.schedule_cron);
}
function cancelEditPolicy() {
  editingPolicy.value = null;
}

async function savePolicy() {
  if (!editingPolicy.value) return;
  policySaving.value = true;
  const cron =
    scheduleMode.value === "simple"
      ? buildCronFromSimple()
      : policyForm.value.schedule_cron;
  try {
    await store.updatePolicy(editingPolicy.value, {
      schedule_cron: cron,
    });
    editingPolicy.value = null;
    await refreshPolicies();
  } catch {
  } finally {
    policySaving.value = false;
  }
}

async function deletePolicy(policyId: string, name: string) {
  if (
    !(await confirm({
      title: "Delete Schedule",
      message: `Delete schedule \u201c${name}\u201d? This cannot be undone.`,
      confirmLabel: "Delete",
      variant: "danger",
    }))
  )
    return;
  await store.deletePolicy(policyId);
  await refreshPolicies();
  await store.fetchSources();
}

// == RETENTION MODAL ==
const showRetention = ref(false);
const retentionSourceId = ref<string | null>(null);
const retentionSourceName = ref("");
const retentionForm = ref<Record<string, number>>({});
const retentionSaving = ref(false);
const retentionSuccess = ref(false);

function openRetention(sourceId: string, sourceName: string) {
  const source = store.sources.find((s) => s.id === sourceId);
  showRetention.value = true;
  retentionSourceId.value = sourceId;
  retentionSourceName.value = sourceName;
  retentionForm.value = {
    ...(source?.retention_policy ?? {
      keep_daily: 7,
      keep_weekly: 4,
      keep_monthly: 6,
    }),
  };
  retentionSaving.value = false;
  retentionSuccess.value = false;
}
function closeRetention() {
  showRetention.value = false;
  retentionSourceId.value = null;
}
function applyRetentionPreset(preset: (typeof RETENTION_PRESETS)[number]) {
  retentionForm.value = { ...preset.policy };
}
async function saveRetention() {
  if (!retentionSourceId.value) return;
  retentionSaving.value = true;
  retentionSuccess.value = false;
  try {
    await store.updateRetention(retentionSourceId.value, retentionForm.value);
    closeRetention();
  } catch {
  } finally {
    retentionSaving.value = false;
  }
}

// == DUMP METHOD (K8s sources) ==
function getSourceDumpMethod(source: {
  extra_config: Record<string, unknown> | null;
}): string | null {
  const k8s = source.extra_config?.kubernetes as
    | Record<string, unknown>
    | undefined;
  return (k8s?.dump_method as string) ?? null;
}
function isK8sSource(source: {
  extra_config: Record<string, unknown> | null;
}): boolean {
  const k8s = source.extra_config?.kubernetes as
    | Record<string, unknown>
    | undefined;
  return !!k8s?.cluster_id;
}
async function toggleDumpMethod(sourceId: string) {
  const source = store.sources.find((s) => s.id === sourceId);
  if (!source) return;
  const current = getSourceDumpMethod(source);
  const next = current === "direct" ? "kubectl" : "direct";
  await store.updateDumpMethod(sourceId, next);
}

// == TIMELINE DRAWER ==
const showTimeline = ref(false);
const timelineSourceId = ref<string | null>(null);
const timelineSourceName = ref("");
const timelineSourceType = ref("");
const timelineArchives = ref<SourceArchive[]>([]);
const timelineLoading = ref(false);
const timelineActionLoading = ref<string | null>(null);
const timelineActionMsg = ref<{ id: string; text: string; ok: boolean } | null>(
  null,
);
const editingArchive = ref<string | null>(null);
const archiveTagInput = ref("");
const archiveNotesInput = ref("");

// Auto-refresh timeline when any job finishes (prune, backup, etc.)
// The plansStore.jobs array gets patched in-place via WebSocket events.
// We watch it deeply so that when a job status flips to terminal, we re-fetch.
watch(
  () => plansStore.jobs.map((j) => j.status),
  async (newStatuses, oldStatuses) => {
    if (!showTimeline.value || !timelineSourceId.value) return;
    // If any status changed to a terminal state, refresh
    const terminal = ["success", "failed", "cancelled"];
    const changed = newStatuses.some(
      (s, i) => terminal.includes(s) && oldStatuses?.[i] !== s,
    );
    if (changed) {
      try {
        timelineArchives.value = await store.fetchSourceArchives(
          timelineSourceId.value,
        );
        // Also refresh source cards (archive counts, last backup time, etc.)
        await store.fetchSources();
      } catch {
        // silent
      }
    }
  },
);

async function openTimeline(
  sourceId: string,
  name: string,
  sourceType: string,
) {
  showTimeline.value = true;
  timelineSourceId.value = sourceId;
  timelineSourceName.value = name;
  timelineSourceType.value = sourceType;
  timelineLoading.value = true;
  timelineActionMsg.value = null;
  editingArchive.value = null;
  try {
    timelineArchives.value = await store.fetchSourceArchives(sourceId);
  } catch {
    timelineArchives.value = [];
  } finally {
    timelineLoading.value = false;
  }
}
function closeTimeline() {
  showTimeline.value = false;
  timelineSourceId.value = null;
  editingArchive.value = null;
}

async function timelineRestore(archiveId: string) {
  if (
    !(await confirm({
      title: "Restore Archive",
      message: "Restore this archive? Existing data may be overwritten.",
      confirmLabel: "Restore",
      variant: "warning",
    }))
  )
    return;
  timelineActionLoading.value = archiveId;
  timelineActionMsg.value = null;
  try {
    const data = await store.restoreArchive(archiveId);
    timelineActionMsg.value = {
      id: archiveId,
      text: data.detail ?? "Restore job queued.",
      ok: true,
    };
  } catch {
    timelineActionMsg.value = {
      id: archiveId,
      text: "Failed to queue restore.",
      ok: false,
    };
  } finally {
    timelineActionLoading.value = null;
  }
}

async function timelineExport(archiveId: string) {
  timelineActionLoading.value = archiveId;
  try {
    await store.downloadArchive(archiveId);
    timelineActionMsg.value = {
      id: archiveId,
      text: "Download started.",
      ok: true,
    };
  } catch {
    timelineActionMsg.value = {
      id: archiveId,
      text: "Failed to download.",
      ok: false,
    };
  } finally {
    timelineActionLoading.value = null;
  }
}

async function timelineDelete(archiveId: string) {
  if (
    !(await confirm({
      title: "Delete Archive",
      message: "Delete this archive permanently? This cannot be undone.",
      confirmLabel: "Delete",
      variant: "danger",
    }))
  )
    return;
  timelineActionLoading.value = archiveId;
  try {
    await store.deleteArchive(archiveId);
    timelineArchives.value = timelineArchives.value.filter(
      (a) => a.id !== archiveId,
    );
    await store.fetchSources();
  } catch {
    timelineActionMsg.value = {
      id: archiveId,
      text: "Failed to delete.",
      ok: false,
    };
  } finally {
    timelineActionLoading.value = null;
  }
}

async function timelineTogglePin(arc: SourceArchive) {
  const prev = arc.keep_forever;
  arc.keep_forever = !prev;
  try {
    await store.toggleKeepForever(arc.id, !prev);
  } catch {
    arc.keep_forever = prev;
  }
}

function startEditArchive(arc: SourceArchive) {
  editingArchive.value = arc.id;
  archiveTagInput.value = (arc.tags ?? []).join(", ");
  archiveNotesInput.value = arc.notes ?? "";
}
function cancelEditArchive() {
  editingArchive.value = null;
}

async function saveArchiveEdit(arc: SourceArchive) {
  const tags = archiveTagInput.value
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);
  try {
    await store.updateArchive(arc.id, {
      tags: tags.length > 0 ? tags : null,
      notes: archiveNotesInput.value || "",
    });
    arc.tags = tags.length > 0 ? tags : null;
    arc.notes = archiveNotesInput.value || "";
    editingArchive.value = null;
  } catch {}
}

// == SOURCE-LEVEL ACTIONS ==
const sourceActionLoading = ref<string | null>(null);
const sourceActionMsg = ref<{
  id: string;
  text: string;
  ok: boolean;
} | null>(null);

async function sourceAction(
  sourceId: string,
  action: "backup" | "prune" | "verify",
) {
  sourceActionLoading.value = sourceId;
  sourceActionMsg.value = null;
  try {
    const pols = await store.fetchPolicies(sourceId);
    const target = pols.find((p) => p.schedule_enabled) ?? pols[0];
    if (!target) {
      sourceActionMsg.value = {
        id: sourceId,
        text: "No backup policy found. Create one first.",
        ok: false,
      };
      return;
    }
    const fn =
      action === "backup"
        ? store.triggerBackup
        : action === "prune"
          ? store.triggerPrune
          : store.triggerVerify;
    const data = await fn(target.id);
    sourceActionMsg.value = {
      id: sourceId,
      text:
        data.detail ??
        action.charAt(0).toUpperCase() + action.slice(1) + " job queued.",
      ok: true,
    };
    setTimeout(() => {
      if (sourceActionMsg.value?.id === sourceId) sourceActionMsg.value = null;
    }, 5000);
  } catch {
    sourceActionMsg.value = {
      id: sourceId,
      text: "Failed to trigger " + action + ".",
      ok: false,
    };
  } finally {
    sourceActionLoading.value = null;
  }
}

// == LOG VIEWER ==
const showLog = ref(false);
const logContent = ref<string | null>(null);
const logLoading = ref(false);
const logJobId = ref<string | null>(null);
const logJobLabel = ref("");
const logJobRunning = ref(false);
const logScrollArea = ref<HTMLElement | null>(null);
let logPollTimer: ReturnType<typeof setInterval> | null = null;

function stopLogPolling() {
  if (logPollTimer) {
    clearInterval(logPollTimer);
    logPollTimer = null;
  }
}

function scrollLogToBottom() {
  nextTick(() => {
    if (logScrollArea.value) {
      logScrollArea.value.scrollTop = logScrollArea.value.scrollHeight;
    }
  });
}

async function fetchLogContent() {
  if (!logJobId.value) return;
  try {
    const result = await plansStore.fetchJobLog(logJobId.value);
    if (result.content !== null) {
      const wasAtBottom =
        logScrollArea.value &&
        logScrollArea.value.scrollHeight -
          logScrollArea.value.scrollTop -
          logScrollArea.value.clientHeight <
          50;
      logContent.value = result.content;
      if (wasAtBottom || logLoading.value) {
        scrollLogToBottom();
      }
    } else if (!logContent.value) {
      logContent.value = null;
    }
  } catch {
    if (!logContent.value) {
      logContent.value = "Failed to load log.";
    }
  }
}

async function openLog(jobId: string, label: string, isRunning: boolean) {
  logJobId.value = jobId;
  logJobLabel.value = label;
  logJobRunning.value = isRunning;
  logContent.value = null;
  logLoading.value = true;
  showLog.value = true;
  stopLogPolling();

  await fetchLogContent();
  logLoading.value = false;

  if (isRunning) {
    logPollTimer = setInterval(async () => {
      await fetchLogContent();
      // Stop polling once job finishes
      const stillRunning = policies.value.some(
        (p) => p.running_job?.id === jobId,
      );
      if (!stillRunning) {
        logJobRunning.value = false;
        stopLogPolling();
        await fetchLogContent();
      }
    }, 2000);
  }
}

function closeLog() {
  showLog.value = false;
  logContent.value = null;
  logJobId.value = null;
  logJobRunning.value = false;
  stopLogPolling();
}

// == CANCEL JOB WITH CONFIRMATION ==
async function cancelJob(jobId: string, jobType: string) {
  if (
    !(await confirm({
      title: "Cancel Job",
      message:
        "Cancel the running " + jobType + " job? This action cannot be undone.",
      confirmLabel: "Cancel Job",
      variant: "warning",
    }))
  )
    return;
  try {
    await plansStore.cancelJob(jobId);
    if (policiesSourceId.value) {
      policies.value = await store.fetchPolicies(policiesSourceId.value);
    }
  } catch {
    /* silent */
  }
}
</script>

<template>
  <div class="p-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-text-primary">Sources</h1>
        <p class="mt-1 text-text-muted">
          Databases and directories you back up. Click a source to browse its
          backup timeline.
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
      Loading sources&hellip;
    </div>

    <!-- Empty state -->
    <div
      v-else-if="store.sources.length === 0"
      class="mt-8 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <Database class="mx-auto h-12 w-12 text-text-muted" />
      <p class="mt-3 text-text-primary font-medium">No sources yet</p>
      <p class="mt-1 text-sm text-text-muted">
        Click &ldquo;Add Source&rdquo; to back up your first database in
        seconds.
      </p>
      <button
        class="mt-4 rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors"
        @click="openWizard"
      >
        + Add Source
      </button>
    </div>

    <!-- Source cards -->
    <TransitionGroup name="list-item" tag="div" v-else class="mt-6 space-y-3">
      <div
        v-for="source in store.sources"
        :key="source.id"
        class="rounded-xl border bg-surface p-4 transition-all duration-300 cursor-pointer group"
        :class="
          source.enabled
            ? 'border-border hover:border-primary/30'
            : 'border-border/50 opacity-60'
        "
        @click="
          openTimeline(source.id, source.display_label, source.source_type)
        "
      >
        <div class="flex items-center justify-between">
          <!-- Left: icon + info -->
          <div class="flex items-center gap-4 min-w-0 flex-1">
            <div
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
              :class="
                source.enabled
                  ? 'bg-primary/10 text-primary'
                  : 'bg-surface-raised text-text-muted'
              "
            >
              <component :is="sourceIcon(source.source_type)" class="h-5 w-5" />
            </div>
            <div class="min-w-0">
              <h3 class="font-medium text-text-primary truncate">
                {{ source.name }}
              </h3>
              <div
                class="mt-0.5 flex items-center gap-3 text-xs text-text-muted"
              >
                <span class="capitalize">{{ source.source_type }}</span>
                <span
                  v-if="source.policy_count"
                  class="flex items-center gap-1"
                >
                  <ClipboardList class="h-3 w-3" />
                  {{ source.policy_count }}
                  {{ source.policy_count === 1 ? "schedule" : "schedules" }}
                </span>
                <span
                  v-if="source.archive_count"
                  class="flex items-center gap-1"
                >
                  <Archive class="h-3 w-3" /> {{ source.archive_count }}
                </span>
                <span v-if="source.last_archive_at"
                  >Last: {{ timeAgo(source.last_archive_at) }}</span
                >
              </div>
            </div>
          </div>

          <!-- Right: actions -->
          <div class="flex items-center gap-2 shrink-0">
            <span
              v-if="testResults[source.id]"
              class="text-xs"
              :class="
                testResults[source.id].ok ? 'text-success' : 'text-danger'
              "
            >
              <CircleCheck
                v-if="testResults[source.id].ok"
                class="inline h-3.5 w-3.5"
              />
              <CircleX v-else class="inline h-3.5 w-3.5" />
            </span>
            <button
              v-if="source.is_database"
              :disabled="testing === source.id"
              class="rounded-lg border border-border px-2.5 py-1 text-xs text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
              title="Test connection"
              @click.stop="testConnection(source.id)"
            >
              <Loader2
                v-if="testing === source.id"
                class="inline h-3 w-3 animate-spin"
              />
              <span v-else>Test</span>
            </button>
            <button
              :disabled="sourceActionLoading === source.id"
              class="flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
              title="Run backup now"
              @click.stop="sourceAction(source.id, 'backup')"
            >
              <Loader2
                v-if="sourceActionLoading === source.id"
                class="h-3 w-3 animate-spin"
              />
              <Play v-else class="h-3 w-3" />
              Backup
            </button>
            <button
              :disabled="sourceActionLoading === source.id"
              class="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
              title="Prune old backups"
              @click.stop="sourceAction(source.id, 'prune')"
            >
              <Scissors class="h-3 w-3" /> Prune
            </button>
            <button
              :disabled="sourceActionLoading === source.id"
              class="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
              title="Verify repository integrity"
              @click.stop="sourceAction(source.id, 'verify')"
            >
              <ShieldCheck class="h-3 w-3" /> Verify
            </button>
            <button
              class="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
              title="Manage backup schedules"
              @click.stop="openPolicies(source.id, source.display_label)"
            >
              <CalendarClock class="h-3.5 w-3.5" /> Schedule
            </button>
            <button
              class="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 text-xs text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
              title="Configure retention policy"
              @click.stop="openRetention(source.id, source.display_label)"
            >
              <Timer class="h-3.5 w-3.5" /> Retention
            </button>
            <button
              v-if="isK8sSource(source)"
              class="flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs transition-colors"
              :class="
                getSourceDumpMethod(source) === 'direct'
                  ? 'border-success/30 bg-success/5 text-success hover:bg-success/10'
                  : 'border-info/30 bg-info/5 text-info hover:bg-info/10'
              "
              :title="
                getSourceDumpMethod(source) === 'direct'
                  ? 'Using direct network dump (click to switch to kubectl)'
                  : 'Using kubectl exec dump (click to switch to direct)'
              "
              @click.stop="toggleDumpMethod(source.id)"
            >
              <Server class="h-3.5 w-3.5" />
              {{
                getSourceDumpMethod(source) === "direct" ? "Direct" : "kubectl"
              }}
            </button>
            <button
              class="rounded-lg p-1.5 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
              title="Edit source"
              @click.stop="openEdit(source.id)"
            >
              <Pencil class="h-3.5 w-3.5" />
            </button>
            <!-- Enable/Disable toggle -->
            <button
              class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none"
              :class="source.enabled ? 'bg-primary' : 'bg-surface-raised'"
              :title="source.enabled ? 'Disable source' : 'Enable source'"
              @click.stop="store.toggleSource(source.id)"
            >
              <span
                class="inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform duration-200 shadow-sm"
                :class="
                  source.enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'
                "
              />
            </button>
            <ChevronRight
              class="h-4 w-4 text-text-muted opacity-0 group-hover:opacity-100 transition-opacity"
            />
          </div>
        </div>
        <!-- Source action feedback -->
        <div
          v-if="sourceActionMsg?.id === source.id"
          class="mt-2 text-xs"
          :class="sourceActionMsg.ok ? 'text-success' : 'text-danger'"
        >
          {{ sourceActionMsg.text }}
        </div>
      </div>
    </TransitionGroup>

    <!-- ===== TIMELINE DRAWER ===== -->
    <Teleport to="body">
      <Transition name="drawer">
        <div
          v-if="showTimeline"
          class="fixed inset-0 z-50 flex justify-end"
          @click.self="closeTimeline"
        >
          <div
            class="absolute inset-0 bg-black/50 backdrop-blur-sm"
            @click="closeTimeline"
          />
          <div
            class="relative w-full max-w-2xl bg-surface border-l border-border shadow-2xl flex flex-col overflow-hidden"
          >
            <!-- Header -->
            <div
              class="flex items-center justify-between border-b border-border px-6 py-4"
            >
              <div class="flex items-center gap-3 min-w-0">
                <div
                  class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                  <component
                    :is="sourceIcon(timelineSourceType)"
                    class="h-4 w-4"
                  />
                </div>
                <div class="min-w-0">
                  <h2 class="text-lg font-semibold text-text-primary truncate">
                    {{ timelineSourceName }}
                  </h2>
                  <p class="text-xs text-text-muted">
                    {{ timelineArchives.length }} archive{{
                      timelineArchives.length !== 1 ? "s" : ""
                    }}
                  </p>
                </div>
              </div>
              <button
                class="rounded-lg p-2 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
                @click="closeTimeline"
              >
                <X class="h-5 w-5" />
              </button>
            </div>

            <!-- Source actions -->
            <div
              class="flex items-center gap-2 border-b border-border px-6 py-3"
            >
              <button
                :disabled="sourceActionLoading === timelineSourceId"
                class="flex items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                @click="sourceAction(timelineSourceId ?? '', 'backup')"
              >
                <Loader2
                  v-if="sourceActionLoading === timelineSourceId"
                  class="h-3 w-3 animate-spin"
                />
                <Play v-else class="h-3 w-3" />
                Backup Now
              </button>
              <button
                :disabled="sourceActionLoading === timelineSourceId"
                class="flex items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
                @click="sourceAction(timelineSourceId ?? '', 'prune')"
              >
                <Scissors class="h-3 w-3" /> Prune
              </button>
              <button
                :disabled="sourceActionLoading === timelineSourceId"
                class="flex items-center gap-1 rounded-lg border border-border px-3 py-1.5 text-xs text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
                @click="sourceAction(timelineSourceId ?? '', 'verify')"
              >
                <ShieldCheck class="h-3 w-3" /> Verify
              </button>
              <div
                v-if="sourceActionMsg?.id === timelineSourceId"
                class="ml-2 text-xs"
                :class="sourceActionMsg.ok ? 'text-success' : 'text-danger'"
              >
                {{ sourceActionMsg.text }}
              </div>
            </div>

            <!-- Timeline content -->
            <div class="flex-1 overflow-y-auto px-6 py-6">
              <div
                v-if="timelineLoading"
                class="flex items-center justify-center py-12"
              >
                <Loader2 class="h-6 w-6 animate-spin text-text-muted" />
              </div>
              <div
                v-else-if="timelineArchives.length === 0"
                class="text-center py-12"
              >
                <Archive class="mx-auto h-10 w-10 text-text-muted" />
                <p class="mt-3 text-text-muted">
                  No backups yet for this source.
                </p>
              </div>

              <!-- Vertical timeline -->
              <div v-else class="relative">
                <div class="absolute left-4 top-2 bottom-2 w-px bg-border" />
                <div
                  v-for="arc in timelineArchives"
                  :key="arc.id"
                  class="relative pl-10 pb-8 last:pb-0 group/item"
                >
                  <!-- Dot -->
                  <div
                    class="absolute left-[10px] top-1.5 h-3 w-3 rounded-full border-2 transition-colors"
                    :class="
                      arc.keep_forever
                        ? 'bg-amber-500 border-amber-500'
                        : 'bg-surface border-primary'
                    "
                  />
                  <!-- Card -->
                  <div
                    class="rounded-lg border border-border bg-surface-raised p-3 transition-all hover:border-primary/20"
                  >
                    <div class="flex items-start justify-between gap-2">
                      <div class="min-w-0">
                        <p class="text-sm font-medium text-text-primary">
                          {{ fmtTime(arc.timestamp) }}
                        </p>
                        <p class="mt-0.5 text-xs text-text-muted">
                          {{ fmtSize(arc.size_original) }}
                          <span v-if="arc.plan_name" class="ml-1"
                            >&middot; {{ arc.plan_name }}</span
                          >
                        </p>
                      </div>
                      <!-- Actions (always visible) -->
                      <div class="flex items-center gap-0.5 shrink-0">
                        <button
                          class="rounded p-1 text-text-muted hover:text-text-primary hover:bg-surface transition-colors"
                          title="Tag / Note"
                          @click="startEditArchive(arc)"
                        >
                          <Tag class="h-3.5 w-3.5" />
                        </button>
                        <button
                          class="rounded p-1 transition-colors"
                          :class="
                            arc.keep_forever
                              ? 'text-amber-500 hover:bg-amber-500/10'
                              : 'text-text-muted hover:text-amber-500 hover:bg-amber-500/10'
                          "
                          :title="arc.keep_forever ? 'Unpin' : 'Pin forever'"
                          @click="timelineTogglePin(arc)"
                        >
                          <Pin v-if="!arc.keep_forever" class="h-3.5 w-3.5" />
                          <PinOff v-else class="h-3.5 w-3.5" />
                        </button>
                        <button
                          class="rounded p-1 text-text-muted hover:text-text-primary hover:bg-surface transition-colors"
                          title="Download"
                          :disabled="timelineActionLoading === arc.id"
                          @click="timelineExport(arc.id)"
                        >
                          <Download class="h-3.5 w-3.5" />
                        </button>
                        <button
                          class="rounded p-1 text-text-muted hover:text-primary hover:bg-primary/10 transition-colors"
                          title="Restore"
                          :disabled="timelineActionLoading === arc.id"
                          @click="timelineRestore(arc.id)"
                        >
                          <RotateCcw class="h-3.5 w-3.5" />
                        </button>
                        <button
                          class="rounded p-1 text-text-muted hover:text-danger hover:bg-danger/10 transition-colors"
                          title="Delete"
                          :disabled="timelineActionLoading === arc.id"
                          @click="timelineDelete(arc.id)"
                        >
                          <Trash2 class="h-3.5 w-3.5" />
                        </button>
                      </div>
                    </div>

                    <!-- Tags & notes -->
                    <div
                      v-if="
                        (arc.tags && arc.tags.length > 0) ||
                        arc.keep_forever ||
                        arc.notes
                      "
                      class="mt-2 flex flex-wrap items-center gap-1.5"
                    >
                      <span
                        v-if="arc.keep_forever"
                        class="inline-flex items-center gap-0.5 rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-amber-500"
                      >
                        <Pin class="h-2.5 w-2.5" /> Pinned
                      </span>
                      <span
                        v-for="tag in arc.tags ?? []"
                        :key="tag"
                        class="inline-flex items-center gap-0.5 rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary"
                      >
                        <Tag class="h-2 w-2" /> {{ tag }}
                      </span>
                      <span
                        v-if="arc.notes"
                        class="text-[11px] text-text-muted italic truncate max-w-[200px]"
                        :title="arc.notes"
                      >
                        <MessageSquare class="inline h-2.5 w-2.5 mr-0.5" />
                        {{ arc.notes }}
                      </span>
                    </div>

                    <!-- Tag/Note editor -->
                    <div
                      v-if="editingArchive === arc.id"
                      class="mt-2 space-y-2 border-t border-border pt-2"
                    >
                      <div>
                        <label class="text-[11px] font-medium text-text-muted"
                          >Tags (comma-separated)</label
                        >
                        <input
                          v-model="archiveTagInput"
                          type="text"
                          placeholder="pre-migration, v2.1"
                          class="mt-0.5 w-full rounded border border-border bg-surface px-2 py-1 text-xs text-text-primary placeholder-text-muted focus:border-primary focus:outline-none"
                        />
                      </div>
                      <div>
                        <label class="text-[11px] font-medium text-text-muted"
                          >Note</label
                        >
                        <input
                          v-model="archiveNotesInput"
                          type="text"
                          placeholder="Optional note"
                          class="mt-0.5 w-full rounded border border-border bg-surface px-2 py-1 text-xs text-text-primary placeholder-text-muted focus:border-primary focus:outline-none"
                        />
                      </div>
                      <div class="flex items-center gap-2">
                        <button
                          class="rounded bg-primary px-2.5 py-1 text-xs font-medium text-white hover:bg-primary/90"
                          @click="saveArchiveEdit(arc)"
                        >
                          Save
                        </button>
                        <button
                          class="text-xs text-text-muted hover:text-text-primary"
                          @click="cancelEditArchive"
                        >
                          Cancel
                        </button>
                      </div>
                    </div>

                    <div
                      v-if="timelineActionMsg?.id === arc.id"
                      class="mt-1.5 text-xs"
                      :class="
                        timelineActionMsg.ok ? 'text-success' : 'text-danger'
                      "
                    >
                      {{ timelineActionMsg.text }}
                    </div>
                    <Loader2
                      v-if="timelineActionLoading === arc.id"
                      class="mt-1 h-3.5 w-3.5 animate-spin text-text-muted"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ===== SCHEDULE MODAL (was Policies) ===== -->
    <Teleport to="body">
      <div
        v-if="showPolicies"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closePolicies"
      >
        <div
          class="w-full max-w-2xl max-h-[85vh] flex flex-col rounded-2xl border border-border bg-surface shadow-xl"
        >
          <div
            class="flex items-center justify-between border-b border-border px-6 py-4"
          >
            <div>
              <h2 class="text-lg font-semibold text-text-primary">
                Backup Schedules
              </h2>
              <p class="text-sm text-text-muted">{{ policiesSourceName }}</p>
            </div>
            <button
              class="rounded-lg p-2 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
              @click="closePolicies"
            >
              <X class="h-5 w-5" />
            </button>
          </div>
          <div class="flex-1 overflow-y-auto px-6 py-4 space-y-3">
            <div
              v-if="policiesLoading"
              class="py-8 text-center text-text-muted"
            >
              <Loader2 class="mx-auto h-6 w-6 animate-spin" />
            </div>
            <div
              v-else-if="policies.length === 0"
              class="py-8 text-center text-text-muted"
            >
              <CalendarClock class="mx-auto h-8 w-8" />
              <p class="mt-2">No schedules for this source.</p>
            </div>

            <div
              v-for="pol in policies"
              :key="pol.id"
              class="rounded-lg border border-border p-4 space-y-3"
            >
              <div class="flex items-center justify-between">
                <div class="min-w-0 flex-1">
                  <h3 class="font-medium text-text-primary truncate text-sm">
                    {{ pol.name }}
                  </h3>
                  <p class="text-xs text-text-muted mt-0.5">
                    {{ describeSchedule(pol.schedule_cron) }}
                    <span class="font-mono text-[10px] opacity-60 ml-1"
                      >({{ pol.schedule_cron }})</span
                    >
                    &middot; {{ pol.engine }}
                  </p>
                </div>
                <button
                  class="relative ml-3 inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none shrink-0"
                  :class="
                    pol.schedule_enabled ? 'bg-primary' : 'bg-surface-raised'
                  "
                  :title="pol.schedule_enabled ? 'Pause' : 'Enable'"
                  @click="togglePolicy(pol.id)"
                >
                  <span
                    class="inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform duration-200 shadow-sm"
                    :class="
                      pol.schedule_enabled
                        ? 'translate-x-[18px]'
                        : 'translate-x-[3px]'
                    "
                  />
                </button>
              </div>

              <!-- Running job progress -->
              <div v-if="pol.running_job" class="space-y-1.5">
                <div class="flex items-center justify-between text-xs">
                  <span class="text-info font-medium capitalize"
                    >{{ pol.running_job.job_type }} in progress&hellip;</span
                  >
                  <span class="text-text-muted tabular-nums"
                    >{{ pol.running_job.progress ?? 0 }}% &middot;
                    {{ elapsed(pol.running_job.started_at) }}</span
                  >
                </div>
                <div
                  class="h-1.5 w-full overflow-hidden rounded-full bg-info/10"
                >
                  <div
                    class="h-full rounded-full bg-info transition-all duration-700 ease-out"
                    :style="{
                      width: Math.max(pol.running_job.progress ?? 0, 2) + '%',
                    }"
                  />
                </div>
                <div class="flex items-center gap-2">
                  <button
                    v-if="pol.running_job.id !== '_pending'"
                    class="flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-medium text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
                    @click="
                      openLog(
                        pol.running_job?.id ?? '',
                        (pol.running_job?.job_type ?? '') +
                          ' \u2014 ' +
                          pol.name,
                        true,
                      )
                    "
                  >
                    <FileText class="h-3 w-3" /> View Log
                  </button>
                  <button
                    v-if="pol.running_job.id !== '_pending'"
                    class="flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-medium text-danger/80 hover:text-danger hover:bg-danger/10 transition-colors"
                    @click="
                      cancelJob(
                        pol.running_job?.id ?? '',
                        pol.running_job?.job_type ?? '',
                      )
                    "
                  >
                    <Square class="h-2.5 w-2.5 fill-current" /> Cancel
                  </button>
                </div>
              </div>

              <!-- Editing schedule -->
              <div
                v-if="editingPolicy === pol.id"
                class="space-y-4 border-t border-border pt-3"
              >
                <!-- Mode toggle -->
                <div class="flex items-center gap-2">
                  <button
                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                      scheduleMode === 'simple'
                        ? 'bg-primary text-white'
                        : 'bg-surface-raised text-text-muted hover:text-text-primary'
                    "
                    @click="scheduleMode = 'simple'"
                  >
                    Simple
                  </button>
                  <button
                    class="rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
                    :class="
                      scheduleMode === 'cron'
                        ? 'bg-primary text-white'
                        : 'bg-surface-raised text-text-muted hover:text-text-primary'
                    "
                    @click="scheduleMode = 'cron'"
                  >
                    Advanced (CRON)
                  </button>
                </div>

                <!-- Simple mode -->
                <div v-if="scheduleMode === 'simple'" class="space-y-3">
                  <!-- Frequency -->
                  <div>
                    <label
                      class="mb-1.5 block text-xs font-medium text-text-muted"
                      >Repeat</label
                    >
                    <div class="flex gap-2">
                      <button
                        v-for="freq in ['daily', 'weekly', 'monthly'] as const"
                        :key="freq"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors capitalize"
                        :class="
                          scheduleFrequency === freq
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-border text-text-muted hover:bg-surface-raised'
                        "
                        @click="scheduleFrequency = freq"
                      >
                        {{ freq }}
                      </button>
                    </div>
                  </div>

                  <!-- Day of week (weekly) -->
                  <div v-if="scheduleFrequency === 'weekly'">
                    <label
                      class="mb-1.5 block text-xs font-medium text-text-muted"
                      >On days</label
                    >
                    <div class="flex gap-1">
                      <button
                        v-for="(day, idx) in [
                          'S',
                          'M',
                          'T',
                          'W',
                          'T',
                          'F',
                          'S',
                        ]"
                        :key="idx"
                        class="h-8 w-8 rounded-full text-xs font-medium transition-colors"
                        :class="
                          scheduleDays.includes(idx)
                            ? 'bg-primary text-white'
                            : 'bg-surface-raised text-text-muted hover:bg-surface-raised hover:text-text-primary'
                        "
                        @click="
                          scheduleDays.includes(idx)
                            ? scheduleDays.splice(scheduleDays.indexOf(idx), 1)
                            : scheduleDays.push(idx)
                        "
                      >
                        {{ day }}
                      </button>
                    </div>
                  </div>

                  <!-- Day of month (monthly) -->
                  <div v-if="scheduleFrequency === 'monthly'">
                    <label
                      class="mb-1.5 block text-xs font-medium text-text-muted"
                      >On day</label
                    >
                    <select
                      v-model.number="scheduleMonthDay"
                      class="rounded-lg border border-border bg-surface-raised px-3 py-1.5 text-sm text-text-primary focus:border-primary focus:outline-none"
                    >
                      <option v-for="d in 28" :key="d" :value="d">
                        {{ d }}
                      </option>
                    </select>
                  </div>

                  <!-- Time picker -->
                  <div>
                    <label
                      class="mb-1.5 block text-xs font-medium text-text-muted"
                      >At time</label
                    >
                    <input
                      v-model="scheduleTime"
                      type="time"
                      class="rounded-lg border border-border bg-surface-raised px-3 py-1.5 text-sm text-text-primary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                  </div>

                  <!-- Preview -->
                  <div
                    class="rounded-lg bg-info/5 border border-info/10 px-3 py-2 text-[11px] text-info leading-relaxed"
                  >
                    <strong>Schedule:</strong>
                    {{ describeSchedule(buildCronFromSimple()) }}
                    <span class="font-mono ml-1 opacity-60"
                      >({{ buildCronFromSimple() }})</span
                    >
                  </div>
                </div>

                <!-- Advanced CRON mode -->
                <div v-if="scheduleMode === 'cron'">
                  <label class="mb-1 block text-xs font-medium text-text-muted"
                    >Cron Expression</label
                  >
                  <input
                    v-model="policyForm.schedule_cron"
                    type="text"
                    placeholder="0 2 * * *"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-1.5 text-sm text-text-primary font-mono focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                  <p class="mt-1 text-[10px] text-text-muted">
                    Format: minute hour day-of-month month day-of-week
                  </p>
                  <div
                    v-if="policyForm.schedule_cron"
                    class="mt-2 rounded-lg bg-info/5 border border-info/10 px-3 py-2 text-[11px] text-info leading-relaxed"
                  >
                    <strong>Preview:</strong>
                    {{ describeSchedule(policyForm.schedule_cron) }}
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <button
                    :disabled="policySaving"
                    class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                    @click="savePolicy"
                  >
                    <Loader2
                      v-if="policySaving"
                      class="inline h-3 w-3 animate-spin mr-1"
                    />
                    Save
                  </button>
                  <button
                    class="text-xs text-text-muted hover:text-text-primary transition-colors"
                    @click="cancelEditPolicy"
                  >
                    Cancel
                  </button>
                </div>
              </div>

              <!-- Policy actions -->
              <div
                v-if="editingPolicy !== pol.id"
                class="flex items-center gap-2 pt-1"
              >
                <div class="ml-auto flex items-center gap-1">
                  <button
                    class="rounded p-1 text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
                    title="Edit schedule"
                    @click="startEditPolicy(pol)"
                  >
                    <Pencil class="h-3.5 w-3.5" />
                  </button>
                  <button
                    class="rounded p-1 text-text-muted hover:text-danger hover:bg-danger/10 transition-colors"
                    title="Delete"
                    @click="deletePolicy(pol.id, pol.name)"
                  >
                    <Trash2 class="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
              <div
                v-if="policyActionMsg?.id === pol.id"
                class="text-xs"
                :class="policyActionMsg.ok ? 'text-success' : 'text-danger'"
              >
                {{ policyActionMsg.text }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ===== RETENTION MODAL ===== -->
    <Teleport to="body">
      <div
        v-if="showRetention"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closeRetention"
      >
        <div
          class="w-full max-w-lg rounded-2xl border border-border bg-surface shadow-xl"
        >
          <div
            class="flex items-center justify-between border-b border-border px-6 py-4"
          >
            <div>
              <h2 class="text-lg font-semibold text-text-primary">
                Retention Policy
              </h2>
              <p class="text-sm text-text-muted">{{ retentionSourceName }}</p>
            </div>
            <button
              class="rounded-lg p-2 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
              @click="closeRetention"
            >
              <X class="h-5 w-5" />
            </button>
          </div>
          <div class="px-6 py-4 space-y-4">
            <p class="text-[11px] text-text-muted leading-relaxed">
              When pruning runs, Cellar keeps the <strong>best backup</strong>
              from each time window below and deletes the rest. For example,
              &ldquo;7 days&rdquo; means keep 1 backup per day for the last 7
              days (7 snapshots from that tier).
            </p>
            <!-- Presets -->
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="preset in RETENTION_PRESETS"
                :key="preset.label"
                class="rounded-lg border border-border px-2.5 py-1.5 text-left hover:bg-surface-raised hover:border-primary/30 transition-colors group/preset"
                :title="preset.detail"
                @click="applyRetentionPreset(preset)"
              >
                <span
                  class="block text-xs font-medium text-text-primary group-hover/preset:text-primary"
                >
                  {{ preset.label }}
                </span>
                <span class="block text-[10px] text-text-muted">
                  {{ preset.desc }}
                </span>
              </button>
            </div>
            <!-- Fields -->
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <span class="text-[11px] text-text-muted w-[140px] shrink-0"
                  >Keep 1 per day for</span
                >
                <input
                  v-model.number="retentionForm.keep_daily"
                  type="number"
                  min="0"
                  class="w-16 rounded border border-border bg-surface-raised px-2 py-1 text-xs text-text-primary text-center focus:border-primary focus:outline-none"
                />
                <span class="text-[11px] text-text-muted">days</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-[11px] text-text-muted w-[140px] shrink-0"
                  >Keep 1 per week for</span
                >
                <input
                  v-model.number="retentionForm.keep_weekly"
                  type="number"
                  min="0"
                  class="w-16 rounded border border-border bg-surface-raised px-2 py-1 text-xs text-text-primary text-center focus:border-primary focus:outline-none"
                />
                <span class="text-[11px] text-text-muted">weeks</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-[11px] text-text-muted w-[140px] shrink-0"
                  >Keep 1 per month for</span
                >
                <input
                  v-model.number="retentionForm.keep_monthly"
                  type="number"
                  min="0"
                  class="w-16 rounded border border-border bg-surface-raised px-2 py-1 text-xs text-text-primary text-center focus:border-primary focus:outline-none"
                />
                <span class="text-[11px] text-text-muted">months</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-[11px] text-text-muted w-[140px] shrink-0"
                  >Keep 1 per year for</span
                >
                <input
                  v-model.number="retentionForm.keep_yearly"
                  type="number"
                  min="0"
                  class="w-16 rounded border border-border bg-surface-raised px-2 py-1 text-xs text-text-primary text-center focus:border-primary focus:outline-none"
                />
                <span class="text-[11px] text-text-muted">years</span>
              </div>
            </div>
            <!-- Summary -->
            <div
              v-if="
                retentionForm.keep_daily ||
                retentionForm.keep_weekly ||
                retentionForm.keep_monthly ||
                retentionForm.keep_yearly
              "
              class="rounded-lg bg-info/5 border border-info/10 px-3 py-2 text-[11px] text-info leading-relaxed"
            >
              <strong>Summary:</strong>
              {{ fmtRetention(retentionForm) }}. Older backups outside these
              windows are automatically deleted when pruning runs.
            </div>
            <!-- Actions -->
            <div class="flex items-center gap-2 pt-1">
              <button
                :disabled="retentionSaving"
                class="rounded-lg bg-primary px-4 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                @click="saveRetention"
              >
                <Loader2
                  v-if="retentionSaving"
                  class="inline h-3 w-3 animate-spin mr-1"
                />
                Save
              </button>
              <button
                class="text-xs text-text-muted hover:text-text-primary transition-colors"
                @click="closeRetention"
              >
                Cancel
              </button>
              <span
                v-if="retentionSuccess"
                class="text-xs text-success ml-auto"
              >
                <CircleCheck class="inline h-3.5 w-3.5 mr-0.5" />
                Saved
              </span>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- ===== EDIT SOURCE MODAL ===== -->
    <Teleport to="body">
      <div
        v-if="showEdit"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closeEdit"
      >
        <div
          class="w-full max-w-lg rounded-2xl border border-border bg-surface p-6 shadow-xl"
        >
          <div v-if="editLoading" class="py-8 text-center text-text-muted">
            <Loader2 class="mx-auto h-8 w-8 animate-spin" />
            <p class="mt-2 text-sm">Loading source&hellip;</p>
          </div>
          <template v-else>
            <div class="flex items-center gap-3 mb-5">
              <div
                class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
              >
                <component
                  :is="sourceIcon(editForm.source_type)"
                  class="h-5 w-5"
                />
              </div>
              <div>
                <h2 class="text-lg font-semibold text-text-primary">
                  Edit Source
                </h2>
                <p class="text-sm text-text-muted">
                  {{
                    ALL_TYPES.find((t) => t.value === editForm.source_type)
                      ?.label ?? editForm.source_type
                  }}
                </p>
              </div>
            </div>

            <form class="space-y-4" @submit.prevent="saveEdit">
              <div>
                <label class="mb-1 block text-sm font-medium text-text-primary"
                  >Name</label
                >
                <input
                  v-model="editForm.name"
                  type="text"
                  placeholder="Auto-generated if blank"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
              </div>

              <div v-if="editIsDatabase" class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                    >Host / IP</label
                  >
                  <input
                    v-model="editForm.host"
                    type="text"
                    placeholder="192.168.1.100"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                    >Port</label
                  >
                  <input
                    v-model.number="editForm.port"
                    type="number"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </div>

              <div v-if="editIsDatabase" class="grid grid-cols-2 gap-3">
                <div>
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                    >Username</label
                  >
                  <input
                    v-model="editForm.username"
                    type="text"
                    placeholder="postgres"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
                <div>
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                    >Password</label
                  >
                  <input
                    v-model="editForm.password"
                    type="password"
                    placeholder="Leave blank to keep current"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </div>

              <div v-if="editIsDatabase && editForm.source_type !== 'redis'">
                <label class="mb-1 block text-sm font-medium text-text-primary"
                  >Database Name</label
                >
                <input
                  v-model="editForm.database_name"
                  type="text"
                  placeholder="myapp_production"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
              </div>

              <div v-if="!editIsDatabase || editForm.source_type === 'sqlite'">
                <label class="mb-1 block text-sm font-medium text-text-primary"
                  >Path</label
                >
                <input
                  v-model="editForm.path"
                  type="text"
                  placeholder="/data/myapp"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
              </div>

              <div>
                <label class="mb-1 block text-sm font-medium text-text-primary"
                  >Notes</label
                >
                <textarea
                  v-model="editForm.notes"
                  rows="2"
                  placeholder="Optional notes"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary resize-y"
                />
              </div>

              <div
                v-if="editError"
                class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
              >
                {{ editError }}
              </div>
              <div
                v-if="editSuccess"
                class="flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2 text-sm text-success"
              >
                <CircleCheck class="h-4 w-4" /> Changes saved.
              </div>

              <div
                class="flex items-center justify-between border-t border-border pt-4"
              >
                <div class="flex items-center gap-2">
                  <button
                    v-if="editIsDatabase"
                    type="button"
                    :disabled="testing === editSourceId"
                    class="rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
                    @click="editTestConnection"
                  >
                    <Loader2
                      v-if="testing === editSourceId"
                      class="inline h-3.5 w-3.5 animate-spin mr-1"
                    />
                    {{
                      testing === editSourceId ? "Testing…" : "Test Connection"
                    }}
                  </button>
                  <span
                    v-if="editTestResult"
                    class="text-xs"
                    :class="editTestResult.ok ? 'text-success' : 'text-danger'"
                  >
                    <CircleCheck
                      v-if="editTestResult.ok"
                      class="inline h-4 w-4"
                    />
                    <CircleX v-else class="inline h-4 w-4" />
                    {{ editTestResult.detail }}
                  </span>
                </div>
                <div class="flex items-center gap-2">
                  <button
                    type="button"
                    class="rounded-lg p-2 text-text-muted hover:bg-danger/10 hover:text-danger transition-colors"
                    title="Delete source"
                    @click="editDeleteSource"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    class="rounded-lg border border-border px-4 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised transition-colors"
                    @click="closeEdit"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    :disabled="editSaving"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                  >
                    <Loader2 v-if="editSaving" class="h-4 w-4 animate-spin" />
                    <Save v-else class="h-4 w-4" />
                    {{ editSaving ? "Saving…" : "Save" }}
                  </button>
                </div>
              </div>
            </form>
          </template>
        </div>
      </div>
    </Teleport>

    <!-- ===== QUICK-ADD WIZARD ===== -->
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
              Select the source type to get started.
            </p>
            <h3
              class="mt-5 mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted"
            >
              Databases
            </h3>
            <div class="grid grid-cols-2 gap-3">
              <button
                v-for="db in DB_TYPES"
                :key="db.value"
                class="flex items-center gap-3 rounded-xl border border-border bg-surface-raised p-4 text-left hover:border-primary/50 transition-colors"
                @click="selectType(db.value, db.defaultPort)"
              >
                <Database class="h-6 w-6 text-primary" />
                <span class="text-sm font-medium text-text-primary">{{
                  db.label
                }}</span>
              </button>
            </div>
            <h3
              class="mt-5 mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted"
            >
              Filesystem
            </h3>
            <div class="grid grid-cols-2 gap-3">
              <button
                v-for="fs in FS_TYPES"
                :key="fs.value"
                class="flex items-center gap-3 rounded-xl border border-border bg-surface-raised p-4 text-left hover:border-primary/50 transition-colors"
                @click="selectType(fs.value, fs.defaultPort)"
              >
                <component
                  :is="sourceIcon(fs.value)"
                  class="h-6 w-6 text-primary"
                />
                <span class="text-sm font-medium text-text-primary">{{
                  fs.label
                }}</span>
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
              {{ selectedType?.label }}
              {{ wizardIsDatabase ? "Connection" : "Details" }}
            </h2>
            <p class="mt-1 text-sm text-text-muted">
              {{
                wizardIsDatabase
                  ? "Enter your database connection details."
                  : "Enter the path to back up."
              }}
            </p>
            <form class="mt-5 space-y-4" @submit.prevent="submitWizard">
              <template v-if="wizardIsDatabase">
                <div class="grid grid-cols-3 gap-3">
                  <div class="col-span-2">
                    <label
                      class="mb-1 block text-sm font-medium text-text-primary"
                      >Host / IP</label
                    >
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
                      >Port</label
                    >
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
                      >Username</label
                    >
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
                      >Password</label
                    >
                    <input
                      v-model="form.password"
                      type="password"
                      class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                  </div>
                </div>
                <div v-if="form.source_type !== 'redis'">
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                    >Database Name</label
                  >
                  <input
                    v-model="form.database_name"
                    type="text"
                    required
                    placeholder="myapp_production"
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </template>
              <template v-else>
                <div>
                  <label
                    class="mb-1 block text-sm font-medium text-text-primary"
                  >
                    {{
                      form.source_type === "docker_volume"
                        ? "Volume / Mount Path"
                        : "Directory Path"
                    }}
                  </label>
                  <input
                    v-model="form.path"
                    type="text"
                    required
                    :placeholder="
                      form.source_type === 'docker_volume'
                        ? '/var/lib/docker/volumes/myapp_data/_data'
                        : '/data/myapp'
                    "
                    class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary placeholder-text-muted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </template>
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
                <span class="text-text-muted">Backup policy:</span>
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

    <!-- ===== LOG VIEWER MODAL ===== -->
    <Teleport to="body">
      <div
        v-if="showLog"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm"
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
          <div ref="logScrollArea" class="flex-1 overflow-auto p-6">
            <div
              v-if="logLoading"
              class="flex items-center gap-2 text-text-muted"
            >
              <Loader2 class="h-4 w-4 animate-spin" />
              Loading log&hellip;
            </div>
            <pre
              v-else-if="logContent"
              class="whitespace-pre-wrap break-words text-xs font-mono leading-relaxed text-text-primary bg-surface-raised rounded-lg p-4 max-h-[60vh] overflow-auto"
              >{{ logContent }}</pre
            >
            <p v-else class="text-sm text-text-muted">
              No log available for this job yet.
            </p>
            <div
              v-if="logJobRunning && !logLoading"
              class="mt-3 flex items-center gap-2 text-xs text-info"
            >
              <Loader2 class="h-3.5 w-3.5 animate-spin" />
              Live &mdash; updating every 2 seconds
            </div>
          </div>
        </div>
      </div>
    </Teleport>
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

.drawer-enter-active,
.drawer-enter-active > div:last-child {
  transition: all 0.3s ease-out;
}
.drawer-leave-active,
.drawer-leave-active > div:last-child {
  transition: all 0.2s ease-in;
}
.drawer-enter-from,
.drawer-leave-to {
  opacity: 0;
}
.drawer-enter-from > div:last-child,
.drawer-leave-to > div:last-child {
  transform: translateX(100%);
}
</style>

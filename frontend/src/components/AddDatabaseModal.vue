<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import { useSourcesStore } from "@/stores/sources";
import { useSettingsStore, type Profile } from "@/stores/settings";
import { useToast } from "@/composables/useToast";

const props = defineProps<{
  open: boolean;
}>();

const emit = defineEmits<{
  close: [];
  added: [];
}>();

const sourcesStore = useSourcesStore();
const settingsStore = useSettingsStore();
const toast = useToast();

const form = ref({
  source_type: "postgresql",
  host: "",
  port: 5432 as number | null,
  username: "",
  password: "",
  database_name: "",
  name: "",
  schedule: "", // will be set to profile cron or custom
});

const loading = ref(false);
const error = ref("");
const selectedScheduleId = ref<string | null>(null);
const customCron = ref("");

const sourceTypes = [
  { value: "postgresql", label: "PostgreSQL", port: 5432 },
  { value: "mysql", label: "MySQL", port: 3306 },
  { value: "mariadb", label: "MariaDB", port: 3306 },
  { value: "mongodb", label: "MongoDB", port: 27017 },
  { value: "sqlite", label: "SQLite", port: null },
  { value: "redis", label: "Redis", port: 6379 },
];

const needsPort = computed(() => form.value.source_type !== "sqlite");
const needsHost = computed(() => form.value.source_type !== "sqlite");

// Schedule profiles
const scheduleProfiles = computed(() =>
  settingsStore.profiles.filter((p) => p.type === "schedule"),
);

onMounted(() => {
  settingsStore.fetchProfiles("schedule");
});

watch(() => props.open, (val) => {
  if (val) {
    settingsStore.fetchProfiles("schedule");
    // Auto-select default schedule if available
    const defaultProfile = scheduleProfiles.value.find((p) => p.is_default);
    if (defaultProfile) {
      selectedScheduleId.value = defaultProfile.id;
    }
  } else {
    error.value = "";
  }
});

watch(() => form.value.source_type, (type) => {
  const st = sourceTypes.find((t) => t.value === type);
  if (st) form.value.port = st.port;
});

// Resolve the schedule cron from selection
const resolvedSchedule = computed(() => {
  if (selectedScheduleId.value === "__custom") return customCron.value;
  if (selectedScheduleId.value) {
    const profile = scheduleProfiles.value.find((p) => p.id === selectedScheduleId.value);
    return profile ? profileCron(profile) : "";
  }
  return "";
});

async function handleSubmit() {
  error.value = "";
  loading.value = true;
  try {
    await sourcesStore.quickAdd({
      source_type: form.value.source_type,
      host: needsHost.value ? form.value.host || undefined : undefined,
      port: needsPort.value ? form.value.port : undefined,
      username: form.value.username || undefined,
      password: form.value.password || undefined,
      database_name: form.value.database_name || undefined,
      name: form.value.name || undefined,
      schedule: resolvedSchedule.value || undefined,
    });
    toast.push({
      title: "Database added",
      desc: form.value.name || form.value.database_name || "New source created",
      type: "success",
    });
    resetForm();
    emit("added");
    emit("close");
  } catch (e: unknown) {
    if (e && typeof e === "object" && "response" in e) {
      const resp = (e as { response: { data?: { message?: string } } }).response;
      error.value = resp?.data?.message || "Failed to add database";
    } else {
      error.value = "Failed to add database";
    }
  } finally {
    loading.value = false;
  }
}

function resetForm() {
  form.value = {
    source_type: "postgresql",
    host: "",
    port: 5432,
    username: "",
    password: "",
    database_name: "",
    name: "",
    schedule: "",
  };
  selectedScheduleId.value = null;
  customCron.value = "";
  error.value = "";
}

function handleClose() {
  emit("close");
}

function profileCron(profile: Profile): string {
  return (profile.config as Record<string, string>)?.cron || "";
}

function cronToHuman(cron: string): string {
  if (!cron) return "";
  const parts = cron.trim().split(/\s+/);
  if (parts.length < 5) return cron;
  const [min, hour, dom, , dow] = parts;
  if (dom === "*" && dow === "*") {
    if (hour === "*") return `Every hour at :${min.padStart(2, "0")}`;
    if (hour.includes("/")) return `Every ${hour.split("/")[1]} hours`;
    if (hour.includes("*") && min.includes("/")) return `Every ${min.split("/")[1]} min`;
    return `Daily at ${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
  }
  return cron;
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-backdrop" @click="handleClose">
      <div class="modal animate-modal-in" @click.stop>
        <div class="modal-header">
          <div>
            <div class="modal-title">Add database</div>
            <div class="modal-sub">Connect a new database to Cellar</div>
          </div>
          <button class="modal-close" @click="handleClose">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        <div class="modal-body">
          <!-- Type -->
          <div class="field">
            <label class="field-label">Database type</label>
            <div class="type-grid">
              <button
                v-for="st in sourceTypes"
                :key="st.value"
                class="type-option"
                :class="{ active: form.source_type === st.value }"
                @click="form.source_type = st.value"
              >
                {{ st.label }}
              </button>
            </div>
          </div>

          <!-- Name -->
          <div class="field">
            <label class="field-label">Display name <span class="field-hint">(optional)</span></label>
            <input
              v-model="form.name"
              class="field-input"
              placeholder="e.g. northwind-prod"
            />
          </div>

          <!-- Host + Port (animated) -->
          <div class="field-row">
            <div v-if="needsHost" class="field" style="flex: 1">
              <label class="field-label">Host</label>
              <input
                v-model="form.host"
                class="field-input"
                placeholder="db.example.com"
              />
            </div>
            <Transition name="slide-port">
              <div v-if="needsPort" class="field port-field">
                <label class="field-label">Port</label>
                <input
                  v-model.number="form.port"
                  class="field-input"
                  type="number"
                />
              </div>
            </Transition>
          </div>

          <!-- Path (for sqlite) -->
          <div v-if="form.source_type === 'sqlite'" class="field">
            <label class="field-label">File path</label>
            <input
              v-model="form.database_name"
              class="field-input"
              placeholder="/path/to/database.sqlite"
            />
          </div>

          <!-- Credentials (not for sqlite) -->
          <Transition name="slide-port">
            <div v-if="form.source_type !== 'sqlite'" class="field-row">
              <div class="field" style="flex: 1">
                <label class="field-label">Username</label>
                <input
                  v-model="form.username"
                  class="field-input"
                  placeholder="cellar_ro"
                />
              </div>
              <div class="field" style="flex: 1">
                <label class="field-label">Password</label>
                <input
                  v-model="form.password"
                  class="field-input"
                  type="password"
                  placeholder="••••••"
                />
              </div>
            </div>
          </Transition>

          <!-- Database name (not for sqlite) -->
          <div v-if="form.source_type !== 'sqlite'" class="field">
            <label class="field-label">Database name</label>
            <input
              v-model="form.database_name"
              class="field-input"
              placeholder="my_database"
            />
          </div>

          <!-- Schedule selection -->
          <div class="field">
            <label class="field-label">Backup schedule</label>
            <div class="schedule-options">
              <button
                v-for="profile in scheduleProfiles"
                :key="profile.id"
                class="schedule-option"
                :class="{ active: selectedScheduleId === profile.id }"
                @click="selectedScheduleId = profile.id"
              >
                <span class="schedule-option-name">{{ profile.name }}</span>
                <span class="schedule-option-cron">{{ cronToHuman(profileCron(profile)) }}</span>
              </button>
              <button
                class="schedule-option"
                :class="{ active: selectedScheduleId === '__custom' }"
                @click="selectedScheduleId = '__custom'"
              >
                <span class="schedule-option-name">Custom</span>
                <span class="schedule-option-cron">write your own cron</span>
              </button>
            </div>
            <!-- Custom cron input -->
            <Transition name="slide-port">
              <div v-if="selectedScheduleId === '__custom'" class="custom-cron">
                <input
                  v-model="customCron"
                  class="field-input"
                  placeholder="0 */3 * * *"
                />
              </div>
            </Transition>
            <div v-if="!scheduleProfiles.length && selectedScheduleId !== '__custom'" class="no-schedules">
              No schedule profiles yet. Select "Custom" or create profiles in Settings.
            </div>
          </div>

          <!-- Error -->
          <div v-if="error" class="form-error">{{ error }}</div>
        </div>

        <div class="modal-footer">
          <div class="modal-footer-meta">
            A backup plan will be auto-created
          </div>
          <div class="modal-footer-actions">
            <button class="btn btn-ghost" @click="handleClose">Cancel</button>
            <button class="btn btn-primary" :disabled="loading" @click="handleSubmit">
              <div v-if="loading" class="spinner-sm" />
              {{ loading ? 'Adding…' : 'Add database' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: oklch(0.15 0.02 40 / 0.35);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: grid;
  place-items: center;
  z-index: 50;
  animation: fade-in calc(0.25s * var(--motion-scale, 1) + 0.001s) var(--ease-out);
}
[data-theme="dark"] .modal-backdrop { background: oklch(0 0 0 / 0.6); }

.modal {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  width: 540px;
  max-width: calc(100vw - 32px);
  max-height: calc(100vh - 60px);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.modal-header {
  padding: 22px 24px 14px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.modal-title {
  font-family: var(--font-display);
  font-size: 22px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.modal-sub {
  font-size: 13px;
  color: var(--color-text-muted);
  margin-top: 4px;
}
.modal-close {
  color: var(--color-text-faint);
  padding: 4px;
  border-radius: 4px;
}
.modal-close:hover { color: var(--color-text-primary); }

.modal-body {
  padding: 8px 24px 20px;
  overflow-y: auto;
}

.field {
  margin-bottom: 16px;
}
.field-hint {
  color: var(--color-text-faint);
  text-transform: none;
  letter-spacing: 0;
}
.field-row {
  display: flex;
  gap: 12px;
}
.port-field {
  width: 90px;
  flex-shrink: 0;
}

.type-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.type-option {
  padding: 6px 12px;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  font-size: 12.5px;
  color: var(--color-text-muted);
  background: var(--color-surface);
  transition: all var(--duration-fast) var(--ease-out);
}
.type-option:hover {
  border-color: var(--color-border-strong);
  color: var(--color-text-primary);
}
.type-option.active {
  border-color: var(--color-wine);
  background: var(--color-wine-soft);
  color: var(--color-wine);
}

/* Schedule options */
.schedule-options {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}
.schedule-option {
  padding: 8px 12px;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  text-align: left;
  transition: all var(--duration-fast) var(--ease-out);
}
.schedule-option:hover {
  border-color: var(--color-border-strong);
}
.schedule-option.active {
  border-color: var(--color-wine);
  background: var(--color-wine-soft);
}
.schedule-option-name {
  display: block;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.schedule-option.active .schedule-option-name {
  color: var(--color-wine);
}
.schedule-option-cron {
  display: block;
  font-family: var(--font-mono);
  font-size: 10.5px;
  color: var(--color-text-faint);
  margin-top: 2px;
}
.custom-cron {
  margin-top: 8px;
}
.no-schedules {
  font-size: 12px;
  color: var(--color-text-faint);
  font-style: italic;
}

.form-error {
  padding: 10px 12px;
  border-radius: 8px;
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: 13px;
  border: 1px solid color-mix(in oklch, var(--color-danger) 20%, var(--color-border));
  margin-top: 8px;
}

.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--color-surface-raised);
}
.modal-footer-meta {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
}
.modal-footer-actions {
  display: flex;
  gap: 8px;
}

.spinner-sm {
  width: 12px;
  height: 12px;
  border: 2px solid oklch(1 0 0 / 0.3);
  border-top-color: oklch(1 0 0 / 0.9);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

/* Animated port/field transitions */
.slide-port-enter-active {
  transition: all 0.3s var(--ease-spring);
  overflow: hidden;
}
.slide-port-leave-active {
  transition: all 0.2s var(--ease-out);
  overflow: hidden;
}
.slide-port-enter-from {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}
.slide-port-enter-to {
  opacity: 1;
  max-height: 100px;
  transform: translateY(0);
}
.slide-port-leave-from {
  opacity: 1;
  max-height: 100px;
}
.slide-port-leave-to {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}
</style>

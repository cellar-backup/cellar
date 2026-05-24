<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import { useSourcesStore, type Source } from "@/stores/sources";
import { useSettingsStore, type Profile } from "@/stores/settings";
import { useToast } from "@/composables/useToast";
import { useConfirm } from "@/composables/useConfirm";

const props = defineProps<{
  source: Source | null;
}>();

const emit = defineEmits<{
  close: [];
  saved: [];
  deleted: [];
}>();

const sourcesStore = useSourcesStore();
const settingsStore = useSettingsStore();
const toast = useToast();
const { confirm } = useConfirm();

const form = ref({
  name: "",
  notes: "",
  enabled: true,
});

const selectedRetentionId = ref<string | null>(null);
const customRetention = ref({
  keep_daily: 7,
  keep_weekly: 4,
  keep_monthly: 6,
  keep_yearly: 0,
});

const selectedScheduleId = ref<string | null>(null);
const customCron = ref("0 */3 * * *");
const currentPlanId = ref<string | null>(null);

const saving = ref(false);
const deleting = ref(false);
const error = ref("");

// Profiles
const retentionProfiles = computed(() =>
  settingsStore.profiles.filter((p) => p.type === "retention"),
);
const scheduleProfiles = computed(() =>
  settingsStore.profiles.filter((p) => p.type === "schedule"),
);

onMounted(() => {
  settingsStore.fetchProfiles();
});

function profileCron(profile: Profile): string {
  return (profile.config as Record<string, string>)?.cron || "";
}

function cronToHuman(cron: string): string {
  if (!cron) return "—";
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

watch(() => props.source, (source) => {
  if (source) {
    form.value.name = source.display_label || source.name;
    form.value.notes = source.notes || "";
    form.value.enabled = source.enabled;

    const rp = source.retention_policy || {};
    customRetention.value = {
      keep_daily: rp.keep_daily ?? 7,
      keep_weekly: rp.keep_weekly ?? 4,
      keep_monthly: rp.keep_monthly ?? 6,
      keep_yearly: rp.keep_yearly ?? 0,
    };

    // Try to match current retention to an existing profile
    const match = retentionProfiles.value.find((p) => {
      const cfg = p.config as Record<string, number>;
      return cfg.keep_daily === customRetention.value.keep_daily &&
        cfg.keep_weekly === customRetention.value.keep_weekly &&
        cfg.keep_monthly === customRetention.value.keep_monthly &&
        cfg.keep_yearly === customRetention.value.keep_yearly;
    });
    selectedRetentionId.value = match ? match.id : "__custom";

    // Load current backup plan schedule
    sourcesStore.fetchPolicies(source.id).then((policies) => {
      if (policies.length > 0) {
        currentPlanId.value = policies[0].id;
        const currentCron = policies[0].schedule_cron;
        customCron.value = currentCron;

        // Match to a schedule profile
        const schedMatch = scheduleProfiles.value.find((p) =>
          profileCron(p) === currentCron,
        );
        selectedScheduleId.value = schedMatch ? schedMatch.id : "__custom";
      }
    });

    settingsStore.fetchProfiles();
  }
}, { immediate: true });

function profileRetention(profile: Profile): string {
  const cfg = profile.config as Record<string, number>;
  const parts: string[] = [];
  if (cfg.keep_daily) parts.push(`${cfg.keep_daily}d`);
  if (cfg.keep_weekly) parts.push(`${cfg.keep_weekly}w`);
  if (cfg.keep_monthly) parts.push(`${cfg.keep_monthly}m`);
  if (cfg.keep_yearly) parts.push(`${cfg.keep_yearly}y`);
  return parts.join(" / ") || "default";
}

// Resolve final schedule cron
const resolvedCron = computed(() => {
  if (selectedScheduleId.value === "__custom") return customCron.value;
  const profile = scheduleProfiles.value.find((p) => p.id === selectedScheduleId.value);
  return profile ? profileCron(profile) : customCron.value;
});

// Resolve final retention values
const resolvedRetention = computed(() => {
  if (selectedRetentionId.value === "__custom") return customRetention.value;
  const profile = retentionProfiles.value.find((p) => p.id === selectedRetentionId.value);
  if (profile) {
    const cfg = profile.config as Record<string, number>;
    return {
      keep_daily: cfg.keep_daily ?? 7,
      keep_weekly: cfg.keep_weekly ?? 4,
      keep_monthly: cfg.keep_monthly ?? 6,
      keep_yearly: cfg.keep_yearly ?? 0,
    };
  }
  return customRetention.value;
});

async function handleSave() {
  if (!props.source) return;
  saving.value = true;
  error.value = "";
  try {
    await sourcesStore.updateSource(props.source.id, {
      name: form.value.name,
      notes: form.value.notes,
      enabled: form.value.enabled,
    });
    await sourcesStore.updateRetention(props.source.id, resolvedRetention.value);
    // Update backup plan schedule if we have one
    if (currentPlanId.value && resolvedCron.value) {
      await sourcesStore.updatePolicy(currentPlanId.value, {
        schedule_cron: resolvedCron.value,
      });
    }
    toast.push({
      title: "Database updated",
      desc: form.value.name,
      type: "success",
    });
    emit("saved");
    emit("close");
  } catch (e: unknown) {
    if (e && typeof e === "object" && "response" in e) {
      const resp = (e as { response: { data?: { message?: string } } }).response;
      error.value = resp?.data?.message || "Failed to save";
    } else {
      error.value = "Failed to save";
    }
  } finally {
    saving.value = false;
  }
}

async function handleDelete() {
  if (!props.source) return;
  const name = form.value.name || props.source.display_label;
  const confirmed = await confirm({
    title: "Delete database",
    message: `Delete "${name}"? All backup history and configuration will be permanently removed. This cannot be undone.`,
    confirmLabel: "Delete",
    variant: "danger",
  });
  if (!confirmed) return;
  deleting.value = true;
  error.value = "";
  try {
    await sourcesStore.deleteSource(props.source.id);
    toast.push({ title: "Database deleted", desc: name, type: "success" });
    emit("deleted");
    emit("close");
  } catch {
    error.value = "Failed to delete database";
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="source" class="modal-backdrop" @click="emit('close')">
      <div class="modal animate-modal-in" @click.stop>
        <div class="modal-header">
          <div>
            <div class="modal-title">Database settings</div>
            <div class="modal-sub">{{ source.source_type }} · {{ source.host }}</div>
          </div>
          <button class="modal-close" @click="emit('close')">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        <div class="modal-body">
          <!-- Name -->
          <div class="field">
            <label class="field-label">Display name</label>
            <input v-model="form.name" class="field-input" placeholder="Database name" />
          </div>

          <!-- Notes -->
          <div class="field">
            <label class="field-label">Notes <span class="field-hint">(optional)</span></label>
            <input v-model="form.notes" class="field-input" placeholder="e.g. Primary production database" />
          </div>

          <!-- Enabled toggle -->
          <div class="toggle-row">
            <div>
              <div class="toggle-title">Enabled</div>
              <div class="toggle-desc">Automated backups run when enabled</div>
            </div>
            <div class="toggle-switch" :class="{ on: form.enabled }" @click="form.enabled = !form.enabled" />
          </div>

          <!-- Backup schedule -->
          <div class="section-title">Backup schedule</div>
          <div class="retention-options">
            <button
              v-for="profile in scheduleProfiles"
              :key="profile.id"
              class="retention-option"
              :class="{ active: selectedScheduleId === profile.id }"
              @click="selectedScheduleId = profile.id"
            >
              <span class="retention-option-name">{{ profile.name }}</span>
              <span class="retention-option-detail">{{ cronToHuman(profileCron(profile)) }}</span>
            </button>
            <button
              class="retention-option"
              :class="{ active: selectedScheduleId === '__custom' }"
              @click="selectedScheduleId = '__custom'"
            >
              <span class="retention-option-name">Custom</span>
              <span class="retention-option-detail">write your own cron</span>
            </button>
          </div>

          <!-- Custom cron input (animated) -->
          <Transition name="slide-fields">
            <div v-if="selectedScheduleId === '__custom'" class="custom-cron-wrap">
              <input
                v-model="customCron"
                class="field-input"
                placeholder="0 */3 * * *"
              />
              <div class="cron-hint">{{ cronToHuman(customCron) }}</div>
            </div>
          </Transition>

          <div v-if="!scheduleProfiles.length && selectedScheduleId !== '__custom'" class="no-profiles">
            No schedule profiles yet. Select "Custom" or create profiles in Settings.
          </div>

          <!-- Retention -->
          <div class="section-title">Retention policy</div>
          <div class="retention-options">
            <button
              v-for="profile in retentionProfiles"
              :key="profile.id"
              class="retention-option"
              :class="{ active: selectedRetentionId === profile.id }"
              @click="selectedRetentionId = profile.id"
            >
              <span class="retention-option-name">{{ profile.name }}</span>
              <span class="retention-option-detail">{{ profileRetention(profile) }}</span>
            </button>
            <button
              class="retention-option"
              :class="{ active: selectedRetentionId === '__custom' }"
              @click="selectedRetentionId = '__custom'"
            >
              <span class="retention-option-name">Custom</span>
              <span class="retention-option-detail">set manually</span>
            </button>
          </div>

          <!-- Custom retention inputs (animated) -->
          <Transition name="slide-fields">
            <div v-if="selectedRetentionId === '__custom'" class="retention-grid">
              <div class="retention-field">
                <label class="field-label">Daily</label>
                <input v-model.number="customRetention.keep_daily" type="number" min="0" class="field-input" />
              </div>
              <div class="retention-field">
                <label class="field-label">Weekly</label>
                <input v-model.number="customRetention.keep_weekly" type="number" min="0" class="field-input" />
              </div>
              <div class="retention-field">
                <label class="field-label">Monthly</label>
                <input v-model.number="customRetention.keep_monthly" type="number" min="0" class="field-input" />
              </div>
              <div class="retention-field">
                <label class="field-label">Yearly</label>
                <input v-model.number="customRetention.keep_yearly" type="number" min="0" class="field-input" />
              </div>
            </div>
          </Transition>

          <div v-if="!retentionProfiles.length && selectedRetentionId !== '__custom'" class="no-profiles">
            No retention profiles yet. Select "Custom" or create profiles in Settings.
          </div>

          <div v-if="error" class="form-error">{{ error }}</div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-danger-ghost" :disabled="deleting" @click="handleDelete">
            {{ deleting ? 'Deleting…' : 'Delete database' }}
          </button>
          <div class="modal-footer-actions">
            <button class="btn btn-ghost" @click="emit('close')">Cancel</button>
            <button class="btn btn-primary" :disabled="saving" @click="handleSave">
              {{ saving ? 'Saving…' : 'Save changes' }}
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
  width: 480px;
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
  font-family: var(--font-mono);
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

.toggle-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 0;
  border-top: 1px solid var(--color-border);
  margin-bottom: 16px;
}
.toggle-title {
  font-size: 13.5px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.toggle-desc {
  font-size: 12px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

.section-title {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
  margin-bottom: 10px;
}

.retention-options {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}
.retention-option {
  padding: 8px 12px;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  text-align: left;
  transition: all var(--duration-fast) var(--ease-out);
}
.retention-option:hover {
  border-color: var(--color-border-strong);
}
.retention-option.active {
  border-color: var(--color-wine);
  background: var(--color-wine-soft);
}
.retention-option-name {
  display: block;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.retention-option.active .retention-option-name {
  color: var(--color-wine);
}
.retention-option-detail {
  display: block;
  font-family: var(--font-mono);
  font-size: 10.5px;
  color: var(--color-text-faint);
  margin-top: 2px;
}

.retention-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-bottom: 16px;
  margin-top: 8px;
}
.retention-field input {
  text-align: center;
}

.no-profiles {
  font-size: 12px;
  color: var(--color-text-faint);
  font-style: italic;
  margin-bottom: 12px;
}

.custom-cron-wrap {
  margin-top: 8px;
  margin-bottom: 16px;
}
.cron-hint {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
  margin-top: 4px;
}

/* Animated field reveal */
.slide-fields-enter-active {
  transition: all 0.3s var(--ease-spring);
  overflow: hidden;
}
.slide-fields-leave-active {
  transition: all 0.2s var(--ease-out);
  overflow: hidden;
}
.slide-fields-enter-from {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}
.slide-fields-enter-to {
  opacity: 1;
  max-height: 120px;
}
.slide-fields-leave-from {
  opacity: 1;
  max-height: 120px;
}
.slide-fields-leave-to {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}

.form-error {
  padding: 10px 12px;
  border-radius: 8px;
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: 13px;
  border: 1px solid color-mix(in oklch, var(--color-danger) 20%, var(--color-border));
}

.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--color-surface-raised);
}
.modal-footer-actions {
  display: flex;
  gap: 8px;
}

.btn-danger-ghost {
  color: var(--color-danger);
  background: transparent;
  border: 1px solid transparent;
  padding: 0 4px;
  font-size: 13px;
  transition: color var(--duration-fast) var(--ease-out),
              background var(--duration-fast) var(--ease-out);
}
.btn-danger-ghost:hover:not(:disabled) {
  background: var(--color-danger-soft);
  border-color: color-mix(in oklch, var(--color-danger) 20%, transparent);
}
.btn-danger-ghost:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>

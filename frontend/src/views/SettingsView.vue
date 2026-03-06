<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import {
  Timer,
  CalendarClock,
  Plus,
  Pencil,
  Trash2,
  Loader2,
  Star,
  X,
} from "lucide-vue-next";
import { useSettingsStore, type Profile } from "@/stores/settings";

const store = useSettingsStore();
const loading = ref(true);
const activeTab = ref<"retention" | "schedule">("retention");

// Profile editing
const showProfileModal = ref(false);
const editingProfileId = ref<string | null>(null);
const profileSaving = ref(false);
const profileForm = ref({
  name: "",
  type: "retention" as "schedule" | "retention",
  is_default: false,
  config: {} as Record<string, unknown>,
});

// Retention profile config fields
const retentionConfig = ref({
  keep_daily: 7,
  keep_weekly: 4,
  keep_monthly: 6,
  keep_yearly: 0,
});

// Schedule profile config fields
const scheduleFrequency = ref<"daily" | "weekly" | "monthly">("daily");
const scheduleTime = ref("02:00");
const scheduleDays = ref<number[]>([]);
const scheduleMonthDay = ref(1);
const scheduleCron = ref("0 2 * * *");
const scheduleMode = ref<"simple" | "cron">("simple");

const retentionProfiles = computed(() =>
  store.profiles.filter((p) => p.type === "retention"),
);
const scheduleProfiles = computed(() =>
  store.profiles.filter((p) => p.type === "schedule"),
);

const RETENTION_PRESETS = [
  {
    label: "Minimal",
    desc: "~5 backups kept",
    policy: { keep_daily: 3, keep_weekly: 2, keep_monthly: 0, keep_yearly: 0 },
  },
  {
    label: "Standard",
    desc: "~17 backups kept",
    policy: { keep_daily: 7, keep_weekly: 4, keep_monthly: 6, keep_yearly: 0 },
  },
  {
    label: "Extended",
    desc: "~36 backups kept",
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
    policy: {
      keep_daily: 30,
      keep_weekly: 12,
      keep_monthly: 24,
      keep_yearly: 5,
    },
  },
];

function fmtRetention(policy: Record<string, unknown> | null) {
  if (!policy) return "Default";
  const p = policy as Record<string, number>;
  const parts: string[] = [];
  if (p.keep_daily) parts.push("1/day \u00D7 " + p.keep_daily + "d");
  if (p.keep_weekly) parts.push("1/week \u00D7 " + p.keep_weekly + "w");
  if (p.keep_monthly) parts.push("1/month \u00D7 " + p.keep_monthly + "m");
  if (p.keep_yearly) parts.push("1/year \u00D7 " + p.keep_yearly + "y");
  return parts.join(", ") || "Custom";
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

function buildCronFromSimple(): string {
  const [h, m] = scheduleTime.value.split(":").map(Number);
  if (scheduleFrequency.value === "daily") return `${m} ${h} * * *`;
  if (scheduleFrequency.value === "weekly") {
    const days =
      scheduleDays.value.length > 0 ? scheduleDays.value.sort().join(",") : "1";
    return `${m} ${h} * * ${days}`;
  }
  return `${m} ${h} ${scheduleMonthDay.value} * *`;
}

onMounted(async () => {
  try {
    await Promise.all([store.fetchProfiles(), store.fetchSettings()]);
  } finally {
    loading.value = false;
  }
});

function openNewProfile(type: "retention" | "schedule") {
  editingProfileId.value = null;
  profileForm.value = { name: "", type, is_default: false, config: {} };
  if (type === "retention") {
    retentionConfig.value = {
      keep_daily: 7,
      keep_weekly: 4,
      keep_monthly: 6,
      keep_yearly: 0,
    };
  } else {
    scheduleFrequency.value = "daily";
    scheduleTime.value = "02:00";
    scheduleDays.value = [];
    scheduleMonthDay.value = 1;
    scheduleCron.value = "0 2 * * *";
    scheduleMode.value = "simple";
  }
  showProfileModal.value = true;
}

function openEditProfile(profile: Profile) {
  editingProfileId.value = profile.id;
  profileForm.value = {
    name: profile.name,
    type: profile.type,
    is_default: profile.is_default,
    config: { ...profile.config },
  };
  if (profile.type === "retention") {
    const c = profile.config as Record<string, number>;
    retentionConfig.value = {
      keep_daily: c.keep_daily ?? 0,
      keep_weekly: c.keep_weekly ?? 0,
      keep_monthly: c.keep_monthly ?? 0,
      keep_yearly: c.keep_yearly ?? 0,
    };
  } else {
    const cron = (profile.config as { cron?: string }).cron ?? "0 2 * * *";
    scheduleCron.value = cron;
    const parts = cron.trim().split(/\s+/);
    if (parts.length === 5) {
      const [min, hour, dom, , dow] = parts;
      if (dom === "*" && dow === "*") {
        scheduleMode.value = "simple";
        scheduleFrequency.value = "daily";
        scheduleTime.value = `${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
      } else if (dom === "*") {
        scheduleMode.value = "simple";
        scheduleFrequency.value = "weekly";
        scheduleTime.value = `${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
        scheduleDays.value = dow
          .split(",")
          .map(Number)
          .filter((n) => !isNaN(n));
      } else if (dow === "*") {
        scheduleMode.value = "simple";
        scheduleFrequency.value = "monthly";
        scheduleTime.value = `${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
        scheduleMonthDay.value = parseInt(dom, 10) || 1;
      } else {
        scheduleMode.value = "cron";
      }
    } else {
      scheduleMode.value = "cron";
    }
  }
  showProfileModal.value = true;
}

function closeProfileModal() {
  showProfileModal.value = false;
  editingProfileId.value = null;
}

async function saveProfile() {
  profileSaving.value = true;
  try {
    const config =
      profileForm.value.type === "retention"
        ? { ...retentionConfig.value }
        : {
            cron:
              scheduleMode.value === "simple"
                ? buildCronFromSimple()
                : scheduleCron.value,
          };

    if (editingProfileId.value) {
      await store.updateProfile(editingProfileId.value, {
        name: profileForm.value.name,
        config,
        is_default: profileForm.value.is_default,
      });
    } else {
      await store.createProfile({
        name: profileForm.value.name,
        type: profileForm.value.type,
        config,
        is_default: profileForm.value.is_default,
      });
    }
    closeProfileModal();
  } finally {
    profileSaving.value = false;
  }
}

async function deleteProfile(id: string, name: string) {
  if (!confirm(`Delete profile "${name}"? This cannot be undone.`)) return;
  await store.deleteProfile(id);
}

async function setDefault(id: string) {
  await store.updateProfile(id, { is_default: true });
}

function profileCron(profile: Profile): string {
  return (profile.config as Record<string, string>)?.cron ?? "";
}
</script>

<template>
  <div class="p-6 max-w-4xl mx-auto">
    <h1 class="text-2xl font-semibold text-text-primary">Settings</h1>
    <p class="mt-1 text-text-muted text-sm">
      Manage reusable profiles and default configurations for new sources.
    </p>

    <!-- Loading -->
    <div v-if="loading" class="mt-12 text-center text-text-muted">
      <Loader2 class="mx-auto h-8 w-8 animate-spin" />
      <p class="mt-2 text-sm">Loading settings&hellip;</p>
    </div>

    <template v-else>
      <!-- Tab bar -->
      <div class="mt-6 flex border-b border-border">
        <button
          class="px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px"
          :class="
            activeTab === 'retention'
              ? 'border-primary text-primary'
              : 'border-transparent text-text-muted hover:text-text-primary'
          "
          @click="activeTab = 'retention'"
        >
          <Timer class="inline h-4 w-4 mr-1.5 -mt-0.5" />
          Retention Profiles
        </button>
        <button
          class="px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px"
          :class="
            activeTab === 'schedule'
              ? 'border-primary text-primary'
              : 'border-transparent text-text-muted hover:text-text-primary'
          "
          @click="activeTab = 'schedule'"
        >
          <CalendarClock class="inline h-4 w-4 mr-1.5 -mt-0.5" />
          Schedule Profiles
        </button>
      </div>

      <!-- Retention Profiles -->
      <div v-if="activeTab === 'retention'" class="mt-4 space-y-3">
        <div class="flex items-center justify-between">
          <p class="text-sm text-text-muted">
            Reusable retention configurations. Set one as default to auto-apply
            to new sources.
          </p>
          <button
            class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors"
            @click="openNewProfile('retention')"
          >
            <Plus class="h-3.5 w-3.5" /> New Profile
          </button>
        </div>

        <div
          v-if="retentionProfiles.length === 0"
          class="py-12 text-center text-text-muted"
        >
          <Timer class="mx-auto h-10 w-10 opacity-40" />
          <p class="mt-2 text-sm">No retention profiles yet.</p>
          <p class="text-xs mt-1">
            Create one to quickly apply retention settings to sources.
          </p>
        </div>

        <div
          v-for="profile in retentionProfiles"
          :key="profile.id"
          class="rounded-lg border border-border p-4 flex items-center justify-between group hover:border-primary/20 transition-colors"
        >
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <h3 class="font-medium text-sm text-text-primary truncate">
                {{ profile.name }}
              </h3>
              <span
                v-if="profile.is_default"
                class="inline-flex items-center gap-0.5 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
              >
                <Star class="h-2.5 w-2.5 fill-current" /> Default
              </span>
            </div>
            <p class="text-xs text-text-muted mt-0.5">
              {{ fmtRetention(profile.config) }}
            </p>
          </div>
          <div
            class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity"
          >
            <button
              v-if="!profile.is_default"
              class="rounded p-1.5 text-text-muted hover:text-primary hover:bg-primary/10 transition-colors"
              title="Set as default"
              @click="setDefault(profile.id)"
            >
              <Star class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1.5 text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
              title="Edit"
              @click="openEditProfile(profile)"
            >
              <Pencil class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1.5 text-text-muted hover:text-danger hover:bg-danger/10 transition-colors"
              title="Delete"
              @click="deleteProfile(profile.id, profile.name)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </div>

      <!-- Schedule Profiles -->
      <div v-if="activeTab === 'schedule'" class="mt-4 space-y-3">
        <div class="flex items-center justify-between">
          <p class="text-sm text-text-muted">
            Reusable schedule configurations. Set one as default for new
            sources.
          </p>
          <button
            class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors"
            @click="openNewProfile('schedule')"
          >
            <Plus class="h-3.5 w-3.5" /> New Profile
          </button>
        </div>

        <div
          v-if="scheduleProfiles.length === 0"
          class="py-12 text-center text-text-muted"
        >
          <CalendarClock class="mx-auto h-10 w-10 opacity-40" />
          <p class="mt-2 text-sm">No schedule profiles yet.</p>
          <p class="text-xs mt-1">
            Create one to quickly apply schedules to backup plans.
          </p>
        </div>

        <div
          v-for="profile in scheduleProfiles"
          :key="profile.id"
          class="rounded-lg border border-border p-4 flex items-center justify-between group hover:border-primary/20 transition-colors"
        >
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2">
              <h3 class="font-medium text-sm text-text-primary truncate">
                {{ profile.name }}
              </h3>
              <span
                v-if="profile.is_default"
                class="inline-flex items-center gap-0.5 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
              >
                <Star class="h-2.5 w-2.5 fill-current" /> Default
              </span>
            </div>
            <p class="text-xs text-text-muted mt-0.5">
              {{ describeSchedule(profileCron(profile)) }}
              <span class="font-mono text-[10px] opacity-60 ml-1"
                >({{ profileCron(profile) }})</span
              >
            </p>
          </div>
          <div
            class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity"
          >
            <button
              v-if="!profile.is_default"
              class="rounded p-1.5 text-text-muted hover:text-primary hover:bg-primary/10 transition-colors"
              title="Set as default"
              @click="setDefault(profile.id)"
            >
              <Star class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1.5 text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
              title="Edit"
              @click="openEditProfile(profile)"
            >
              <Pencil class="h-3.5 w-3.5" />
            </button>
            <button
              class="rounded p-1.5 text-text-muted hover:text-danger hover:bg-danger/10 transition-colors"
              title="Delete"
              @click="deleteProfile(profile.id, profile.name)"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- ===== PROFILE MODAL ===== -->
    <Teleport to="body">
      <div
        v-if="showProfileModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="closeProfileModal"
      >
        <div
          class="w-full max-w-lg rounded-2xl border border-border bg-surface shadow-xl"
        >
          <div
            class="flex items-center justify-between border-b border-border px-6 py-4"
          >
            <h2 class="text-lg font-semibold text-text-primary">
              {{
                editingProfileId
                  ? "Edit Profile"
                  : profileForm.type === "retention"
                    ? "New Retention Profile"
                    : "New Schedule Profile"
              }}
            </h2>
            <button
              class="rounded-lg p-2 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
              @click="closeProfileModal"
            >
              <X class="h-5 w-5" />
            </button>
          </div>
          <div class="px-6 py-4 space-y-4">
            <!-- Name -->
            <div>
              <label class="mb-1 block text-xs font-medium text-text-muted"
                >Profile Name</label
              >
              <input
                v-model="profileForm.name"
                type="text"
                placeholder="e.g. Production Standard"
                class="w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-text-primary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              />
            </div>

            <!-- Default toggle -->
            <label class="flex items-center gap-3 cursor-pointer">
              <button
                class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none"
                :class="
                  profileForm.is_default ? 'bg-primary' : 'bg-surface-raised'
                "
                @click="profileForm.is_default = !profileForm.is_default"
              >
                <span
                  class="inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform duration-200 shadow-sm"
                  :class="
                    profileForm.is_default
                      ? 'translate-x-[18px]'
                      : 'translate-x-[3px]'
                  "
                />
              </button>
              <span class="text-sm text-text-primary"
                >Set as default for new sources</span
              >
            </label>

            <!-- Retention config -->
            <div v-if="profileForm.type === 'retention'" class="space-y-3">
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="preset in RETENTION_PRESETS"
                  :key="preset.label"
                  class="rounded-lg border border-border px-2.5 py-1.5 text-left hover:bg-surface-raised hover:border-primary/30 transition-colors"
                  @click="retentionConfig = { ...preset.policy }"
                >
                  <span class="block text-xs font-medium text-text-primary">{{
                    preset.label
                  }}</span>
                  <span class="block text-[10px] text-text-muted">{{
                    preset.desc
                  }}</span>
                </button>
              </div>
              <div class="space-y-2">
                <div class="flex items-center gap-2">
                  <span class="text-[11px] text-text-muted w-[140px] shrink-0"
                    >Keep 1 per day for</span
                  >
                  <input
                    v-model.number="retentionConfig.keep_daily"
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
                    v-model.number="retentionConfig.keep_weekly"
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
                    v-model.number="retentionConfig.keep_monthly"
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
                    v-model.number="retentionConfig.keep_yearly"
                    type="number"
                    min="0"
                    class="w-16 rounded border border-border bg-surface-raised px-2 py-1 text-xs text-text-primary text-center focus:border-primary focus:outline-none"
                  />
                  <span class="text-[11px] text-text-muted">years</span>
                </div>
              </div>
            </div>

            <!-- Schedule config -->
            <div v-if="profileForm.type === 'schedule'" class="space-y-3">
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

              <div v-if="scheduleMode === 'simple'" class="space-y-3">
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
                <div v-if="scheduleFrequency === 'weekly'">
                  <label
                    class="mb-1.5 block text-xs font-medium text-text-muted"
                    >On days</label
                  >
                  <div class="flex gap-1">
                    <button
                      v-for="(day, idx) in ['S', 'M', 'T', 'W', 'T', 'F', 'S']"
                      :key="idx"
                      class="h-8 w-8 rounded-full text-xs font-medium transition-colors"
                      :class="
                        scheduleDays.includes(idx)
                          ? 'bg-primary text-white'
                          : 'bg-surface-raised text-text-muted hover:text-text-primary'
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

              <div v-if="scheduleMode === 'cron'">
                <label class="mb-1 block text-xs font-medium text-text-muted"
                  >Cron Expression</label
                >
                <input
                  v-model="scheduleCron"
                  type="text"
                  placeholder="0 2 * * *"
                  class="w-full rounded-lg border border-border bg-surface-raised px-3 py-1.5 text-sm text-text-primary font-mono focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                />
                <p class="mt-1 text-[10px] text-text-muted">
                  Format: minute hour day-of-month month day-of-week
                </p>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-2 pt-2 border-t border-border">
              <button
                :disabled="profileSaving || !profileForm.name.trim()"
                class="rounded-lg bg-primary px-4 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                @click="saveProfile"
              >
                <Loader2
                  v-if="profileSaving"
                  class="inline h-3 w-3 animate-spin mr-1"
                />
                {{ editingProfileId ? "Update" : "Create" }}
              </button>
              <button
                class="text-xs text-text-muted hover:text-text-primary transition-colors"
                @click="closeProfileModal"
              >
                Cancel
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

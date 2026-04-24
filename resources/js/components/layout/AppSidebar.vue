<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { useSourcesStore, type Source } from "@/stores/sources";
import { usePlansStore } from "@/stores/plans";
import { useTheme } from "@/composables/useTheme";
import { useActiveDatabase } from "@/composables/useActiveDatabase";
import echo from "@/lib/echo";
import JobLogModal from "@/components/JobLogModal.vue";
import AddDatabaseModal from "@/components/AddDatabaseModal.vue";
import EditDatabaseModal from "@/components/EditDatabaseModal.vue";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const sourcesStore = useSourcesStore();
const plansStore = usePlansStore();
// Theme composable initialized (syncs data-theme to body)
useTheme();

// Active database (shared composable)
const { activeDbId, setActiveDatabase } = useActiveDatabase();

// ── State ──
const showAddDb = ref(false);
const search = ref("");
const collapsed = ref<Record<string, boolean>>(
  (() => {
    try {
      return JSON.parse(localStorage.getItem("cellar-groups-collapsed") || "{}");
    } catch {
      return {};
    }
  })(),
);
const pinned = ref<Set<string>>(
  (() => {
    try {
      return new Set<string>(
        JSON.parse(localStorage.getItem("cellar-pinned") || "[]"),
      );
    } catch {
      return new Set<string>();
    }
  })(),
);

// Persist
watch(
  () => JSON.stringify(collapsed.value),
  (v) => localStorage.setItem("cellar-groups-collapsed", v),
);
watch(pinned, (p) => localStorage.setItem("cellar-pinned", JSON.stringify([...p])), { deep: true });

// ── Data loading ──
onMounted(() => {
  sourcesStore.fetchSources();
  plansStore.fetchJobs();

  echo.channel("jobs").listen(
    ".job.updated",
    (event: Record<string, unknown>) => {
      plansStore.handleJobEvent(event as never);
      // Re-fetch sources when a job completes (imports create new sources)
      const status = event.status as string;
      if (status === "success" || status === "failed") {
        sourcesStore.fetchSources();
      }
    },
  );

  // Also listen for source changes (if backend broadcasts them)
  echo.channel("sources").listen(
    ".source.updated",
    () => { sourcesStore.fetchSources(); },
  );
});

onUnmounted(() => {
  echo.leaveChannel("jobs");
  echo.leaveChannel("sources");
});

// Auto-select first database if none selected
watch(
  () => sourcesStore.sources,
  (sources) => {
    if (!activeDbId.value && sources.length > 0) {
      setActiveDatabase(sources[0].id);
    }
  },
  { immediate: true },
);

// ── Computed: grouping & filtering ──
const query = computed(() => search.value.trim().toLowerCase());

function matchesSearch(source: Source): boolean {
  if (!query.value) return true;
  return (
    source.display_label.toLowerCase().includes(query.value) ||
    source.host.toLowerCase().includes(query.value) ||
    source.source_type.toLowerCase().includes(query.value) ||
    (source.database_name || "").toLowerCase().includes(query.value)
  );
}

const filteredSources = computed(() =>
  sourcesStore.sources.filter(matchesSearch),
);

const pinnedDbs = computed(() =>
  filteredSources.value.filter((s) => pinned.value.has(s.id)),
);

// Group by source_type, sorted alphabetically
const groupedByType = computed(() => {
  const groups: Record<string, Source[]> = {};
  for (const source of filteredSources.value) {
    if (pinned.value.has(source.id)) continue; // shown in pinned section
    const type = source.source_type || "other";
    if (!groups[type]) groups[type] = [];
    groups[type].push(source);
  }
  // Sort databases alphabetically within each group
  for (const sources of Object.values(groups)) {
    sources.sort((a, b) => a.display_label.localeCompare(b.display_label));
  }
  // Sort groups by number of objects (largest first)
  return Object.entries(groups).sort((a, b) => b[1].length - a[1].length);
});

const totalSources = computed(() => sourcesStore.sources.length);

// ── Actions ──
function selectDb(id: string) {
  setActiveDatabase(id);
  // Always navigate to backups view for the selected database
  if (route.path !== "/") {
    router.push("/");
  }
}

function togglePin(id: string) {
  const next = new Set(pinned.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  pinned.value = next;
}

function toggleGroup(type: string) {
  if (query.value) return; // don't collapse during search
  collapsed.value = { ...collapsed.value, [type]: !collapsed.value[type] };
}

function isGroupExpanded(type: string, hasMatches: boolean): boolean {
  if (query.value && hasMatches) return true;
  return !collapsed.value[type];
}

// ── Navigation ──
const navItems = [
  { id: "schedule", label: "Schedule", icon: "clock", to: "/schedule" },
  { id: "jobs", label: "Jobs", icon: "scroll", to: "/jobs" },
  { id: "storage", label: "Storage", icon: "storage", to: "/storage" },
  { id: "radar", label: "Radar", icon: "radar", to: "/radar" },
  { id: "settings", label: "Settings", icon: "settings", to: "/settings" },
];

function isNavActive(to: string) {
  return route.path.startsWith(to);
}

// ── Database settings modal ──
const editingSource = ref<Source | null>(null);

function openSourceSettings(source: Source) {
  editingSource.value = source;
}

// ── Running jobs ──
const runningJobs = computed(() =>
  plansStore.sortedJobs
    .filter((j) => j.status === "running" || j.status === "pending")
    .slice(0, 3),
);

// ── Helpers ──
function formatLastBackup(source: Source): string {
  if (!source.last_archive_at) return "—";
  const diff = (Date.now() - new Date(source.last_archive_at).getTime()) / 60000;
  if (diff < 60) return `${Math.round(diff)}m`;
  if (diff < 60 * 24) return `${Math.round(diff / 60)}h`;
  if (diff < 60 * 24 * 30) return `${Math.round(diff / 60 / 24)}d`;
  return `${Math.round(diff / 60 / 24 / 30)}mo`;
}

function isStale(source: Source): boolean {
  if (!source.enabled || !source.last_archive_at) return false;
  const hoursSince =
    (Date.now() - new Date(source.last_archive_at).getTime()) / 3600000;
  return hoursSince > 12;
}

function typeLabel(type: string): string {
  const labels: Record<string, string> = {
    postgresql: "PostgreSQL",
    mysql: "MySQL",
    mariadb: "MariaDB",
    sqlite: "SQLite",
    mongodb: "MongoDB",
    couchdb: "CouchDB",
    filesystem: "Filesystem",
    other: "Other",
  };
  return labels[type] || type.charAt(0).toUpperCase() + type.slice(1);
}

function statusColor(source: Source): string {
  if (!source.enabled) return "paused";
  if (source.is_reachable === false) return "unreachable";
  return "active";
}

function dotColorClass(source: Source): string {
  const type = source.source_type;
  if (type === "postgresql") return "wine";
  if (type === "mysql" || type === "mariadb") return "oak";
  if (type === "mongodb") return "sage";
  if (type === "couchdb") return "gold";
  if (type === "filesystem") return "gold";
  return "smoke";
}

function handleLogout() {
  auth.logout();
  router.push("/login");
}

// ── Highlight search matches ──
function highlightMatch(text: string): string {
  if (!query.value) return text;
  const idx = text.toLowerCase().indexOf(query.value);
  if (idx === -1) return text;
  return (
    text.slice(0, idx) +
    `<mark>${text.slice(idx, idx + query.value.length)}</mark>` +
    text.slice(idx + query.value.length)
  );
}

// Log modal
const logJobId = ref<string | null>(null);
const logJobLabel = ref("");
function closeJobLog() {
  logJobId.value = null;
  logJobLabel.value = "";
}
</script>

<template>
  <aside class="sidebar">
    <!-- Job log modal (teleported) -->
    <Teleport to="body">
      <JobLogModal :job-id="logJobId" :label="logJobLabel" @close="closeJobLog" />
    </Teleport>

    <!-- Add database modal -->
    <AddDatabaseModal
      :open="showAddDb"
      @close="showAddDb = false"
      @added="sourcesStore.fetchSources()"
    />

    <!-- Edit database modal -->
    <EditDatabaseModal
      :source="editingSource"
      @close="editingSource = null"
      @saved="sourcesStore.fetchSources()"
    />
    <!-- Brand -->
    <div class="sidebar-brand">
      <div class="sidebar-brand-mark">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 17V10a7 7 0 0114 0v7" />
          <path d="M7 17v-7a3 3 0 016 0v7" />
          <path d="M10 17v-4" />
        </svg>
      </div>
      <div class="sidebar-brand-name">
        Cellar
      </div>
      <button class="sidebar-add-btn" title="Add database" @click="showAddDb = true">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M8 3v10M3 8h10" />
        </svg>
      </button>
    </div>

    <!-- Search -->
    <div class="sidebar-search-wrap">
      <span class="sidebar-search-icon">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="7" cy="7" r="5" />
          <path d="M14 14l-3.5-3.5" />
        </svg>
      </span>
      <input
        v-model="search"
        class="sidebar-search"
        :placeholder="`Filter ${totalSources} databases…`"
      />
      <button
        v-if="search"
        class="sidebar-search-clear"
        @click="search = ''"
      >
        <svg width="9" height="9" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M4 4l8 8M12 4l-8 8" />
        </svg>
      </button>
    </div>

    <!-- Scrollable database list -->
    <div class="sidebar-scroll">
      <!-- No matches -->
      <div v-if="query && filteredSources.length === 0" class="empty-search">
        No matches for "{{ search }}"
      </div>

      <!-- Pinned section -->
      <div v-if="pinnedDbs.length > 0" class="pinned-section">
        <div class="env-header">
          <span class="env-header-chevron">
            <svg width="9" height="9" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 5l5 5 5-5" />
            </svg>
          </span>
          <span class="env-header-name">Pinned</span>
          <span class="env-header-count">{{ pinnedDbs.length }}</span>
        </div>
        <div
          v-for="source in pinnedDbs"
          :key="source.id"
          class="db-item"
          :class="{ active: activeDbId === source.id, paused: !source.enabled }"
          :title="`${source.display_label} · ${source.host}`"
          @click="selectDb(source.id)"
        >
          <span class="db-status" :class="[statusColor(source), dotColorClass(source)]" />
          <span class="db-item-name" v-html="highlightMatch(source.display_label)" />
          <span v-if="isStale(source)" class="db-stale-dot" title="No recent backup" />
          <span class="db-meta">{{ formatLastBackup(source) }}</span>
          <button
            class="db-cog"
            title="Database settings"
            @click.stop="openSourceSettings(source)"
          >
            <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="8" cy="8" r="2" />
              <path d="M8 1.5v2M8 12.5v2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M1.5 8h2M12.5 8h2M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" />
            </svg>
          </button>
          <button
            class="db-pin pinned"
            title="Unpin"
            @click.stop="togglePin(source.id)"
          >
            <svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 1.5h4l-.5 4 2.5 2.5h-3.5v4l-1 2-1-2v-4H3l2.5-2.5L6 1.5z" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Grouped by type -->
      <div
        v-for="[type, sources] in groupedByType"
        :key="type"
        class="env-group"
      >
        <div
          class="env-header"
          :class="{
            collapsed: !isGroupExpanded(type, sources.length > 0),
            'has-active': sources.some((s) => s.id === activeDbId),
          }"
          @click="toggleGroup(type)"
        >
          <span class="env-header-chevron">
            <svg width="9" height="9" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 5l5 5 5-5" />
            </svg>
          </span>
          <span class="env-header-name">{{ typeLabel(type) }}</span>
          <span class="env-header-count">{{ sources.length }}</span>
        </div>
        <div class="env-body" :class="{ collapsed: !isGroupExpanded(type, sources.length > 0) }">
          <div class="env-body-inner">
            <div
              v-for="source in sources"
              :key="source.id"
              class="db-item"
              :class="{ active: activeDbId === source.id, paused: !source.enabled }"
              :title="`${source.display_label} · ${source.host}`"
              @click="selectDb(source.id)"
            >
              <span class="db-status" :class="[statusColor(source), dotColorClass(source)]" />
              <span class="db-item-name" v-html="highlightMatch(source.display_label)" />
              <span v-if="isStale(source)" class="db-stale-dot" title="No recent backup" />
              <span class="db-meta">{{ formatLastBackup(source) }}</span>
              <button
                class="db-cog"
                title="Database settings"
                @click.stop="openSourceSettings(source)"
              >
                <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="8" cy="8" r="2" />
                  <path d="M8 1.5v2M8 12.5v2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M1.5 8h2M12.5 8h2M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" />
                </svg>
              </button>
              <button
                class="db-pin"
                :class="{ pinned: pinned.has(source.id) }"
                :title="pinned.has(source.id) ? 'Unpin' : 'Pin to top'"
                @click.stop="togglePin(source.id)"
              >
                <svg width="11" height="11" viewBox="0 0 16 16" :fill="pinned.has(source.id) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M6 1.5h4l-.5 4 2.5 2.5h-3.5v4l-1 2-1-2v-4H3l2.5-2.5L6 1.5z" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Running jobs mini-list -->
    <div v-if="runningJobs.length > 0" class="sidebar-jobs">
      <div class="sidebar-jobs-title">
        <span>Active jobs</span>
        <router-link to="/jobs" class="sidebar-jobs-link">View all</router-link>
      </div>
      <div
        v-for="job in runningJobs"
        :key="job.id"
        class="sidebar-job-item"
      >
        <div class="sidebar-job-dot" :class="job.status" />
        <div class="sidebar-job-info">
          <div class="sidebar-job-name">{{ job.source_name || job.plan_name || 'Job' }}</div>
          <div v-if="job.status === 'running'" class="sidebar-job-progress">
            <div class="sidebar-job-bar">
              <div class="sidebar-job-bar-fill" :style="{ width: `${job.progress}%` }" />
            </div>
            <span class="sidebar-job-pct">{{ job.progress }}%</span>
          </div>
          <div v-else class="sidebar-job-status">Queued</div>
        </div>
      </div>
    </div>

    <!-- Bottom nav rail -->
    <div class="sidebar-bottom">
      <nav class="sidebar-nav">
        <router-link
          v-for="item in navItems"
          :key="item.id"
          :to="item.to"
          class="nav-item"
          :class="{ active: isNavActive(item.to) }"
          @click="setActiveDatabase(null)"
        >
          <!-- Bottle icon -->
          <svg v-if="item.icon === 'bottle'" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6.5 1.5h3v3c0 1 1.5 2 1.5 4v5.5a1.5 1.5 0 01-1.5 1.5h-3A1.5 1.5 0 015 14v-5.5c0-2 1.5-3 1.5-4v-3z" />
            <path d="M5 10h6" />
          </svg>
          <!-- Clock icon -->
          <svg v-else-if="item.icon === 'clock'" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="8" cy="8" r="6" />
            <path d="M8 5v3l2 1.5" />
          </svg>
          <!-- Scroll icon -->
          <svg v-else-if="item.icon === 'scroll'" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2.5 4h11M2.5 8h11M2.5 12h7" />
          </svg>
          <!-- Storage icon -->
          <svg v-else-if="item.icon === 'storage'" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="3" width="12" height="3.5" rx="1" />
            <rect x="2" y="9.5" width="12" height="3.5" rx="1" />
            <circle cx="4.5" cy="4.75" r="0.5" fill="currentColor" />
            <circle cx="4.5" cy="11.25" r="0.5" fill="currentColor" />
          </svg>
          <!-- Radar icon -->
          <svg v-else-if="item.icon === 'radar'" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="8" cy="8" r="6" />
            <circle cx="8" cy="8" r="2" />
            <path d="M8 2v2M8 12v2" />
          </svg>
          <!-- Settings icon -->
          <svg v-else-if="item.icon === 'settings'" width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="8" cy="8" r="2" />
            <path d="M8 1.5v2M8 12.5v2M3.4 3.4l1.4 1.4M11.2 11.2l1.4 1.4M1.5 8h2M12.5 8h2M3.4 12.6l1.4-1.4M11.2 4.8l1.4-1.4" />
          </svg>
          <span>{{ item.label }}</span>
        </router-link>
      </nav>

      <!-- Storage widget -->
      <div class="storage-widget">
        <div class="storage-label">
          <span>Cellar storage</span>
          <span>{{ sourcesStore.sources.length }} dbs</span>
        </div>
      </div>

      <!-- User / Sign out -->
      <div class="sidebar-profile">
        <div class="avatar">{{ auth.user?.charAt(0)?.toUpperCase() || 'U' }}</div>
        <div class="profile-info">
          <div class="profile-name">{{ auth.user || 'User' }}</div>
        </div>
        <button class="profile-logout" title="Sign out" @click="handleLogout">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 2H4a2 2 0 00-2 2v8a2 2 0 002 2h2M11 11l3-3-3-3M6 8h8" />
          </svg>
        </button>
      </div>
    </div>
  </aside>
</template>

<style scoped>
.sidebar {
  width: 280px;
  background: var(--color-surface-raised);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  position: relative;
  z-index: 2;
  height: 100vh;
}

.sidebar-brand {
  padding: 18px 16px 14px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}
.sidebar-brand-mark {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  background: var(--color-wine);
  display: grid;
  place-items: center;
  color: oklch(0.97 0.02 80);
  box-shadow:
    0 0 0 1px color-mix(in oklch, var(--color-wine) 40%, transparent),
    inset 0 1px 0 oklch(1 0 0 / 0.15),
    0 1px 2px oklch(0 0 0 / 0.15);
}
.sidebar-brand-name {
  font-family: var(--font-display);
  font-size: 20px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
  flex: 1;
}
.sidebar-add-btn {
  width: 22px;
  height: 22px;
  border-radius: 6px;
  color: var(--color-text-faint);
  display: grid;
  place-items: center;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  transition: all var(--duration-fast) var(--ease-out);
}
.sidebar-add-btn:hover {
  color: var(--color-wine);
  border-color: var(--color-wine);
  background: var(--color-wine-soft);
}

/* Search */
.sidebar-search-wrap {
  padding: 0 12px 10px;
  position: relative;
  flex-shrink: 0;
  display: flex;
  align-items: center;
}
.sidebar-search {
  width: 100%;
  padding: 6px 28px 6px 28px;
  border-radius: 7px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  font-size: 12.5px;
  outline: none;
  transition: all var(--duration-fast) var(--ease-out);
}
.sidebar-search:focus {
  border-color: var(--color-wine);
  box-shadow: 0 0 0 3px var(--color-wine-soft);
}
.sidebar-search-icon {
  position: absolute;
  left: 22px;
  top: 0;
  bottom: 10px;
  color: var(--color-text-faint);
  pointer-events: none;
  display: flex;
  align-items: center;
}
.sidebar-search-clear {
  position: absolute;
  right: 18px;
  top: 0;
  bottom: 10px;
  color: var(--color-text-faint);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  border-radius: 4px;
}
.sidebar-search-clear:hover {
  color: var(--color-text-primary);
  background: var(--color-border);
}

/* Scrollable middle */
.sidebar-scroll {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 4px 8px 12px;
  scrollbar-gutter: stable;
}

/* Env group */
.env-group {
  margin-bottom: 4px;
}
.env-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 8px;
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-text-faint);
  cursor: pointer;
  user-select: none;
  border-radius: 6px;
  transition: all var(--duration-fast) var(--ease-out);
}
.env-header:hover {
  background: var(--color-border);
  color: var(--color-text-muted);
}
.env-header-chevron {
  display: grid;
  place-items: center;
  width: 12px;
  height: 12px;
  transition: transform var(--duration-DEFAULT) var(--ease-spring);
}
.env-header.collapsed .env-header-chevron {
  transform: rotate(-90deg);
}
.env-header-name {
  flex: 1;
}
.env-header-count {
  font-size: 10px;
  letter-spacing: 0.04em;
  text-transform: none;
  color: var(--color-text-faint);
  background: var(--color-background);
  padding: 1px 6px;
  border-radius: 999px;
  border: 1px solid var(--color-border);
}
.env-header.has-active .env-header-name {
  color: var(--color-text-muted);
}

.env-body {
  overflow: hidden;
  transition: grid-template-rows calc(0.4s * var(--motion-scale, 1) + 0.001s) var(--ease-spring);
  display: grid;
  grid-template-rows: 1fr;
}
.env-body.collapsed {
  grid-template-rows: 0fr;
}
.env-body-inner {
  min-height: 0;
  overflow: hidden;
  padding: 2px 0 6px;
}

/* DB item */
.db-item {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 6px 8px 6px 12px;
  border-radius: 6px;
  cursor: pointer;
  position: relative;
  transition: background var(--duration-fast) var(--ease-out);
  margin-bottom: 1px;
  font-size: 12.5px;
}
.db-item:hover {
  background: var(--color-border);
}
.db-item:hover .db-pin,
.db-item:hover .db-cog {
  opacity: 0.5;
}
.db-item.active {
  background: var(--color-surface);
  box-shadow: inset 0 0 0 1px var(--color-border);
}
.db-item.active .db-item-name {
  color: var(--color-text-primary);
  font-weight: 500;
}
.db-item.active::before {
  content: "";
  position: absolute;
  left: -8px;
  top: 8px;
  bottom: 8px;
  width: 2px;
  border-radius: 2px;
  background: var(--color-wine);
}
.db-item.paused {
  opacity: 0.55;
}
.db-item.paused:hover {
  opacity: 1;
}

/* Status dot */
.db-status {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
  position: relative;
}
.db-status.active.wine { background: var(--color-wine); }
.db-status.active.oak { background: var(--color-oak); }
.db-status.active.gold { background: var(--color-gold); }
.db-status.active.sage { background: var(--color-sage); }
.db-status.active.smoke { background: var(--color-smoke); }
.db-status.active::after {
  content: "";
  position: absolute;
  inset: -3px;
  border-radius: 50%;
  background: inherit;
  opacity: 0.25;
  animation: pulse-dot 2.6s var(--ease-in-out) infinite;
}
.db-status.paused {
  background: transparent;
  border: 1.5px solid var(--color-warning);
}
.db-status.unreachable {
  background: transparent;
  border: 1.5px solid var(--color-danger);
}

.db-item-name {
  color: var(--color-text-muted);
  letter-spacing: -0.005em;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.db-item-name :deep(mark) {
  background: var(--color-wine-soft);
  color: var(--color-wine);
  padding: 0 1px;
  border-radius: 2px;
}

.db-meta {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--color-text-faint);
  flex-shrink: 0;
  letter-spacing: 0.02em;
}
.db-item.active .db-meta {
  color: var(--color-text-muted);
}

.db-cog,
.db-pin {
  opacity: 0;
  color: var(--color-text-faint);
  display: grid;
  place-items: center;
  width: 18px;
  height: 18px;
  border-radius: 4px;
  transition: opacity var(--duration-fast), color var(--duration-fast);
}
.db-pin {
  margin-right: -4px;
}
.db-cog:hover,
.db-pin:hover {
  color: var(--color-wine);
  background: var(--color-wine-soft);
  opacity: 1 !important;
}
.db-pin.pinned {
  opacity: 1;
  color: var(--color-wine);
}

/* Pinned section */
.pinned-section {
  padding: 4px 0 6px;
  margin-bottom: 4px;
  border-bottom: 1px dashed var(--color-border);
}
.pinned-section .env-header {
  color: var(--color-wine);
}
.pinned-section .env-header:hover {
  background: var(--color-wine-soft);
}

/* Stale warning dot */
.db-stale-dot {
  position: absolute;
  right: 28px;
  top: 50%;
  transform: translateY(-50%);
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: var(--color-warning);
}

/* Empty search */
.empty-search {
  padding: 24px 16px;
  text-align: center;
  color: var(--color-text-faint);
  font-family: var(--font-mono);
  font-size: 11px;
}

/* Bottom nav rail */
.sidebar-bottom {
  flex-shrink: 0;
  border-top: 1px solid var(--color-border);
  background: var(--color-surface-raised);
}

.sidebar-nav {
  padding: 8px;
  display: flex;
  flex-direction: column;
  gap: 1px;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 10px;
  border-radius: 6px;
  color: var(--color-text-muted);
  font-size: 12.5px;
  cursor: pointer;
  transition: all var(--duration-fast) var(--ease-out);
  text-decoration: none;
}
.nav-item:hover {
  background: var(--color-border);
  color: var(--color-text-primary);
}
.nav-item.active {
  background: var(--color-surface);
  color: var(--color-text-primary);
  box-shadow: inset 0 0 0 1px var(--color-border);
}

/* Storage widget */
.storage-widget {
  padding: 10px 14px;
  border-top: 1px solid var(--color-border);
}
.storage-label {
  font-family: var(--font-mono);
  font-size: 9.5px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
  display: flex;
  justify-content: space-between;
}

/* Profile / sign out */
.sidebar-profile {
  padding: 10px 14px;
  border-top: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  gap: 10px;
}
.avatar {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: linear-gradient(140deg, var(--color-oak), var(--color-wine));
  display: grid;
  place-items: center;
  color: oklch(0.98 0.01 80);
  font-family: var(--font-display);
  font-size: 12px;
  flex-shrink: 0;
}
.profile-info {
  flex: 1;
  min-width: 0;
}
.profile-name {
  font-size: 12px;
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.profile-logout {
  color: var(--color-text-faint);
  padding: 4px;
  border-radius: 4px;
  transition: all var(--duration-fast);
}
.profile-logout:hover {
  color: var(--color-text-primary);
  background: var(--color-border);
}

/* Running jobs mini-list */
.sidebar-jobs {
  flex-shrink: 0;
  border-top: 1px solid var(--color-border);
  padding: 8px 10px;
}
.sidebar-jobs-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 4px 6px;
  font-family: var(--font-mono);
  font-size: 9.5px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
}
.sidebar-jobs-link {
  font-size: 9.5px;
  color: var(--color-wine);
  text-decoration: none;
  text-transform: none;
  letter-spacing: 0;
}
.sidebar-jobs-link:hover { text-decoration: underline; }
.sidebar-job-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 4px;
  border-radius: 5px;
}
.sidebar-job-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}
.sidebar-job-dot.running {
  background: var(--color-wine);
  animation: pulse-dot 2s var(--ease-in-out) infinite;
}
.sidebar-job-dot.pending {
  background: var(--color-text-faint);
}
.sidebar-job-info {
  flex: 1;
  min-width: 0;
}
.sidebar-job-name {
  font-size: 11px;
  font-weight: 500;
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sidebar-job-progress {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 3px;
}
.sidebar-job-bar {
  flex: 1;
  height: 3px;
  border-radius: 2px;
  background: var(--color-wine-soft);
  overflow: hidden;
}
.sidebar-job-bar-fill {
  height: 100%;
  border-radius: 2px;
  background: var(--color-wine);
  transition: width 0.5s var(--ease-out);
}
.sidebar-job-pct {
  font-family: var(--font-mono);
  font-size: 9px;
  color: var(--color-wine);
  min-width: 24px;
  text-align: right;
}
.sidebar-job-status {
  font-size: 10px;
  color: var(--color-text-faint);
  margin-top: 1px;
}
</style>

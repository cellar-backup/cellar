<script setup lang="ts">
import { ref, computed, onMounted, watch, onUnmounted } from "vue";
import { useActiveDatabase } from "@/composables/useActiveDatabase";
import { useSourcesStore, type SourceArchive } from "@/stores/sources";
import BackupsHeader from "@/components/BackupsHeader.vue";
import BackupDetailsDrawer from "@/components/BackupDetailsDrawer.vue";
import CreateBackupModal from "@/components/CreateBackupModal.vue";
import RestoreModal from "@/components/RestoreModal.vue";
import { useToast } from "@/composables/useToast";

const sourcesStore = useSourcesStore();
const { activeDbId, activeDatabase } = useActiveDatabase();
const toast = useToast();

// ── State ──
const query = ref("");
const advancedOpen = ref(false);
const view = ref<"timeline" | "list">(
  (localStorage.getItem("cellar-view") as "timeline" | "list") || "timeline",
);
const archives = ref<SourceArchive[]>([]);
const loading = ref(false);
const selectedId = ref<string | null>(null);
const restoreTarget = ref<SourceArchive | null>(null);
const checkedIds = ref<Set<string>>(new Set());

// Persist view preference
watch(view, (v) => localStorage.setItem("cellar-view", v));

// ── Fetch archives for active database ──
async function loadArchives() {
  if (!activeDbId.value) return;
  loading.value = true;
  try {
    archives.value = await sourcesStore.fetchSourceArchives(activeDbId.value);
  } catch {
    archives.value = [];
  } finally {
    loading.value = false;
  }
}

onMounted(loadArchives);
watch(activeDbId, loadArchives);

// ── Filtering ──
const filteredArchives = computed(() => {
  if (!query.value) return archives.value;
  const q = query.value.toLowerCase();
  return archives.value.filter(
    (a) =>
      a.archive_id.toLowerCase().includes(q) ||
      a.plan_name.toLowerCase().includes(q) ||
      (a.tags || []).some((t) => t.toLowerCase().includes(q)) ||
      (a.notes || "").toLowerCase().includes(q),
  );
});

// ── Group by day ──
interface DayGroup {
  date: string;
  label: string;
  isToday: boolean;
  items: SourceArchive[];
}

const groupedByDay = computed<DayGroup[]>(() => {
  const groups: Record<string, SourceArchive[]> = {};
  for (const a of filteredArchives.value) {
    const day = a.timestamp.split("T")[0];
    if (!groups[day]) groups[day] = [];
    groups[day].push(a);
  }
  const today = new Date().toISOString().split("T")[0];
  return Object.entries(groups)
    .sort((a, b) => b[0].localeCompare(a[0]))
    .map(([date, items]) => ({
      date,
      label: formatDayLabel(date),
      isToday: date === today,
      items,
    }));
});

function formatDayLabel(dateStr: string): string {
  const d = new Date(dateStr + "T00:00:00");
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diff = Math.floor((today.getTime() - d.getTime()) / 86400000);
  if (diff === 0) return "Today";
  if (diff === 1) return "Yesterday";
  return d.toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric" });
}

function formatTime(timestamp: string): string {
  return new Date(timestamp).toLocaleTimeString("en-US", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

function timeAgo(timestamp: string): string {
  const diff = (Date.now() - new Date(timestamp).getTime()) / 60000;
  if (diff < 1) return "just now";
  if (diff < 60) return `${Math.round(diff)}m ago`;
  if (diff < 60 * 24) return `${Math.round(diff / 60)}h ago`;
  return `${Math.round(diff / (60 * 24))}d ago`;
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function dayOfMonth(dateStr: string): number {
  return new Date(dateStr + "T00:00:00").getDate();
}

function monthAbbr(dateStr: string): string {
  return new Date(dateStr + "T00:00:00").toLocaleDateString("en-US", { month: "short" });
}

// ── Search highlight ──
function highlight(text: string): string {
  if (!query.value) return text;
  const q = query.value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  return text.replace(new RegExp(`(${q})`, "gi"), '<mark>$1</mark>');
}

// ── Selected archive for details drawer ──
const selectedArchive = computed(() =>
  archives.value.find((a) => a.id === selectedId.value) || null,
);

// ── Actions ──
function handleCreate() {
  if (!activeDbId.value) return;
  const tid = toast.push({
    title: "Creating backup",
    desc: `${activeDatabase.value?.display_label} · starting…`,
    type: "progress",
    sticky: true,
    progress: 0,
  });

  sourcesStore.fetchPolicies(activeDbId.value).then((policies) => {
    if (policies.length > 0) {
      toast.update(tid, { desc: `${activeDatabase.value?.display_label} · streaming…`, progress: 40 });
      sourcesStore.triggerBackup(policies[0].id).then(() => {
        toast.update(tid, {
          title: "Backup started",
          desc: `${activeDatabase.value?.display_label} · job dispatched`,
          type: "success",
          sticky: false,
          progress: undefined,
        });
        setTimeout(() => toast.dismiss(tid), 3500);
        setTimeout(loadArchives, 3000);
      }).catch(() => {
        toast.update(tid, {
          title: "Backup failed",
          desc: "Could not start backup job",
          type: "error",
          sticky: false,
          progress: undefined,
        });
        setTimeout(() => toast.dismiss(tid), 4000);
      });
    } else {
      toast.update(tid, {
        title: "No backup plan",
        desc: "Configure a plan for this database first",
        type: "error",
        sticky: false,
        progress: undefined,
      });
      setTimeout(() => toast.dismiss(tid), 4000);
    }
  });
}

function handleCreateAdvanced(options: { label: string; compression: string; scope: string; encrypt: boolean; pinned: boolean }) {
  advancedOpen.value = false;
  // For now, just trigger regular backup with a toast showing the label
  const tid = toast.push({
    title: options.label ? `Creating "${options.label}"` : "Creating backup",
    desc: `${activeDatabase.value?.display_label} · advanced`,
    type: "progress",
    sticky: true,
    progress: 0,
  });

  if (!activeDbId.value) return;
  sourcesStore.fetchPolicies(activeDbId.value).then((policies) => {
    if (policies.length > 0) {
      toast.update(tid, { progress: 50 });
      sourcesStore.triggerBackup(policies[0].id).then(() => {
        toast.update(tid, {
          title: "Backup started",
          desc: options.label || "Job dispatched",
          type: "success",
          sticky: false,
          progress: undefined,
        });
        setTimeout(() => toast.dismiss(tid), 3500);
        setTimeout(loadArchives, 3000);
      });
    }
  });
}

// ── Restore ──
function handleRestore(archive: SourceArchive) {
  const tid = toast.push({
    title: `Restoring from ${archive.notes || archive.archive_id}`,
    desc: "pre-restore snapshot created · starting…",
    type: "progress",
    sticky: true,
    progress: 0,
  });
  sourcesStore.restoreArchive(archive.id).then(() => {
    toast.update(tid, {
      title: "Restore initiated",
      desc: "Job dispatched — check Jobs for progress",
      type: "success",
      sticky: false,
      progress: undefined,
    });
    setTimeout(() => toast.dismiss(tid), 4000);
  }).catch(() => {
    toast.update(tid, {
      title: "Restore failed",
      type: "error",
      sticky: false,
      progress: undefined,
    });
    setTimeout(() => toast.dismiss(tid), 4000);
  });
}

// ── Bulk selection ──
function toggleCheck(id: string) {
  const next = new Set(checkedIds.value);
  if (next.has(id)) next.delete(id);
  else next.add(id);
  checkedIds.value = next;
}

function clearChecked() {
  checkedIds.value = new Set();
}

function handleBulkDownload() {
  toast.push({ title: `Downloading ${checkedIds.value.size} backups`, type: "progress", duration: 3000 });
  for (const id of checkedIds.value) {
    sourcesStore.downloadArchive(id);
  }
  clearChecked();
}

// ── Diff ──
const diffView = ref<{ a: SourceArchive; b: SourceArchive } | null>(null);

function handleDiff() {
  const ids = [...checkedIds.value];
  if (ids.length !== 2) return;
  const a = archives.value.find((ar) => ar.id === ids[0]);
  const b = archives.value.find((ar) => ar.id === ids[1]);
  if (!a || !b) return;
  // Put earlier first
  const [base, target] = new Date(a.timestamp) < new Date(b.timestamp) ? [a, b] : [b, a];
  diffView.value = { a: base, b: target };
  clearChecked();
}

function closeDiff() {
  diffView.value = null;
}

// ── Keyboard shortcuts ──
function handleKeydown(e: KeyboardEvent) {
  if ((e.metaKey || e.ctrlKey) && e.key === "k") {
    e.preventDefault();
    const input = document.querySelector<HTMLInputElement>(".search-input");
    input?.focus();
  }
  if (e.key === "Escape") {
    selectedId.value = null;
    advancedOpen.value = false;
  }
}
onMounted(() => window.addEventListener("keydown", handleKeydown));
onUnmounted(() => window.removeEventListener("keydown", handleKeydown));
</script>

<template>
  <div class="backups-page">
    <BackupsHeader
      :db-name="activeDatabase?.display_label || 'Select a database'"
      :query="query"
      :view="view"
      @update:query="query = $event"
      @update:view="view = $event"
      @create="handleCreate"
      @create-advanced="advancedOpen = true"
    />

    <div class="backups-content">
      <!-- Loading -->
      <div v-if="loading" class="empty-msg">
        <div class="spinner" />
        Loading backups…
      </div>

      <!-- No database selected -->
      <div v-else-if="!activeDbId" class="empty-msg">
        Select a database from the sidebar to view its backups.
      </div>

      <!-- No results -->
      <div v-else-if="filteredArchives.length === 0 && query" class="empty-msg">
        No backups match "{{ query }}"
      </div>

      <!-- No backups at all -->
      <div v-else-if="filteredArchives.length === 0" class="empty-state">
        <div class="empty-illustration">
          <svg width="80" height="80" viewBox="0 0 80 80" fill="none">
            <rect x="16" y="20" width="48" height="44" rx="8" stroke="var(--color-border-strong)" stroke-width="1.5" stroke-dasharray="4 3" />
            <path d="M32 8h16v12c0 4 6 8 6 16v20a6 6 0 01-6 6H32a6 6 0 01-6-6V36c0-8 6-12 6-16V8z" fill="var(--color-wine-soft)" stroke="var(--color-wine)" stroke-width="1.2" opacity="0.6" />
            <path d="M26 40h28" stroke="var(--color-wine)" stroke-width="1" opacity="0.4" />
            <circle cx="40" cy="52" r="3" fill="var(--color-wine)" opacity="0.3" />
          </svg>
        </div>
        <div class="empty-title">No backups yet</div>
        <div class="empty-desc">
          Create your first backup for {{ activeDatabase?.display_label }} using the button above,<br />
          or configure a schedule to automate it.
        </div>
      </div>

      <!-- Timeline view -->
      <div v-else-if="view === 'timeline'" class="timeline-wrap">
        <div class="timeline-spine" />
        <div
          v-for="group in groupedByDay"
          :key="group.date"
          class="timeline-day"
        >
          <div class="timeline-day-date">
            <div class="timeline-day-date-main">{{ dayOfMonth(group.date) }}</div>
            <div class="timeline-day-date-sub">{{ monthAbbr(group.date) }}</div>
          </div>
          <div class="timeline-day-header">
            <span class="timeline-day-count">
              {{ group.items.length }} backup{{ group.items.length === 1 ? "" : "s" }}
            </span>
            <span v-if="group.isToday" class="timeline-day-today-chip">Today</span>
          </div>
          <div
            v-for="(archive, idx) in group.items"
            :key="archive.id"
            class="timeline-item success"
            :class="{ latest: idx === 0 && group.isToday, checked: checkedIds.has(archive.id) }"
            :style="{ '--idx': idx }"
          >
            <div class="timeline-item-node" />
            <div
              class="timeline-checkbox"
              @click.stop="toggleCheck(archive.id)"
            >
              <svg v-if="checkedIds.has(archive.id)" width="10" height="10" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3.5 8.5L6.5 11.5 12.5 5" />
              </svg>
            </div>
            <div
              class="timeline-card"
              :class="{ selected: selectedId === archive.id }"
              @click="selectedId = selectedId === archive.id ? null : archive.id"
            >
              <div>
                <div class="tl-time">{{ formatTime(archive.timestamp) }}</div>
                <div class="tl-time-sub">{{ timeAgo(archive.timestamp) }}</div>
              </div>
              <div class="tl-body">
                <div class="tl-label-row">
                  <span v-if="archive.notes" class="tl-label" v-html="highlight(archive.notes)" />
                  <span v-else class="tl-label-plain" v-html="highlight(archive.archive_id)" />
                  <span class="trigger-pill">
                    <svg width="10" height="10" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                      <circle cx="8" cy="8" r="6" />
                      <path d="M8 5v3l2 1.5" />
                    </svg>
                    {{ archive.plan_name }}
                  </span>
                  <span v-if="archive.keep_forever" class="trigger-pill manual">pinned</span>
                </div>
                <div class="tl-meta-row">
                  <span>{{ formatSize(archive.size_compressed) }}</span>
                  <span>·</span>
                  <span>{{ archive.file_count }} files</span>
                </div>
              </div>
              <div class="tl-actions">
                <button
                  class="tl-action-btn"
                  title="Download"
                  @click.stop="sourcesStore.downloadArchive(archive.id)"
                >
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 2v8M4.5 6.5L8 10l3.5-3.5" />
                    <path d="M2.5 12.5V13a1 1 0 001 1h9a1 1 0 001-1v-.5" />
                  </svg>
                </button>
                <button
                  class="tl-action-btn"
                  title="Restore"
                  @click.stop="restoreTarget = archive"
                >
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 8a5 5 0 019.2-2.7" />
                    <path d="M13 3v3h-3" />
                    <path d="M13 8a5 5 0 01-9.2 2.7" />
                    <path d="M3 13v-3h3" />
                  </svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- List view -->
      <div v-else class="list-wrap">
        <div
          v-for="group in groupedByDay"
          :key="group.date"
          class="list-day"
        >
          <div class="list-day-header">
            <span>{{ group.label }}</span>
            <span>{{ group.items.length }}</span>
          </div>
          <div
            v-for="(archive, idx) in group.items"
            :key="archive.id"
            class="list-row"
            :class="{ selected: selectedId === archive.id }"
            :style="{ '--idx': idx }"
            @click="selectedId = selectedId === archive.id ? null : archive.id"
          >
            <div class="list-col-mono">{{ formatTime(archive.timestamp) }}</div>
            <div>
              <div class="list-row-main">
                <span class="list-row-label">{{ archive.notes || archive.archive_id }}</span>
                <span class="trigger-pill">{{ archive.plan_name }}</span>
              </div>
            </div>
            <div class="list-col-mono">{{ formatSize(archive.size_compressed) }}</div>
            <div class="list-col-mono">{{ archive.file_count }} files</div>
            <div class="tl-actions" style="opacity: 1; justify-content: flex-end;">
              <button
                class="tl-action-btn"
                title="Download"
                @click.stop="sourcesStore.downloadArchive(archive.id)"
              >
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M8 2v8M4.5 6.5L8 10l3.5-3.5" />
                  <path d="M2.5 12.5V13a1 1 0 001 1h9a1 1 0 001-1v-.5" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Details drawer -->
    <BackupDetailsDrawer
      :archive="selectedArchive"
      @close="selectedId = null"
    />

    <!-- Advanced create modal -->
    <CreateBackupModal
      :open="advancedOpen"
      :db-name="activeDatabase?.display_label || ''"
      @close="advancedOpen = false"
      @create="handleCreateAdvanced"
    />

    <!-- Restore confirmation modal -->
    <RestoreModal
      :open="!!restoreTarget"
      :archive="restoreTarget"
      :db-name="activeDatabase?.display_label || ''"
      @close="restoreTarget = null"
      @confirm="handleRestore"
    />

    <!-- Bulk action bar -->
    <div v-if="checkedIds.size > 0" class="bulk-bar">
      <span>{{ checkedIds.size }} selected</span>
      <button class="bulk-btn" :class="{ disabled: checkedIds.size !== 2 }" :disabled="checkedIds.size !== 2" @click="handleDiff">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="4" cy="4" r="1.5" /><circle cx="12" cy="12" r="1.5" />
          <path d="M4 5.5v5a2 2 0 002 2h4.5M12 10.5v-5a2 2 0 00-2-2H5.5" />
        </svg>
        Compare
      </button>
      <button class="bulk-btn" @click="handleBulkDownload">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M8 2v8M4.5 6.5L8 10l3.5-3.5" />
          <path d="M2.5 12.5V13a1 1 0 001 1h9a1 1 0 001-1v-.5" />
        </svg>
        Download
      </button>
      <button class="bulk-btn ghost" @click="clearChecked">Clear</button>
    </div>

    <!-- Diff view overlay -->
    <div v-if="diffView" class="diff-overlay">
      <div class="diff-wrap">
        <div class="diff-top">
          <div>
            <div class="diff-title">Compare backups</div>
            <div class="diff-subtitle">What changed between these two snapshots</div>
          </div>
          <button class="btn btn-ghost" style="border: 1px solid var(--color-border)" @click="closeDiff">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
            Close
          </button>
        </div>

        <div class="diff-header">
          <div class="diff-side">
            <div class="diff-side-label">Base</div>
            <div class="diff-side-title">{{ diffView.a.notes || diffView.a.archive_id }}</div>
            <div class="diff-side-meta">{{ formatTime(diffView.a.timestamp) }} · {{ formatSize(diffView.a.size_compressed) }}</div>
          </div>
          <div class="diff-arrow">
            <svg width="20" height="20" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 3l5 5-5 5" />
            </svg>
          </div>
          <div class="diff-side target">
            <div class="diff-side-label">Target</div>
            <div class="diff-side-title">{{ diffView.b.notes || diffView.b.archive_id }}</div>
            <div class="diff-side-meta">{{ formatTime(diffView.b.timestamp) }} · {{ formatSize(diffView.b.size_compressed) }}</div>
          </div>
        </div>

        <div class="diff-stats">
          <div class="diff-stat">
            <div class="diff-stat-value">{{ formatSize(Math.abs(diffView.b.size_compressed - diffView.a.size_compressed)) }}</div>
            <div class="diff-stat-label">size delta</div>
          </div>
          <div class="diff-stat">
            <div class="diff-stat-value">{{ Math.abs(diffView.b.file_count - diffView.a.file_count) }}</div>
            <div class="diff-stat-label">file count delta</div>
          </div>
          <div class="diff-stat">
            <div class="diff-stat-value">{{ formatSize(diffView.b.size_dedup - diffView.a.size_dedup) }}</div>
            <div class="diff-stat-label">dedup delta</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.backups-page {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.backups-content {
  flex: 1;
  overflow-y: auto;
}

.empty-msg {
  text-align: center;
  padding: 80px 20px;
  color: var(--color-text-faint);
  font-family: var(--font-mono);
  font-size: 13px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.empty-state {
  text-align: center;
  padding: 80px 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}
.empty-illustration {
  opacity: 0.8;
  animation: fade-up 0.6s var(--ease-out) both;
}
.empty-title {
  font-family: var(--font-display);
  font-size: 20px;
  color: var(--color-text-primary);
  letter-spacing: -0.01em;
}
.empty-desc {
  font-size: 13px;
  color: var(--color-text-muted);
  line-height: 1.6;
}

.spinner {
  width: 14px;
  height: 14px;
  border: 2px solid var(--color-wine-soft);
  border-top-color: var(--color-wine);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

/* ─── Timeline ─── */
.timeline-wrap {
  padding: 0 48px 80px;
  max-width: 980px;
  margin: 0 auto;
  position: relative;
}

.timeline-day {
  position: relative;
  padding-left: 140px;
  padding-top: 24px;
}

.timeline-day-date {
  position: absolute;
  left: 0;
  top: 42px;
  width: 120px;
  text-align: right;
  z-index: 4;
}
.timeline-day-date-main {
  font-family: var(--font-display);
  font-size: 32px;
  line-height: 1;
  color: var(--color-text-primary);
  letter-spacing: -0.02em;
}
.timeline-day-date-sub {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-text-faint);
  margin-top: 6px;
}

.timeline-day-header {
  position: sticky;
  top: 0;
  z-index: 3;
  padding: 16px 0 10px;
  display: flex;
  align-items: baseline;
  gap: 14px;
  background: linear-gradient(to bottom, var(--color-background) 70%, transparent);
  margin-left: -140px;
  padding-left: 140px;
}
.timeline-day-count {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
  letter-spacing: 0.05em;
}
.timeline-day-today-chip {
  font-family: var(--font-mono);
  font-size: 10px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--color-wine);
  background: var(--color-wine-soft);
  padding: 2px 8px;
  border-radius: 999px;
  align-self: center;
}

/* Vertical spine */
.timeline-spine {
  position: absolute;
  left: 140px;
  top: 0;
  bottom: 0;
  width: 1px;
  background: linear-gradient(to bottom, transparent, var(--color-border) 3%, var(--color-border) 97%, transparent);
}

.timeline-item {
  position: relative;
  padding: 10px 0 10px 32px;
  animation: pour-in calc(0.55s * var(--motion-scale, 1) + 0.001s) var(--ease-out) both;
  animation-delay: calc(var(--idx, 0) * 40ms);
}
[data-motion="none"] .timeline-item { animation: none; }

.timeline-item-node {
  position: absolute;
  left: -4.5px;
  top: 22px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--color-background);
  border: 2px solid var(--color-border-strong);
  transition: all var(--duration-DEFAULT) var(--ease-spring);
  z-index: 2;
}
.timeline-item.success .timeline-item-node {
  border-color: var(--color-wine);
  background: var(--color-background);
}
.timeline-item.latest .timeline-item-node {
  background: var(--color-wine);
  border-color: var(--color-wine);
  box-shadow: 0 0 0 4px var(--color-wine-soft);
}
.timeline-item.latest .timeline-item-node::after {
  content: "";
  position: absolute;
  inset: -6px;
  border-radius: 50%;
  border: 2px solid var(--color-wine);
  opacity: 0;
  animation: ripple 2s var(--ease-out) infinite;
}

.timeline-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 14px 18px;
  display: grid;
  grid-template-columns: 80px 1fr auto;
  align-items: center;
  gap: 20px;
  cursor: pointer;
  transition: all var(--duration-DEFAULT) var(--ease-spring);
  position: relative;
  box-shadow: var(--shadow-sm);
}
.timeline-card:hover {
  border-color: var(--color-border-strong);
  transform: translateX(2px);
  box-shadow: var(--shadow-md);
}
.timeline-card.selected {
  border-color: var(--color-wine);
  box-shadow: 0 0 0 1px var(--color-wine), var(--shadow-md);
}

.tl-time {
  font-family: var(--font-mono);
  font-size: 14px;
  color: var(--color-text-primary);
  letter-spacing: -0.01em;
  font-feature-settings: "tnum";
}
.tl-time-sub {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--color-text-faint);
  margin-top: 2px;
}

.tl-body { min-width: 0; }
.tl-label-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.tl-label {
  font-size: 14px;
  color: var(--color-text-primary);
  font-weight: 500;
  letter-spacing: -0.01em;
}
.tl-label :deep(mark),
.tl-label-plain :deep(mark) {
  background: var(--color-wine-soft);
  color: var(--color-wine);
  padding: 0 2px;
  border-radius: 2px;
}
.tl-label-plain {
  font-size: 13px;
  color: var(--color-text-muted);
  font-family: var(--font-mono);
}
.tl-meta-row {
  display: flex;
  gap: 16px;
  margin-top: 5px;
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
}
.tl-meta-row span { display: inline-flex; align-items: center; gap: 4px; }

.tl-actions {
  display: flex;
  gap: 4px;
  opacity: 0;
  transition: opacity var(--duration-fast) var(--ease-out);
}
.timeline-card:hover .tl-actions { opacity: 1; }
.timeline-card.selected .tl-actions { opacity: 1; }

.tl-action-btn {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  color: var(--color-text-muted);
  transition: all var(--duration-fast) var(--ease-out);
}
.tl-action-btn:hover {
  background: var(--color-wine-soft);
  color: var(--color-wine);
}

/* ─── List view ─── */
.list-wrap {
  padding: 0 32px 80px;
  max-width: 1100px;
  margin: 0 auto;
}
.list-day { margin-bottom: 28px; }
.list-day-header {
  font-family: var(--font-mono);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-text-faint);
  padding: 12px 16px;
  display: flex;
  justify-content: space-between;
  position: sticky;
  top: 0;
  background: linear-gradient(to bottom, var(--color-background) 70%, transparent);
  z-index: 2;
}
.list-row {
  display: grid;
  grid-template-columns: 90px 1fr 120px 100px 80px;
  align-items: center;
  gap: 16px;
  padding: 14px 16px;
  border-top: 1px solid var(--color-border);
  cursor: pointer;
  transition: all var(--duration-fast) var(--ease-out);
  font-size: 13px;
  animation: fade-up calc(0.4s * var(--motion-scale, 1) + 0.001s) var(--ease-out) both;
  animation-delay: calc(var(--idx, 0) * 20ms);
}
.list-row:hover { background: var(--color-surface); }
.list-row.selected { background: var(--color-wine-soft); }
.list-col-mono {
  font-family: var(--font-mono);
  color: var(--color-text-muted);
  font-feature-settings: "tnum";
}
.list-row-main {
  display: flex;
  align-items: center;
  gap: 10px;
}
.list-row-label {
  font-weight: 500;
  letter-spacing: -0.01em;
}

/* ─── Checkboxes ─── */
.timeline-checkbox {
  position: absolute;
  left: -26px;
  top: 22px;
  width: 16px;
  height: 16px;
  border: 1.5px solid var(--color-border-strong);
  border-radius: 4px;
  background: var(--color-background);
  display: grid;
  place-items: center;
  cursor: pointer;
  opacity: 0;
  transition: all var(--duration-fast) var(--ease-out);
  color: transparent;
}
.timeline-item:hover .timeline-checkbox { opacity: 0.6; }
.timeline-item.checked .timeline-checkbox {
  opacity: 1;
  background: var(--color-wine);
  border-color: var(--color-wine);
  color: oklch(0.98 0 0);
}

/* ─── Bulk bar ─── */
.bulk-bar {
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--color-text-primary);
  color: var(--color-background);
  padding: 10px 14px 10px 18px;
  border-radius: 999px;
  box-shadow: var(--shadow-lg);
  display: flex;
  align-items: center;
  gap: 14px;
  z-index: 30;
  animation: modal-in 0.3s var(--ease-spring);
  font-size: 13px;
}
.bulk-btn {
  padding: 6px 12px;
  border-radius: 999px;
  background: var(--color-background);
  color: var(--color-text-primary);
  font-size: 12px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 5px;
}
.bulk-btn:hover { opacity: 0.85; }
.bulk-btn.ghost {
  background: transparent;
  color: var(--color-background);
}
.bulk-btn.disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

/* ─── Diff view ─── */
.diff-overlay {
  position: absolute;
  inset: 0;
  z-index: 10;
  background: var(--color-background);
  overflow-y: auto;
  animation: fade-in 0.3s var(--ease-out);
}
.diff-wrap {
  padding: 32px 48px 80px;
  max-width: 1000px;
  margin: 0 auto;
}
.diff-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 28px;
}
.diff-title {
  font-family: var(--font-display);
  font-size: 32px;
  letter-spacing: -0.02em;
  color: var(--color-text-primary);
}
.diff-subtitle {
  font-size: 13px;
  color: var(--color-text-muted);
  margin-top: 4px;
}
.diff-header {
  display: grid;
  grid-template-columns: 1fr 40px 1fr;
  align-items: center;
  gap: 20px;
  margin-bottom: 28px;
}
.diff-side {
  padding: 16px 18px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 12px;
}
.diff-side.target {
  border-color: var(--color-wine);
  background: var(--color-wine-soft);
}
.diff-side-label {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
  margin-bottom: 6px;
}
.diff-side-title {
  font-family: var(--font-display);
  font-size: 18px;
  color: var(--color-text-primary);
  letter-spacing: -0.01em;
}
.diff-side-meta {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 4px;
}
.diff-arrow {
  display: grid;
  place-items: center;
  color: var(--color-wine);
}
.diff-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}
.diff-stat {
  padding: 16px;
  border-radius: 12px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
}
.diff-stat-value {
  font-family: var(--font-display);
  font-size: 24px;
  color: var(--color-text-primary);
  letter-spacing: -0.02em;
}
.diff-stat-label {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}
</style>

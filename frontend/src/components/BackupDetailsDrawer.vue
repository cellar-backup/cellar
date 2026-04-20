<script setup lang="ts">
import { computed } from "vue";
import type { SourceArchive } from "@/stores/sources";
import { useSourcesStore } from "@/stores/sources";
import { useActiveDatabase } from "@/composables/useActiveDatabase";

const props = defineProps<{
  archive: SourceArchive | null;
}>();

const emit = defineEmits<{
  close: [];
}>();

const sourcesStore = useSourcesStore();
const { activeDatabase } = useActiveDatabase();

const open = computed(() => !!props.archive);

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function formatDate(timestamp: string): string {
  const d = new Date(timestamp);
  return d.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" });
}

function formatTime(timestamp: string): string {
  return new Date(timestamp).toLocaleTimeString("en-US", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  });
}

function yearFromTimestamp(timestamp: string): number {
  return new Date(timestamp).getFullYear();
}

function dayOfYear(timestamp: string): string {
  const d = new Date(timestamp);
  return `${String(d.getMonth() + 1).padStart(2, "0")}·${String(d.getDate()).padStart(2, "0")}`;
}

function timeAgo(timestamp: string): string {
  const diff = (Date.now() - new Date(timestamp).getTime()) / 60000;
  if (diff < 1) return "just now";
  if (diff < 60) return `${Math.round(diff)}m ago`;
  if (diff < 60 * 24) return `${Math.round(diff / 60)}h ago`;
  return `${Math.round(diff / (60 * 24))}d ago`;
}

function dedupRatio(archive: SourceArchive): string {
  if (!archive.size_original || archive.size_original === 0) return "—";
  const ratio = (1 - archive.size_dedup / archive.size_original) * 100;
  return `${ratio.toFixed(0)}%`;
}

async function handleDownload() {
  if (!props.archive) return;
  await sourcesStore.downloadArchive(props.archive.id);
}

async function handleRestore() {
  if (!props.archive) return;
  await sourcesStore.restoreArchive(props.archive.id);
}

async function handleTogglePin() {
  if (!props.archive) return;
  await sourcesStore.toggleKeepForever(props.archive.id, !props.archive.keep_forever);
  // Optimistic update
  props.archive.keep_forever = !props.archive.keep_forever;
}
</script>

<template>
  <div class="details-drawer" :class="{ open }">
    <template v-if="archive">
      <!-- Header -->
      <div class="drawer-header">
        <div class="drawer-title-block">
          <div class="drawer-title">{{ archive.notes || archive.archive_id }}</div>
          <div class="drawer-sub">
            {{ archive.archive_id }} · {{ activeDatabase?.display_label }}
          </div>
        </div>
        <button class="drawer-close" @click="emit('close')">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
            <path d="M4 4l8 8M12 4l-8 8" />
          </svg>
        </button>
      </div>

      <!-- Body -->
      <div class="drawer-body">
        <!-- Vintage card (the wine-cellar-themed element) -->
        <div class="drawer-vintage">
          <div class="vintage-seal">
            <div>
              <div class="vintage-seal-year">{{ yearFromTimestamp(archive.timestamp) }}</div>
              <div class="vintage-seal-doy">{{ dayOfYear(archive.timestamp) }}</div>
            </div>
          </div>
          <div class="vintage-text">
            <div class="vintage-label-tag">Vintage · {{ archive.plan_name }}</div>
            <div class="vintage-label-date">
              {{ formatSize(archive.size_compressed) }} · {{ archive.file_count }} files
            </div>
            <div class="vintage-label-time">
              {{ formatDate(archive.timestamp) }} at {{ formatTime(archive.timestamp) }}
            </div>
          </div>
        </div>

        <!-- Stats grid -->
        <div class="drawer-stats">
          <div class="stat">
            <div class="stat-label">Compressed</div>
            <div class="stat-value">{{ formatSize(archive.size_compressed) }}</div>
          </div>
          <div class="stat">
            <div class="stat-label">Original</div>
            <div class="stat-value">{{ formatSize(archive.size_original) }}</div>
          </div>
          <div class="stat">
            <div class="stat-label">Dedup</div>
            <div class="stat-value">{{ formatSize(archive.size_dedup) }}</div>
          </div>
          <div class="stat">
            <div class="stat-label">Files</div>
            <div class="stat-value">{{ archive.file_count.toLocaleString() }}</div>
          </div>
        </div>

        <!-- Metadata -->
        <div class="drawer-section-title">Metadata</div>
        <div class="kv-list">
          <div class="kv-row">
            <span class="kv-key">Plan</span>
            <span class="kv-val">{{ archive.plan_name }}</span>
          </div>
          <div class="kv-row">
            <span class="kv-key">Archive ID</span>
            <span class="kv-val">{{ archive.archive_id }}</span>
          </div>
          <div class="kv-row">
            <span class="kv-key">Created</span>
            <span class="kv-val">{{ timeAgo(archive.timestamp) }}</span>
          </div>
          <div class="kv-row">
            <span class="kv-key">Dedup ratio</span>
            <span class="kv-val">{{ dedupRatio(archive) }}</span>
          </div>
          <div v-if="archive.tags && archive.tags.length > 0" class="kv-row">
            <span class="kv-key">Tags</span>
            <span class="kv-val">
              <span
                v-for="tag in archive.tags"
                :key="tag"
                class="tag-chip"
              >{{ tag }}</span>
            </span>
          </div>
        </div>

        <!-- Retention -->
        <div class="drawer-section-title">Retention</div>
        <div class="retention-card">
          <span v-if="archive.keep_forever" class="retention-status pinned">
            <svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M6 1.5h4l-.5 4 2.5 2.5h-3.5v4l-1 2-1-2v-4H3l2.5-2.5L6 1.5z" />
            </svg>
            Pinned — never expires
          </span>
          <span v-else class="retention-status">Subject to retention policy</span>
          <button class="retention-toggle" @click="handleTogglePin">
            {{ archive.keep_forever ? "Unpin" : "Pin" }}
          </button>
        </div>
      </div>

      <!-- Actions footer -->
      <div class="drawer-actions">
        <button class="btn btn-ghost drawer-action-btn" @click="handleDownload">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M8 2v8M4.5 6.5L8 10l3.5-3.5" />
            <path d="M2.5 12.5V13a1 1 0 001 1h9a1 1 0 001-1v-.5" />
          </svg>
          Download
        </button>
        <button class="btn btn-primary drawer-action-btn" @click="handleRestore">
          <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 8a5 5 0 019.2-2.7" />
            <path d="M13 3v3h-3" />
            <path d="M13 8a5 5 0 01-9.2 2.7" />
            <path d="M3 13v-3h3" />
          </svg>
          Restore
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.details-drawer {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  width: 420px;
  background: var(--color-background);
  border-left: 1px solid var(--color-border);
  box-shadow: var(--shadow-lg);
  z-index: 20;
  display: flex;
  flex-direction: column;
  transform: translateX(100%);
  transition: transform calc(0.45s * var(--motion-scale, 1) + 0.001s) var(--ease-spring);
}
.details-drawer.open {
  transform: translateX(0);
}

/* Header */
.drawer-header {
  padding: 20px 24px 16px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-shrink: 0;
}
.drawer-title-block { min-width: 0; }
.drawer-title {
  font-family: var(--font-display);
  font-size: 20px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
  line-height: 1.2;
}
.drawer-sub {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
  margin-top: 4px;
  letter-spacing: 0.04em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.drawer-close {
  color: var(--color-text-faint);
  padding: 4px;
  border-radius: 4px;
  transition: all var(--duration-fast);
}
.drawer-close:hover {
  color: var(--color-text-primary);
  background: var(--color-border);
}

/* Body */
.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: 8px 24px 24px;
}

/* Vintage card */
.drawer-vintage {
  padding: 18px 18px 16px;
  background: linear-gradient(135deg, var(--color-wine-soft) 0%, var(--color-oak-soft) 100%);
  border: 1px solid color-mix(in oklch, var(--color-wine) 15%, var(--color-border));
  border-radius: 14px;
  margin: 16px 0 18px;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  gap: 18px;
}
.vintage-seal {
  width: 64px;
  height: 64px;
  flex-shrink: 0;
  border-radius: 50%;
  background: var(--color-wine);
  color: oklch(0.97 0.02 80);
  display: grid;
  place-items: center;
  position: relative;
  box-shadow:
    inset 0 1px 0 oklch(1 0 0 / 0.2),
    inset 0 -8px 16px oklch(0 0 0 / 0.2),
    0 2px 4px oklch(0 0 0 / 0.15);
  font-family: var(--font-display);
  letter-spacing: 0.04em;
  text-align: center;
  line-height: 1;
}
.vintage-seal::before {
  content: "";
  position: absolute;
  inset: 4px;
  border-radius: 50%;
  border: 1px solid oklch(1 0 0 / 0.28);
  pointer-events: none;
}
.vintage-seal::after {
  content: "";
  position: absolute;
  inset: 7px;
  border-radius: 50%;
  border: 0.5px dashed oklch(1 0 0 / 0.22);
  pointer-events: none;
}
.vintage-seal-year {
  font-size: 18px;
  letter-spacing: -0.02em;
}
.vintage-seal-doy {
  font-family: var(--font-mono);
  font-size: 8px;
  letter-spacing: 0.14em;
  opacity: 0.7;
  margin-top: 2px;
}
.vintage-text { min-width: 0; flex: 1; }
.vintage-label-tag {
  font-family: var(--font-mono);
  font-size: 9.5px;
  text-transform: uppercase;
  letter-spacing: 0.14em;
  color: var(--color-wine);
  opacity: 0.75;
}
.vintage-label-date {
  font-family: var(--font-display);
  font-size: 24px;
  color: var(--color-text-primary);
  letter-spacing: -0.02em;
  line-height: 1.1;
  margin-top: 4px;
}
.vintage-label-time {
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--color-text-muted);
  margin-top: 4px;
}

/* Stats grid */
.drawer-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1px;
  background: var(--color-border);
  border: 1px solid var(--color-border);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 20px;
}
.stat {
  padding: 12px 14px;
  background: var(--color-background);
}
.stat-label {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
}
.stat-value {
  font-family: var(--font-mono);
  font-size: 15px;
  color: var(--color-text-primary);
  margin-top: 4px;
  font-feature-settings: "tnum";
}

/* Section titles */
.drawer-section-title {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
  margin: 20px 0 10px;
}

/* Key-value list */
.kv-list { display: flex; flex-direction: column; gap: 2px; }
.kv-row {
  display: flex;
  justify-content: space-between;
  padding: 9px 0;
  border-bottom: 1px dashed var(--color-border);
  font-size: 13px;
}
.kv-row:last-child { border-bottom: none; }
.kv-key { color: var(--color-text-muted); }
.kv-val {
  color: var(--color-text-primary);
  font-family: var(--font-mono);
  font-size: 12.5px;
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  justify-content: flex-end;
}

.tag-chip {
  font-size: 10.5px;
  padding: 2px 6px;
  border-radius: 4px;
  background: var(--color-wine-soft);
  color: var(--color-wine);
}

/* Retention card */
.retention-card {
  padding: 12px;
  border-radius: 10px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  font-size: 12.5px;
  color: var(--color-text-muted);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.retention-status {
  display: flex;
  align-items: center;
  gap: 6px;
}
.retention-status.pinned {
  color: var(--color-wine);
}
.retention-toggle {
  font-size: 11px;
  font-family: var(--font-mono);
  color: var(--color-wine);
  padding: 4px 8px;
  border-radius: 6px;
  transition: all var(--duration-fast);
}
.retention-toggle:hover {
  background: var(--color-wine-soft);
}

/* Actions footer */
.drawer-actions {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  display: flex;
  gap: 8px;
  background: var(--color-surface-raised);
  flex-shrink: 0;
}
.drawer-action-btn {
  flex: 1;
  justify-content: center;
}
.btn-ghost.drawer-action-btn {
  border: 1px solid var(--color-border);
}
</style>

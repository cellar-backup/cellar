<script setup lang="ts">
import { useTheme } from "@/composables/useTheme";

defineProps<{
  dbName: string;
  query: string;
  view: "timeline" | "list";
}>();

const emit = defineEmits<{
  "update:query": [value: string];
  "update:view": [value: "timeline" | "list"];
  create: [];
  createAdvanced: [];
}>();

const { theme, toggleTheme } = useTheme();
</script>

<template>
  <header class="backups-header">
    <div class="header-title-block">
      <div class="header-title">{{ dbName }}</div>
      <div class="header-breadcrumb">backup history</div>
    </div>

    <div class="header-spacer" />

    <!-- Search -->
    <div class="search-wrap">
      <svg class="search-icon" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="7" cy="7" r="5" />
        <path d="M14 14l-3.5-3.5" />
      </svg>
      <input
        class="search-input"
        placeholder="Search backups, labels, versions…"
        :value="query"
        @input="emit('update:query', ($event.target as HTMLInputElement).value)"
      />
      <span class="kbd search-kbd">⌘K</span>
    </div>

    <!-- View toggle -->
    <div class="view-toggle">
      <button :class="{ active: view === 'timeline' }" @click="emit('update:view', 'timeline')">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <circle cx="4" cy="4" r="1.5" /><circle cx="4" cy="12" r="1.5" />
          <path d="M4 5.5v5M8 4h6M8 12h6M8 8h4" />
        </svg>
        Timeline
      </button>
      <button :class="{ active: view === 'list' }" @click="emit('update:view', 'list')">
        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M2.5 4h11M2.5 8h11M2.5 12h11" />
        </svg>
        List
      </button>
    </div>

    <!-- Theme toggle -->
    <button class="icon-btn" title="Toggle theme" @click="toggleTheme">
      <!-- Sun -->
      <svg v-if="theme === 'dark'" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="8" cy="8" r="3" />
        <path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.3 3.3l1 1M11.7 11.7l1 1M3.3 12.7l1-1M11.7 4.3l1-1" />
      </svg>
      <!-- Moon -->
      <svg v-else width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M13 9.5A5.5 5.5 0 116.5 3c0 3 2.5 5.5 5.5 5.5.3 0 .7 0 1 .05z" />
      </svg>
    </button>

    <!-- Create backup -->
    <div class="create-btn">
      <button class="create-btn-main" @click="emit('create')">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M8 3v10M3 8h10" />
        </svg>
        Create backup
      </button>
      <div class="create-btn-divider" />
      <button class="create-btn-more" title="Advanced options" @click="emit('createAdvanced')">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 6l5 5 5-5" />
        </svg>
      </button>
    </div>
  </header>
</template>

<style scoped>
.backups-header {
  height: 60px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  padding: 0 24px 0 32px;
  gap: 16px;
  background: var(--color-background);
  position: sticky;
  top: 0;
  z-index: 5;
  flex-shrink: 0;
}

.header-title-block {
  display: flex;
  align-items: baseline;
  gap: 12px;
  min-width: 0;
}
.header-title {
  font-family: var(--font-display);
  font-size: 22px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.header-breadcrumb {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
  letter-spacing: 0.04em;
}

.header-spacer { flex: 1; }

.search-wrap {
  position: relative;
  width: 280px;
  display: flex;
  align-items: center;
}
.search-icon {
  position: absolute;
  left: 10px;
  color: var(--color-text-faint);
  pointer-events: none;
}
.search-input {
  width: 100%;
  padding: 7px 12px 7px 32px;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: var(--color-surface);
  font-size: 13px;
  transition: all var(--duration-fast) var(--ease-out);
  outline: none;
}
.search-input:focus {
  border-color: var(--color-wine);
  box-shadow: 0 0 0 3px var(--color-wine-soft);
}
.search-input::placeholder { color: var(--color-text-faint); }
.search-kbd {
  position: absolute;
  right: 8px;
  pointer-events: none;
}

.view-toggle {
  display: flex;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 2px;
  gap: 1px;
}
.view-toggle button {
  padding: 5px 9px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--color-text-muted);
  transition: all var(--duration-fast) var(--ease-out);
}
.view-toggle button.active {
  background: var(--color-background);
  color: var(--color-text-primary);
  box-shadow: var(--shadow-sm);
}

.icon-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  color: var(--color-text-muted);
  transition: all var(--duration-fast) var(--ease-out);
}
.icon-btn:hover {
  background: var(--color-surface);
  color: var(--color-text-primary);
}

/* Create button — the signature element */
.create-btn {
  position: relative;
  display: flex;
  align-items: stretch;
  height: 36px;
  border-radius: 10px;
  overflow: hidden;
  background: var(--color-wine);
  color: oklch(0.98 0.015 80);
  box-shadow:
    0 1px 2px oklch(0 0 0 / 0.2),
    inset 0 1px 0 oklch(1 0 0 / 0.15),
    0 0 0 1px oklch(0 0 0 / 0.05);
  transition: transform var(--duration-fast) var(--ease-spring), box-shadow var(--duration-fast);
}
.create-btn:hover {
  transform: translateY(-1px);
  box-shadow:
    0 4px 10px oklch(0.4 0.14 18 / 0.35),
    inset 0 1px 0 oklch(1 0 0 / 0.2),
    0 0 0 1px oklch(0 0 0 / 0.05);
}
.create-btn:active { transform: translateY(0); }
.create-btn-main {
  padding: 0 14px;
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 500;
  letter-spacing: -0.01em;
}
.create-btn-divider {
  width: 1px;
  background: oklch(0 0 0 / 0.18);
  margin: 6px 0;
}
.create-btn-more {
  padding: 0 9px;
  display: flex;
  align-items: center;
  color: oklch(1 0 0 / 0.85);
}
.create-btn-more:hover { background: oklch(0 0 0 / 0.1); }
</style>

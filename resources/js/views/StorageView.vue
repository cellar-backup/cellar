<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useSourcesStore } from "@/stores/sources";

const sourcesStore = useSourcesStore();
const loading = ref(true);

onMounted(async () => {
  await sourcesStore.fetchSources();
  loading.value = false;
});

// Compute storage data from sources (aggregate archive sizes)
// In a real app this would come from the repository API, but we'll derive from sources
interface StorageSegment {
  id: string;
  name: string;
  type: string;
  archiveCount: number;
  color: string;
}

const segments = computed<StorageSegment[]>(() => {
  return sourcesStore.sources
    .filter((s) => s.archive_count > 0)
    .map((s) => ({
      id: s.id,
      name: s.display_label,
      type: s.source_type,
      archiveCount: s.archive_count,
      color: typeColor(s.source_type),
    }))
    .sort((a, b) => b.archiveCount - a.archiveCount);
});

const totalArchives = computed(() =>
  segments.value.reduce((sum, s) => sum + s.archiveCount, 0),
);

function typeColor(type: string): string {
  switch (type) {
    case "postgresql": return "var(--color-wine)";
    case "mysql":
    case "mariadb": return "var(--color-oak)";
    case "mongodb": return "var(--color-sage)";
    case "directory": return "var(--color-gold)";
    default: return "var(--color-smoke)";
  }
}

// SVG donut math
const donutRadius = 80;
const donutCircumference = 2 * Math.PI * donutRadius;

interface DonutArc {
  color: string;
  dashArray: string;
  dashOffset: number;
  name: string;
  percent: number;
}

const donutArcs = computed<DonutArc[]>(() => {
  if (totalArchives.value === 0) return [];
  let accumulated = 0;
  return segments.value.map((seg) => {
    const percent = (seg.archiveCount / totalArchives.value) * 100;
    const len = (seg.archiveCount / totalArchives.value) * donutCircumference;
    const offset = -accumulated;
    accumulated += len;
    return {
      color: seg.color,
      dashArray: `${len} ${donutCircumference}`,
      dashOffset: offset,
      name: seg.name,
      percent,
    };
  });
});
</script>

<template>
  <div class="storage-page">
    <header class="page-header">
      <div class="page-header-title-block">
        <div class="page-header-title">Storage</div>
        <div class="page-header-breadcrumb">all databases</div>
      </div>
    </header>

    <div class="storage-content">
      <div v-if="loading" class="empty-msg">
        <div class="spinner" /> Loading storage data…
      </div>

      <template v-else>
        <!-- Hero stats -->
        <div class="storage-hero">
          <div class="storage-hero-label">Total backups</div>
          <div class="storage-hero-big">
            {{ totalArchives }}<span> across {{ sourcesStore.sources.length }} databases</span>
          </div>
        </div>

        <!-- Donut + breakdown -->
        <div class="storage-pie-wrap">
          <div class="storage-pie">
            <svg width="200" height="200" viewBox="0 0 200 200" style="transform: rotate(-90deg)">
              <circle cx="100" cy="100" :r="donutRadius" fill="none" stroke="var(--color-surface-raised)" stroke-width="18" />
              <circle
                v-for="(arc, i) in donutArcs"
                :key="i"
                cx="100" cy="100" :r="donutRadius"
                fill="none"
                :stroke="arc.color"
                stroke-width="18"
                :stroke-dasharray="arc.dashArray"
                :stroke-dashoffset="arc.dashOffset"
                style="transition: stroke-dasharray 1s var(--ease-out)"
              />
            </svg>
            <div class="donut-center">
              <div>
                <div class="donut-center-big">{{ segments.length }}</div>
                <div class="donut-center-label">databases</div>
              </div>
            </div>
          </div>

          <!-- Legend / breakdown list -->
          <div class="storage-breakdown">
            <div
              v-for="seg in segments"
              :key="seg.id"
              class="breakdown-row"
            >
              <div class="breakdown-dot" :style="{ background: seg.color }" />
              <div class="breakdown-info">
                <div class="breakdown-name">{{ seg.name }}</div>
                <div class="breakdown-bar">
                  <div
                    class="breakdown-bar-fill"
                    :style="{ width: `${(seg.archiveCount / totalArchives) * 100}%`, background: seg.color }"
                  />
                </div>
              </div>
              <div class="breakdown-value">
                {{ seg.archiveCount }} <span class="breakdown-unit">backups</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Per-database detail cards -->
        <div class="storage-section-title">Per-database detail</div>
        <div class="storage-grid">
          <div
            v-for="source in sourcesStore.sources"
            :key="source.id"
            class="storage-card"
          >
            <div class="storage-card-header">
              <span class="storage-card-dot" :style="{ background: typeColor(source.source_type) }" />
              <span class="storage-card-name">{{ source.display_label }}</span>
              <span class="storage-card-type">{{ source.source_type }}</span>
            </div>
            <div class="storage-card-stats">
              <div class="storage-card-stat">
                <div class="storage-card-stat-value">{{ source.archive_count }}</div>
                <div class="storage-card-stat-label">Backups</div>
              </div>
              <div class="storage-card-stat">
                <div class="storage-card-stat-value">{{ source.policy_count }}</div>
                <div class="storage-card-stat-label">Plans</div>
              </div>
              <div class="storage-card-stat">
                <div class="storage-card-stat-value">{{ source.enabled ? 'Active' : 'Paused' }}</div>
                <div class="storage-card-stat-label">Status</div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.storage-page {
  display: flex;
  flex-direction: column;
  height: 100%;
}

.page-header {
  height: 60px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  padding: 0 32px;
  background: var(--color-background);
  flex-shrink: 0;
}
.page-header-title-block { display: flex; align-items: baseline; gap: 12px; }
.page-header-title {
  font-family: var(--font-display);
  font-size: 22px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.page-header-breadcrumb {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
  letter-spacing: 0.04em;
}

.storage-content {
  flex: 1;
  overflow-y: auto;
  padding: 32px 48px 80px;
  max-width: 900px;
  margin: 0 auto;
  width: 100%;
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
.spinner {
  width: 14px;
  height: 14px;
  border: 2px solid var(--color-wine-soft);
  border-top-color: var(--color-wine);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

/* Hero */
.storage-hero {
  margin-bottom: 24px;
}
.storage-hero-label {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-text-faint);
  margin-bottom: 6px;
}
.storage-hero-big {
  font-family: var(--font-display);
  font-size: 48px;
  letter-spacing: -0.03em;
  line-height: 1;
  color: var(--color-text-primary);
}
.storage-hero-big span {
  font-family: var(--font-ui);
  font-size: 16px;
  color: var(--color-text-faint);
  font-weight: 400;
  margin-left: 8px;
}

/* Donut + breakdown */
.storage-pie-wrap {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 40px;
  align-items: center;
  padding: 32px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 16px;
  margin: 24px 0 32px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.storage-pie {
  position: relative;
  width: 200px;
  height: 200px;
  flex-shrink: 0;
}
.donut-center {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  text-align: center;
}
.donut-center-big {
  font-family: var(--font-display);
  font-size: 32px;
  letter-spacing: -0.02em;
  color: var(--color-text-primary);
}
.donut-center-label {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--color-text-faint);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-top: -4px;
}

.storage-breakdown {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.breakdown-row {
  display: flex;
  align-items: center;
  gap: 14px;
}
.breakdown-dot {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  flex-shrink: 0;
}
.breakdown-info {
  flex: 1;
  min-width: 0;
}
.breakdown-name {
  font-size: 13.5px;
  font-weight: 500;
  color: var(--color-text-primary);
  margin-bottom: 5px;
}
.breakdown-bar {
  height: 4px;
  background: var(--color-surface-raised);
  border-radius: 2px;
  overflow: hidden;
}
.breakdown-bar-fill {
  height: 100%;
  border-radius: 2px;
  transition: width 1s var(--ease-out);
}
.breakdown-value {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--color-text-muted);
  min-width: 80px;
  text-align: right;
}
.breakdown-unit {
  color: var(--color-text-faint);
}

/* Section title */
.storage-section-title {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-text-faint);
  margin-bottom: 12px;
}

/* Grid cards */
.storage-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 12px;
}
.storage-card {
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface);
  transition: all var(--duration-fast) var(--ease-out);
}
.storage-card:hover {
  border-color: var(--color-border-strong);
  box-shadow: var(--shadow-sm);
}
.storage-card-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.storage-card-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.storage-card-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-primary);
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.storage-card-type {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--color-text-faint);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.storage-card-stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}
.storage-card-stat {
  text-align: center;
  padding: 8px 4px;
  background: var(--color-background);
  border-radius: 8px;
}
.storage-card-stat-value {
  font-family: var(--font-mono);
  font-size: 14px;
  color: var(--color-text-primary);
  font-feature-settings: "tnum";
}
.storage-card-stat-label {
  font-family: var(--font-mono);
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
  margin-top: 2px;
}
</style>

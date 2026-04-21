<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useActiveDatabase } from "@/composables/useActiveDatabase";
import { useSourcesStore, type Policy } from "@/stores/sources";

const sourcesStore = useSourcesStore();
const { activeDbId, activeDatabase } = useActiveDatabase();

const policies = ref<Policy[]>([]);
const loading = ref(false);

async function loadPolicies() {
  if (!activeDbId.value) return;
  loading.value = true;
  try {
    policies.value = await sourcesStore.fetchPolicies(activeDbId.value);
  } catch {
    policies.value = [];
  } finally {
    loading.value = false;
  }
}

onMounted(loadPolicies);
watch(activeDbId, loadPolicies);

// Compute next run countdown
function timeUntil(dateStr: string | null): string {
  if (!dateStr) return "—";
  const diff = new Date(dateStr).getTime() - Date.now();
  if (diff <= 0) return "overdue";
  const mins = Math.floor(diff / 60000);
  if (mins < 60) return `${mins} min`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours}h ${mins % 60}m`;
  const days = Math.floor(hours / 24);
  return `${days}d ${hours % 24}h`;
}

function cronToHuman(cron: string): string {
  if (!cron) return "—";
  const parts = cron.trim().split(/\s+/);
  if (parts.length < 5) return cron;
  const [min, hour, dom, , dow] = parts;

  if (dom === "*" && dow === "*") {
    if (hour === "*") return `Every hour at :${min.padStart(2, "0")}`;
    if (hour.includes("/")) return `Every ${hour.split("/")[1]} hours`;
    return `Daily at ${hour.padStart(2, "0")}:${min.padStart(2, "0")}`;
  }
  if (dow !== "*") return `Weekly (${dow}) at ${hour}:${min.padStart(2, "0")}`;
  return cron;
}

function retentionSummary(policy: Record<string, number> | null): string {
  if (!policy) return "default";
  const parts: string[] = [];
  if (policy.keep_daily) parts.push(`${policy.keep_daily}d`);
  if (policy.keep_weekly) parts.push(`${policy.keep_weekly}w`);
  if (policy.keep_monthly) parts.push(`${policy.keep_monthly}m`);
  if (policy.keep_yearly) parts.push(`${policy.keep_yearly}y`);
  return parts.join(" / ") || "default";
}

// Find the primary (first) policy for the hero section
const primaryPolicy = computed(() => policies.value[0] || null);
</script>

<template>
  <div class="schedule-page">
    <header class="page-header">
      <div class="page-header-title-block">
        <div class="page-header-title">{{ activeDatabase?.display_label || 'Schedule' }}</div>
        <div class="page-header-breadcrumb">schedule</div>
      </div>
    </header>

    <div class="schedule-content">
      <!-- Loading -->
      <div v-if="loading" class="empty-msg">
        <div class="spinner" /> Loading schedule…
      </div>

      <!-- No database -->
      <div v-else-if="!activeDbId" class="empty-msg">
        Select a database from the sidebar.
      </div>

      <!-- No policies -->
      <div v-else-if="policies.length === 0" class="empty-msg">
        No backup schedule configured for this database.
      </div>

      <template v-else>
        <!-- Hero: next backup countdown -->
        <div class="schedule-hero">
          <div class="schedule-hero-label">Next backup in</div>
          <div class="schedule-hero-big">{{ timeUntil(primaryPolicy?.next_run ?? null) }}</div>
          <div class="schedule-hero-sub">
            {{ cronToHuman(primaryPolicy?.schedule_cron ?? '') }} · retained {{ retentionSummary(primaryPolicy?.retention_policy ?? null) }}
          </div>
        </div>

        <!-- Rules list -->
        <div class="schedule-section-title">
          Rules for {{ activeDatabase?.display_label }}
        </div>

        <div class="schedule-rules">
          <div
            v-for="policy in policies"
            :key="policy.id"
            class="schedule-rule"
          >
            <div class="rule-icon">
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="8" cy="8" r="6" />
                <path d="M8 5v3l2 1.5" />
              </svg>
            </div>
            <div class="rule-info">
              <div class="rule-title">{{ policy.name }}</div>
              <div class="rule-sub">{{ cronToHuman(policy.schedule_cron) }}</div>
            </div>
            <div class="rule-meta">
              <span class="rule-status" :class="{ enabled: policy.schedule_enabled, disabled: !policy.schedule_enabled }">
                {{ policy.schedule_enabled ? 'Enabled' : 'Paused' }}
              </span>
            </div>
          </div>

          <!-- Retention rule card -->
          <div class="schedule-rule">
            <div class="rule-icon retention">
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2.5" y="3.5" width="11" height="10" rx="1.5" />
                <path d="M2.5 6.5h11M5.5 2v3M10.5 2v3" />
              </svg>
            </div>
            <div class="rule-info">
              <div class="rule-title">Retention</div>
              <div class="rule-sub">{{ retentionSummary(primaryPolicy?.retention_policy ?? null) }} · pinned backups excluded</div>
            </div>
            <div class="rule-meta">
              <span class="rule-action">Edit</span>
            </div>
          </div>
        </div>

        <!-- Schedule details table -->
        <div class="schedule-section-title" style="margin-top: 28px;">All plans</div>
        <div class="schedule-table">
          <div class="schedule-table-header">
            <div>Plan</div>
            <div>Schedule</div>
            <div>Last run</div>
            <div>Next run</div>
            <div>Status</div>
          </div>
          <div
            v-for="policy in policies"
            :key="policy.id"
            class="schedule-table-row"
          >
            <div class="schedule-cell-name">{{ policy.name }}</div>
            <div class="schedule-cell-mono">{{ cronToHuman(policy.schedule_cron) }}</div>
            <div class="schedule-cell-mono">{{ policy.last_run ? new Date(policy.last_run).toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—' }}</div>
            <div class="schedule-cell-mono">{{ timeUntil(policy.next_run) }}</div>
            <div>
              <span class="status-pill" :class="policy.status">{{ policy.status }}</span>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.schedule-page {
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

.schedule-content {
  flex: 1;
  overflow-y: auto;
  padding: 32px 48px 80px;
  max-width: 860px;
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
  width: 14px; height: 14px;
  border: 2px solid var(--color-wine-soft);
  border-top-color: var(--color-wine);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}

/* Hero */
.schedule-hero {
  padding: 24px 28px;
  background: linear-gradient(135deg, var(--color-wine-soft), var(--color-oak-soft));
  border-radius: 16px;
  border: 1px solid var(--color-border);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.schedule-hero-label {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-text-muted);
}
.schedule-hero-big {
  font-family: var(--font-display);
  font-size: 36px;
  letter-spacing: -0.02em;
  color: var(--color-text-primary);
  margin-top: 4px;
}
.schedule-hero-sub {
  font-size: 13px;
  color: var(--color-text-muted);
  margin-top: 4px;
}

/* Section title */
.schedule-section-title {
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--color-text-faint);
  padding: 12px 0 10px;
}

/* Rules */
.schedule-rules {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.schedule-rule {
  padding: 16px 20px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  background: var(--color-surface);
  display: flex;
  align-items: center;
  gap: 16px;
  transition: all var(--duration-fast) var(--ease-out);
}
.schedule-rule:hover {
  border-color: var(--color-border-strong);
}
.rule-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: var(--color-wine-soft);
  color: var(--color-wine);
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.rule-icon.retention {
  background: var(--color-oak-soft);
  color: var(--color-oak);
}
.rule-info { flex: 1; }
.rule-title {
  font-size: 14px;
  font-weight: 500;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.rule-sub {
  font-size: 12.5px;
  color: var(--color-text-muted);
  margin-top: 2px;
}
.rule-meta {
  flex-shrink: 0;
}
.rule-status {
  font-size: 12px;
  font-family: var(--font-mono);
  padding: 4px 10px;
  border-radius: 6px;
}
.rule-status.enabled {
  color: var(--color-success);
  background: var(--color-success-soft);
}
.rule-status.disabled {
  color: var(--color-warning);
  background: var(--color-warning-soft);
}
.rule-action {
  font-size: 12px;
  font-family: var(--font-mono);
  color: var(--color-wine);
  padding: 4px 10px;
  border-radius: 6px;
  cursor: pointer;
}
.rule-action:hover {
  background: var(--color-wine-soft);
}

/* Table */
.schedule-table {
  border: 1px solid var(--color-border);
  border-radius: 12px;
  overflow: hidden;
  background: var(--color-surface);
}
.schedule-table-header {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr 1fr 90px;
  gap: 16px;
  padding: 10px 20px;
  font-family: var(--font-mono);
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-text-faint);
  background: var(--color-surface-raised);
  border-bottom: 1px solid var(--color-border);
}
.schedule-table-row {
  display: grid;
  grid-template-columns: 1.5fr 1fr 1fr 1fr 90px;
  gap: 16px;
  padding: 14px 20px;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  font-size: 13px;
}
.schedule-table-row:last-child { border-bottom: none; }
.schedule-cell-name {
  font-weight: 500;
  color: var(--color-text-primary);
}
.schedule-cell-mono {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--color-text-muted);
}
.status-pill {
  font-family: var(--font-mono);
  font-size: 10.5px;
  padding: 3px 8px;
  border-radius: 999px;
  text-transform: capitalize;
}
.status-pill.healthy { background: var(--color-success-soft); color: var(--color-success); }
.status-pill.idle { background: var(--color-surface-raised); color: var(--color-text-muted); }
.status-pill.warning { background: var(--color-warning-soft); color: var(--color-warning); }
.status-pill.failed { background: var(--color-danger-soft); color: var(--color-danger); }
.status-pill.running { background: var(--color-wine-soft); color: var(--color-wine); }
</style>

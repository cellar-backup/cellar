<script setup lang="ts">
import { ref, watch, onUnmounted, nextTick } from "vue";
import { usePlansStore, type Job } from "@/stores/plans";

const props = defineProps<{
  jobId: string | null;
  label: string;
}>();

const emit = defineEmits<{
  close: [];
}>();

const store = usePlansStore();

const logContent = ref<string | null>(null);
const logLoading = ref(false);
const scrollArea = ref<HTMLElement | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

function scrollToBottom() {
  nextTick(() => {
    if (scrollArea.value) {
      scrollArea.value.scrollTop = scrollArea.value.scrollHeight;
    }
  });
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

function isRunning(): boolean {
  if (!props.jobId) return false;
  const job = store.jobs.find((j: Job) => j.id === props.jobId);
  return job?.status === "running" || job?.status === "pending";
}

async function fetchLog() {
  if (!props.jobId) return;
  try {
    const result = await store.fetchJobLog(props.jobId);
    if (result.content !== null) {
      const wasAtBottom =
        scrollArea.value &&
        scrollArea.value.scrollHeight -
          scrollArea.value.scrollTop -
          scrollArea.value.clientHeight <
          50;
      logContent.value = result.content;
      if (wasAtBottom || logLoading.value) {
        scrollToBottom();
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

async function open() {
  logContent.value = null;
  logLoading.value = true;
  stopPolling();

  await fetchLog();
  logLoading.value = false;

  if (isRunning()) {
    pollTimer = setInterval(async () => {
      await fetchLog();
      if (!isRunning()) {
        stopPolling();
        await fetchLog();
      }
    }, 2000);
  }
}

function close() {
  stopPolling();
  logContent.value = null;
  emit("close");
}

watch(
  () => props.jobId,
  (id) => {
    if (id) open();
    else stopPolling();
  },
  { immediate: true },
);

onUnmounted(() => {
  stopPolling();
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="jobId"
      class="modal-backdrop"
      @click.self="close"
    >
      <div class="log-modal animate-modal-in">
        <!-- Header -->
        <div class="log-header">
          <div class="log-header-left">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 2.5h10a1 1 0 011 1v9a1 1 0 01-1 1H3a1 1 0 01-1-1v-9a1 1 0 011-1z" />
              <path d="M5 6h6M5 9h4" />
            </svg>
            <div>
              <div class="log-title">Job Log</div>
              <div class="log-sub">{{ label }}</div>
            </div>
          </div>
          <button class="modal-close" @click="close">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <div ref="scrollArea" class="log-content">
          <div v-if="logLoading" class="log-loading">
            <div class="spinner" />
            Loading log…
          </div>
          <pre v-else-if="logContent" class="log-pre">{{ logContent }}</pre>
          <p v-else class="log-empty">No log available for this job yet.</p>
          <div v-if="isRunning() && !logLoading" class="log-live">
            <div class="spinner" />
            Live — updating every 2 seconds
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
  z-index: 90;
  background: oklch(0.15 0.02 40 / 0.35);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: grid;
  place-items: center;
  animation: fade-in calc(0.2s * var(--motion-scale, 1) + 0.001s) var(--ease-out);
}
[data-theme="dark"] .modal-backdrop { background: oklch(0 0 0 / 0.6); }

.log-modal {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  width: 720px;
  max-width: calc(100vw - 32px);
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.log-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 24px;
  border-bottom: 1px solid var(--color-border);
}
.log-header-left {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--color-text-muted);
}
.log-title {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.log-sub {
  font-size: 12px;
  color: var(--color-text-muted);
  font-family: var(--font-mono);
}
.modal-close {
  color: var(--color-text-faint);
  padding: 4px;
  border-radius: 4px;
}
.modal-close:hover { color: var(--color-text-primary); }

.log-content {
  flex: 1;
  overflow-y: auto;
  padding: 20px 24px;
}
.log-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--color-text-muted);
  font-size: 13px;
}
.log-pre {
  white-space: pre-wrap;
  word-break: break-word;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.7;
  color: var(--color-text-primary);
  background: var(--color-surface-raised);
  border-radius: 10px;
  padding: 16px;
  margin: 0;
}
.log-empty {
  font-size: 13px;
  color: var(--color-text-muted);
}
.log-live {
  margin-top: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: var(--color-wine);
  font-family: var(--font-mono);
}

.spinner {
  width: 14px;
  height: 14px;
  border: 2px solid var(--color-wine-soft);
  border-top-color: var(--color-wine);
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
}
</style>

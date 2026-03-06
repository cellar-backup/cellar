<script setup lang="ts">
import { ref, watch, onUnmounted, nextTick } from "vue";
import { usePlansStore, type Job } from "@/stores/plans";
import { FileText, X, Loader2 } from "lucide-vue-next";

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

// Open when jobId is set
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
    <Transition name="log-modal">
      <div
        v-if="jobId"
        class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="close"
      >
        <div
          class="w-full max-w-3xl max-h-[80vh] flex flex-col rounded-2xl border border-border bg-surface shadow-xl"
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between border-b border-border px-6 py-4"
          >
            <div class="flex items-center gap-3">
              <FileText class="h-5 w-5 text-text-muted" />
              <div>
                <h2 class="text-sm font-semibold text-text-primary">Job Log</h2>
                <p class="text-xs text-text-muted">{{ label }}</p>
              </div>
            </div>
            <button
              class="rounded-lg p-1 text-text-muted hover:text-text-primary hover:bg-surface-raised transition-colors"
              @click="close"
            >
              <X class="h-5 w-5" />
            </button>
          </div>

          <!-- Content -->
          <div ref="scrollArea" class="flex-1 overflow-auto p-6">
            <div
              v-if="logLoading"
              class="flex items-center gap-2 text-text-muted"
            >
              <Loader2 class="h-4 w-4 animate-spin" />
              Loading log…
            </div>
            <pre
              v-else-if="logContent"
              class="whitespace-pre-wrap break-words text-xs font-mono leading-relaxed text-text-primary bg-surface-raised rounded-lg p-4 max-h-[60vh] overflow-auto"
              >{{ logContent }}</pre
            >
            <p v-else class="text-sm text-text-muted">
              No log available for this job yet.
            </p>
            <div
              v-if="isRunning() && !logLoading"
              class="mt-3 flex items-center gap-2 text-xs text-info"
            >
              <Loader2 class="h-3.5 w-3.5 animate-spin" />
              Live — updating every 2 seconds
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.log-modal-enter-active,
.log-modal-leave-active {
  transition: opacity 0.2s ease;
}
.log-modal-enter-from,
.log-modal-leave-to {
  opacity: 0;
}
</style>

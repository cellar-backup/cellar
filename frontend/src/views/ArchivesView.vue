<script setup lang="ts">
import { onMounted, ref } from "vue";
import { usePlansStore } from "@/stores/plans";
import {
  Archive,
  HardDrive,
  RotateCcw,
  Download,
  Loader2,
  Pin,
  PinOff,
} from "lucide-vue-next";

const store = usePlansStore();

onMounted(() => {
  store.fetchArchives();
});

const actionLoading = ref<string | null>(null);
const actionMessage = ref<{
  archiveId: string;
  text: string;
  ok: boolean;
} | null>(null);

async function restoreArchive(archiveId: string) {
  if (
    !confirm(
      "This will restore the database to the state captured in this archive. Existing data will be overwritten. Continue?",
    )
  ) {
    return;
  }

  actionLoading.value = archiveId;
  actionMessage.value = null;
  try {
    const data = await store.triggerRestore(archiveId);
    actionMessage.value = {
      archiveId,
      text: data.detail ?? "Restore job queued.",
      ok: true,
    };
  } catch {
    actionMessage.value = {
      archiveId,
      text: "Failed to queue restore.",
      ok: false,
    };
  } finally {
    actionLoading.value = null;
  }
}

async function exportArchive(archiveId: string) {
  actionLoading.value = archiveId;
  actionMessage.value = null;
  try {
    await store.downloadArchive(archiveId);
    actionMessage.value = {
      archiveId,
      text: "Download started.",
      ok: true,
    };
  } catch {
    actionMessage.value = {
      archiveId,
      text: "Failed to download archive.",
      ok: false,
    };
  } finally {
    actionLoading.value = null;
  }
}

function fmtDate(dateStr: string | null) {
  if (!dateStr) return "—";
  return new Date(dateStr).toLocaleString();
}

function fmtSize(bytes: number | null) {
  if (!bytes) return "—";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let i = 0;
  let size = bytes;
  while (size >= 1024 && i < units.length - 1) {
    size /= 1024;
    i++;
  }
  return `${size.toFixed(1)} ${units[i]}`;
}

const pinLoading = ref<string | null>(null);

async function togglePin(arc: { id: string; keep_forever: boolean }) {
  pinLoading.value = arc.id;
  const previous = arc.keep_forever;
  // Optimistic update — flip immediately so the UI reacts instantly
  arc.keep_forever = !previous;
  try {
    await store.toggleKeepForever(arc.id, !previous);
  } catch {
    // Revert on failure
    arc.keep_forever = previous;
    actionMessage.value = {
      archiveId: arc.id,
      text: "Failed to update pin status.",
      ok: false,
    };
  } finally {
    pinLoading.value = null;
  }
}
</script>

<template>
  <div class="p-6">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-semibold text-text-primary">Archives</h1>
      <p class="mt-1 text-text-muted">
        Browse snapshots, restore databases, or export dump files.
      </p>
    </div>

    <!-- Loading -->
    <div v-if="store.loading" class="mt-8 text-text-muted">
      Loading archives…
    </div>

    <!-- Empty -->
    <div
      v-else-if="store.archives.length === 0"
      class="mt-8 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <Archive class="mx-auto h-10 w-10 text-text-muted" />
      <p class="mt-3 text-text-muted">No archives yet.</p>
      <p class="mt-1 text-sm text-text-muted">
        Run a backup to create your first archive snapshot.
      </p>
    </div>

    <!-- Table -->
    <div
      v-else
      class="mt-6 overflow-x-auto rounded-xl border border-border bg-surface"
    >
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-border text-left text-xs text-text-muted">
            <th class="px-5 py-3 font-medium">Name</th>
            <th class="px-5 py-3 font-medium">Plan</th>
            <th class="px-5 py-3 font-medium">Created</th>
            <th class="px-5 py-3 font-medium">Size</th>
            <th class="px-5 py-3 font-medium">Deduplicated</th>
            <th class="px-5 py-3 font-medium text-right">Actions</th>
          </tr>
        </thead>
        <TransitionGroup name="table-row" tag="tbody">
          <tr
            v-for="arc in store.archives"
            :key="arc.id"
            class="border-b border-border last:border-none transition-all duration-300"
          >
            <td class="px-5 py-3 font-medium text-text-primary">
              <div class="flex items-center gap-2">
                <HardDrive class="h-4 w-4 text-text-muted" />
                {{ arc.archive_id ?? arc.id }}
                <span
                  v-if="arc.keep_forever"
                  class="inline-flex items-center gap-0.5 rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-amber-500"
                >
                  <Pin class="h-2.5 w-2.5" /> Pinned
                </span>
              </div>
            </td>
            <td class="px-5 py-3 text-text-muted">
              {{ arc.plan_name ?? "—" }}
            </td>
            <td class="px-5 py-3 text-text-muted">
              {{ fmtDate(arc.created_at) }}
            </td>
            <td class="px-5 py-3 text-text-muted">
              {{ fmtSize(arc.size_original) }}
            </td>
            <td class="px-5 py-3 text-text-muted">
              {{ fmtSize(arc.size_dedup) }}
            </td>
            <td class="px-5 py-3 text-right">
              <div class="flex items-center justify-end gap-1.5">
                <button
                  :disabled="pinLoading === arc.id"
                  class="flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors disabled:opacity-50"
                  :class="
                    arc.keep_forever
                      ? 'border-amber-500/30 bg-amber-500/10 text-amber-500 hover:bg-amber-500/20'
                      : 'border-border text-text-muted hover:bg-surface-raised'
                  "
                  :title="
                    arc.keep_forever
                      ? 'Unpin — allow pruning'
                      : 'Pin — keep forever'
                  "
                  @click="togglePin(arc)"
                >
                  <Pin v-if="!arc.keep_forever" class="h-3.5 w-3.5" />
                  <PinOff v-else class="h-3.5 w-3.5" />
                  {{ arc.keep_forever ? "Unpin" : "Pin" }}
                </button>
                <button
                  :disabled="actionLoading === arc.id"
                  class="flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                  title="Restore this archive back to the database"
                  @click="restoreArchive(arc.id)"
                >
                  <RotateCcw class="h-3.5 w-3.5" />
                  Restore
                </button>
                <button
                  :disabled="actionLoading === arc.id"
                  class="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
                  title="Download the dump file"
                  @click="exportArchive(arc.id)"
                >
                  <Download class="h-3.5 w-3.5" />
                  Export
                </button>
                <Loader2
                  v-if="actionLoading === arc.id"
                  class="ml-1 h-4 w-4 animate-spin text-text-muted"
                />
              </div>

              <!-- Inline message -->
              <div
                v-if="actionMessage?.archiveId === arc.id"
                class="mt-1.5 text-xs"
                :class="actionMessage.ok ? 'text-success' : 'text-danger'"
              >
                {{ actionMessage.text }}
              </div>
            </td>
          </tr>
        </TransitionGroup>
      </table>
    </div>
  </div>
</template>

<style scoped>
.table-row-enter-active,
.table-row-leave-active {
  transition: all 0.3s ease;
}
.table-row-enter-from {
  opacity: 0;
}
.table-row-leave-to {
  opacity: 0;
}
.table-row-move {
  transition: transform 0.3s ease;
}
</style>

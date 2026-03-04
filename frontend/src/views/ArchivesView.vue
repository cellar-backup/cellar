<script setup lang="ts">
import { onMounted, ref } from "vue";
import { usePlansStore } from "@/stores/plans";
import {
  Archive,
  HardDrive,
  RotateCcw,
  Download,
  Loader2,
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
        <tbody>
          <tr
            v-for="arc in store.archives"
            :key="arc.id"
            class="border-b border-border last:border-none"
          >
            <td class="px-5 py-3 font-medium text-text-primary">
              <div class="flex items-center gap-2">
                <HardDrive class="h-4 w-4 text-text-muted" />
                {{ arc.archive_id ?? arc.id }}
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
        </tbody>
      </table>
    </div>
  </div>
</template>

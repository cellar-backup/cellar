<script setup lang="ts">
import { onMounted, ref } from "vue";
import { usePlansStore } from "@/stores/plans";
import { useConfirm } from "@/composables/useConfirm";
import {
  Archive,
  HardDrive,
  RotateCcw,
  Download,
  Loader2,
  Pin,
  PinOff,
  Tag,
  Trash2,
} from "lucide-vue-next";

const store = usePlansStore();
const { confirm } = useConfirm();

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
    !(await confirm({
      title: "Restore Archive",
      message:
        "This will restore the database to the state captured in this archive. Existing data will be overwritten. Continue?",
      confirmLabel: "Restore",
      variant: "warning",
    }))
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

// ── Tag management ────────────────────────────
const tagEditId = ref<string | null>(null);
const tagInput = ref("");

function openTagEditor(arc: { id: string; tags?: string[] }) {
  tagEditId.value = arc.id;
  tagInput.value = (arc.tags ?? []).join(", ");
}

async function saveTags(arcId: string) {
  const tags = tagInput.value
    .split(",")
    .map((t) => t.trim())
    .filter(Boolean);
  try {
    await store.updateArchiveTags(arcId, tags);
  } catch {
    actionMessage.value = {
      archiveId: arcId,
      text: "Failed to update tags.",
      ok: false,
    };
  }
  tagEditId.value = null;
}

// ── Delete archive ────────────────────────────
async function deleteArchive(archiveId: string) {
  if (
    !(await confirm({
      title: "Delete Archive",
      message:
        "This will permanently remove this archive snapshot. This action cannot be undone.",
      confirmLabel: "Delete",
      variant: "danger",
    }))
  ) {
    return;
  }

  actionLoading.value = archiveId;
  actionMessage.value = null;
  try {
    await store.deleteArchive(archiveId);
    actionMessage.value = {
      archiveId,
      text: "Archive deleted.",
      ok: true,
    };
  } catch {
    actionMessage.value = {
      archiveId,
      text: "Failed to delete archive.",
      ok: false,
    };
  } finally {
    actionLoading.value = null;
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
            <th class="px-5 py-3 font-medium">Actions</th>
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
            <td class="px-5 py-3">
              <!-- Tags row -->
              <div
                v-if="arc.tags && arc.tags.length > 0 && tagEditId !== arc.id"
                class="mb-1.5 flex flex-wrap gap-1"
              >
                <span
                  v-for="tag in arc.tags"
                  :key="tag"
                  class="inline-flex items-center gap-0.5 rounded-full bg-accent/10 px-2 py-0.5 text-[10px] font-medium text-accent"
                >
                  <Tag class="h-2.5 w-2.5" />{{ tag }}
                </span>
              </div>
              <!-- Tag editor inline -->
              <div
                v-if="tagEditId === arc.id"
                class="mb-1.5 flex items-center gap-1.5"
              >
                <input
                  v-model="tagInput"
                  class="flex-1 rounded-lg border border-border bg-surface px-2 py-1 text-xs text-text-primary placeholder-text-muted focus:outline-none focus:ring-1 focus:ring-primary"
                  placeholder="tag1, tag2, …"
                  @keydown.enter="saveTags(arc.id)"
                  @keydown.escape="tagEditId = null"
                />
                <button
                  class="rounded-lg bg-primary px-2 py-1 text-xs font-medium text-white hover:bg-primary/90 transition-colors"
                  @click="saveTags(arc.id)"
                >
                  Save
                </button>
                <button
                  class="rounded-lg border border-border px-2 py-1 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors"
                  @click="tagEditId = null"
                >
                  Cancel
                </button>
              </div>
              <!-- Action buttons: tag, pin, export, restore, delete -->
              <div class="flex items-center gap-1.5">
                <!-- Tag -->
                <button
                  class="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors"
                  title="Edit tags"
                  @click="openTagEditor(arc)"
                >
                  <Tag class="h-3.5 w-3.5" />
                  Tag
                </button>
                <!-- Pin -->
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
                <!-- Export -->
                <button
                  :disabled="actionLoading === arc.id"
                  class="flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-text-muted hover:bg-surface-raised transition-colors disabled:opacity-50"
                  title="Download the dump file"
                  @click="exportArchive(arc.id)"
                >
                  <Download class="h-3.5 w-3.5" />
                  Export
                </button>
                <!-- Restore -->
                <button
                  :disabled="actionLoading === arc.id"
                  class="flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1.5 text-xs font-medium text-white hover:bg-primary/90 transition-colors disabled:opacity-50"
                  title="Restore this archive back to the database"
                  @click="restoreArchive(arc.id)"
                >
                  <RotateCcw class="h-3.5 w-3.5" />
                  Restore
                </button>
                <!-- Delete -->
                <button
                  :disabled="actionLoading === arc.id"
                  class="flex items-center gap-1 rounded-lg border border-danger/30 px-2.5 py-1.5 text-xs font-medium text-danger hover:bg-danger/10 transition-colors disabled:opacity-50"
                  title="Delete this archive permanently"
                  @click="deleteArchive(arc.id)"
                >
                  <Trash2 class="h-3.5 w-3.5" />
                  Delete
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
                :class="actionMessage?.ok ? 'text-success' : 'text-danger'"
              >
                {{ actionMessage?.text }}
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

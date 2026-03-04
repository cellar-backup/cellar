<script setup lang="ts">
import { onMounted } from "vue";
import { usePlansStore } from "@/stores/plans";
import { Archive, HardDrive } from "lucide-vue-next";

const store = usePlansStore();

onMounted(() => {
  store.fetchArchives();
});

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
        Browse all backup snapshots across your plans.
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
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

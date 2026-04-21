<script setup lang="ts">
import { ref, watch } from "vue";
import type { SourceArchive } from "@/stores/sources";

const props = defineProps<{
  open: boolean;
  archive: SourceArchive | null;
  dbName: string;
}>();

const emit = defineEmits<{
  close: [];
  confirm: [archive: SourceArchive];
}>();

const confirmText = ref("");

watch(() => props.open, (val) => {
  if (!val) confirmText.value = "";
});

function formatSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function formatTime(timestamp: string): string {
  return new Date(timestamp).toLocaleTimeString("en-US", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

const canConfirm = ref(false);
watch(confirmText, (val) => {
  canConfirm.value = val === props.dbName;
});

function handleConfirm() {
  if (!canConfirm.value || !props.archive) return;
  emit("confirm", props.archive);
  emit("close");
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open && archive" class="modal-backdrop" @click="emit('close')">
      <div class="modal animate-modal-in" @click.stop>
        <div class="modal-header">
          <div>
            <div class="modal-title">Restore backup</div>
            <div class="modal-sub">This will overwrite {{ dbName }}</div>
          </div>
          <button class="modal-close" @click="emit('close')">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        <div class="modal-body">
          <!-- Backup info card -->
          <div class="restore-info-card">
            <div class="restore-info-icon">
              <svg width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6.5 1.5h3v3c0 1 1.5 2 1.5 4v5.5a1.5 1.5 0 01-1.5 1.5h-3A1.5 1.5 0 015 14v-5.5c0-2 1.5-3 1.5-4v-3z" />
                <path d="M5 10h6" />
              </svg>
            </div>
            <div>
              <div class="restore-info-name">{{ archive.notes || archive.archive_id }}</div>
              <div class="restore-info-meta">
                {{ formatTime(archive.timestamp) }} · {{ formatSize(archive.size_compressed) }}
              </div>
            </div>
          </div>

          <div class="restore-warning">
            Restoring will replace the current database contents. A pre-restore
            snapshot will be created automatically, so you can roll back if
            something goes wrong.
          </div>

          <div class="field">
            <label class="field-label">
              Type <span class="confirm-name">{{ dbName }}</span> to confirm
            </label>
            <input
              v-model="confirmText"
              class="field-input"
              :placeholder="dbName"
              @keydown.enter="handleConfirm"
            />
          </div>
        </div>

        <div class="modal-footer">
          <div />
          <div class="modal-footer-actions">
            <button class="btn btn-ghost" @click="emit('close')">Cancel</button>
            <button
              class="btn btn-danger"
              :class="{ disabled: !canConfirm }"
              :disabled="!canConfirm"
              @click="handleConfirm"
            >
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 8a5 5 0 019.2-2.7" />
                <path d="M13 3v3h-3" />
                <path d="M13 8a5 5 0 01-9.2 2.7" />
                <path d="M3 13v-3h3" />
              </svg>
              Restore now
            </button>
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
  background: oklch(0.15 0.02 40 / 0.35);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: grid;
  place-items: center;
  z-index: 50;
  animation: fade-in calc(0.25s * var(--motion-scale, 1) + 0.001s) var(--ease-out);
}
[data-theme="dark"] .modal-backdrop { background: oklch(0 0 0 / 0.6); }

.modal {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  width: 520px;
  max-width: calc(100vw - 32px);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.modal-header {
  padding: 22px 24px 14px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.modal-title {
  font-family: var(--font-display);
  font-size: 22px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.modal-sub {
  font-size: 13px;
  color: var(--color-text-muted);
  margin-top: 4px;
}
.modal-close {
  color: var(--color-text-faint);
  padding: 4px;
  border-radius: 4px;
}
.modal-close:hover { color: var(--color-text-primary); }

.modal-body {
  padding: 8px 24px 20px;
}

.restore-info-card {
  padding: 16px;
  border-radius: 12px;
  background: var(--color-wine-soft);
  border: 1px solid color-mix(in oklch, var(--color-wine) 20%, var(--color-border));
  margin-bottom: 16px;
  display: flex;
  gap: 14px;
  align-items: center;
}
.restore-info-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: var(--color-wine);
  color: oklch(0.98 0 0);
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.restore-info-name {
  font-weight: 500;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.restore-info-meta {
  font-size: 12px;
  color: var(--color-text-muted);
  font-family: var(--font-mono);
  margin-top: 2px;
}

.restore-warning {
  font-size: 13px;
  color: var(--color-text-muted);
  line-height: 1.6;
  margin-bottom: 16px;
}

.field {
  margin-bottom: 0;
}
.confirm-name {
  font-family: var(--font-mono);
  color: var(--color-wine);
}

.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--color-surface-raised);
}
.modal-footer-actions {
  display: flex;
  gap: 8px;
}

.btn-danger.disabled {
  opacity: 0.4;
  cursor: not-allowed;
  transform: none !important;
}
</style>

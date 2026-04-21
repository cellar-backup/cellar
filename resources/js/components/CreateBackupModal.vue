<script setup lang="ts">
import { ref } from "vue";

defineProps<{
  open: boolean;
  dbName: string;
}>();

const emit = defineEmits<{
  close: [];
  create: [options: { label: string; compression: string; scope: string; encrypt: boolean; pinned: boolean }];
}>();

const label = ref("");
const compression = ref("gzip");
const scope = ref("full");
const encrypt = ref(true);
const pinned = ref(false);

function handleCreate() {
  emit("create", {
    label: label.value,
    compression: compression.value,
    scope: scope.value,
    encrypt: encrypt.value,
    pinned: pinned.value,
  });
  resetForm();
}

function handleClose() {
  emit("close");
  resetForm();
}

function resetForm() {
  label.value = "";
  compression.value = "gzip";
  scope.value = "full";
  encrypt.value = true;
  pinned.value = false;
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-backdrop" @click="handleClose">
      <div class="modal animate-modal-in" @click.stop>
        <!-- Header -->
        <div class="modal-header">
          <div>
            <div class="modal-title">New backup</div>
            <div class="modal-sub">{{ dbName }} · advanced options</div>
          </div>
          <button class="modal-close" @click="handleClose">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        <!-- Body -->
        <div class="modal-body">
          <div class="field">
            <label class="field-label">
              Label <span class="field-hint">(optional)</span>
            </label>
            <input
              v-model="label"
              class="field-input"
              placeholder="e.g. pre-migration snapshot"
              autofocus
            />
          </div>

          <div class="field">
            <label class="field-label">Scope</label>
            <div class="segmented">
              <button :class="{ active: scope === 'full' }" @click="scope = 'full'">Full database</button>
              <button :class="{ active: scope === 'schema' }" @click="scope = 'schema'">Schema only</button>
            </div>
          </div>

          <div class="field">
            <label class="field-label">Compression</label>
            <div class="segmented">
              <button :class="{ active: compression === 'none' }" @click="compression = 'none'">none</button>
              <button :class="{ active: compression === 'gzip' }" @click="compression = 'gzip'">gzip</button>
              <button :class="{ active: compression === 'zstd' }" @click="compression = 'zstd'">zstd</button>
            </div>
          </div>

          <div class="toggle-section">
            <div class="toggle-row">
              <div>
                <div class="toggle-title">Encrypt at rest</div>
                <div class="toggle-desc">AES-256 using workspace key</div>
              </div>
              <div class="toggle-switch" :class="{ on: encrypt }" @click="encrypt = !encrypt" />
            </div>
            <div class="toggle-row">
              <div>
                <div class="toggle-title">Pin (never auto-expire)</div>
                <div class="toggle-desc">Exempt from retention policy</div>
              </div>
              <div class="toggle-switch" :class="{ on: pinned }" @click="pinned = !pinned" />
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="modal-footer">
          <div class="modal-footer-meta">
            est. ~30s
          </div>
          <div class="modal-footer-actions">
            <button class="btn btn-ghost" @click="handleClose">Cancel</button>
            <button class="btn btn-primary" @click="handleCreate">
              <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6.5 1.5h3v3c0 1 1.5 2 1.5 4v5.5a1.5 1.5 0 01-1.5 1.5h-3A1.5 1.5 0 015 14v-5.5c0-2 1.5-3 1.5-4v-3z" />
                <path d="M5 10h6" />
              </svg>
              Create backup
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
  max-height: calc(100vh - 60px);
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
  overflow-y: auto;
}

.field {
  margin-bottom: 18px;
}
.field-hint {
  color: var(--color-text-faint);
  text-transform: none;
  letter-spacing: 0;
}

.toggle-section {
  border-top: 1px solid var(--color-border);
  padding-top: 12px;
}
.toggle-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
}
.toggle-row + .toggle-row {
  border-top: 1px dashed var(--color-border);
}
.toggle-title {
  font-size: 13.5px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.toggle-desc {
  font-size: 12px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: var(--color-surface-raised);
}
.modal-footer-meta {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--color-text-faint);
}
.modal-footer-actions {
  display: flex;
  gap: 8px;
}
</style>

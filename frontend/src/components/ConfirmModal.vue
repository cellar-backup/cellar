<script setup lang="ts">
import { useConfirm } from "@/composables/useConfirm";

const { visible, options, resolve } = useConfirm();

function onBackdrop() {
  resolve(false);
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === "Escape") resolve(false);
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      class="modal-backdrop"
      @click.self="onBackdrop"
      @keydown="onKeydown"
    >
      <div class="modal animate-modal-in" role="alertdialog" aria-modal="true">
        <!-- Header -->
        <div class="modal-header">
          <div class="modal-header-icon" :class="options.variant">
            <!-- Danger -->
            <svg v-if="options.variant === 'danger'" width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M2 14h12L8 3 2 14zM8 7v3M8 12h.01" />
            </svg>
            <!-- Warning -->
            <svg v-else-if="options.variant === 'warning'" width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M2 14h12L8 3 2 14zM8 7v3M8 12h.01" />
            </svg>
            <!-- Default -->
            <svg v-else width="18" height="18" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="8" cy="8" r="6" />
              <path d="M8 11h.01M8 5v3" />
            </svg>
          </div>
          <h2 class="modal-title-sm">{{ options.title }}</h2>
          <button class="modal-close" @click="resolve(false)">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
              <path d="M4 4l8 8M12 4l-8 8" />
            </svg>
          </button>
        </div>

        <!-- Body -->
        <div class="modal-body">
          <p class="modal-message">{{ options.message }}</p>
        </div>

        <!-- Footer -->
        <div class="modal-footer">
          <div />
          <div class="modal-footer-actions">
            <button class="btn btn-ghost" @click="resolve(false)">
              {{ options.cancelLabel }}
            </button>
            <button
              class="btn"
              :class="{
                'btn-danger': options.variant === 'danger',
                'btn-warning': options.variant === 'warning',
                'btn-primary': options.variant === 'default',
              }"
              autofocus
              @click="resolve(true)"
            >
              {{ options.confirmLabel }}
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
  z-index: 100;
  background: oklch(0.15 0.02 40 / 0.35);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: grid;
  place-items: center;
  animation: fade-in calc(0.2s * var(--motion-scale, 1) + 0.001s) var(--ease-out);
}
[data-theme="dark"] .modal-backdrop { background: oklch(0 0 0 / 0.6); }

.modal {
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  width: 440px;
  max-width: calc(100vw - 32px);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.modal-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 24px 12px;
}
.modal-header-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.modal-header-icon.danger {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}
.modal-header-icon.warning {
  background: var(--color-warning-soft);
  color: var(--color-warning);
}
.modal-header-icon.default {
  background: var(--color-wine-soft);
  color: var(--color-wine);
}
.modal-title-sm {
  flex: 1;
  font-family: var(--font-display);
  font-size: 18px;
  letter-spacing: -0.01em;
  color: var(--color-text-primary);
}
.modal-close {
  color: var(--color-text-faint);
  padding: 4px;
  border-radius: 4px;
}
.modal-close:hover { color: var(--color-text-primary); }

.modal-body {
  padding: 4px 24px 20px;
}
.modal-message {
  font-size: 13.5px;
  line-height: 1.6;
  color: var(--color-text-muted);
}

.modal-footer {
  padding: 14px 24px;
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

.btn-warning {
  background: var(--color-warning);
  color: oklch(0.15 0.02 40);
}
.btn-warning:hover { filter: brightness(1.1); }
</style>

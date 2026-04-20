<script setup lang="ts">
import { useToast } from "@/composables/useToast";

const { toasts, dismiss } = useToast();
</script>

<template>
  <div class="toast-stack">
    <div
      v-for="toast in toasts"
      :key="toast.id"
      class="toast"
      :class="{ exit: toast.exiting }"
    >
      <div class="toast-icon" :class="toast.type || ''">
        <!-- Spinner for progress -->
        <div v-if="toast.type === 'progress'" class="spinner" />
        <!-- Check for success -->
        <svg v-else-if="toast.type === 'success'" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3.5 8.5L6.5 11.5 12.5 5" />
        </svg>
        <!-- X for error -->
        <svg v-else-if="toast.type === 'error'" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M4 4l8 8M12 4l-8 8" />
        </svg>
        <!-- Default check -->
        <svg v-else width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3.5 8.5L6.5 11.5 12.5 5" />
        </svg>
      </div>
      <div class="toast-body">
        <div class="toast-title">{{ toast.title }}</div>
        <div v-if="toast.desc" class="toast-desc">{{ toast.desc }}</div>
      </div>
      <button class="toast-close" @click="dismiss(toast.id)">
        <svg width="10" height="10" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
          <path d="M4 4l8 8M12 4l-8 8" />
        </svg>
      </button>
      <div
        v-if="toast.progress !== undefined"
        class="toast-progress"
        :style="{ width: `${toast.progress}%` }"
      />
    </div>
  </div>
</template>

<style scoped>
.toast-stack {
  position: fixed;
  bottom: 20px;
  right: 20px;
  display: flex;
  flex-direction: column-reverse;
  gap: 8px;
  z-index: 60;
  pointer-events: none;
}

.toast {
  pointer-events: auto;
  min-width: 320px;
  max-width: 400px;
  padding: 12px 14px;
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  box-shadow: var(--shadow-lg);
  display: flex;
  align-items: flex-start;
  gap: 12px;
  animation: toast-in calc(0.4s * var(--motion-scale, 1) + 0.001s) var(--ease-spring);
  position: relative;
  overflow: hidden;
}
.toast.exit {
  animation: toast-out 0.3s var(--ease-out) forwards;
}

.toast-icon {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  background: var(--color-wine-soft);
  color: var(--color-wine);
}
.toast-icon.success {
  background: var(--color-success-soft);
  color: var(--color-success);
}
.toast-icon.error {
  background: var(--color-danger-soft);
  color: var(--color-danger);
}
.toast-icon.progress {
  background: var(--color-wine-soft);
  color: var(--color-wine);
}

.toast-body { flex: 1; min-width: 0; }
.toast-title {
  font-size: 13.5px;
  font-weight: 500;
  color: var(--color-text-primary);
  letter-spacing: -0.01em;
}
.toast-desc {
  font-size: 12px;
  color: var(--color-text-muted);
  margin-top: 2px;
  font-family: var(--font-mono);
}

.toast-progress {
  position: absolute;
  left: 0;
  bottom: 0;
  height: 2px;
  background: var(--color-wine);
  transition: width 0.3s linear;
}

.toast-close {
  color: var(--color-text-faint);
  width: 20px;
  height: 20px;
  display: grid;
  place-items: center;
  border-radius: 4px;
}
.toast-close:hover {
  color: var(--color-text-primary);
  background: var(--color-surface-raised);
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

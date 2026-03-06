<script setup lang="ts">
import { useConfirm } from "@/composables/useConfirm";
import { AlertTriangle, Trash2, HelpCircle, X } from "lucide-vue-next";

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
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="visible"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm"
        @click.self="onBackdrop"
        @keydown="onKeydown"
      >
        <Transition
          enter-active-class="transition duration-150 ease-out"
          enter-from-class="scale-95 opacity-0"
          enter-to-class="scale-100 opacity-100"
          leave-active-class="transition duration-100 ease-in"
          leave-from-class="scale-100 opacity-100"
          leave-to-class="scale-95 opacity-0"
        >
          <div
            v-if="visible"
            class="w-full max-w-md rounded-2xl border border-border bg-surface shadow-xl"
            role="alertdialog"
            aria-modal="true"
          >
            <!-- Header -->
            <div
              class="flex items-center gap-3 border-b border-border px-6 py-4"
            >
              <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                :class="{
                  'bg-red-500/10 text-red-400': options.variant === 'danger',
                  'bg-amber-500/10 text-amber-400':
                    options.variant === 'warning',
                  'bg-accent/10 text-accent': options.variant === 'default',
                }"
              >
                <Trash2 v-if="options.variant === 'danger'" class="h-5 w-5" />
                <AlertTriangle
                  v-else-if="options.variant === 'warning'"
                  class="h-5 w-5"
                />
                <HelpCircle v-else class="h-5 w-5" />
              </div>
              <h2 class="text-lg font-semibold text-text-primary">
                {{ options.title }}
              </h2>
              <button
                class="ml-auto rounded-lg p-2 text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
                @click="resolve(false)"
              >
                <X class="h-5 w-5" />
              </button>
            </div>

            <!-- Body -->
            <div class="px-6 py-5">
              <p class="text-sm leading-relaxed text-text-muted">
                {{ options.message }}
              </p>
            </div>

            <!-- Footer -->
            <div
              class="flex items-center justify-end gap-3 border-t border-border px-6 py-4"
            >
              <button
                class="rounded-xl px-4 py-2 text-sm font-medium text-text-muted hover:bg-surface-raised hover:text-text-primary transition-colors"
                @click="resolve(false)"
              >
                {{ options.cancelLabel }}
              </button>
              <button
                class="rounded-xl px-4 py-2 text-sm font-medium transition-colors"
                :class="{
                  'bg-red-500/10 text-red-400 hover:bg-red-500/20':
                    options.variant === 'danger',
                  'bg-amber-500/10 text-amber-400 hover:bg-amber-500/20':
                    options.variant === 'warning',
                  'bg-accent/10 text-accent hover:bg-accent/20':
                    options.variant === 'default',
                }"
                ref="confirmBtn"
                autofocus
                @click="resolve(true)"
              >
                {{ options.confirmLabel }}
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

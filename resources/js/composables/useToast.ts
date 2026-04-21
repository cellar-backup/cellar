import { ref } from "vue";

export interface Toast {
  id: string;
  title: string;
  desc?: string;
  type?: "success" | "progress" | "error";
  progress?: number;
  sticky?: boolean;
  duration?: number;
  exiting?: boolean;
}

const toasts = ref<Toast[]>([]);

export function useToast() {
  function push(t: Omit<Toast, "id">): string {
    const id = Math.random().toString(36).slice(2);
    const toast: Toast = { id, ...t };
    toasts.value.push(toast);
    if (!t.sticky) {
      setTimeout(() => dismiss(id), t.duration || 4200);
    }
    return id;
  }

  function update(id: string, patch: Partial<Toast>) {
    const idx = toasts.value.findIndex((t) => t.id === id);
    if (idx !== -1) {
      toasts.value[idx] = { ...toasts.value[idx], ...patch };
    }
  }

  function dismiss(id: string) {
    const idx = toasts.value.findIndex((t) => t.id === id);
    if (idx !== -1) {
      toasts.value[idx] = { ...toasts.value[idx], exiting: true };
      setTimeout(() => {
        toasts.value = toasts.value.filter((t) => t.id !== id);
      }, 300);
    }
  }

  return {
    toasts,
    push,
    update,
    dismiss,
  };
}

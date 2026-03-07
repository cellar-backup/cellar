import { ref, readonly } from "vue";

export interface ConfirmOptions {
  title: string;
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  variant?: "danger" | "warning" | "default";
}

const visible = ref(false);
const options = ref<ConfirmOptions>({
  title: "",
  message: "",
  confirmLabel: "Confirm",
  cancelLabel: "Cancel",
  variant: "default",
});

let _resolve: ((value: boolean) => void) | null = null;

export function useConfirm() {
  function confirm(opts: ConfirmOptions): Promise<boolean> {
    options.value = {
      confirmLabel: "Confirm",
      cancelLabel: "Cancel",
      variant: "default",
      ...opts,
    };
    visible.value = true;

    return new Promise<boolean>((resolve) => {
      _resolve = resolve;
    });
  }

  function resolve(value: boolean) {
    visible.value = false;
    _resolve?.(value);
    _resolve = null;
  }

  return {
    visible: readonly(visible),
    options: readonly(options),
    confirm,
    resolve,
  };
}

import { ref } from "vue";

export type ToastVariant = "success" | "error" | "info";

export type ToastItem = {
  id: number;
  title: string;
  message?: string;
  variant: ToastVariant;
  durationMs: number;
};

const toasts = ref<ToastItem[]>([]);
let nextToastId = 1;
const TOAST_ENTER_ANIMATION_MS = 600;

function remove(id: number) {
  toasts.value = toasts.value.filter((item) => item.id !== id);
}

function push(input: {
  title: string;
  message?: string;
  variant?: ToastVariant;
  durationMs?: number;
}) {
  const variant = input.variant ?? "info";
  const defaultDuration = variant === "error" ? 6000 : 3000;
  const toast: ToastItem = {
    id: nextToastId++,
    title: input.title,
    message: input.message,
    variant,
    durationMs: input.durationMs ?? defaultDuration,
  };

  toasts.value = [...toasts.value, toast];
  // Start dismiss countdown after entry animation so the toast stays readable.
  window.setTimeout(() => remove(toast.id), toast.durationMs + TOAST_ENTER_ANIMATION_MS);
  return toast.id;
}

export function useToast() {
  return {
    toasts,
    remove,
    push,
    success: (title: string, message?: string, durationMs?: number) =>
      push({ title, message, durationMs, variant: "success" }),
    error: (title: string, message?: string, durationMs?: number) =>
      push({ title, message, durationMs, variant: "error" }),
    info: (title: string, message?: string, durationMs?: number) =>
      push({ title, message, durationMs, variant: "info" }),
  };
}

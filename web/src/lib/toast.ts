import { ref } from 'vue'

export type ToastType = 'success' | 'error'

export type Toast = {
  id: number
  message: string
  type: ToastType
}

// Module-level shared state so every importer sees the same toast queue.
const toasts = ref<Toast[]>([])
let sequence = 0

export function useToasts() {
  return toasts
}

/**
 * Show a transient toast. Use for confirming successful saves/actions.
 */
export function showToast(message: string, type: ToastType = 'success', timeout = 3000): void {
  const id = ++sequence
  toasts.value.push({ id, message, type })
  window.setTimeout(() => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }, timeout)
}

/**
 * Ask the user to confirm a crucial/destructive action BEFORE it runs.
 * Returns true when confirmed. Centralized so every caller is consistent.
 */
export function confirmAction(message: string): boolean {
  return window.confirm(message)
}

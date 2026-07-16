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

const MAX_VISIBLE_TOASTS = 4

const BASE_TIMEOUT = 3000
const LENGTH_GRACE_CHARS = 20
const MS_PER_EXTRA_CHAR = 50
const MAX_TIMEOUT = 8000
const ERROR_MIN_TIMEOUT = 4500

type TimerEntry = {
  timerId: ReturnType<typeof window.setTimeout> | null
  remaining: number
  armedAt: number
}

// Per-toast timer bookkeeping, kept out of the reactive Toast objects so the
// template only ever sees id/message/type.
const timers = new Map<number, TimerEntry>()

/**
 * Longer messages get more time to read; errors get a floor bump since
 * severity is its own signal of importance, not just message length.
 */
function defaultTimeoutFor(message: string, type: ToastType): number {
  const lengthBased = BASE_TIMEOUT + Math.max(0, message.length - LENGTH_GRACE_CHARS) * MS_PER_EXTRA_CHAR
  const floor = type === 'error' ? ERROR_MIN_TIMEOUT : BASE_TIMEOUT
  return Math.min(MAX_TIMEOUT, Math.max(floor, lengthBased))
}

function arm(id: number, ms: number): void {
  const timerId = window.setTimeout(() => dismissToast(id), ms)
  timers.set(id, { timerId, remaining: ms, armedAt: Date.now() })
}

function clearTimer(id: number): void {
  const entry = timers.get(id)
  if (entry?.timerId !== null && entry?.timerId !== undefined) {
    window.clearTimeout(entry.timerId)
  }
  timers.delete(id)
}

export function useToasts() {
  return toasts
}

/**
 * Show a transient toast. Use for confirming successful saves/actions.
 * `timeout` is optional — omit it to auto-scale by message length/severity.
 */
export function showToast(message: string, type: ToastType = 'success', timeout?: number): void {
  const id = ++sequence
  toasts.value.push({ id, message, type })

  if (toasts.value.length > MAX_VISIBLE_TOASTS) {
    const evicted = toasts.value.splice(0, toasts.value.length - MAX_VISIBLE_TOASTS)
    evicted.forEach((toast) => clearTimer(toast.id))
  }

  arm(id, timeout ?? defaultTimeoutFor(message, type))
}

/** Dismiss a toast immediately — used by the close button and auto-expiry alike. */
export function dismissToast(id: number): void {
  clearTimer(id)
  toasts.value = toasts.value.filter((toast) => toast.id !== id)
}

/** Pause a toast's countdown, e.g. while the user is hovering it. */
export function pauseToast(id: number): void {
  const entry = timers.get(id)
  if (!entry || entry.timerId === null) return

  window.clearTimeout(entry.timerId)
  entry.remaining = Math.max(0, entry.remaining - (Date.now() - entry.armedAt))
  entry.timerId = null
}

/** Resume a paused toast's countdown with whatever time it had left. */
export function resumeToast(id: number): void {
  const entry = timers.get(id)
  if (!entry || entry.timerId !== null) return

  arm(id, entry.remaining)
}

/**
 * Ask the user to confirm a crucial/destructive action BEFORE it runs.
 * Returns true when confirmed. Centralized so every caller is consistent.
 */
export function confirmAction(message: string): boolean {
  return window.confirm(message)
}

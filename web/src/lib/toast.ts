import { reactive, ref } from 'vue'

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

// --- In-app confirm / prompt dialog ----------------------------------------
// Replaces the native window.confirm/prompt ("Localhost says…") popups with a
// styled modal rendered by ConfirmHost.vue (mounted once, app-wide). The public
// API stays promise-based so callers just `await confirmAction(...)`.

export type ConfirmTone = 'default' | 'danger'

export type ConfirmOptions = {
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  tone?: ConfirmTone
}

export type PromptOptions = {
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  placeholder?: string
  initialValue?: string
  /** When true, an empty/whitespace value is rejected with `requiredError`. */
  required?: boolean
  requiredError?: string
}

type DialogMode = 'confirm' | 'prompt'

type DialogState = {
  open: boolean
  mode: DialogMode
  title: string
  message: string
  confirmLabel: string
  cancelLabel: string
  tone: ConfirmTone
  placeholder: string
  inputValue: string
  required: boolean
  requiredError: string
  inputError: string
  resolver: ((value: boolean | string | null) => void) | null
}

const dialog = reactive<DialogState>({
  open: false,
  mode: 'confirm',
  title: '',
  message: '',
  confirmLabel: 'Confirm',
  cancelLabel: 'Cancel',
  tone: 'default',
  placeholder: '',
  inputValue: '',
  required: false,
  requiredError: 'This field is required.',
  inputError: '',
  resolver: null,
})

/** Reactive dialog state consumed by ConfirmHost.vue. */
export function useConfirmDialog(): DialogState {
  return dialog
}

function settle(value: boolean | string | null): void {
  const resolver = dialog.resolver
  dialog.open = false
  dialog.resolver = null
  dialog.inputError = ''
  resolver?.(value)
}

/**
 * Ask the user to confirm a crucial/destructive action BEFORE it runs.
 * Resolves true when confirmed, false otherwise. Pass a string for a plain
 * confirmation, or options for a title / custom labels / danger tone.
 */
export function confirmAction(input: string | ConfirmOptions): Promise<boolean> {
  const opts: ConfirmOptions = typeof input === 'string' ? { message: input } : input

  return new Promise<boolean>((resolve) => {
    // If a dialog is somehow already open, cancel it first.
    if (dialog.resolver) settle(false)

    dialog.mode = 'confirm'
    dialog.title = opts.title ?? 'Please confirm'
    dialog.message = opts.message
    dialog.confirmLabel = opts.confirmLabel ?? 'Confirm'
    dialog.cancelLabel = opts.cancelLabel ?? 'Cancel'
    dialog.tone = opts.tone ?? 'default'
    dialog.open = true
    dialog.resolver = (value) => resolve(value === true)
  })
}

/**
 * Ask the user for a short text value (replaces window.prompt). Resolves with
 * the entered string, or null if cancelled.
 */
export function promptAction(input: string | PromptOptions): Promise<string | null> {
  const opts: PromptOptions = typeof input === 'string' ? { message: input } : input

  return new Promise<string | null>((resolve) => {
    if (dialog.resolver) settle(false)

    dialog.mode = 'prompt'
    dialog.title = opts.title ?? 'Please provide a reason'
    dialog.message = opts.message
    dialog.confirmLabel = opts.confirmLabel ?? 'Submit'
    dialog.cancelLabel = opts.cancelLabel ?? 'Cancel'
    dialog.tone = 'default'
    dialog.placeholder = opts.placeholder ?? ''
    dialog.inputValue = opts.initialValue ?? ''
    dialog.required = opts.required ?? false
    dialog.requiredError = opts.requiredError ?? 'This field is required.'
    dialog.inputError = ''
    dialog.open = true
    dialog.resolver = (value) => resolve(typeof value === 'string' ? value : null)
  })
}

/** Called by ConfirmHost.vue when the user confirms/submits the dialog. */
export function acceptDialog(): void {
  if (dialog.mode === 'prompt') {
    const value = dialog.inputValue.trim()
    if (dialog.required && value === '') {
      dialog.inputError = dialog.requiredError
      return
    }
    settle(value)
    return
  }
  settle(true)
}

/** Called by ConfirmHost.vue when the user cancels/dismisses the dialog. */
export function cancelDialog(): void {
  settle(dialog.mode === 'prompt' ? null : false)
}

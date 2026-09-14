/**
 * App-wide, unmissable feedback.
 *
 * THE PROBLEM THIS SOLVES: failures were reported in four different ways —
 * a native Alert, a small line of red text inside a form, a full-screen
 * ErrorState, or nothing at all — and the quiet ones were routinely missed.
 * A student would tap Save, see no visible change, and assume it had worked.
 *
 * Everything now goes through one channel that is deliberately loud: a solid
 * coloured bar that slides down from the top, carries an icon, and stays put
 * long enough to read.
 *
 * Errors are NOT auto-dismissed on a short timer like a success. A success
 * message is a receipt you can miss harmlessly; an error is something you
 * must act on, so it holds for longer and can be dismissed by tapping.
 */
export type ToastKind = 'error' | 'success' | 'info';

export type Toast = {
  id: number;
  kind: ToastKind;
  message: string;
  /** Optional second line — the "what to do about it" half. */
  detail?: string;
};

const DURATION: Record<ToastKind, number> = {
  // Long enough to read a sentence and a fix, without trapping the screen.
  error: 7000,
  success: 3000,
  info: 4500,
};

let nextId = 1;
let current: Toast | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;

const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((listen) => listen());
}

export function subscribeToToast(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getToastSnapshot(): Toast | null {
  return current;
}

function show(kind: ToastKind, message: string, detail?: string): void {
  if (timer) clearTimeout(timer);
  current = { id: nextId++, kind, message, detail };
  emit();
  timer = setTimeout(() => {
    current = null;
    emit();
  }, DURATION[kind]);
}

export function showError(message: string, detail?: string): void {
  show('error', message, detail);
}

export function showSuccess(message: string, detail?: string): void {
  show('success', message, detail);
}

export function showInfo(message: string, detail?: string): void {
  show('info', message, detail);
}

export function dismissToast(): void {
  if (timer) clearTimeout(timer);
  current = null;
  emit();
}

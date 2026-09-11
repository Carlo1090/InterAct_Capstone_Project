/**
 * The app's own confirmation dialog, replacing `Alert.alert` everywhere.
 *
 * WHY THIS EXISTS (2026-09-11, project owner: "change the default look in
 * every message… i mean the double verification"). Every crucial action —
 * submitting a journal, discarding an info-sheet edit, clearing
 * notifications, deleting a weekly-activity row — asked for confirmation
 * through the OS. That meant the one moment a student has to stop and read
 * was drawn by Android, in Android's type, with Android's blue, on a page
 * that is otherwise entirely navy InternTrack. It read as a system warning
 * rather than as part of the app, and its buttons carried no weight: a
 * destructive "Delete" looked exactly like a routine "Submit".
 *
 * A single store + one host also fixes two things the native Alert could not:
 * a DESTRUCTIVE action can be drawn in red, and every message is written in
 * one place, so the app cannot end up with four ways of saying "this cannot
 * be undone".
 *
 * Promise-based, deliberately, so a call site reads as a straight line:
 *
 *     if (!(await confirmAction({ ... }))) return;
 *
 * rather than the callback-in-an-array shape `Alert.alert` forces, which is
 * what pushed several of these dialogs into nested helper functions.
 *
 * THE STORE IS MODULE-LEVEL, not a context/provider, for the same reason
 * `toast.ts` is: a dialog has to be openable from a plain async function in
 * the middle of a save, not only from inside a component body.
 */
export type ConfirmTone = 'default' | 'danger' | 'success' | 'warn';

export type ConfirmRequest = {
  id: number;
  title: string;
  /** One short line. If the title already says it, leave this out. */
  message?: string;
  confirmLabel: string;
  /** null for a one-button acknowledgement — there is nothing to cancel. */
  cancelLabel: string | null;
  tone: ConfirmTone;
};

type Pending = { request: ConfirmRequest; resolve: (ok: boolean) => void };

let nextId = 1;
let pending: Pending | null = null;

const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((listen) => listen());
}

export function subscribeToConfirm(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getConfirmSnapshot(): ConfirmRequest | null {
  return pending?.request ?? null;
}

/**
 * Answer the open dialog. Called only by `ConfirmHost`.
 *
 * Keyed by id: a dialog opened after this one was dismissed must not have its
 * answer stolen by an animation that finished late.
 */
export function resolveConfirm(id: number, ok: boolean): void {
  if (!pending || pending.request.id !== id) return;
  const { resolve } = pending;
  pending = null;
  emit();
  resolve(ok);
}

function open(request: Omit<ConfirmRequest, 'id'>): Promise<boolean> {
  return new Promise((resolve) => {
    // A second dialog replaces the first rather than queueing behind it. The
    // student answered by acting somewhere else, and leaving the old question
    // to pop up afterwards would ask about a decision already made.
    if (pending) {
      const stale = pending;
      pending = null;
      stale.resolve(false);
    }
    pending = { request: { ...request, id: nextId++ }, resolve };
    emit();
  });
}

/**
 * Ask before doing something. Resolves true only if they press the confirm
 * button — a backdrop tap, the hardware back button and Cancel all resolve
 * false, because "I did not answer" is not consent.
 */
export function confirmAction({
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  tone = 'default',
}: {
  title: string;
  message?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: ConfirmTone;
}): Promise<boolean> {
  return open({ title, message, confirmLabel, cancelLabel, tone });
}

/**
 * Tell them something they must read before carrying on — one button, nothing
 * to decline. Used where `Alert.alert` was called with no button array.
 *
 * Deliberately NOT collapsed into `showError`: a toast slides away on its own,
 * which is right for a receipt and wrong for "your clock-in did not record".
 */
export function alertAction({
  title,
  message,
  confirmLabel = 'OK',
  tone = 'default',
}: {
  title: string;
  message?: string;
  confirmLabel?: string;
  tone?: ConfirmTone;
}): Promise<boolean> {
  return open({ title, message, confirmLabel, cancelLabel: null, tone });
}

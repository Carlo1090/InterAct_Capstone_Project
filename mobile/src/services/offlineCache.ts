import AsyncStorage from '@react-native-async-storage/async-storage';

const PREFIX = 'interntrack_cache_';

/**
 * Generic last-known-good cache for read screens (Dashboard, Calendar,
 * Journals list, current user) — lets a screen render instantly from the
 * last successful response instead of a blank spinner, while a fresh fetch
 * happens in the background. Never the source of truth; only a fallback for
 * when the network fetch itself fails. Wrapped in try/catch throughout since
 * storage can fail (quota, corrupted native module) and that must never
 * crash a screen that would otherwise work fine over the network.
 */
export async function getCached<T>(key: string): Promise<T | null> {
  try {
    const raw = await AsyncStorage.getItem(PREFIX + key);
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

export async function setCached<T>(key: string, value: T): Promise<void> {
  try {
    await AsyncStorage.setItem(PREFIX + key, JSON.stringify(value));
  } catch {
    // Best-effort — a failed cache write must never block the screen that
    // already has the real, fresh data in memory.
  }
}

/**
 * Removes every cached read screen. Called on sign-out, and again on a login
 * that turns out to be a different account.
 *
 * THE BUG THIS FIXES: nothing used to clear this on logout — clearUser() only
 * reset the in-memory store. So the next student to sign in on the same handset
 * was painted with the previous student's name, photo, dashboard, journals and
 * activity log until each screen's own fetch landed, and saw all of it
 * indefinitely if they happened to be offline. On a shared or borrowed phone
 * that is one student reading another's record.
 *
 * Only keys under our own PREFIX are removed — the session token lives in
 * SecureStore and the outbox has its own keys, so this can never take out
 * something it does not own.
 */
export async function clearAllCached(): Promise<void> {
  try {
    const keys = await AsyncStorage.getAllKeys();
    const ours = keys.filter((k) => k.startsWith(PREFIX));
    if (ours.length > 0) await AsyncStorage.multiRemove(ours);
  } catch {
    // Best-effort, like every other access here.
  }
}

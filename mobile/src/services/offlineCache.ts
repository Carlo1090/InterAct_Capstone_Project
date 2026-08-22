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

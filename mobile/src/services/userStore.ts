import { apiGet, ApiError } from './api';
import { endpoints } from './endpoints';
import { getCached, setCached } from './offlineCache';
import { CurrentUser } from '../types/api';

const CACHE_KEY = 'current_user';

/**
 * ONE copy of the signed-in user, shared by every screen.
 *
 * THE BUG THIS FIXES: useCurrentUser() used to hold its own useState, so each
 * caller got a private copy. TopBar, Profile and the tab layout were three
 * independent copies of the same user. Changing your profile photo refetched
 * only Profile's copy — the header kept rendering the stale one until the app
 * was restarted, which read as "the change didn't save".
 *
 * A module-level store with subscribers means a single refresh updates every
 * screen at once. Deliberately not a dependency: this is ~60 lines against
 * pulling in a state library for one object.
 */
type UserState = {
  user: CurrentUser | null;
  loading: boolean;
  error: ApiError | null;
  isOffline: boolean;
};

let state: UserState = { user: null, loading: true, error: null, isOffline: false };

const listeners = new Set<() => void>();

/**
 * The snapshot object must be REPLACED on change and otherwise returned by the
 * same reference. useSyncExternalStore re-renders whenever the reference
 * differs, so returning a fresh object every call is an infinite loop.
 */
function setState(patch: Partial<UserState>): void {
  state = { ...state, ...patch };
  listeners.forEach((listen) => listen());
}

export function subscribeToUser(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getUserSnapshot(): UserState {
  return state;
}

let inFlight: Promise<void> | null = null;

/**
 * Fetches the current user and notifies every subscriber.
 *
 * Concurrent calls share one request: several screens mount at once on a cold
 * start, and without this each would fire its own /api/user.
 */
export function loadUser(): Promise<void> {
  if (inFlight) return inFlight;

  setState({ loading: true, error: null });

  inFlight = (async () => {
    try {
      const fresh = await apiGet<CurrentUser>(endpoints.me);
      setState({ user: fresh, isOffline: false, error: null });
      await setCached(CACHE_KEY, fresh);
    } catch (err) {
      const cached = await getCached<CurrentUser>(CACHE_KEY);
      // Fall back to the CACHED user, never to null: the gate flags derive as
      // `user?.field ?? false`, so a null user would silently report a gated
      // or paused student as unrestricted.
      setState({ error: err as ApiError, user: cached, isOffline: cached !== null });
    } finally {
      setState({ loading: false });
      inFlight = null;
    }
  })();

  return inFlight;
}

/** Paints the last-known user before the network call settles. */
export async function hydrateUserFromCache(): Promise<void> {
  if (state.user !== null) return;
  const cached = await getCached<CurrentUser>(CACHE_KEY);
  if (cached !== null && state.user === null) setState({ user: cached });
}

/**
 * Applies a server response that already contains the updated user (e.g. the
 * avatar upload returns the fresh row), so the header updates immediately
 * rather than after a round trip.
 */
export function setUser(user: CurrentUser): void {
  setState({ user, error: null });
  void setCached(CACHE_KEY, user);
}

/** Clears everything on sign-out so the next account starts blank. */
export function clearUser(): void {
  setState({ user: null, loading: false, error: null, isOffline: false });
}

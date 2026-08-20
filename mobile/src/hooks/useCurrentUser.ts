import { useCallback, useEffect, useState } from 'react';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { getCached, setCached } from '../services/offlineCache';
import { CurrentUser } from '../types/api';

const CACHE_KEY = 'current_user';

/**
 * Mobile's analogue of the web SPA's authStore role in router/index.ts's
 * navigation guard: the single source of truth for whether the signed-in
 * student is gated (info sheet not yet approved), paused (dropped from
 * their batch), or must change a temporary password. Every one of those
 * flags comes from GET /api/user, exactly like the web app.
 *
 * Falls back to the last-known-good cached user on a failed fetch (e.g.
 * offline) instead of `null` — without this, a gated/paused student who
 * opens the app with no connection would have every gate flag silently
 * default to `false` (`user?.field ?? false` against a null user) and see
 * the full app, defeating the whole point of the gate. Falling back to the
 * cached user preserves the last known truth instead.
 */
export function useCurrentUser() {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [isOffline, setIsOffline] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await apiGet<CurrentUser>(endpoints.me);
      setUser(data);
      setIsOffline(false);
      await setCached(CACHE_KEY, data);
    } catch (err) {
      setError(err as ApiError);
      const cached = await getCached<CurrentUser>(CACHE_KEY);
      setUser(cached);
      setIsOffline(cached !== null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // Hydrate instantly from cache before the network call settles, so the
    // very first render already has the last-known gate state rather than
    // a blank "loading" window with no flags to route on yet.
    getCached<CurrentUser>(CACHE_KEY).then((cached) => {
      if (cached) setUser(cached);
    });
    load();
  }, [load]);

  return {
    user,
    loading,
    error,
    isOffline,
    refetch: load,
    studentGated: user?.student_gated ?? false,
    studentPaused: user?.student_paused ?? false,
    mustChangePassword: user?.must_change_password ?? false,
  };
}

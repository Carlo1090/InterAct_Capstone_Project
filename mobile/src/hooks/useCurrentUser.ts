import { useCallback, useEffect, useState } from 'react';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { CurrentUser } from '../types/api';

/**
 * Mobile's analogue of the web SPA's authStore role in router/index.ts's
 * navigation guard: the single source of truth for whether the signed-in
 * student is gated (info sheet not yet approved), paused (dropped from
 * their batch), or must change a temporary password. Every one of those
 * flags comes from GET /api/user, exactly like the web app.
 */
export function useCurrentUser() {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await apiGet<CurrentUser>(endpoints.me);
      setUser(data);
    } catch (err) {
      setError(err as ApiError);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return {
    user,
    loading,
    error,
    refetch: load,
    studentGated: user?.student_gated ?? false,
    studentPaused: user?.student_paused ?? false,
    mustChangePassword: user?.must_change_password ?? false,
  };
}

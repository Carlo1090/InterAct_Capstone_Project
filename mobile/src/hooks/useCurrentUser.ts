import { useEffect, useSyncExternalStore } from 'react';
import {
  getUserSnapshot,
  hydrateUserFromCache,
  loadUser,
  subscribeToUser,
} from '../services/userStore';

/**
 * Mobile's analogue of the web SPA's authStore: the single source of truth for
 * whether the signed-in student is gated (info sheet not yet approved), paused
 * (dropped from their batch), or must change a temporary password.
 *
 * Backed by a shared module-level store rather than local state, so every
 * screen reads the SAME user. Previously each caller kept a private copy, and
 * updating your profile photo refreshed only the screen you were on while the
 * header kept showing the old one.
 */
export function useCurrentUser() {
  const snapshot = useSyncExternalStore(subscribeToUser, getUserSnapshot, getUserSnapshot);

  useEffect(() => {
    void hydrateUserFromCache().then(() => loadUser());
  }, []);

  return {
    user: snapshot.user,
    loading: snapshot.loading,
    error: snapshot.error,
    isOffline: snapshot.isOffline,
    refetch: loadUser,
    studentGated: snapshot.user?.student_gated ?? false,
    studentPaused: snapshot.user?.student_paused ?? false,
    mustChangePassword: snapshot.user?.must_change_password ?? false,
    // Defaults to false so the Scan tab stays hidden until the server has
    // actually said the batch uses a DTR.
    dtrEnabled: snapshot.user?.student_dtr_enabled ?? false,
  };
}

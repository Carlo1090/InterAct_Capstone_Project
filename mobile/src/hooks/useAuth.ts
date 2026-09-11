import { useState, useCallback, useEffect } from 'react';
import * as SecureStore from 'expo-secure-store';
import { api, apiPost, TOKEN_KEY, toApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { clearLocalReminders } from '../services/localReminders';
import { clearUser, setUser } from '../services/userStore';
import { getCached, clearAllCached } from '../services/offlineCache';
import { clearOutbox } from '../services/journalOutbox';
import { CurrentUser } from '../types/api';

type LoginResult = { ok: true; user: CurrentUser } | { ok: false; error: string };

/**
 * Everything this device is holding on behalf of the signed-in student: the
 * read caches, the queued journal writes, and the on-device alarms. Kept in one
 * function so sign-out and account-switch cannot clear different halves of it.
 */
async function clearDeviceSession(): Promise<void> {
  await clearAllCached();
  await clearOutbox();
  await clearLocalReminders();
  clearUser();
}

export function useAuth() {
  const [isAuthenticated, setIsAuthenticated] = useState<boolean | null>(null);

  useEffect(() => {
    SecureStore.getItemAsync(TOKEN_KEY).then((t) => setIsAuthenticated(!!t));
  }, []);

  const login = useCallback(async (identifier: string, password: string): Promise<LoginResult> => {
    try {
      // `retry: true` is deliberate on this ONE write. Signing in is the first
      // thing that touches the API after the app has sat unused — exactly when
      // the free-tier instance is asleep — and a lost response here just means
      // the student is told to check a connection that was never the problem.
      // Repeating it is safe: a duplicate login only issues a second token,
      // where repeating a journal or a punch would file it twice.
      const res = await apiPost<{ token: string; user: CurrentUser }>(
        endpoints.login,
        { login: identifier, password },
        { retry: true }
      );

      // Sign-out is where the wipe belongs, but it is not the only way a
      // session ends — a revoked or expired token drops the student back at
      // this form with every cache still on disk. So the account that just
      // signed in is compared against the one the cache describes, and anything
      // belonging to somebody else goes before their first screen paints.
      const previous = await getCached<CurrentUser>('current_user');
      if (previous !== null && previous.id !== res.user.id) {
        await clearDeviceSession();
      }

      await SecureStore.setItemAsync(TOKEN_KEY, res.token);
      // Seed the shared store from the login response so the first screen has
      // the right name and photo without waiting on /api/user.
      setUser(res.user);
      setIsAuthenticated(true);
      return { ok: true, user: res.user };
    } catch (err) {
      const apiErr = toApiError(err);
      const fieldMessage = apiErr.fieldErrors?.login?.[0] ?? apiErr.fieldErrors?.email?.[0];
      return { ok: false, error: fieldMessage ?? apiErr.message };
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post(endpoints.logout);
    } catch {
      // best-effort; still clear the local token even if the request fails
      // (e.g. the token was already revoked or the device is offline)
    }
    await SecureStore.deleteItemAsync(TOKEN_KEY);
    // Local alarms outlive the session otherwise — a shared or handed-on
    // handset would keep nudging whoever signed in last. The read caches and
    // the journal outbox go with them: see clearAllCached() and clearOutbox()
    // for what leaving either behind actually did.
    await clearDeviceSession();
    setIsAuthenticated(false);
  }, []);

  return { isAuthenticated, login, logout };
}

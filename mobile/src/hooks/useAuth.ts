import { useState, useCallback, useEffect } from 'react';
import * as SecureStore from 'expo-secure-store';
import { api, TOKEN_KEY, toApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { clearLocalReminders } from '../services/localReminders';
import { clearUser } from '../services/userStore';
import { CurrentUser } from '../types/api';

type LoginResult = { ok: true; user: CurrentUser } | { ok: false; error: string };

export function useAuth() {
  const [isAuthenticated, setIsAuthenticated] = useState<boolean | null>(null);

  useEffect(() => {
    SecureStore.getItemAsync(TOKEN_KEY).then((t) => setIsAuthenticated(!!t));
  }, []);

  const login = useCallback(async (identifier: string, password: string): Promise<LoginResult> => {
    try {
      const res = await api.post<{ token: string; user: CurrentUser }>(endpoints.login, {
        login: identifier,
        password,
      });
      await SecureStore.setItemAsync(TOKEN_KEY, res.data.token);
      setIsAuthenticated(true);
      return { ok: true, user: res.data.user };
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
    // handset would keep nudging whoever signed in last.
    await clearLocalReminders();
    // The shared user store is module-level, so without this the next account
    // to sign in briefly sees the previous student's name and photo.
    clearUser();
    setIsAuthenticated(false);
  }, []);

  return { isAuthenticated, login, logout };
}

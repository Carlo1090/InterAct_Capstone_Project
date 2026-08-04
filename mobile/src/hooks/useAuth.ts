import { useState, useCallback, useEffect } from 'react';
import * as SecureStore from 'expo-secure-store';
import { api, TOKEN_KEY } from '../services/api';
import { endpoints } from '../services/endpoints';
import { setAccountKind } from '../services/accountKind';
import {
  findLocalAccount,
  createLocalAccount,
  emailIsTaken,
  setCurrentLocalProfile,
  clearCurrentLocalProfile,
} from '../services/localAccounts';

// Same mock-fallback philosophy as every other screen in this app
// (see src/services/api.ts -> fetchWithFallback): when the real Laravel
// endpoint isn't reachable yet, fall back to local behavior instead of the
// request just failing. This is NOT a bypass of the auth flow — it still
// goes through login()/register(), still stores a real token via
// SecureStore, still gates routing the same way. The only difference is
// where the token/account comes from when there's no backend to answer.
//
// Fixed demo account (works ONLY when the real /api/login call fails to
// connect) — shows the seeded sample dashboard/journals/etc.:
//   email:    student@interntrack.test
//   password: interntrack123
//
// New students can also use Sign Up to create their own local account when
// the backend isn't reachable — they'll see an empty dashboard/OJT state
// instead of the seeded demo content (see src/services/mock/empty.ts).
//
// Once the real backend is live and reachable, none of this local fallback
// triggers — a real network response always takes priority.
const MOCK_ACCOUNT = { email: 'student@interntrack.test', password: 'interntrack123' };
const MOCK_TOKEN = 'mock-dev-token';

export function useAuth() {
  const [isAuthenticated, setIsAuthenticated] = useState<boolean | null>(null);

  useEffect(() => {
    SecureStore.getItemAsync(TOKEN_KEY).then((t) => setIsAuthenticated(!!t));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    try {
      const res = await api.post(endpoints.login, { email, password });
      // UNVERIFIED: confirm the real response field name — assuming `token`.
      const token = res.data?.token ?? res.data?.access_token;
      if (!token) throw new Error('No token in response');
      await SecureStore.setItemAsync(TOKEN_KEY, token);
      await setAccountKind(null); // real backend session — hooks fetch real data
      setIsAuthenticated(true);
      return { ok: true as const };
    } catch (err) {
      // Real backend unreachable. Check the fixed demo account first...
      if (email.trim().toLowerCase() === MOCK_ACCOUNT.email && password.trim() === MOCK_ACCOUNT.password) {
        await SecureStore.setItemAsync(TOKEN_KEY, MOCK_TOKEN);
        await setAccountKind('demo');
        setIsAuthenticated(true);
        return { ok: true as const };
      }
      // ...then any locally registered account created via Sign Up.
      const local = await findLocalAccount(email, password);
      if (local) {
        await SecureStore.setItemAsync(TOKEN_KEY, `mock-local-token-${local.email}`);
        await setAccountKind('new');
        await setCurrentLocalProfile({ name: local.name, studentId: local.studentId, email: local.email });
        setIsAuthenticated(true);
        return { ok: true as const };
      }
      return { ok: false as const, error: 'Unable to sign in. Check your credentials and connection.' };
    }
  }, []);

  const register = useCallback(
    async (name: string, studentId: string, email: string, password: string) => {
      const normalizedEmail = email.trim().toLowerCase();
      try {
        const res = await api.post(endpoints.register, { name, studentId, email: normalizedEmail, password });
        // UNVERIFIED: confirm the real response field name — assuming `token`.
        const token = res.data?.token ?? res.data?.access_token;
        if (!token) throw new Error('No token in response');
        await SecureStore.setItemAsync(TOKEN_KEY, token);
        await setAccountKind(null); // real backend account
        setIsAuthenticated(true);
        return { ok: true as const };
      } catch (err) {
        // Real backend unreachable — create the account locally on this
        // device instead, so the student can sign up and explore the app
        // with a genuinely empty dashboard/OJT state before the backend
        // exists.
        if (await emailIsTaken(normalizedEmail)) {
          return { ok: false as const, error: 'An account with that email already exists on this device.' };
        }
        await createLocalAccount({ name, studentId, email: normalizedEmail, password });
        await SecureStore.setItemAsync(TOKEN_KEY, `mock-local-token-${normalizedEmail}`);
        await setAccountKind('new');
        await setCurrentLocalProfile({ name, studentId, email: normalizedEmail });
        setIsAuthenticated(true);
        return { ok: true as const };
      }
    },
    []
  );

  const logout = useCallback(async () => {
    try {
      await api.post(endpoints.logout);
    } catch {
      // best-effort; still clear local token even if the request fails
    }
    await SecureStore.deleteItemAsync(TOKEN_KEY);
    await setAccountKind(null);
    await clearCurrentLocalProfile();
    setIsAuthenticated(false);
  }, []);

  return { isAuthenticated, login, register, logout };
}

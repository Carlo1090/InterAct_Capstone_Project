import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { fetchWithFallback, postWithFallback } from '../services/api';
import { endpoints } from '../services/endpoints';
import { mockWeeklyLogs, WeeklyLog } from '../services/mock/weekly';
import { getAccountKind } from '../services/accountKind';
import { getCurrentLocalProfile } from '../services/localAccounts';
import {
  computeWeeklyLogsFromEntries,
  computeWeeklyLogDetailFromEntries,
  saveWeeklyNarrative,
  submitWeeklyNarrative,
} from '../services/localData';

export function useWeeklyLogs() {
  const [logs, setLogs] = useState<WeeklyLog[]>([]);
  const [loading, setLoading] = useState(true);

  useFocusEffect(
    useCallback(() => {
      let mounted = true;
      (async () => {
        const kind = await getAccountKind();
        if (kind === 'new') {
          const profile = await getCurrentLocalProfile();
          const computed = profile ? await computeWeeklyLogsFromEntries(profile.email) : [];
          if (mounted) {
            setLogs(computed as WeeklyLog[]);
            setLoading(false);
          }
          return;
        }
        if (kind === 'demo') {
          if (mounted) {
            setLogs(mockWeeklyLogs);
            setLoading(false);
          }
          return;
        }
        const r = await fetchWithFallback(endpoints.weeklyLogs, mockWeeklyLogs);
        if (mounted) {
          setLogs(r.data);
          setLoading(false);
        }
      })();
      return () => {
        mounted = false;
      };
    }, [])
  );

  return { logs, loading };
}

/**
 * Detail + save/submit for a single week's narrative. Follows the same
 * kind-branch shape as useWeeklyLogs: a locally-registered "new" account
 * reads/writes real AsyncStorage state via localData.ts; the fixed demo
 * account only updates in-memory state (nothing it does is ever persisted,
 * matching every other demo-account write path in this app); otherwise a
 * real backend call is tried first via postWithFallback.
 */
export function useWeeklyLogDetail(id: string) {
  const [log, setLog] = useState<WeeklyLog | null>(null);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    const kind = await getAccountKind();

    if (kind === 'new') {
      const profile = await getCurrentLocalProfile();
      const detail = profile ? await computeWeeklyLogDetailFromEntries(profile.email, id) : null;
      setLog(detail as WeeklyLog | null);
      setLoading(false);
      return;
    }

    if (kind === 'demo') {
      setLog(mockWeeklyLogs.find((w) => w.id === id) ?? null);
      setLoading(false);
      return;
    }

    const r = await fetchWithFallback(`${endpoints.weeklyLogs}/${id}`, mockWeeklyLogs.find((w) => w.id === id) ?? null);
    setLog(r.data);
    setLoading(false);
  }, [id]);

  useFocusEffect(
    useCallback(() => {
      let mounted = true;
      (async () => {
        await load();
        if (!mounted) return;
      })();
      return () => {
        mounted = false;
      };
    }, [load])
  );

  async function saveNarrative(narrative: string) {
    const kind = await getAccountKind();

    if (kind === 'new') {
      const profile = await getCurrentLocalProfile();
      if (profile) await saveWeeklyNarrative(profile.email, id, narrative);
    } else if (kind === 'demo') {
      setLog((prev) => (prev ? { ...prev, narrative } : prev));
    } else {
      await postWithFallback(`${endpoints.weeklyLogs}/${id}`, { narrative }, { ok: true });
    }

    await load();
  }

  async function submitNarrative(narrative: string) {
    const kind = await getAccountKind();

    if (kind === 'new') {
      const profile = await getCurrentLocalProfile();
      if (profile) await submitWeeklyNarrative(profile.email, id, narrative);
    } else if (kind === 'demo') {
      setLog((prev) => (prev ? { ...prev, narrative, status: 'pending' } : prev));
    } else {
      await postWithFallback(`${endpoints.weeklyLogs}/${id}/submit`, { narrative }, { ok: true });
    }

    await load();
  }

  return { log, loading, saveNarrative, submitNarrative };
}

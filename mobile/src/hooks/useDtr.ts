import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { getCached, setCached } from '../services/offlineCache';
import { DtrOverview } from '../types/api';

const CACHE_KEY = 'dtr_overview';

/**
 * The student's Daily Time Record overview: open session, this week's
 * punches, and hours banked against hours required.
 *
 * Same cache-then-network shape as useDashboard — the RECORD of past hours is
 * worth showing offline (a student checking how many hours they've banked
 * doesn't need a connection to read a number that already happened). Punching
 * itself is deliberately NOT cached or queued; see scan.tsx for why.
 */
export function useDtr() {
  const [data, setData] = useState<DtrOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [isOffline, setIsOffline] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<DtrOverview>(endpoints.dtr);
      setData(res);
      setIsOffline(false);
      await setCached(CACHE_KEY, res);
    } catch (err) {
      setError(err as ApiError);
      const cached = await getCached<DtrOverview>(CACHE_KEY);
      if (cached) setData((prev) => prev ?? cached);
      setIsOffline(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  return { data, loading, error, isOffline, reload: load };
}

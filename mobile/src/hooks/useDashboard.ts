import { useCallback, useEffect, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { getCached, setCached } from '../services/offlineCache';
import { DashboardResponse } from '../types/api';

const CACHE_KEY = 'dashboard';

export function useDashboard() {
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  // True only when the currently-shown `data` came from cache because the
  // live fetch failed — distinct from a genuine loading state, so the
  // screen can show "offline, showing saved data" instead of either a
  // spinner or a hard error when there's something last-known to show.
  const [isOffline, setIsOffline] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<DashboardResponse>(endpoints.dashboard);
      setData(res);
      setIsOffline(false);
      await setCached(CACHE_KEY, res);
    } catch (err) {
      setError(err as ApiError);
      // Keep already-loaded data on screen; only fall back to cache if this
      // hook instance hasn't loaded anything yet this session.
      const cached = await getCached<DashboardResponse>(CACHE_KEY);
      setData((prev) => prev ?? cached);
      setIsOffline(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    getCached<DashboardResponse>(CACHE_KEY).then((cached) => {
      if (cached) setData((prev) => prev ?? cached);
    });
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  return { data, loading, error, isOffline, reload: load };
}

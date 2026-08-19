import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { DashboardResponse } from '../types/api';

export function useDashboard() {
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<DashboardResponse>(endpoints.dashboard);
      setData(res);
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  return { data, loading, error, reload: load };
}

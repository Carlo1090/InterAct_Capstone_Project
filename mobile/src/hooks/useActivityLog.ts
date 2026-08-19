import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { Paginated, SystemLogEntry } from '../types/api';

/** Mirrors web's ActivityLogPanel.vue — the student's own SystemLog rows. */
export function useActivityLog() {
  const [entries, setEntries] = useState<SystemLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<Paginated<SystemLogEntry>>(endpoints.profileActivity);
      setEntries(res.data);
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

  return { entries, loading, error, reload: load };
}

import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, apiPut, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { ReminderPreferences } from '../types/api';

export function useReminderPreferences() {
  const [data, setData] = useState<ReminderPreferences | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<ReminderPreferences>(endpoints.reminderPreferences);
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

  async function save(payload: {
    reminder_enabled: boolean;
    reminder_days: number[] | null;
    reminder_time: string | null;
  }): Promise<{ ok: true } | { ok: false; error: string }> {
    try {
      const res = await apiPut<ReminderPreferences>(endpoints.reminderPreferences, payload);
      setData(res);
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  return { data, loading, error, reload: load, save };
}

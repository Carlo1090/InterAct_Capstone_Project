import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, apiPut, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { setCached } from '../services/offlineCache';
import { syncLocalReminders } from '../services/localReminders';
import { ReminderPreferences } from '../types/api';

const CACHE_KEY = 'reminder_preferences';

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
      await setCached(CACHE_KEY, res);
      // Re-arm the device's own alarms immediately, so a preference change
      // takes effect now rather than at the next app launch. Deliberately
      // uses the SERVER's response, not the submitted payload — the server
      // normalizes (days sorted/deduped, an empty array stored as null
      // meaning "follow my batch"), and scheduling off the raw payload would
      // drift from what was actually saved.
      await syncLocalReminders(res);
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  return { data, loading, error, reload: load, save };
}

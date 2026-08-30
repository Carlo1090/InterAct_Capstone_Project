import { useCallback } from 'react';
import { apiGet, apiPut, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { setCached } from '../services/offlineCache';
import { syncLocalReminders } from '../services/localReminders';
import { useCachedResource } from './useCachedResource';
import { ReminderPreferences } from '../types/api';

const CACHE_KEY = 'reminder_preferences';

export function useReminderPreferences() {
  const { data, loading, error, isOffline, reload, setData } = useCachedResource<ReminderPreferences>(
    CACHE_KEY,
    useCallback(() => apiGet<ReminderPreferences>(endpoints.reminderPreferences), [])
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

  return { data, loading, error, isOffline, reload, save };
}

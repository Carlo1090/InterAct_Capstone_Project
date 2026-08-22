import { useEffect } from 'react';
import { apiGet } from '../services/api';
import { endpoints } from '../services/endpoints';
import { getCached, setCached } from '../services/offlineCache';
import { configureNotificationHandler, syncLocalReminders } from '../services/localReminders';
import { useAuth } from './useAuth';
import { ReminderPreferences } from '../types/api';

const CACHE_KEY = 'reminder_preferences';

/**
 * Keeps the device's local reminder alarms in step with the student's saved
 * preferences. Mounted once at the app root, not per-screen.
 *
 * Runs on every launch rather than only when preferences change: the OS
 * schedule can be cleared by a reinstall, a device restart on some Android
 * builds, or the student revoking and re-granting notification permission,
 * and rescheduling is cheap (at most seven repeating entries).
 *
 * Falls back to the cached preferences when the fetch fails, so a student who
 * launches offline still gets their alarms re-armed from the last known
 * settings instead of silently losing them.
 */
export function useLocalReminderSync(): void {
  const { isAuthenticated } = useAuth();

  useEffect(() => {
    configureNotificationHandler();
  }, []);

  useEffect(() => {
    if (!isAuthenticated) return;

    let cancelled = false;

    (async () => {
      let prefs: ReminderPreferences | null = null;
      try {
        prefs = await apiGet<ReminderPreferences>(endpoints.reminderPreferences);
        await setCached(CACHE_KEY, prefs);
      } catch {
        prefs = await getCached<ReminderPreferences>(CACHE_KEY);
      }

      if (cancelled || !prefs) return;
      await syncLocalReminders(prefs);
    })();

    return () => {
      cancelled = true;
    };
  }, [isAuthenticated]);
}

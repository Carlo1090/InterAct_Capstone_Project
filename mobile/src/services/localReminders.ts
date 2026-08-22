import { Platform } from 'react-native';
import * as Notifications from 'expo-notifications';
import { SchedulableTriggerInputTypes, AndroidImportance } from 'expo-notifications';
import { ReminderPreferences } from '../types/api';

const CHANNEL_ID = 'journal-reminders';

/**
 * On-device journal reminders — the offline half of the reminder feature.
 *
 * The server's `journal:send-missing-entry-reminders` command is the online
 * half: it runs hourly, knows exactly which entries are missing, and delivers
 * an in-app bell row (plus email for a verified address). It cannot fire at
 * all without connectivity, which is precisely the case a student on an OJT
 * site most often finds themselves in.
 *
 * These are Android/iOS scheduled local notifications, handed to the OS's own
 * alarm scheduling — so they fire with no network, no push service, and even
 * with the app closed.
 *
 * THE HONESTY CONSTRAINT: a local notification cannot know whether entries
 * are actually missing — that is derived server-side from BatchWorkingDays.
 * So the copy is a plain nudge ("time to write"), never a claim about how
 * many entries are outstanding. This matches the documented split in
 * PROJECT.md: ReminderSchedule answers only "should we nudge?" and must never
 * become the yardstick a student's compliance is measured against.
 */

/**
 * The backend stores ISO weekdays (1 = Monday .. 7 = Sunday);
 * expo-notifications' WeeklyTriggerInput uses 1 = Sunday .. 7 = Saturday.
 * Getting this wrong shifts every reminder by a day, silently.
 */
function isoDayToExpoWeekday(isoDay: number): number {
  return (isoDay % 7) + 1;
}

/** `reminder_time` arrives as "HH:mm" (or "HH:mm:ss"); no date, so it is
 *  split rather than parsed — a wall-clock time has no instant to represent. */
function parseHourMinute(value: string): { hour: number; minute: number } {
  const [rawHour, rawMinute] = value.split(':');
  const hour = Number(rawHour);
  const minute = Number(rawMinute);
  return {
    hour: Number.isFinite(hour) ? Math.min(23, Math.max(0, hour)) : 21,
    minute: Number.isFinite(minute) ? Math.min(59, Math.max(0, minute)) : 0,
  };
}

export function configureNotificationHandler(): void {
  Notifications.setNotificationHandler({
    handleNotification: async () => ({
      shouldShowBanner: true,
      shouldShowList: true,
      shouldPlaySound: true,
      shouldSetBadge: false,
    }),
  });
}

async function ensureAndroidChannel(): Promise<void> {
  if (Platform.OS !== 'android') return;
  await Notifications.setNotificationChannelAsync(CHANNEL_ID, {
    name: 'Journal reminders',
    importance: AndroidImportance.DEFAULT,
    sound: 'default',
  });
}

/**
 * Asks for notification permission. Android 13+ requires an explicit
 * POST_NOTIFICATIONS grant; older Androids and iOS resolve immediately.
 */
export async function requestReminderPermission(): Promise<boolean> {
  const existing = await Notifications.getPermissionsAsync();
  if (existing.granted) return true;
  if (!existing.canAskAgain) return false;

  const requested = await Notifications.requestPermissionsAsync();
  return requested.granted;
}

/**
 * Rebuilds the whole local schedule from the student's saved preferences.
 *
 * Deliberately cancel-then-reschedule rather than diffing: the schedule is at
 * most seven repeating entries, and a diff would have to reconcile
 * OS-assigned identifiers across preference edits for no practical gain. This
 * is also what makes the function safe to call on every app start.
 *
 * Returns how many reminders are now scheduled, so the settings screen can
 * tell the student plainly what to expect rather than implying success.
 */
export async function syncLocalReminders(prefs: ReminderPreferences): Promise<number> {
  try {
    await Notifications.cancelAllScheduledNotificationsAsync();

    // Switching reminders off is what `reminder_enabled` is for. An EMPTY
    // `reminder_days` means "follow my batch" (hence the defaults fallback
    // below), NOT "never remind me" — same rule the backend documents.
    if (!prefs.reminder_enabled) return 0;

    const granted = await requestReminderPermission();
    if (!granted) return 0;

    await ensureAndroidChannel();

    const days = prefs.reminder_days?.length ? prefs.reminder_days : prefs.defaults.days;
    const { hour, minute } = parseHourMinute(prefs.reminder_time ?? prefs.defaults.time);

    for (const isoDay of days) {
      await Notifications.scheduleNotificationAsync({
        content: {
          title: 'Write your journal',
          // No count, and no claim that anything is missing — see the header
          // comment. This device has no way to know either.
          body: "Take a minute to record today's OJT activities in InternTrack.",
        },
        trigger: {
          type: SchedulableTriggerInputTypes.WEEKLY,
          weekday: isoDayToExpoWeekday(isoDay),
          hour,
          minute,
          channelId: CHANNEL_ID,
        },
      });
    }

    return days.length;
  } catch {
    // A scheduling failure must never break the screen that triggered it —
    // the student's saved preference is already persisted server-side, and
    // the online reminder path is unaffected.
    return 0;
  }
}

/** Used when signing out, so a shared handset stops nudging the last user. */
export async function clearLocalReminders(): Promise<void> {
  try {
    await Notifications.cancelAllScheduledNotificationsAsync();
  } catch {
    // best-effort
  }
}

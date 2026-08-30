import { apiGet } from './api';
import { endpoints } from './endpoints';
import { setCached } from './offlineCache';

/**
 * Warms every read cache in one pass, so the app is fully usable offline
 * from the first launch rather than only for screens the student happened
 * to open while connected.
 *
 * Without this, "works offline" really meant "works offline for whatever you
 * already visited" — a student who logged in at home and lost signal at the
 * workplace would find half the app empty.
 *
 * Design rules:
 *  - Every request is INDEPENDENT and failures are swallowed per-endpoint.
 *    One 422 (e.g. the DTR is off for this batch, or there is no exit
 *    interview yet) must not abort the rest of the warm-up.
 *  - The cache keys written here are exactly the ones the hooks read, so a
 *    preloaded screen hydrates instantly instead of re-fetching.
 *  - It never blocks the UI for long: the caller shows its own progress and
 *    proceeds regardless of the outcome.
 */

type PreloadStep = { key: string; label: string; run: () => Promise<unknown> };

const STEPS: PreloadStep[] = [
  { key: 'current_user', label: 'your account', run: () => apiGet(endpoints.me) },
  { key: 'dashboard', label: 'dashboard', run: () => apiGet(endpoints.dashboard) },
  {
    key: 'journal_list',
    label: 'journals',
    run: async () => {
      const res = await apiGet<{ data: unknown }>(endpoints.journalEntries);
      return res.data;
    },
  },
  {
    key: 'weekly_logs',
    label: 'weekly reports',
    run: async () => {
      const res = await apiGet<{ weeks: unknown }>(endpoints.weeklyLogs);
      return res.weeks;
    },
  },
  { key: 'weekly_activity_logs', label: 'time log summary', run: () => apiGet(endpoints.weeklyActivityLogs) },
  { key: 'notifications', label: 'notifications', run: () => apiGet(endpoints.notifications) },
  {
    key: 'activity_log',
    label: 'activity history',
    run: async () => {
      const res = await apiGet<{ data: unknown }>(endpoints.profileActivity);
      return res.data;
    },
  },
  { key: 'reminder_preferences', label: 'reminder settings', run: () => apiGet(endpoints.reminderPreferences) },
  { key: 'dtr_overview', label: 'time record', run: () => apiGet(endpoints.dtr) },
  { key: 'exit_interview', label: 'exit interview', run: () => apiGet(endpoints.exitInterview) },
];

export type PreloadProgress = { done: number; total: number; label: string };

export async function preloadAll(onProgress?: (p: PreloadProgress) => void): Promise<void> {
  let done = 0;

  // The calendar is per-month, so warm the CURRENT month under the exact key
  // useJournalCalendar reads.
  const month = new Date();
  const monthKey = `${month.getFullYear()}-${String(month.getMonth() + 1).padStart(2, '0')}`;

  const steps: PreloadStep[] = [
    ...STEPS,
    {
      key: `calendar_${monthKey}`,
      label: 'calendar',
      run: async () => {
        const res = await apiGet<{ days: unknown }>(endpoints.journalCalendar, { month: monthKey });
        return res.days;
      },
    },
    {
      key: 'info_sheet',
      label: 'information sheet',
      run: async () => {
        const [sheet, companies] = await Promise.all([
          apiGet(endpoints.infoSheet),
          apiGet(endpoints.companies),
        ]);
        return { sheet, companies };
      },
    },
  ];

  for (const step of steps) {
    onProgress?.({ done, total: steps.length, label: step.label });
    try {
      const value = await step.run();
      if (value !== undefined && value !== null) await setCached(step.key, value);
    } catch {
      // Expected for endpoints that legitimately 422 for this student (no
      // enrollment yet, DTR disabled, no exit interview). Skip and continue —
      // a warm-up must never be the thing that stops the app opening.
    }
    done += 1;
  }

  onProgress?.({ done, total: steps.length, label: 'done' });
}

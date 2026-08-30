import { useCallback } from 'react';
import { apiGet, apiPost, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { useCachedResource } from './useCachedResource';
import { WeeklyLogsResponse, WeeklyLogSummary, WeeklyLogDetail } from '../types/api';

export function useWeeklyLogs() {
  const { data, loading, error, isOffline, reload } = useCachedResource<WeeklyLogSummary[]>(
    'weekly_logs',
    useCallback(async () => {
      const res = await apiGet<WeeklyLogsResponse>(endpoints.weeklyLogs);
      return res.weeks;
    }, [])
  );

  return { logs: data ?? [], loading, error, isOffline, reload };
}

/**
 * Detail + save/submit for a single week's narrative, addressed by its
 * Monday date (week_start) — the real backend's only identifier for a week,
 * not an opaque id.
 *
 * Reads are cached so a submitted week stays readable offline. Writes are
 * deliberately NOT queued: unlike a daily journal, a weekly narrative can be
 * bundled, returned by a supervisor, or approved while the device is offline,
 * so a queued save could overwrite a supervisor's decision with text written
 * before it. The offline capability matrix records this as read-only.
 */
export function useWeeklyLogDetail(weekStart: string) {
  const { data, loading, error, isOffline, reload } = useCachedResource<WeeklyLogDetail>(
    `weekly_log_${weekStart}`,
    useCallback(() => apiGet<WeeklyLogDetail>(endpoints.weeklyLog(weekStart)), [weekStart])
  );

  async function saveNarrative(narrative: string): Promise<{ ok: true } | { ok: false; error: string }> {
    try {
      await apiPost(endpoints.weeklyLogs, { week_start: weekStart, narrative });
      await reload();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  async function submitNarrative(narrative: string): Promise<{ ok: true } | { ok: false; error: string }> {
    try {
      await apiPost(endpoints.weeklyLogs, { week_start: weekStart, narrative });
      await apiPost(endpoints.weeklyLogSubmit(weekStart));
      await reload();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  return { log: data, loading, error, isOffline, reload, saveNarrative, submitNarrative };
}

import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, apiPost, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { WeeklyLogsResponse, WeeklyLogSummary, WeeklyLogDetail } from '../types/api';

export function useWeeklyLogs() {
  const [logs, setLogs] = useState<WeeklyLogSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<WeeklyLogsResponse>(endpoints.weeklyLogs);
      setLogs(res.weeks);
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

  return { logs, loading, error, reload: load };
}

/**
 * Detail + save/submit for a single week's narrative, addressed by its
 * Monday date (week_start) — the real backend's only identifier for a week,
 * not an opaque id.
 */
export function useWeeklyLogDetail(weekStart: string) {
  const [log, setLog] = useState<WeeklyLogDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<WeeklyLogDetail>(endpoints.weeklyLog(weekStart));
      setLog(res);
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setLoading(false);
    }
  }, [weekStart]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  async function saveNarrative(narrative: string): Promise<{ ok: true } | { ok: false; error: string }> {
    try {
      await apiPost(endpoints.weeklyLogs, { week_start: weekStart, narrative });
      await load();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  async function submitNarrative(narrative: string): Promise<{ ok: true } | { ok: false; error: string }> {
    try {
      await apiPost(endpoints.weeklyLogs, { week_start: weekStart, narrative });
      await apiPost(endpoints.weeklyLogSubmit(weekStart));
      await load();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  return { log, loading, error, reload: load, saveNarrative, submitNarrative };
}

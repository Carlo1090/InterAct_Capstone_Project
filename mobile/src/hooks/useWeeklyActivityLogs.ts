import { useCallback } from 'react';
import { apiDelete, apiGet, apiPost, apiPut, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { useCachedResource } from './useCachedResource';
import { WeeklyActivityEntry, WeeklyActivityLogDetail, WeeklyActivityLogSummary } from '../types/api';

type Result = { ok: true } | { ok: false; error: string };

export function useWeeklyActivityLogs() {
  const { data, loading, error, isOffline, reload } = useCachedResource<WeeklyActivityLogSummary[]>(
    'weekly_activity_logs',
    useCallback(() => apiGet<WeeklyActivityLogSummary[]>(endpoints.weeklyActivityLogs), [])
  );

  async function createSheet(payload: {
    week_start: string;
    week_end: string;
    area_assigned?: string | null;
    no_of_hours?: string | null;
  }): Promise<{ ok: true; id: number } | { ok: false; error: string }> {
    try {
      const created = await apiPost<WeeklyActivityLogSummary>(endpoints.weeklyActivityLogs, payload);
      await reload();
      return { ok: true, id: created.id };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  return { logs: data ?? [], loading, error, isOffline, reload, createSheet };
}

/**
 * One Period-Covered sheet and its activity rows.
 *
 * DIVERGENCE FROM WEB, deliberate: the web page auto-saves every row on an
 * 800ms debounce and has no Save button. Mobile saves each row explicitly.
 * A phone's connection drops mid-keystroke in a way a lab desktop's does
 * not, and a silent debounced POST that fails leaves the student believing
 * their typing was stored. An explicit Save that can report success or
 * failure is the honest trade on this device.
 */
export function useWeeklyActivityLog(id: number) {
  const { data, loading, error, isOffline, reload } = useCachedResource<WeeklyActivityLogDetail>(
    `weekly_activity_log_${id}`,
    useCallback(() => apiGet<WeeklyActivityLogDetail>(endpoints.weeklyActivityLog(id)), [id]),
    { enabled: Number.isFinite(id) && id > 0 }
  );

  async function updateSheet(payload: {
    area_assigned?: string | null;
    no_of_hours?: string | null;
  }): Promise<Result> {
    try {
      await apiPut(endpoints.weeklyActivityLog(id), payload);
      await reload();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  async function addEntry(payload: Partial<WeeklyActivityEntry>): Promise<Result> {
    try {
      await apiPost(endpoints.weeklyActivityEntries(id), payload);
      await reload();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  async function updateEntry(entryId: number, payload: Partial<WeeklyActivityEntry>): Promise<Result> {
    try {
      await apiPut(endpoints.weeklyActivityEntry(id, entryId), payload);
      await reload();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  async function deleteEntry(entryId: number): Promise<Result> {
    try {
      await apiDelete(endpoints.weeklyActivityEntry(id, entryId));
      await reload();
      return { ok: true };
    } catch (err) {
      return { ok: false, error: (err as ApiError).message };
    }
  }

  return { sheet: data, loading, error, isOffline, reload, updateSheet, addEntry, updateEntry, deleteEntry };
}

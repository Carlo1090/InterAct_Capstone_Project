import { useCallback } from 'react';
import { apiGet, apiPost, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { useCachedResource } from './useCachedResource';
import { ExitInterviewResponse } from '../types/api';

/**
 * The closing form of a placement. Read-cached so a submitted interview stays
 * readable offline; saving needs a connection, because the server enforces
 * per-question width limits measured against the printed form's geometry and
 * a queued answer could be silently over-length by the time it lands.
 */
export function useExitInterview() {
  const { data, loading, error, isOffline, reload } = useCachedResource<ExitInterviewResponse>(
    'exit_interview',
    useCallback(() => apiGet<ExitInterviewResponse>(endpoints.exitInterview), [])
  );

  async function save(payload: {
    submit: boolean;
    student_info: Record<string, string>;
    responses: Record<string, string>;
  }): Promise<{ ok: true } | { ok: false; error: string; fieldErrors?: Record<string, string[]> }> {
    try {
      await apiPost(endpoints.exitInterview, payload);
      await reload();
      return { ok: true };
    } catch (err) {
      const apiErr = err as ApiError;
      return { ok: false, error: apiErr.message, fieldErrors: apiErr.fieldErrors };
    }
  }

  return { data, loading, error, isOffline, reload, save };
}

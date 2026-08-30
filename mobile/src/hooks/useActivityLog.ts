import { useCallback } from 'react';
import { apiGet } from '../services/api';
import { endpoints } from '../services/endpoints';
import { useCachedResource } from './useCachedResource';
import { Paginated, SystemLogEntry } from '../types/api';

/** Mirrors web's ActivityLogPanel.vue — the student's own SystemLog rows. */
export function useActivityLog() {
  const { data, loading, error, isOffline, reload } = useCachedResource<SystemLogEntry[]>(
    'activity_log',
    useCallback(async () => {
      const res = await apiGet<Paginated<SystemLogEntry>>(endpoints.profileActivity);
      return res.data;
    }, [])
  );

  return { entries: data ?? [], loading, error, isOffline, reload };
}

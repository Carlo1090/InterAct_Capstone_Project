import { useCallback, useEffect, useRef, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiDelete, apiGet, apiPost, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { getCached, setCached } from '../services/offlineCache';
import { NotificationsResponse } from '../types/api';

const POLL_INTERVAL_MS = 60_000;
const CACHE_KEY = 'notifications';

/**
 * Mirrors web's NotificationBell.vue: polls the unread count every 60s so
 * the badge stays current even while the student is on another screen, and
 * exposes markAllRead/clearAll for the notifications screen to call.
 */
export function useNotifications() {
  const [data, setData] = useState<NotificationsResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [isOffline, setIsOffline] = useState(false);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await apiGet<NotificationsResponse>(endpoints.notifications);
      setData(res);
      setError(null);
      setIsOffline(false);
      await setCached(CACHE_KEY, res);
    } catch (err) {
      setError(err as ApiError);
      setIsOffline(true);
      // A failed 60s poll must never blank a list that is already on screen,
      // so the cache is only used when there is nothing to show yet.
      const cached = await getCached<NotificationsResponse>(CACHE_KEY);
      if (cached !== null) setData((prev) => (prev === null ? cached : prev));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    getCached<NotificationsResponse>(CACHE_KEY).then((cached) => {
      if (cached !== null) setData((prev) => (prev === null ? cached : prev));
    });
    load();
    intervalRef.current = setInterval(load, POLL_INTERVAL_MS);
    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
    };
  }, [load]);

  // Also refresh whenever this hook's screen regains focus, so returning
  // from the notifications list clears a stale badge immediately.
  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  async function markAllRead() {
    await apiPost(endpoints.notificationsReadAll);
    await load();
  }

  async function clearAll() {
    await apiDelete(endpoints.notifications);
    await load();
  }

  return {
    notifications: data?.data ?? [],
    unreadCount: data?.unread_count ?? 0,
    loading,
    error,
    isOffline,
    reload: load,
    markAllRead,
    clearAll,
  };
}

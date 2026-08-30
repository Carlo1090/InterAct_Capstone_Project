import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { getCached, setCached } from '../services/offlineCache';
import { currentMonth, shiftMonthString } from '../lib/datetime';
import { JournalEntrySummary, Paginated, CalendarDay, CalendarResponse } from '../types/api';

const LIST_CACHE_KEY = 'journal_list';

export function useJournalList() {
  const [entries, setEntries] = useState<JournalEntrySummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [isOffline, setIsOffline] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<Paginated<JournalEntrySummary>>(endpoints.journalEntries);
      setEntries(res.data);
      setIsOffline(false);
      await setCached(LIST_CACHE_KEY, res.data);
    } catch (err) {
      setError(err as ApiError);
      // Only fall back to cache if nothing is already showing — a failed
      // background refresh must never regress fresher in-memory data.
      const cached = await getCached<JournalEntrySummary[]>(LIST_CACHE_KEY);
      if (cached) setEntries((prev) => (prev.length > 0 ? prev : cached));
      setIsOffline(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  return { entries, loading, error, isOffline, reload: load };
}

export function useJournalCalendar() {
  const [month, setMonth] = useState(currentMonth());
  const [days, setDays] = useState<CalendarDay[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [isOffline, setIsOffline] = useState(false);

  const load = useCallback(async (targetMonth: string) => {
    setLoading(true);
    setError(null);
    const cacheKey = `calendar_${targetMonth}`;
    try {
      const res = await apiGet<CalendarResponse>(endpoints.journalCalendar, { month: targetMonth });
      setDays(res.days);
      setIsOffline(false);
      await setCached(cacheKey, res.days);
    } catch (err) {
      setError(err as ApiError);
      const cached = await getCached<CalendarDay[]>(cacheKey);
      if (cached) setDays(cached);
      setIsOffline(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      load(month);
    }, [load, month])
  );

  function shiftMonth(delta: number) {
    setMonth((prev) => shiftMonthString(prev, delta));
  }

  return { days, loading, error, isOffline, month, shiftMonth, reload: () => load(month) };
}

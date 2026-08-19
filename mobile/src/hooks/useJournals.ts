import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { JournalEntrySummary, Paginated, CalendarDay, CalendarResponse } from '../types/api';

function currentMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

export function useJournalList() {
  const [entries, setEntries] = useState<JournalEntrySummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<Paginated<JournalEntrySummary>>(endpoints.journalEntries);
      setEntries(res.data);
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

  return { entries, loading, error, reload: load };
}

export function useJournalCalendar() {
  const [month, setMonth] = useState(currentMonth());
  const [days, setDays] = useState<CalendarDay[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async (targetMonth: string) => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<CalendarResponse>(endpoints.journalCalendar, { month: targetMonth });
      setDays(res.days);
    } catch (err) {
      setError(err as ApiError);
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
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`);
  }

  return { days, loading, error, month, shiftMonth, reload: () => load(month) };
}

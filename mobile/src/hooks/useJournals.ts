import { useCallback, useEffect, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { getCached, setCached } from '../services/offlineCache';
import { useCachedResource } from './useCachedResource';
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

/** One month of calendar cells, cached under its own key. */
function calendarCacheKey(month: string): string {
  return `calendar_${month}`;
}

/**
 * Warms a month into the cache WITHOUT touching any component state.
 *
 * Every failure is swallowed on purpose: this is speculative work for a month
 * the student has not asked for yet, so it must never surface an error or a
 * spinner, and offline it must do nothing at all rather than retry.
 */
async function prefetchMonth(month: string): Promise<void> {
  try {
    const res = await apiGet<CalendarResponse>(endpoints.journalCalendar, { month });
    await setCached(calendarCacheKey(month), res.days);
  } catch {
    // Deliberately silent.
  }
}

/**
 * The journal calendar, one month at a time.
 *
 * WHY THIS WAS SLOW, and what each half of the fix does. The hook used to
 * hand-roll its own fetch, and it read the cache **only inside the catch
 * block** — so the saved copy was used only when the request actually FAILED.
 * On a working-but-slow connection (a sleeping free-tier API costs 30-60s to
 * wake) every month change was a blocking round trip with nothing on screen to
 * cover it, even for a month already sitting in storage.
 *
 *  1. `useCachedResource` gives cache-THEN-network: a month already saved
 *     paints immediately and refreshes behind the scenes. This is also the
 *     sixth hook to hand-roll that pattern and the second to drift from it,
 *     which is the whole reason that helper exists.
 *  2. The two ADJACENT months are warmed in the background after each load,
 *     so stepping one month either way is normally instant rather than
 *     instant-only-if-you-have-been-there-before.
 *
 * Nothing here weakens offline behaviour — a month never visited online still
 * has nothing to show, which is honest, and the notice says so.
 */
export function useJournalCalendar() {
  const [month, setMonth] = useState(currentMonth());

  const { data, loading, error, isOffline, reload } = useCachedResource<CalendarDay[]>(
    calendarCacheKey(month),
    () => apiGet<CalendarResponse>(endpoints.journalCalendar, { month }).then((res) => res.days)
  );

  // Only after a month has genuinely loaded, and never while offline — firing
  // two doomed requests on every month change would just add work behind a
  // connection that is already failing.
  useEffect(() => {
    if (data === null || isOffline) return;
    void prefetchMonth(shiftMonthString(month, -1));
    void prefetchMonth(shiftMonthString(month, 1));
  }, [month, data, isOffline]);

  function shiftMonth(delta: number) {
    setMonth((prev) => shiftMonthString(prev, delta));
  }

  return { days: data ?? [], loading, error, isOffline, month, shiftMonth, reload };
}

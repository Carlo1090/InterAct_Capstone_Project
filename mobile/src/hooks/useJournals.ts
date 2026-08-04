import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { fetchWithFallback } from '../services/api';
import { endpoints } from '../services/endpoints';
import { mockJournals, mockCalendarMay2025, JournalEntry, CalDay } from '../services/mock/journals';
import { getAccountKind } from '../services/accountKind';
import { getCurrentLocalProfile } from '../services/localAccounts';
import { computeJournalListFromEntries, computeCalendarFromEntries } from '../services/localData';

export function useJournalList() {
  const [entries, setEntries] = useState<JournalEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [kind, setKind] = useState<'demo' | 'new' | null>(null);

  useFocusEffect(
    useCallback(() => {
      let mounted = true;
      (async () => {
        const accountKind = await getAccountKind();
        if (mounted) setKind(accountKind);
        if (accountKind === 'new') {
          const profile = await getCurrentLocalProfile();
          const computed = profile ? await computeJournalListFromEntries(profile.email) : [];
          if (mounted) {
            setEntries(computed as JournalEntry[]);
            setLoading(false);
          }
          return;
        }
        if (accountKind === 'demo') {
          if (mounted) {
            setEntries(mockJournals);
            setLoading(false);
          }
          return;
        }
        const r = await fetchWithFallback(endpoints.journalEntries, mockJournals);
        if (mounted) {
          setEntries(r.data);
          setLoading(false);
        }
      })();
      return () => {
        mounted = false;
      };
    }, [])
  );

  return { entries, loading, kind };
}

export function useJournalCalendar() {
  const [days, setDays] = useState<CalDay[]>([]);
  const [loading, setLoading] = useState(true);

  useFocusEffect(
    useCallback(() => {
      let mounted = true;
      (async () => {
        const kind = await getAccountKind();
        if (kind === 'new') {
          const profile = await getCurrentLocalProfile();
          const now = new Date();
          const computed = profile
            ? await computeCalendarFromEntries(profile.email, now.getFullYear(), now.getMonth())
            : [];
          if (mounted) {
            setDays(computed as CalDay[]);
            setLoading(false);
          }
          return;
        }
        if (kind === 'demo') {
          if (mounted) {
            setDays(mockCalendarMay2025);
            setLoading(false);
          }
          return;
        }
        const r = await fetchWithFallback(`${endpoints.journalEntries}/calendar`, mockCalendarMay2025);
        if (mounted) {
          setDays(r.data);
          setLoading(false);
        }
      })();
      return () => {
        mounted = false;
      };
    }, [])
  );

  return { days, loading };
}

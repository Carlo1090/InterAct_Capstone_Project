import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { fetchWithFallback } from '../services/api';
import { endpoints } from '../services/endpoints';
import { mockDashboard, DashboardData } from '../services/mock/dashboard';
import { getAccountKind } from '../services/accountKind';
import { getCurrentLocalProfile } from '../services/localAccounts';
import { computeDashboardFromEntries } from '../services/localData';

export function useDashboard() {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);

  useFocusEffect(
    useCallback(() => {
      let mounted = true;
      (async () => {
        const kind = await getAccountKind();
        if (kind === 'new') {
          const profile = await getCurrentLocalProfile();
          const computed = profile ? await computeDashboardFromEntries(profile.email) : null;
          if (mounted) {
            setData(computed as DashboardData);
            setLoading(false);
          }
          return;
        }
        if (kind === 'demo') {
          if (mounted) {
            setData(mockDashboard);
            setLoading(false);
          }
          return;
        }
        const r = await fetchWithFallback(endpoints.dashboard, mockDashboard);
        if (mounted) {
          setData(r.data);
          setLoading(false);
        }
      })();
      return () => {
        mounted = false;
      };
    }, [])
  );

  return { data, loading };
}

import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { fetchWithFallback } from '../services/api';
import { endpoints } from '../services/endpoints';
import { mockStudentInfo, StudentInfo } from '../services/mock/studentInfo';
import { emptyStudentInfo } from '../services/mock/empty';
import { getAccountKind } from '../services/accountKind';
import { getCurrentLocalProfile } from '../services/localAccounts';
import { getSavedStudentInfo } from '../services/localData';

// Fixed key so the demo account's manual edits persist across app restarts
// too, same as a real account would — without needing a real login session.
const DEMO_STORAGE_EMAIL = 'student@interntrack.test';

export function useStudentInfo() {
  const [data, setData] = useState<StudentInfo | null>(null);
  const [loading, setLoading] = useState(true);
  const [storageEmail, setStorageEmail] = useState<string | null>(null);

  const load = useCallback(async () => {
    const kind = await getAccountKind();

    if (kind === 'new') {
      const profile = await getCurrentLocalProfile();
      const email = profile?.email ?? null;
      setStorageEmail(email);

      let base: StudentInfo = { ...emptyStudentInfo, personal: { ...emptyStudentInfo.personal } };
      if (profile) {
        const [firstName, ...rest] = profile.name.trim().split(' ');
        base.personal.firstName = firstName ?? '';
        base.personal.lastName = rest.join(' ');
        base.personal.studentId = profile.studentId;
        base.personal.email = profile.email;
      }

      if (email) {
        const saved = await getSavedStudentInfo<StudentInfo>(email);
        if (saved) base = saved;
      }

      setData(base);
      setLoading(false);
      return;
    }

    // Demo account (or a real backend session, treated the same way here
    // since it's a reasonable default until real PATCH support is wired).
    setStorageEmail(DEMO_STORAGE_EMAIL);
    const saved = await getSavedStudentInfo<StudentInfo>(DEMO_STORAGE_EMAIL);
    if (saved) {
      setData(saved);
      setLoading(false);
      return;
    }
    const r = await fetchWithFallback(endpoints.studentInfoSheet, mockStudentInfo);
    setData(r.data);
    setLoading(false);
  }, []);

  useFocusEffect(
    useCallback(() => {
      setLoading(true);
      load();
    }, [load])
  );

  return { data, loading, storageEmail, reload: load };
}

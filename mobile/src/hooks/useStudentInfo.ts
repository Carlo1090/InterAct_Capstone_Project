import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { apiGet, apiPost, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { InfoSheet, CompanyOption } from '../types/api';

export function useStudentInfo() {
  const [data, setData] = useState<InfoSheet | null>(null);
  const [companies, setCompanies] = useState<CompanyOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [sheet, companyList] = await Promise.all([
        apiGet<InfoSheet>(endpoints.infoSheet),
        apiGet<CompanyOption[]>(endpoints.companies),
      ]);
      setData(sheet);
      setCompanies(companyList);
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

  async function save(payload: {
    status: 'draft' | 'submitted';
    personal_info: InfoSheet['personal_info'];
    academic_info: InfoSheet['academic_info'];
    ojt_info: InfoSheet['ojt_info'];
    emergency_contact?: InfoSheet['emergency_contact'];
  }): Promise<{ ok: true } | { ok: false; error: string; fieldErrors?: Record<string, string[]> }> {
    try {
      const saved = await apiPost<InfoSheet>(endpoints.infoSheet, payload);
      setData(saved);
      return { ok: true };
    } catch (err) {
      const apiErr = err as ApiError;
      return { ok: false, error: apiErr.message, fieldErrors: apiErr.fieldErrors };
    }
  }

  return { data, companies, loading, error, reload: load, save };
}

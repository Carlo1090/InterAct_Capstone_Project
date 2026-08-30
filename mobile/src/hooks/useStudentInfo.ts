import { useCallback } from 'react';
import { apiGet, apiPost, ApiError } from '../services/api';
import { endpoints } from '../services/endpoints';
import { useCachedResource } from './useCachedResource';
import { InfoSheet, CompanyOption } from '../types/api';

/** Sheet and company list are cached together — the company dropdown is
 *  useless without the sheet and vice versa, so one cache entry keeps the
 *  two from ever being restored out of step. */
type InfoSheetBundle = { sheet: InfoSheet; companies: CompanyOption[] };

export function useStudentInfo() {
  const {
    data: bundle,
    loading,
    error,
    isOffline,
    reload,
    setData,
  } = useCachedResource<InfoSheetBundle>(
    'info_sheet',
    useCallback(async () => {
      const [sheet, companies] = await Promise.all([
        apiGet<InfoSheet>(endpoints.infoSheet),
        apiGet<CompanyOption[]>(endpoints.companies),
      ]);
      return { sheet, companies };
    }, [])
  );

  const data = bundle?.sheet ?? null;
  const companies = bundle?.companies ?? [];

  async function save(payload: {
    status: 'draft' | 'submitted';
    personal_info: InfoSheet['personal_info'];
    academic_info: InfoSheet['academic_info'];
    ojt_info: InfoSheet['ojt_info'];
    emergency_contact?: InfoSheet['emergency_contact'];
  }): Promise<{ ok: true } | { ok: false; error: string; fieldErrors?: Record<string, string[]> }> {
    try {
      const saved = await apiPost<InfoSheet>(endpoints.infoSheet, payload);
      // Keep the cached companies alongside the freshly-saved sheet rather
      // than dropping them — the save response carries no company list.
      setData((prev) => ({ sheet: saved, companies: prev?.companies ?? [] }));
      return { ok: true };
    } catch (err) {
      const apiErr = err as ApiError;
      return { ok: false, error: apiErr.message, fieldErrors: apiErr.fieldErrors };
    }
  }

  return { data, companies, loading, error, isOffline, reload, save };
}

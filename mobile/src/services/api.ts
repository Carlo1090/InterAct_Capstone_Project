import axios, { AxiosError } from 'axios';
import * as FileSystem from 'expo-file-system/legacy';
import * as Sharing from 'expo-sharing';
import * as SecureStore from 'expo-secure-store';

// 10.0.2.2 = Android emulator alias for the host machine's localhost.
// Swap for your machine's LAN IP (e.g. http://192.168.1.20:8000) when
// testing on a physical device on the same Wi-Fi network.
export const API_BASE_URL = process.env.EXPO_PUBLIC_API_URL ?? 'http://10.0.2.2:8000';

export const TOKEN_KEY = 'interntrack_token';

export const api = axios.create({
  baseURL: API_BASE_URL,
  // 60s, not the usual 10s: the API runs on a Render free instance that spins
  // down after ~15 minutes idle and takes 30-60s to cold start. A 10s timeout
  // made the FIRST request after any idle period fail every time, surfacing as
  // a bogus "check your internet connection" on a perfectly good network.
  timeout: 60000,
});

api.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  if (token) {
    config.headers = config.headers ?? ({} as typeof config.headers);
    (config.headers as any).Authorization = `Bearer ${token}`;
  }
  return config;
});

/**
 * A real, typed error thrown by apiGet/apiPost/etc — carries the HTTP
 * status and whatever Laravel's error body contained, so a screen can show
 * an honest message instead of a generic one. There is no mock-data
 * fallback here: a real error must always surface as a real error, since a
 * silently-substituted fake value could show stale/wrong data (e.g. the
 * wrong coordinator/supervisor) without the student ever knowing.
 */
export class ApiError extends Error {
  status: number | null;
  fieldErrors?: Record<string, string[]>;

  constructor(message: string, status: number | null, fieldErrors?: Record<string, string[]>) {
    super(message);
    this.status = status;
    this.fieldErrors = fieldErrors;
  }
}

export function toApiError(err: unknown): ApiError {
  if (err instanceof ApiError) return err;
  if (axios.isAxiosError(err)) {
    const axiosErr = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
    if (!axiosErr.response) {
      // A timeout is NOT the same as being offline, and must not be reported as
      // one: the API sleeps on its free tier, so a slow first response is
      // expected and the connection is usually fine. Telling the student to
      // check their Wi-Fi here sends them chasing a problem they don't have.
      if (axiosErr.code === 'ECONNABORTED' || axiosErr.code === 'ETIMEDOUT') {
        return new ApiError('InternTrack is taking longer than usual to respond. Please try again in a moment.', null);
      }
      // No response at all — device offline or DNS failure. Deliberately avoids
      // the word "server": that reads as a scary/technical system fault to a
      // non-technical user rather than what it almost always actually is —
      // their own Wi-Fi/mobile data being off.
      return new ApiError("We couldn't connect to InternTrack. Please check your internet connection and try again.", null);
    }
    const body = axiosErr.response.data;
    const message = body?.message ?? `Something went wrong (error ${axiosErr.response.status}). Please try again.`;
    return new ApiError(message, axiosErr.response.status, body?.errors);
  }
  return new ApiError('Something went wrong. Please try again.', null);
}

export async function apiGet<T>(path: string, params?: Record<string, unknown>): Promise<T> {
  try {
    const res = await api.get<T>(path, { params });
    return res.data;
  } catch (err) {
    throw toApiError(err);
  }
}

export async function apiPost<T>(path: string, body?: Record<string, unknown>): Promise<T> {
  try {
    const res = await api.post<T>(path, body);
    return res.data;
  } catch (err) {
    throw toApiError(err);
  }
}

export async function apiPut<T>(path: string, body?: Record<string, unknown>): Promise<T> {
  try {
    const res = await api.put<T>(path, body);
    return res.data;
  } catch (err) {
    throw toApiError(err);
  }
}

export async function apiDelete<T>(path: string): Promise<T> {
  try {
    const res = await api.delete<T>(path);
    return res.data;
  } catch (err) {
    throw toApiError(err);
  }
}

/**
 * Downloads a PDF from an auth:sanctum-protected endpoint and hands it to
 * the OS share sheet. Linking.openURL can't be used here — it wouldn't carry
 * the Authorization bearer header the backend requires, so the request would
 * 401. Fetched as base64 and written to the app's cache dir instead.
 */
export async function downloadAndSharePdf(path: string, filename: string): Promise<void> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);
  const url = `${API_BASE_URL}${path}`;

  const cacheDir = FileSystem.cacheDirectory ?? FileSystem.documentDirectory;
  if (!cacheDir) {
    throw new ApiError('This device has no writable storage available for downloads.', null);
  }

  const target = `${cacheDir}${filename}`;
  const result = await FileSystem.downloadAsync(url, target, {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  });

  if (result.status !== 200) {
    throw new ApiError('Could not download the PDF. Please try again.', result.status);
  }

  if (await Sharing.isAvailableAsync()) {
    await Sharing.shareAsync(result.uri, { mimeType: 'application/pdf' });
  }
}

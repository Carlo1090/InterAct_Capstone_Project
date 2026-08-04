import axios from 'axios';
import * as SecureStore from 'expo-secure-store';

// 10.0.2.2 = Android emulator alias for the host machine's localhost.
// Swap for your machine's LAN IP (e.g. http://192.168.1.20:8000) when
// testing on a physical device on the same Wi-Fi network.
export const API_BASE_URL = process.env.EXPO_PUBLIC_API_URL ?? 'http://10.0.2.2:8000';

export const TOKEN_KEY = 'interntrack_token';

export const api = axios.create({
  baseURL: API_BASE_URL,
  timeout: 4000,
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
 * Calls the real endpoint; if it fails for any reason (backend not running
 * yet, that Phase isn't built, network error, 404/500), silently falls back
 * to the provided mock data so the screen still renders something useful.
 */
export async function fetchWithFallback<T>(
  path: string,
  mockData: T,
  params?: Record<string, unknown>
): Promise<{ data: T; isMock: boolean }> {
  try {
    const res = await api.get<T>(path, { params });
    return { data: res.data, isMock: false };
  } catch {
    return { data: mockData, isMock: true };
  }
}

export async function postWithFallback<T>(
  path: string,
  body: Record<string, unknown>,
  mockResponse: T
): Promise<{ data: T; isMock: boolean }> {
  try {
    const res = await api.post<T>(path, body);
    return { data: res.data, isMock: false };
  } catch {
    return { data: mockResponse, isMock: true };
  }
}

import axios, { AxiosError } from 'axios';
import * as FileSystem from 'expo-file-system/legacy';
import * as Sharing from 'expo-sharing';
import * as SecureStore from 'expo-secure-store';
// Safe: endpoints.ts imports nothing, so there is no cycle back into here.
import { endpoints } from './endpoints';
import type { CurrentUser } from '../types/api';

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
  /**
   * True only when the request itself timed out (Axios `ECONNABORTED`/
   * `ETIMEDOUT`) — never for a genuine "no route to host" failure.
   *
   * THE BUG THIS FIXES: every screen's "Offline" banner was firing on ANY
   * failed request, timeouts included. The API sleeps on a free Render
   * instance and can take up to the full 60s timeout to wake — so a student
   * with a perfectly good connection, opening the app right as it woke up,
   * was told "Offline — check your internet connection" for a problem that
   * was never theirs. This flag is what lets a caller tell "your device has
   * no signal" apart from "the server is slow to answer" and say the honest
   * thing instead of guessing wrong.
   */
  isTimeout: boolean;

  constructor(message: string, status: number | null, fieldErrors?: Record<string, string[]>, isTimeout = false) {
    super(message);
    this.status = status;
    this.fieldErrors = fieldErrors;
    this.isTimeout = isTimeout;
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
        return new ApiError(
          'InternTrack is taking longer than usual to respond. Please try again in a moment.',
          null,
          undefined,
          true
        );
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

/**
 * A request that failed for a NETWORK reason is retried; one that the server
 * actually answered is not.
 *
 * THE BUG THIS FIXES: nothing in the app retried anything, ever. A screen that
 * failed once stayed "Offline" until the student happened to switch tabs or
 * pull to refresh — so a single unlucky moment stuck the whole app in offline
 * mode indefinitely, on a phone with a perfectly good connection.
 *
 * That unlucky moment is not rare here, it is the NORMAL launch: the API sleeps
 * on a free Render instance after ~15 minutes idle, and installing or updating
 * the app is precisely when it has been idle. `preloadAll()` then fires its
 * whole warm-up at a server that is still waking, and on an over-the-air update
 * the new bundle is downloading over the same connection at the same time.
 *
 * Delays are short and few on purpose. This is not a general resilience layer —
 * it is a wake-up allowance for a server that is coming back in seconds, and a
 * cushion for the transient failure a phone hands you when it switches between
 * Wi-Fi and mobile data.
 */
const RETRY_DELAYS_MS = [1200, 3500];

function wait(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function withRetry<T>(run: () => Promise<T>): Promise<T> {
  for (let attempt = 0; ; attempt += 1) {
    try {
      return await run();
    } catch (err) {
      const apiErr = toApiError(err);

      // `status === null` means no response reached us at all — offline, DNS,
      // a dropped connection, or a timeout. Anything with a status is the
      // server's considered answer (401, 422, 500) and repeating it would just
      // get the same answer more slowly.
      const worthRetrying = apiErr.status === null && attempt < RETRY_DELAYS_MS.length;
      if (!worthRetrying) throw apiErr;

      await wait(RETRY_DELAYS_MS[attempt]);
    }
  }
}

export async function apiGet<T>(path: string, params?: Record<string, unknown>): Promise<T> {
  // GET is idempotent, so retrying it can only ever cost time.
  return withRetry(async () => {
    const res = await api.get<T>(path, { params });
    return res.data;
  });
}

export async function apiPost<T>(
  path: string,
  body?: Record<string, unknown>,
  options: { retry?: boolean } = {}
): Promise<T> {
  // A write is NOT retried by default, and that is the whole reason this is an
  // opt-in flag rather than the same treatment GET gets: a POST whose response
  // was lost may well have succeeded, so repeating it can file a second journal
  // entry or a second punch. Losing a response is exactly the case the journal
  // outbox exists to handle safely.
  const send = async () => {
    const res = await api.post<T>(path, body);
    return res.data;
  };

  try {
    return options.retry ? await withRetry(send) : await send();
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

/**
 * Uploads a picked image as the user's avatar.
 *
 * Sent as real multipart/form-data rather than base64 JSON: the backend's
 * rule is `['required','image','mimes:jpeg,jpg,png,webp','max:2048']`, and
 * `image`/`mimes` validate an uploaded FILE — a base64 string would fail
 * before ever reaching AvatarProcessingService.
 *
 * The Content-Type header is deliberately NOT set. React Native's fetch adds
 * the multipart boundary itself, and setting the header by hand omits that
 * boundary, which makes the server parse zero fields and report the photo as
 * missing.
 */
export async function uploadAvatar(uri: string, mimeType?: string | null): Promise<CurrentUser> {
  const token = await SecureStore.getItemAsync(TOKEN_KEY);

  // Derive a filename with a real extension — Laravel's `mimes:` rule reads
  // the uploaded name's extension as well as the sniffed type.
  const extensionFromUri = uri.split('.').pop()?.split('?')[0]?.toLowerCase();
  const extension = ['jpg', 'jpeg', 'png', 'webp'].includes(extensionFromUri ?? '')
    ? (extensionFromUri as string)
    : 'jpg';

  const form = new FormData();
  form.append('photo', {
    uri,
    name: `avatar.${extension}`,
    type: mimeType || `image/${extension === 'jpg' ? 'jpeg' : extension}`,
  } as unknown as Blob);

  const response = await fetch(`${API_BASE_URL}${endpoints.profilePhoto}`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: form,
  });

  if (!response.ok) {
    let message = 'Could not upload your photo. Please try again.';
    try {
      const body = await response.json();
      message = body?.errors?.photo?.[0] ?? body?.message ?? message;
    } catch {
      // Non-JSON error body — keep the default wording.
    }
    throw new ApiError(message, response.status);
  }

  // The endpoint returns the refreshed user row, so the caller can push it
  // straight into the shared store — no extra /api/user round trip, and the
  // header updates the moment the upload lands.
  return (await response.json()) as CurrentUser;
}

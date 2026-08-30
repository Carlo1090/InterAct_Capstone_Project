import { useCallback, useEffect, useRef, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { ApiError } from '../services/api';
import { getCached, setCached } from '../services/offlineCache';

/**
 * Cache-then-network, in one place.
 *
 * Five hooks were each hand-rolling this and two of them had already drifted.
 * The rules it enforces:
 *
 *  - Hydrate from cache immediately, so a screen opens with real content
 *    instead of a spinner, then refresh in the background.
 *  - On a failed fetch, KEEP whatever is already showing. A failed background
 *    refresh must never regress fresher in-memory data back to a stale cache.
 *  - `ErrorState` is only reached when there is genuinely nothing to show —
 *    a first-ever load with no cache at all.
 *
 * The fetcher is held in a ref rather than a dependency. Passing it as a dep
 * means every caller must remember to `useCallback` it, and forgetting causes
 * a silent refetch loop; holding it in a ref makes that impossible while
 * still always calling the latest closure.
 */
export function useCachedResource<T>(
  cacheKey: string,
  fetcher: () => Promise<T>,
  options: { enabled?: boolean } = {}
) {
  const { enabled = true } = options;

  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [isOffline, setIsOffline] = useState(false);

  const fetcherRef = useRef(fetcher);
  fetcherRef.current = fetcher;

  // Guards against a slow response from a previous cacheKey landing after a
  // newer one has already resolved.
  const requestId = useRef(0);

  const load = useCallback(async () => {
    // Must clear `loading`, not just bail: `loading` starts true, so an early
    // return here left a disabled resource spinning forever (hit with an
    // invalid :id route param, where `Number(rawId)` is NaN).
    if (!enabled) {
      setLoading(false);
      return;
    }

    const id = ++requestId.current;
    setLoading(true);
    setError(null);

    try {
      const fresh = await fetcherRef.current();
      if (id !== requestId.current) return;
      setData(fresh);
      setIsOffline(false);
      await setCached(cacheKey, fresh);
    } catch (err) {
      if (id !== requestId.current) return;
      setError(err as ApiError);
      const cached = await getCached<T>(cacheKey);
      // Only fall back to cache when nothing is on screen yet.
      if (cached !== null) setData((prev) => (prev === null ? cached : prev));
      setIsOffline(true);
    } finally {
      if (id === requestId.current) setLoading(false);
    }
  }, [cacheKey, enabled]);

  // Paint the cached copy before the network call settles.
  useEffect(() => {
    let cancelled = false;
    getCached<T>(cacheKey).then((cached) => {
      if (!cancelled && cached !== null) setData((prev) => (prev === null ? cached : prev));
    });
    return () => {
      cancelled = true;
    };
  }, [cacheKey]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  return { data, loading, error, isOffline, reload: load, setData };
}

import { useEffect, useRef, useState } from 'react';
import { preloadAll, PreloadProgress } from '../services/preload';
import { useIsOnline } from '../services/network';
import { useAuth } from './useAuth';

const PRELOAD_TIMEOUT_MS = 12_000;

/**
 * Runs the one-time cache warm-up after sign-in and reports progress so the
 * splash can say what it is doing.
 *
 * Two deliberate limits:
 *  - It only ever runs ONCE per app launch (a ref, not state, so a re-render
 *    cannot restart it).
 *  - It is capped by a timeout. A warm-up is an optimisation; if the network
 *    is slow the student must still reach their dashboard, so the splash
 *    gives up waiting and lets them through with whatever was cached.
 */
export function usePreload() {
  const { isAuthenticated } = useAuth();
  const isOnline = useIsOnline();

  const [progress, setProgress] = useState<PreloadProgress | null>(null);
  const [finished, setFinished] = useState(false);
  const started = useRef(false);

  useEffect(() => {
    if (isAuthenticated !== true) {
      // Nothing to warm for a signed-out user; don't hold the splash.
      if (isAuthenticated === false) setFinished(true);
      return;
    }
    if (started.current) return;
    started.current = true;

    // Offline at launch: everything already cached stays usable, and there
    // is nothing to fetch, so go straight through.
    if (!isOnline) {
      setFinished(true);
      return;
    }

    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      setFinished(true);
    };

    const timer = setTimeout(finish, PRELOAD_TIMEOUT_MS);

    preloadAll(setProgress)
      .catch(() => {
        // preloadAll already swallows per-endpoint failures; this is only a
        // last-resort guard so a thrown error cannot strand the splash.
      })
      .finally(() => {
        clearTimeout(timer);
        finish();
      });

    return () => clearTimeout(timer);
  }, [isAuthenticated, isOnline]);

  return { progress, finished };
}

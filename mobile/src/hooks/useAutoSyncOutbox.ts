import { useEffect, useRef } from 'react';
import { AppState } from 'react-native';
import { useIsOnline } from '../services/network';
import { flushQueue } from '../services/journalOutbox';

/**
 * Mounted once at the app root (not per-screen). Flushes the offline
 * journal outbox whenever connectivity transitions from offline to online,
 * and opportunistically when the app returns to the foreground while
 * already online — covers the case where connectivity silently came back
 * while the app was backgrounded, with no clean transition event to catch.
 */
export function useAutoSyncOutbox() {
  const online = useIsOnline();
  const wasOnline = useRef(online);

  useEffect(() => {
    if (online && !wasOnline.current) {
      flushQueue();
    }
    wasOnline.current = online;
  }, [online]);

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (state) => {
      if (state === 'active' && wasOnline.current) {
        flushQueue();
      }
    });
    return () => subscription.remove();
  }, []);
}

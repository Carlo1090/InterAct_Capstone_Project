import { useEffect, useState } from 'react';
import NetInfo, { NetInfoState } from '@react-native-community/netinfo';

/** `isInternetReachable` is nullable — netinfo can't always confirm actual
 * internet reachability (vs. just "connected to a network"), so `null` is
 * treated as online rather than offline; only an explicit `false` counts. */
function isOnlineState(state: NetInfoState): boolean {
  return !!state.isConnected && state.isInternetReachable !== false;
}

export function useIsOnline(): boolean {
  const [online, setOnline] = useState(true);

  useEffect(() => {
    let mounted = true;
    NetInfo.fetch().then((state) => {
      if (mounted) setOnline(isOnlineState(state));
    });
    const unsubscribe = NetInfo.addEventListener((state) => {
      setOnline(isOnlineState(state));
    });
    return () => {
      mounted = false;
      unsubscribe();
    };
  }, []);

  return online;
}

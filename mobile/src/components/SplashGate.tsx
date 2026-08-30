import { View, Text, Image, ActivityIndicator, StyleSheet } from 'react-native';
import { usePreload } from '../hooks/usePreload';
import { colors } from '../constants/colors';

/**
 * The launch screen. Holds the app for one pass of the cache warm-up so the
 * student lands on a Dashboard that already has content — and, more to the
 * point, so every other screen works offline afterwards even if they never
 * opened it while connected.
 *
 * It always lets go: usePreload caps itself with a timeout, and an offline
 * launch skips straight through.
 */
export function SplashGate() {
  const { progress, finished } = usePreload();

  if (finished) return null;

  const pct =
    progress && progress.total > 0 ? Math.round((progress.done / progress.total) * 100) : 0;

  return (
    <View
      style={{
        ...StyleSheet.absoluteFillObject,
        backgroundColor: colors.blue900,
        alignItems: 'center',
        justifyContent: 'center',
        padding: 32,
        gap: 16,
        zIndex: 10,
      }}
    >
      <View
        style={{
          width: 88,
          height: 88,
          borderRadius: 44,
          backgroundColor: 'white',
          alignItems: 'center',
          justifyContent: 'center',
          overflow: 'hidden',
        }}
      >
        <Image
          source={require('../../assets/mater-dei-logo.png')}
          style={{ width: 74, height: 74 }}
          resizeMode="contain"
        />
      </View>

      <Text style={{ color: 'white', fontSize: 20, fontWeight: '700' }}>InternTrack</Text>
      <Text style={{ color: colors.blue200, fontSize: 12 }}>Journal &amp; Monitoring</Text>

      <View style={{ width: '100%', maxWidth: 260, marginTop: 8 }}>
        <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.blue700, overflow: 'hidden' }}>
          <View
            style={{ width: `${pct}%`, height: '100%', borderRadius: 3, backgroundColor: colors.blue400 }}
          />
        </View>
      </View>

      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginTop: 4 }}>
        <ActivityIndicator size="small" color={colors.blue300} />
        <Text style={{ color: colors.blue200, fontSize: 11.5 }}>
          {progress ? `Preparing ${progress.label}…` : 'Getting things ready…'}
        </Text>
      </View>

      <Text style={{ color: colors.blue300, fontSize: 10.5, textAlign: 'center', marginTop: 4, lineHeight: 15 }}>
        Saving your data to this device so the app keeps working without a connection.
      </Text>
    </View>
  );
}

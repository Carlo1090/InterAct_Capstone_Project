import { View, Text } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Button } from '../src/components/Button';
import { colors } from '../src/constants/colors';
import { useAuth } from '../src/hooks/useAuth';

/**
 * Mirrors the web SPA's /student/paused page: shown when a student has
 * cleared the info-sheet gate but currently has no active/completed
 * enrollment (dropped from their batch). Submitted work is preserved —
 * this is a calm notice, not an error state. No API calls.
 */
export default function Paused() {
  const { logout } = useAuth();

  async function onLogout() {
    await logout();
    router.replace('/login');
  }

  return (
    <View style={{ flex: 1, backgroundColor: colors.gray50, alignItems: 'center', justifyContent: 'center', padding: 32 }}>
      <View
        style={{
          width: 72,
          height: 72,
          borderRadius: 36,
          backgroundColor: colors.amberBg,
          alignItems: 'center',
          justifyContent: 'center',
          marginBottom: 20,
        }}
      >
        <Ionicons name="pause-circle-outline" size={36} color={colors.amberTx} />
      </View>
      <Text style={{ fontSize: 18, fontWeight: '700', color: colors.black, textAlign: 'center', marginBottom: 10 }}>
        Your enrollment is currently inactive
      </Text>
      <Text style={{ fontSize: 13, color: colors.gray600, textAlign: 'center', lineHeight: 20, marginBottom: 24 }}>
        Please contact your coordinator. Your submitted journal entries and weekly reports are safely kept and will
        be available again once you are re-enrolled in a batch.
      </Text>
      <Button
        label="View Student Info Sheet"
        icon="clipboard-outline"
        onPress={() => router.push('/infosheet')}
        style={{ marginBottom: 12 }}
      />
      <Button label="Log Out" variant="danger" icon="log-out-outline" onPress={onLogout} />
    </View>
  );
}

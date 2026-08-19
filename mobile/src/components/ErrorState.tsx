import { View, Text, Pressable, ActivityIndicator } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';

/**
 * Shared loading / real-error / retry block. A failed request must always
 * show a real message with a way to retry — never a silently-substituted
 * fake value, since that could show stale or wrong data (e.g. the wrong
 * coordinator/supervisor) without the student knowing.
 */
export function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 32, gap: 12 }}>
      <Ionicons name="cloud-offline-outline" size={32} color={colors.gray400} />
      <Text style={{ fontSize: 13, color: colors.gray600, textAlign: 'center', lineHeight: 19 }}>{message}</Text>
      <Pressable
        onPress={onRetry}
        style={{
          marginTop: 8,
          paddingVertical: 10,
          paddingHorizontal: 20,
          borderRadius: 10,
          backgroundColor: colors.blue600,
        }}
      >
        <Text style={{ color: 'white', fontSize: 13, fontWeight: '600' }}>Retry</Text>
      </Pressable>
    </View>
  );
}

export function LoadingState() {
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}>
      <ActivityIndicator color={colors.blue600} />
    </View>
  );
}

import { View, Text } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';

/**
 * An inline error that cannot be mistaken for body text.
 *
 * Replaces the small lines of red text that were previously used for form
 * failures. Those sat at body weight in the page's normal flow, so on a busy
 * screen they read as a caption rather than as something that had gone wrong.
 *
 * The left stripe is the point: it gives the block a hard edge that breaks
 * the page's vertical rhythm, which is what actually draws the eye — colour
 * alone did not, and colour alone also fails for a red-green colour-blind
 * reader. The icon carries the same meaning a third time.
 */
export function ErrorNotice({ message, detail }: { message: string; detail?: string }) {
  return (
    <View
      accessibilityRole="alert"
      style={{
        marginHorizontal: 20,
        marginTop: 14,
        flexDirection: 'row',
        alignItems: 'flex-start',
        gap: 10,
        backgroundColor: colors.redBg,
        borderWidth: 1,
        borderColor: '#fecaca',
        borderLeftWidth: 4,
        borderLeftColor: colors.redDark,
        borderRadius: 10,
        paddingVertical: 12,
        paddingHorizontal: 13,
      }}
    >
      <Ionicons name="alert-circle" size={18} color={colors.redDark} style={{ marginTop: 1 }} />
      <View style={{ flex: 1 }}>
        <Text style={{ color: colors.redTx, fontSize: 13, fontWeight: '700', lineHeight: 18 }}>
          {message}
        </Text>
        {detail ? (
          <Text style={{ color: colors.redTx, fontSize: 12, lineHeight: 17, marginTop: 3, opacity: 0.9 }}>
            {detail}
          </Text>
        ) : null}
      </View>
    </View>
  );
}

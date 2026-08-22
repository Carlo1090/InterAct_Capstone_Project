import { View, Text } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';

type Variant = 'warn' | 'info' | 'neutral';

const VARIANT_STYLE: Record<Variant, { bg: string; border: string; text: string; icon: keyof typeof Ionicons.glyphMap }> = {
  warn: { bg: colors.warnBg, border: colors.warnBorder, text: colors.warnTx, icon: 'warning-outline' },
  info: { bg: colors.blue50, border: colors.blue200, text: colors.blue700, icon: 'information-circle-outline' },
  // Amber is reserved for the single most actionable notice on a page (e.g.
  // missing entries) — a second, less urgent notice (like "showing offline
  // data") uses this neutral slate treatment instead of competing for the
  // same amber attention.
  neutral: { bg: colors.gray100, border: colors.gray200, text: colors.gray600, icon: 'cloud-offline-outline' },
};

export function Banner({ variant, children }: { variant: Variant; children: React.ReactNode }) {
  const s = VARIANT_STYLE[variant];

  return (
    <View
      style={{
        marginHorizontal: 20,
        marginTop: 16,
        padding: 12,
        borderRadius: 10,
        backgroundColor: s.bg,
        borderWidth: 1,
        borderColor: s.border,
        flexDirection: 'row',
        gap: 10,
      }}
    >
      <Ionicons name={s.icon} size={16} color={s.text} style={{ marginTop: 1 }} />
      <Text style={{ flex: 1, fontSize: 12.5, lineHeight: 18, color: s.text }}>{children}</Text>
    </View>
  );
}

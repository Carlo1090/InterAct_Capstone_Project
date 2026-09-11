import { View, Text } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';

type Variant = 'warn' | 'info' | 'neutral' | 'offline';

const VARIANT_STYLE: Record<
  Variant,
  { bg: string; border: string; text: string; icon: keyof typeof Ionicons.glyphMap; stripe?: string }
> = {
  warn: { bg: colors.warnBg, border: colors.warnBorder, text: colors.warnTx, icon: 'warning-outline' },
  info: { bg: colors.blue50, border: colors.blue200, text: colors.blue700, icon: 'information-circle-outline' },
  neutral: { bg: colors.gray100, border: colors.gray200, text: colors.gray600, icon: 'cloud-offline-outline' },
  /**
   * OFFLINE — and it is deliberately the loudest thing on the page it appears on.
   *
   * THE BUG THIS FIXES: offline used to render as `neutral` — grey text on a
   * grey wash inside a grey border, which is the quietest treatment the app
   * has. So the one notice that says "what you are reading may be out of date"
   * looked less important than the ordinary blue info banners beside it, and
   * students did not notice they were offline at all. The old comment argued
   * amber belongs to "the single most actionable notice" — true, but when the
   * device is offline THAT is the most important state on the screen, and it
   * is also the one the student can act on (find a signal).
   *
   * It carries its meaning FOUR ways, because colour alone fails a red-green
   * colour-blind reader and a bright screen outdoors: a solid left stripe, a
   * filled icon chip, a bold title line, and the colour itself.
   */
  offline: {
    bg: colors.offlineBg,
    border: colors.offlineBorder,
    text: colors.offlineTx,
    icon: 'cloud-offline',
    stripe: colors.offlineStripe,
  },
};

export function Banner({
  variant,
  title,
  children,
}: {
  variant: Variant;
  /** Bold lead line above the body. Used by the offline banner; optional elsewhere. */
  title?: string;
  children: React.ReactNode;
}) {
  const s = VARIANT_STYLE[variant];

  return (
    <View
      style={{
        marginHorizontal: 20,
        marginTop: 16,
        borderRadius: 10,
        backgroundColor: s.bg,
        borderWidth: 1,
        borderColor: s.border,
        // The stripe is drawn as a thick left border so it cannot be clipped by
        // the rounded corner or drift out of step with the text block's height.
        borderLeftWidth: s.stripe ? 5 : 1,
        borderLeftColor: s.stripe ?? s.border,
        flexDirection: 'row',
        gap: 10,
        padding: 12,
        overflow: 'hidden',
      }}
    >
      {s.stripe ? (
        // A filled chip rather than a bare glyph: at 12.5px a hairline outline
        // icon disappears against a tinted wash in daylight.
        <View
          style={{
            width: 24,
            height: 24,
            borderRadius: 12,
            backgroundColor: s.stripe,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name={s.icon} size={14} color={colors.white} />
        </View>
      ) : (
        <Ionicons name={s.icon} size={16} color={s.text} style={{ marginTop: 1 }} />
      )}

      <View style={{ flex: 1 }}>
        {title ? (
          <Text style={{ fontSize: 13, fontWeight: '700', color: s.text, marginBottom: 2 }}>{title}</Text>
        ) : null}
        <Text style={{ fontSize: 12.5, lineHeight: 18, color: s.text }}>{children}</Text>
      </View>
    </View>
  );
}

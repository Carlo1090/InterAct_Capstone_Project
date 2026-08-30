import { Pressable, Text, ActivityIndicator, View, StyleProp, ViewStyle } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';

/**
 * The one button in the app.
 *
 * Before this existed every screen hand-rolled its own: border radii of 8, 10
 * and 12, four different paddings, and — the actual problem — "secondary"
 * actions drawn as a grey outline with grey text, which did not read as a
 * button at all. Save Draft sitting next to Submit looked like a label next to
 * a button.
 *
 * So secondary is now blue-outlined rather than grey: both actions are
 * visibly blue and visibly pressable, with fill vs outline carrying the
 * emphasis instead of colour-vs-no-colour.
 *
 * `danger` is deliberately kept for Log Out only. It is the one action here
 * that is not part of the normal forward flow, and colouring it blue like
 * everything else would be a step backwards in clarity.
 */
type Variant = 'primary' | 'secondary' | 'danger';
type Size = 'sm' | 'md';

const SIZE = {
  sm: { paddingVertical: 9, paddingHorizontal: 16, fontSize: 12.5, radius: 10 },
  md: { paddingVertical: 14, paddingHorizontal: 18, fontSize: 14, radius: 10 },
} as const;

export function Button({
  label,
  onPress,
  variant = 'primary',
  size = 'md',
  disabled = false,
  loading = false,
  icon,
  fullWidth = false,
  style,
}: {
  label: string;
  onPress: () => void;
  variant?: Variant;
  size?: Size;
  disabled?: boolean;
  loading?: boolean;
  icon?: keyof typeof Ionicons.glyphMap;
  fullWidth?: boolean;
  style?: StyleProp<ViewStyle>;
}) {
  const s = SIZE[size];
  const isDisabled = disabled || loading;

  // A disabled PRIMARY goes grey because a washed-out blue still reads as
  // "press me". Outline variants just dim — their border already carries the
  // affordance, and greying the border would make them vanish again.
  const background =
    variant === 'primary' ? (isDisabled ? colors.gray300 : colors.blue600) : colors.white;

  const borderColor =
    variant === 'primary' ? 'transparent' : variant === 'danger' ? colors.redDark : colors.blue600;

  const textColor =
    variant === 'primary' ? colors.white : variant === 'danger' ? colors.redDark : colors.blue600;

  return (
    <Pressable
      onPress={onPress}
      disabled={isDisabled}
      accessibilityRole="button"
      accessibilityState={{ disabled: isDisabled }}
      style={({ pressed }) => [
        {
          paddingVertical: s.paddingVertical,
          paddingHorizontal: s.paddingHorizontal,
          borderRadius: s.radius,
          backgroundColor: background,
          borderWidth: variant === 'primary' ? 0 : 1.5,
          borderColor,
          flexDirection: 'row',
          alignItems: 'center',
          justifyContent: 'center',
          gap: 8,
          alignSelf: fullWidth ? 'stretch' : 'auto',
          flex: fullWidth ? 1 : undefined,
          opacity: variant !== 'primary' && isDisabled ? 0.45 : pressed ? 0.85 : 1,
        },
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator size="small" color={textColor} />
      ) : (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 7 }}>
          {icon ? <Ionicons name={icon} size={s.fontSize + 3} color={textColor} /> : null}
          <Text style={{ fontSize: s.fontSize, fontWeight: '600', color: textColor }}>{label}</Text>
        </View>
      )}
    </Pressable>
  );
}

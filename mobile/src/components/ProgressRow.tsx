import { View, Text } from 'react-native';
import { colors } from '../constants/colors';

const variantColor = {
  blue: colors.blue500,
  dark: colors.blue800,
  light: colors.blue300,
};

export function ProgressRow({
  name,
  pct,
  variant,
}: {
  name: string;
  pct: number;
  variant: keyof typeof variantColor;
}) {
  return (
    <View style={{ marginBottom: 12 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 }}>
        <Text style={{ fontSize: 12, color: colors.gray600 }}>{name}</Text>
        <Text style={{ fontSize: 12, fontWeight: '600', color: colors.blue600 }}>{pct}%</Text>
      </View>
      <View style={{ height: 6, backgroundColor: colors.gray100, borderRadius: 999, overflow: 'hidden' }}>
        <View
          style={{
            height: '100%',
            width: `${pct}%`,
            borderRadius: 999,
            backgroundColor: variantColor[variant],
          }}
        />
      </View>
    </View>
  );
}

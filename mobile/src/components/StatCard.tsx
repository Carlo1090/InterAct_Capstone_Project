import { View, Text } from 'react-native';
import { colors } from '../constants/colors';

export function StatCard({
  label,
  value,
  sub,
  accent,
  danger,
}: {
  label: string;
  value: number | string;
  sub: string;
  accent?: boolean;
  danger?: boolean;
}) {
  return (
    <View
      style={{
        width: '47%',
        backgroundColor: accent ? colors.blue900 : colors.white,
        borderRadius: 12,
        padding: 14,
        borderWidth: 1,
        borderColor: accent ? colors.blue800 : colors.gray200,
      }}
    >
      <Text
        style={{
          fontSize: 10,
          fontWeight: '600',
          color: accent ? colors.blue300 : colors.gray400,
          letterSpacing: 0.6,
          textTransform: 'uppercase',
        }}
      >
        {label}
      </Text>
      <Text
        style={{
          fontSize: 26,
          fontWeight: '700',
          marginVertical: 4,
          color: accent ? colors.white : danger ? colors.redDark : colors.black,
        }}
      >
        {value}
      </Text>
      <Text style={{ fontSize: 11, color: accent ? colors.blue200 : colors.gray400 }}>{sub}</Text>
    </View>
  );
}

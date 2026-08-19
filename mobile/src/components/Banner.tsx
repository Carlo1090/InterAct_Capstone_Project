import { View, Text } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';

type Variant = 'warn' | 'info';

export function Banner({ variant, children }: { variant: Variant; children: React.ReactNode }) {
  const isWarn = variant === 'warn';
  const bg = isWarn ? colors.warnBg : colors.blue50;
  const border = isWarn ? colors.warnBorder : colors.blue200;
  const text = isWarn ? colors.warnTx : colors.blue700;

  return (
    <View
      style={{
        marginHorizontal: 20,
        marginTop: 16,
        padding: 12,
        borderRadius: 10,
        backgroundColor: bg,
        borderWidth: 1,
        borderColor: border,
        flexDirection: 'row',
        gap: 10,
      }}
    >
      <Ionicons
        name={isWarn ? 'warning-outline' : 'information-circle-outline'}
        size={16}
        color={text}
        style={{ marginTop: 1 }}
      />
      <Text style={{ flex: 1, fontSize: 12.5, lineHeight: 18, color: text }}>{children}</Text>
    </View>
  );
}

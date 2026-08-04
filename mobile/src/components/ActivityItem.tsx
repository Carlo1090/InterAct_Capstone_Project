import { View, Text } from 'react-native';
import { colors } from '../constants/colors';
import { ActivityDot } from '../services/mock/dashboard';

const dotColor: Record<ActivityDot, string> = {
  green: colors.green,
  blue: colors.blue500,
  orange: colors.orange,
};

export function ActivityItem({ dot, text, time }: { dot: ActivityDot; text: string; time: string }) {
  return (
    <View
      style={{
        flexDirection: 'row',
        gap: 12,
        paddingVertical: 10,
        borderBottomWidth: 1,
        borderBottomColor: colors.gray100,
      }}
    >
      <View
        style={{
          width: 8,
          height: 8,
          borderRadius: 4,
          backgroundColor: dotColor[dot],
          marginTop: 5,
        }}
      />
      <View style={{ flex: 1 }}>
        <Text style={{ fontSize: 12.5, color: colors.gray800, lineHeight: 17 }}>{text}</Text>
        <Text style={{ fontSize: 11, color: colors.gray400, marginTop: 2 }}>{time}</Text>
      </View>
    </View>
  );
}

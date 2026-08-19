import { View, Text } from 'react-native';
import { colors } from '../constants/colors';
import { ActivityTone } from '../types/api';

const dotColor: Record<ActivityTone, string> = {
  green: colors.green,
  blue: colors.blue500,
  amber: colors.orange,
  slate: colors.gray300,
};

export function ActivityItem({ tone, text, time }: { tone: ActivityTone; text: string; time: string | null }) {
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
          backgroundColor: dotColor[tone],
          marginTop: 5,
        }}
      />
      <View style={{ flex: 1 }}>
        <Text style={{ fontSize: 12.5, color: colors.gray800, lineHeight: 17 }}>{text}</Text>
        {time ? <Text style={{ fontSize: 11, color: colors.gray400, marginTop: 2 }}>{time}</Text> : null}
      </View>
    </View>
  );
}

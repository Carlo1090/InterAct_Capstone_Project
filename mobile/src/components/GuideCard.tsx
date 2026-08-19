import { View, Text } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';

export function GuideCard({
  icon,
  title,
  sub,
  items,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  title: string;
  sub: string;
  items: string[];
}) {
  return (
    <View
      style={{
        marginHorizontal: 20,
        marginTop: 12,
        backgroundColor: colors.white,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: colors.gray200,
        overflow: 'hidden',
      }}
    >
      <View style={{ flexDirection: 'row', gap: 12, padding: 16, paddingBottom: 10 }}>
        <View
          style={{
            width: 36,
            height: 36,
            borderRadius: 10,
            backgroundColor: colors.blue50,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name={icon} size={18} color={colors.blue600} />
        </View>
        <View style={{ flex: 1 }}>
          <Text style={{ fontSize: 13.5, fontWeight: '700', color: colors.black, marginBottom: 3 }}>{title}</Text>
          <Text style={{ fontSize: 12, color: colors.gray400 }}>{sub}</Text>
        </View>
      </View>
      <View style={{ borderTopWidth: 1, borderTopColor: colors.gray100 }} />
      <View style={{ paddingHorizontal: 16, paddingTop: 10, paddingBottom: 14, gap: 6 }}>
        {items.map((item, i) => (
          <Text key={i} style={{ fontSize: 12, color: colors.gray600, lineHeight: 18 }}>
            {'\u00B7 '}
            {item}
          </Text>
        ))}
      </View>
    </View>
  );
}

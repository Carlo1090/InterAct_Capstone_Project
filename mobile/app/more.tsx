import { View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../src/constants/colors';

const items: { icon: keyof typeof Ionicons.glyphMap; label: string; href: string }[] = [
  { icon: 'book-outline', label: 'Activity Log Guide', href: '/guide' },
  { icon: 'person-outline', label: 'Student Info Sheet', href: '/infosheet' },
  { icon: 'download-outline', label: 'Generate Report', href: '/reports' },
  { icon: 'person-circle-outline', label: 'Profile', href: '/profile' },
];

export default function More() {
  return (
    <View style={{ flex: 1, backgroundColor: 'rgba(5,13,26,0.4)', justifyContent: 'flex-end' }}>
      <Pressable style={{ flex: 1 }} onPress={() => router.back()} />
      <View style={{ backgroundColor: colors.white, borderTopLeftRadius: 20, borderTopRightRadius: 20, paddingBottom: 30 }}>
        <View style={{ alignItems: 'center', paddingTop: 10, paddingBottom: 4 }}>
          <View style={{ width: 36, height: 4, borderRadius: 2, backgroundColor: colors.gray200 }} />
        </View>
        {items.map((item) => (
          <Pressable
            key={item.href}
            onPress={() => {
              router.back();
              router.push(item.href as any);
            }}
            style={{
              flexDirection: 'row',
              alignItems: 'center',
              gap: 14,
              paddingVertical: 16,
              paddingHorizontal: 22,
              borderBottomWidth: 1,
              borderBottomColor: colors.gray100,
            }}
          >
            <Ionicons name={item.icon} size={20} color={colors.blue600} />
            <Text style={{ fontSize: 14, fontWeight: '500', color: colors.black }}>{item.label}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

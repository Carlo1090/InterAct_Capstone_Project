import { View, Text, Pressable, Image } from 'react-native';
import { router } from 'expo-router';
import { colors } from '../constants/colors';
import { useCurrentUser } from '../hooks/useCurrentUser';
import { NotificationBell } from './NotificationBell';

function initialsFor(name: string | undefined) {
  if (!name) return '?';
  const parts = name.trim().split(' ').filter(Boolean);
  if (parts.length === 0) return '?';
  return parts.slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}

export function TopBar() {
  const { user } = useCurrentUser();

  return (
    <View
      style={{
        backgroundColor: colors.blue900,
        paddingTop: 50,
        paddingBottom: 12,
        paddingHorizontal: 20,
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
      }}
    >
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
        <View
          style={{
            width: 34,
            height: 34,
            borderRadius: 8,
            backgroundColor: 'white',
            alignItems: 'center',
            justifyContent: 'center',
            overflow: 'hidden',
          }}
        >
          <Image
            source={require('../../assets/mater-dei-logo.png')}
            style={{ width: 28, height: 28 }}
            resizeMode="contain"
          />
        </View>
        <View>
          <Text style={{ color: 'white', fontSize: 16, fontWeight: '700' }}>InternTrack</Text>
          <Text style={{ color: colors.blue200, fontSize: 10 }}>Journal & Monitoring</Text>
        </View>
      </View>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 4 }}>
        <NotificationBell />
        <Pressable
          onPress={() => router.push('/profile')}
          style={{
            width: 34,
            height: 34,
            borderRadius: 17,
            backgroundColor: colors.blue600,
            borderWidth: 2,
            borderColor: colors.blue400,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Text style={{ color: 'white', fontSize: 13, fontWeight: '600' }}>{initialsFor(user?.name)}</Text>
        </Pressable>
      </View>
    </View>
  );
}

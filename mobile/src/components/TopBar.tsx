import { useEffect, useState } from 'react';
import { View, Text, Pressable, Image } from 'react-native';
import { router } from 'expo-router';
import { colors } from '../constants/colors';
import { getAccountKind } from '../services/accountKind';
import { getCurrentLocalProfile } from '../services/localAccounts';

function initialsFor(name: string) {
  const parts = name.trim().split(' ').filter(Boolean);
  if (parts.length === 0) return '?';
  return parts.slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}

/**
 * initials can still be passed explicitly if a screen already knows them,
 * but by default TopBar looks up the actual signed-in account so it never
 * shows the demo account's "JD" for someone else's account again.
 */
export function TopBar({ initials }: { initials?: string }) {
  const [resolved, setResolved] = useState(initials ?? '');

  useEffect(() => {
    if (initials) return; // explicit override wins, skip lookup
    (async () => {
      const kind = await getAccountKind();
      if (kind === 'new') {
        const profile = await getCurrentLocalProfile();
        setResolved(profile ? initialsFor(profile.name) : '?');
      } else if (kind === 'demo') {
        setResolved('JD');
      } else {
        setResolved('ST');
      }
    })();
  }, [initials]);

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
      <Pressable
        onPress={() => router.push('/write')}
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
        <Text style={{ color: 'white', fontSize: 13, fontWeight: '600' }}>{resolved}</Text>
      </Pressable>
    </View>
  );
}

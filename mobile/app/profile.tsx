import { useEffect, useState } from 'react';
import { ScrollView, View, Text, Pressable, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { InfoSectionTitle, ProfileRow } from '../src/components/InfoField';
import { mockProfile } from '../src/services/mock/studentInfo';
import { emptyProfile } from '../src/services/mock/empty';
import { getAccountKind } from '../src/services/accountKind';
import { getCurrentLocalProfile } from '../src/services/localAccounts';
import { useAuth } from '../src/hooks/useAuth';
import { colors } from '../src/constants/colors';

function initialsFor(name: string) {
  const parts = name.trim().split(' ').filter(Boolean);
  if (parts.length === 0) return '?';
  return parts.slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}

export default function Profile() {
  const { logout } = useAuth();
  const [p, setP] = useState<typeof mockProfile | null>(null);

  useEffect(() => {
    (async () => {
      const kind = await getAccountKind();
      if (kind === 'new') {
        const profile = await getCurrentLocalProfile();
        if (profile) {
          setP({
            ...emptyProfile,
            name: profile.name,
            initials: initialsFor(profile.name),
            email: profile.email,
            studentId: profile.studentId || 'Not yet set',
          });
        } else {
          setP(emptyProfile);
        }
        return;
      }
      setP(mockProfile);
    })();
  }, []);

  async function onLogout() {
    await logout();
    router.replace('/login');
  }

  if (!p) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.blue600} />
      </View>
    );
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 24 }}>
      <View
        style={{
          backgroundColor: colors.blue900,
          paddingTop: 80,
          paddingBottom: 24,
          paddingHorizontal: 20,
          alignItems: 'center',
          gap: 10,
        }}
      >
        <View
          style={{
            width: 72,
            height: 72,
            borderRadius: 36,
            backgroundColor: colors.blue600,
            borderWidth: 3,
            borderColor: colors.blue400,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Text style={{ color: 'white', fontSize: 26, fontWeight: '700' }}>{p.initials}</Text>
        </View>
        <Text style={{ color: 'white', fontSize: 18, fontWeight: '700' }}>{p.name || 'New Student'}</Text>
        <Text style={{ color: colors.blue200, fontSize: 12 }}>{p.roleLine}</Text>
        <View style={{ flexDirection: 'row', gap: 8, marginTop: 4 }}>
          {p.badges.map((b) => (
            <View key={b} style={{ backgroundColor: colors.blue700, paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20 }}>
              <Text style={{ color: colors.blue100, fontSize: 11, fontWeight: '500' }}>{b}</Text>
            </View>
          ))}
        </View>
      </View>

      <InfoSectionTitle>Account</InfoSectionTitle>
      <ProfileRow label="Email" value={p.email || 'Not yet set'} />
      <ProfileRow label="Student ID" value={p.studentId} />
      <ProfileRow label="Contact" value={p.contact} />

      <InfoSectionTitle>Internship</InfoSectionTitle>
      <ProfileRow label="Company" value={p.company} />
      <ProfileRow label="Supervisor" value={p.supervisor} />
      <ProfileRow label="Coordinator" value={p.coordinator} />
      <ProfileRow label="OJT Period" value={p.ojtPeriod} />

      <InfoSectionTitle>Preferences</InfoSectionTitle>
      <ProfileRow label="Notifications" value={p.notifications} highlight />
      <ProfileRow label="Reminder Time" value={p.reminderTime} />

      <View style={{ paddingHorizontal: 20, paddingTop: 20, paddingBottom: 8 }}>
        <Pressable
          onPress={onLogout}
          style={{
            width: '100%',
            padding: 13,
            borderRadius: 12,
            borderWidth: 1.5,
            borderColor: colors.gray200,
            backgroundColor: colors.white,
            alignItems: 'center',
          }}
        >
          <Text style={{ fontSize: 13, fontWeight: '600', color: colors.redDark }}>Log Out</Text>
        </Pressable>
      </View>
    </ScrollView>
  );
}

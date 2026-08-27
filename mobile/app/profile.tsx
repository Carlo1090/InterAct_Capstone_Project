import { ScrollView, View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Button } from '../src/components/Button';
import { InfoSectionTitle, ProfileRow } from '../src/components/InfoField';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { useCurrentUser } from '../src/hooks/useCurrentUser';
import { useDashboard } from '../src/hooks/useDashboard';
import { useAuth } from '../src/hooks/useAuth';
import { colors } from '../src/constants/colors';

function initialsFor(name: string | undefined) {
  if (!name) return '?';
  const parts = name.trim().split(' ').filter(Boolean);
  if (parts.length === 0) return '?';
  return parts.slice(0, 2).map((p) => p[0]?.toUpperCase()).join('');
}

export default function Profile() {
  const { logout } = useAuth();
  const { user, loading: userLoading, error: userError, refetch } = useCurrentUser();
  // Company/supervisor/coordinator aren't returned by /api/user — the
  // dashboard endpoint is the only place they exist server-side today, and
  // Profile is only reachable in the normal (non-gated/paused) state, where
  // an active enrollment — and therefore a dashboard response — is
  // guaranteed to exist.
  const { data: dashboard, loading: dashboardLoading, error: dashboardError, reload } = useDashboard();

  async function onLogout() {
    await logout();
    router.replace('/login');
  }

  if ((userLoading && !user) || (dashboardLoading && !dashboard)) return <LoadingState />;
  if (userError && !user) return <ErrorState message={userError.message} onRetry={refetch} />;

  if (!user) return null;

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
          <Text style={{ color: 'white', fontSize: 26, fontWeight: '700' }}>{initialsFor(user.name)}</Text>
        </View>
        <Text style={{ color: 'white', fontSize: 18, fontWeight: '700' }}>{user.name}</Text>
        <Text style={{ color: colors.blue200, fontSize: 12 }}>
          Student{user.program?.department?.name ? ` · ${user.program.department.name}` : ''}
        </Text>
        {user.program?.name ? (
          <View style={{ backgroundColor: colors.blue700, paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20 }}>
            <Text style={{ color: colors.blue100, fontSize: 11, fontWeight: '500' }}>{user.program.name}</Text>
          </View>
        ) : null}
      </View>

      <InfoSectionTitle>Account</InfoSectionTitle>
      <ProfileRow label="Username" value={user.username} />
      <ProfileRow label="Email" value={user.email ?? 'Not yet set'} />
      <ProfileRow label="Student ID" value={user.student_id_number ?? 'Not yet set'} />

      <InfoSectionTitle>Internship</InfoSectionTitle>
      {dashboardError && !dashboard ? (
        <View style={{ paddingHorizontal: 20, paddingVertical: 14 }}>
          <Text style={{ fontSize: 12, color: colors.gray400 }}>{dashboardError.message}</Text>
          <Pressable onPress={reload} style={{ marginTop: 8 }}>
            <Text style={{ fontSize: 12, color: colors.blue600, fontWeight: '600' }}>Retry</Text>
          </Pressable>
        </View>
      ) : (
        <>
          <ProfileRow label="Company" value={dashboard?.internship.host_company ?? 'Not yet assigned'} />
          <ProfileRow label="Supervisor" value={dashboard?.internship.supervisor ?? 'Not yet assigned'} />
          <ProfileRow label="Coordinator" value={dashboard?.internship.coordinator ?? 'Not yet assigned'} />
          <ProfileRow label="Department" value={dashboard?.internship.department ?? 'Not yet assigned'} />
          <ProfileRow label="Program" value={dashboard?.internship.program ?? 'Not yet assigned'} />
          <ProfileRow label="Start Date" value={dashboard?.internship.start_date ?? '—'} />
        </>
      )}
      {/* Moved here from the header. The Info Sheet is the record of this
          placement, so it belongs with the placement's own details rather
          than as a permanent icon next to the notification bell. */}
      <MenuRow icon="clipboard-outline" label="Student Info Sheet" onPress={() => router.push('/infosheet')} />

      <InfoSectionTitle>Settings</InfoSectionTitle>
      <MenuRow icon="notifications-outline" label="Reminder Settings" onPress={() => router.push('/reminder-settings')} />
      <MenuRow icon="key-outline" label="Change Password" onPress={() => router.push('/change-password')} />
      <MenuRow icon="time-outline" label="Activity Log" onPress={() => router.push('/activity-log')} />
      <MenuRow icon="book-outline" label="Guide & Submission Rules" onPress={() => router.push('/guide')} />

      <View style={{ paddingHorizontal: 20, paddingTop: 20, paddingBottom: 8 }}>
        <Button label="Log Out" variant="danger" icon="log-out-outline" fullWidth onPress={onLogout} />
      </View>
    </ScrollView>
  );
}

function MenuRow({
  icon,
  label,
  onPress,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable
      onPress={onPress}
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: 12,
        paddingVertical: 14,
        paddingHorizontal: 20,
        borderBottomWidth: 1,
        borderBottomColor: colors.gray100,
      }}
    >
      <Ionicons name={icon} size={18} color={colors.blue600} />
      <Text style={{ flex: 1, fontSize: 13.5, color: colors.black, fontWeight: '500' }}>{label}</Text>
      <Ionicons name="chevron-forward" size={16} color={colors.gray400} />
    </Pressable>
  );
}

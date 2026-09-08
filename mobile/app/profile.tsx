import { useState } from 'react';
import { ScrollView, View, Text, Pressable, Image, ActivityIndicator, Alert } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import * as ImagePicker from 'expo-image-picker';
import { Button } from '../src/components/Button';
import { OfflineNotice } from '../src/components/OfflineNotice';
import { uploadAvatar, ApiError } from '../src/services/api';
import { setUser } from '../src/services/userStore';
import { showError, showSuccess } from '../src/services/toast';
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
  const { user, loading: userLoading, error: userError, isOffline, refetch } = useCurrentUser();
  const [uploadingPhoto, setUploadingPhoto] = useState(false);

  async function onChangePhoto() {
    const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      Alert.alert(
        'Photo access needed',
        'InternTrack needs access to your photos so you can choose a profile picture.'
      );
      return;
    }

    const picked = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ['images'],
      // The server center-crops to a square anyway (AvatarProcessingService),
      // so cropping here just lets the student choose WHICH square.
      allowsEditing: true,
      aspect: [1, 1],
      quality: 0.8,
    });

    if (picked.canceled || !picked.assets?.[0]) return;

    const asset = picked.assets[0];
    setUploadingPhoto(true);
    try {
      // The endpoint returns the refreshed user, so push it straight into the
      // shared store. Every screen holding a user — the header avatar above
      // all — re-renders at once, which is what was broken before.
      const updated = await uploadAvatar(asset.uri, asset.mimeType);
      setUser(updated);
      showSuccess('Profile photo updated');
    } catch (err) {
      const apiErr = err as ApiError;
      showError(
        'Could not update your photo',
        apiErr.status === null
          ? 'You appear to be offline. Try again once you have a connection.'
          : apiErr.message
      );
    } finally {
      setUploadingPhoto(false);
    }
  }
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
        {/* Profile is pushed from the header avatar and had no way back —
            the only exits were Log Out or the hardware button. Absolutely
            positioned so the avatar stays centred in the banner. */}
        <Pressable
          onPress={() => router.back()}
          accessibilityLabel="Go back"
          hitSlop={10}
          style={{
            position: 'absolute',
            top: 44,
            left: 16,
            width: 34,
            height: 34,
            borderRadius: 8,
            borderWidth: 1.5,
            borderColor: colors.blue700,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="chevron-back" size={18} color="white" />
        </Pressable>

        <Pressable
          onPress={onChangePhoto}
          disabled={uploadingPhoto}
          accessibilityLabel="Change profile photo"
          style={{
            width: 72,
            height: 72,
            borderRadius: 36,
            backgroundColor: colors.blue600,
            borderWidth: 3,
            borderColor: colors.blue400,
            alignItems: 'center',
            justifyContent: 'center',
            overflow: 'hidden',
          }}
        >
          {uploadingPhoto ? (
            <ActivityIndicator color="white" />
          ) : user.avatar_url ? (
            <Image source={{ uri: user.avatar_url }} style={{ width: '100%', height: '100%' }} />
          ) : (
            <Text style={{ color: 'white', fontSize: 26, fontWeight: '700' }}>{initialsFor(user.name)}</Text>
          )}
        </Pressable>
        <Pressable onPress={onChangePhoto} disabled={uploadingPhoto} hitSlop={6}>
          <Text style={{ color: colors.blue200, fontSize: 11.5, fontWeight: '600' }}>
            {uploadingPhoto ? 'Uploading…' : 'Change photo'}
          </Text>
        </Pressable>
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

      <OfflineNotice feature="profile" show={isOffline} />

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
      <MenuRow
        icon="time-outline"
        label="Weekly and Time Log Summary"
        onPress={() => router.push('/weekly-activity')}
      />
      <MenuRow icon="exit-outline" label="Exit Interview" onPress={() => router.push('/exit-interview')} />

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

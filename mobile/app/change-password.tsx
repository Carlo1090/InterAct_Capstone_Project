import { useState } from 'react';
import { View, Text, TextInput, Pressable, ScrollView } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Button } from '../src/components/Button';
import { colors } from '../src/constants/colors';
import { apiPut, ApiError } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { useCurrentUser } from '../src/hooks/useCurrentUser';
import { ErrorNotice } from '../src/components/ErrorNotice';

/**
 * Reachable normally from Profile, and force-opened (no back button) when
 * must_change_password is true — mirrors the web SPA's blocking
 * ProfileMenuPopover behavior for a temporary password issued by an admin.
 */
/**
 * The rule and the hint below it read from ONE constant — the two had to be
 * edited together before, which is exactly how a form ends up promising one
 * length and enforcing another.
 *
 * NOTE this is STRICTER than the server, which uses Laravel's
 * `Password::defaults()` (8). A stricter client is safe — every value it
 * accepts the server accepts too — but the web SPA's own reset form still
 * allows 8, so the two surfaces do not ask for the same thing.
 */
const MIN_PASSWORD_LENGTH = 15;

export default function ChangePassword() {
  const { mustChangePassword, refetch } = useCurrentUser();
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const canSubmit = currentPassword.length > 0 && password.length >= MIN_PASSWORD_LENGTH && password === confirmPassword;

  async function onSave() {
    setError(null);
    setSaving(true);
    try {
      await apiPut(endpoints.profilePassword, {
        current_password: currentPassword,
        password,
        password_confirmation: confirmPassword,
      });
      setSuccess(true);
      await refetch();
      if (!mustChangePassword) {
        router.back();
      }
    } catch (err) {
      const apiErr = err as ApiError;
      const fieldMessage =
        apiErr.fieldErrors?.password?.[0] ?? apiErr.fieldErrors?.current_password?.[0];
      setError(fieldMessage ?? apiErr.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 40 }}>
      <View style={{ paddingTop: 50, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        {mustChangePassword ? null : (
          <Pressable onPress={() => router.back()}>
            <Ionicons name="chevron-back" size={22} color={colors.black} />
          </Pressable>
        )}
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Change Password</Text>
      </View>

      {mustChangePassword ? (
        <Banner variant="warn">
          A temporary password was issued for your account. You must set a new password before continuing.
        </Banner>
      ) : null}

      {success && !mustChangePassword ? <Banner variant="info">Password updated.</Banner> : null}

      {error ? <ErrorNotice message={error} /> : null}

      <View style={{ marginHorizontal: 20, marginTop: 16, gap: 14 }}>
        <Field label="Current Password" value={currentPassword} onChangeText={setCurrentPassword} />
        <Field label="New Password" value={password} onChangeText={setPassword} />
        <Field label="Confirm New Password" value={confirmPassword} onChangeText={setConfirmPassword} />
        <Text style={{ fontSize: 11, color: colors.gray400 }}>At least {MIN_PASSWORD_LENGTH} characters for better security.</Text>
      </View>

      <Button label="Save Password" icon="key-outline" loading={saving} disabled={saving || !canSubmit} onPress={onSave} style={{ marginHorizontal: 20, marginTop: 20 }} />
    </ScrollView>
  );
}

function Field({
  label,
  value,
  onChangeText,
}: {
  label: string;
  value: string;
  onChangeText: (v: string) => void;
}) {
  return (
    <View>
      <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray600, marginBottom: 6 }}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChangeText}
        secureTextEntry
        autoCapitalize="none"
        autoCorrect={false}
        style={{
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 10,
          paddingHorizontal: 12,
          paddingVertical: 10,
          fontSize: 13.5,
          backgroundColor: colors.white,
        }}
      />
    </View>
  );
}

import { useState } from 'react';
import {
  View,
  Text,
  TextInput,
  Pressable,
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  Image,
  useWindowDimensions,
} from 'react-native';
import { Redirect, router } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../src/hooks/useAuth';
import { colors } from '../src/constants/colors';
import { apiGet } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { CurrentUser } from '../src/types/api';

export default function Login() {
  const { isAuthenticated, login } = useAuth();
  const { width } = useWindowDimensions();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Already signed in (e.g. app relaunched with a stored token) — skip login.
  if (isAuthenticated) return <Redirect href="/(tabs)" />;

  async function onSubmit() {
    setError(null);
    setSubmitting(true);
    const result = await login(identifier.trim(), password);
    if (result.ok) {
      // The login response's `user` doesn't carry student_gated/
      // student_paused — those are computed only by GET /api/user (the
      // single source of truth the tab layout's own guard also reads from).
      // Fetch it once here so we route correctly on the first navigation
      // instead of flashing the dashboard before the tab layout redirects.
      let gated = false;
      let paused = false;
      try {
        const me = await apiGet<CurrentUser>(endpoints.me);
        gated = me.student_gated ?? false;
        paused = me.student_paused ?? false;
      } catch {
        // Fall through to /(tabs) — its own guard will re-check and redirect.
      }
      setSubmitting(false);
      if (gated) {
        router.replace('/infosheet');
      } else if (paused) {
        router.replace('/paused');
      } else {
        router.replace('/(tabs)');
      }
    } else {
      setSubmitting(false);
      setError(result.error);
    }
  }

  // Cap the card's width on tablets/large screens so the form doesn't
  // stretch edge-to-edge — same pattern the reference SIS login uses.
  const cardMaxWidth = Math.min(width - 48, 420);

  return (
    <LinearGradient colors={[colors.blue300, colors.blue100]} style={{ flex: 1 }}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
          <View style={{ width: '100%', maxWidth: cardMaxWidth, backgroundColor: colors.white, borderRadius: 18, overflow: 'hidden' }}>
            <LinearGradient
              colors={[colors.blue700, colors.blue500]}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={{ paddingVertical: 36, alignItems: 'center' }}
            >
              <View
                style={{
                  width: 84,
                  height: 84,
                  borderRadius: 42,
                  backgroundColor: 'white',
                  borderWidth: 2,
                  borderColor: 'rgba(255,255,255,0.6)',
                  alignItems: 'center',
                  justifyContent: 'center',
                  marginBottom: 12,
                  overflow: 'hidden',
                }}
              >
                <Image
                  source={require('../assets/mater-dei-logo.png')}
                  style={{ width: 72, height: 72 }}
                  resizeMode="contain"
                />
              </View>
              <Text style={{ color: 'white', fontSize: 20, fontWeight: '700' }}>InternTrack</Text>
              <Text style={{ color: colors.blue100, fontSize: 12, marginTop: 2 }}>Journal & Monitoring</Text>
            </LinearGradient>

            <View style={{ padding: 24 }}>
              <Text style={{ fontSize: 19, fontWeight: '700', color: colors.black, textAlign: 'center', marginBottom: 20 }}>
                Welcome Back!
              </Text>

              {error ? (
                <View
                  style={{
                    backgroundColor: colors.redBg,
                    borderWidth: 1,
                    borderColor: '#fecaca',
                    borderRadius: 10,
                    padding: 12,
                    marginBottom: 16,
                  }}
                >
                  <Text style={{ color: colors.redTx, fontSize: 12.5, textAlign: 'center' }}>{error}</Text>
                </View>
              ) : null}

              <View style={{ flexDirection: 'row', alignItems: 'center', borderWidth: 1.5, borderColor: colors.gray200, borderRadius: 10, marginBottom: 14, overflow: 'hidden' }}>
                <View style={{ backgroundColor: colors.blue500, padding: 12 }}>
                  <Ionicons name="person-outline" size={16} color="white" />
                </View>
                <TextInput
                  value={identifier}
                  onChangeText={setIdentifier}
                  placeholder="Username or email"
                  placeholderTextColor={colors.gray400}
                  autoCapitalize="none"
                  autoComplete="username"
                  textContentType="username"
                  style={{ flex: 1, paddingHorizontal: 14, paddingVertical: 12, fontSize: 13.5 }}
                />
              </View>

              <View style={{ flexDirection: 'row', alignItems: 'center', borderWidth: 1.5, borderColor: colors.gray200, borderRadius: 10, marginBottom: 20, overflow: 'hidden' }}>
                <View style={{ backgroundColor: colors.red, padding: 12 }}>
                  <Ionicons name="lock-closed-outline" size={16} color="white" />
                </View>
                <TextInput
                  value={password}
                  onChangeText={setPassword}
                  placeholder="Enter your password"
                  placeholderTextColor={colors.gray400}
                  secureTextEntry
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="password"
                  textContentType="password"
                  style={{ flex: 1, paddingHorizontal: 14, paddingVertical: 12, fontSize: 13.5 }}
                />
              </View>

              <Pressable onPress={onSubmit} disabled={submitting || !identifier || !password}>
                <LinearGradient
                  colors={[colors.blue600, colors.blue500]}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 0 }}
                  style={{
                    borderRadius: 12,
                    paddingVertical: 14,
                    alignItems: 'center',
                    flexDirection: 'row',
                    justifyContent: 'center',
                    gap: 8,
                    opacity: submitting || !identifier || !password ? 0.6 : 1,
                  }}
                >
                  {submitting ? (
                    <ActivityIndicator color="white" />
                  ) : (
                    <>
                      <Ionicons name="log-in-outline" size={16} color="white" />
                      <Text style={{ color: 'white', fontWeight: '600', fontSize: 14 }}>Login</Text>
                    </>
                  )}
                </LinearGradient>
              </Pressable>

              <View
                style={{
                  marginTop: 18,
                  backgroundColor: colors.blue50,
                  borderRadius: 10,
                  padding: 12,
                  borderWidth: 1,
                  borderColor: colors.blue100,
                }}
              >
                <Text style={{ fontSize: 11.5, color: colors.blue700, lineHeight: 17, textAlign: 'center' }}>
                  Accounts are created by your coordinator. If you do not know your account credentials, or if you
                  have forgotten your password, please contact the Systems Development & Administration Office.
                </Text>
              </View>
            </View>
          </View>

          <Text style={{ color: colors.blue700, fontSize: 11, textAlign: 'center', marginTop: 20 }}>
            Mater Dei College · InternTrack App v1.0.0
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </LinearGradient>
  );
}

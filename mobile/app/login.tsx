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
import { Button } from '../src/components/Button';
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
  const [showPassword, setShowPassword] = useState(false);

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
    // THE GROUND IS DARK NOW. It used to be a pale blue300 -> blue100 wash,
    // which left a white card floating on an almost-white page: the card had
    // no edge and the screen had no anchor. On the deep ground the card reads
    // as an object, and the one dark surface in the app is the first thing
    // seen. Three stops rather than two so the light does not sit flatly in
    // the corner.
    <LinearGradient
      colors={['#2a3f60', colors.blue900, '#16213a']}
      start={{ x: 1, y: 0 }}
      end={{ x: 0, y: 1 }}
      style={{ flex: 1 }}
    >
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
          <View
            style={{
              width: '100%',
              maxWidth: cardMaxWidth,
              backgroundColor: colors.white,
              borderRadius: 20,
              overflow: 'hidden',
              // Lifts the card off the dark ground. Both platforms are set
              // because iOS ignores `elevation` and Android ignores the
              // shadow* props — setting only one leaves the card flat on half
              // the devices that run this.
              elevation: 10,
              shadowColor: '#000',
              shadowOpacity: 0.3,
              shadowRadius: 22,
              shadowOffset: { width: 0, height: 12 },
            }}
          >
            <LinearGradient
              colors={[colors.blue700, colors.blue500]}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={{ paddingVertical: 36, alignItems: 'center', overflow: 'hidden' }}
            >
              {/* Two faint rings echoing the logo's circle. Hairline borders at
                  low opacity rather than filled shapes, so they read as an
                  embossed watermark and never compete with the mark itself.
                  pointerEvents none: they sit over the header, not in the way. */}
              <View
                pointerEvents="none"
                style={{
                  position: 'absolute',
                  width: 210,
                  height: 210,
                  borderRadius: 105,
                  borderWidth: 1,
                  borderColor: 'rgba(255,255,255,0.13)',
                  top: -74,
                  right: -52,
                }}
              />
              <View
                pointerEvents="none"
                style={{
                  position: 'absolute',
                  width: 140,
                  height: 140,
                  borderRadius: 70,
                  borderWidth: 1,
                  borderColor: 'rgba(255,255,255,0.10)',
                  bottom: -58,
                  left: -34,
                }}
              />
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

              {/* THE ICON IS A NEUTRAL CHIP INSIDE A FILLED FIELD, not a
                  coloured slab bolted to its left edge. The old treatment put a
                  saturated blue block on Username and a RED one on Password —
                  red is the app's error colour everywhere else, so a resting
                  password field looked like a field in an error state. */}
              <View style={{ flexDirection: 'row', alignItems: 'center', backgroundColor: colors.gray50, borderWidth: 1.5, borderColor: colors.gray200, borderRadius: 11, marginBottom: 12, paddingLeft: 8, overflow: 'hidden' }}>
                <View style={{ width: 30, height: 30, borderRadius: 8, backgroundColor: colors.blue50, alignItems: 'center', justifyContent: 'center' }}>
                  <Ionicons name="person-outline" size={15} color={colors.blue600} />
                </View>
                <TextInput
                  value={identifier}
                  onChangeText={setIdentifier}
                  placeholder="Username or email"
                  placeholderTextColor={colors.gray400}
                  autoCapitalize="none"
                  autoComplete="username"
                  textContentType="username"
                  style={{ flex: 1, paddingHorizontal: 12, paddingVertical: 13, fontSize: 13.5, color: colors.black }}
                />
              </View>

              <View style={{ flexDirection: 'row', alignItems: 'center', backgroundColor: colors.gray50, borderWidth: 1.5, borderColor: colors.gray200, borderRadius: 11, marginBottom: 20, paddingLeft: 8, overflow: 'hidden' }}>
                <View style={{ width: 30, height: 30, borderRadius: 8, backgroundColor: colors.blue50, alignItems: 'center', justifyContent: 'center' }}>
                  <Ionicons name="lock-closed-outline" size={15} color={colors.blue600} />
                </View>
                <TextInput
                  // Android has a long-standing bug where toggling `secureTextEntry`
                  // on a live TextInput doesn't reliably re-apply the native masking
                  // (the field can get stuck showing plain text). Remounting via
                  // `key` on toggle forces Android to recreate the input with the
                  // correct mode instead of trying to mutate it in place.
                  key={showPassword ? 'visible' : 'masked'}
                  value={password}
                  onChangeText={setPassword}
                  placeholder="Enter your password"
                  placeholderTextColor={colors.gray400}
                  secureTextEntry={!showPassword}
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="password"
                  textContentType="password"
                  style={{ flex: 1, paddingHorizontal: 12, paddingVertical: 13, fontSize: 13.5, color: colors.black }}
                />
                <Pressable
                  onPress={() => setShowPassword((prev) => !prev)}
                  hitSlop={8}
                  accessibilityLabel={showPassword ? 'Hide password' : 'Show password'}
                  style={{ paddingHorizontal: 12 }}
                >
                  <Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={18} color={colors.gray400} />
                </Pressable>
              </View>

              <Button
                label="Login"
                icon="log-in-outline"
                loading={submitting}
                disabled={submitting || !identifier || !password}
                onPress={onSubmit}
              />

            </View>
          </View>

          {/* blue700 was invisible against the new dark ground — the accent
              is the token meant for light-on-dark, at 7.08:1. */}
          <Text style={{ color: colors.blue300, fontSize: 11, textAlign: 'center', marginTop: 24 }}>
            Mater Dei College
          </Text>
        </ScrollView>
      </KeyboardAvoidingView>
    </LinearGradient>
  );
}

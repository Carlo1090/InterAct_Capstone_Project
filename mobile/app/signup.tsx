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
import { router } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../src/hooks/useAuth';
import { colors } from '../src/constants/colors';

export default function SignUp() {
  const { register } = useAuth();
  const { width } = useWindowDimensions();
  const [name, setName] = useState('');
  const [studentId, setStudentId] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canSubmit = name.trim() && studentId.trim() && email.trim() && password.length >= 6 && !submitting;
  const cardMaxWidth = Math.min(width - 48, 420);

  async function onSubmit() {
    setError(null);

    if (password.length < 6) {
      setError('Password must be at least 6 characters.');
      return;
    }
    if (password !== confirmPassword) {
      setError('Passwords do not match.');
      return;
    }

    setSubmitting(true);
    const result = await register(name.trim(), studentId.trim(), email.trim(), password);
    setSubmitting(false);

    if (result.ok) {
      router.replace('/(tabs)');
    } else {
      setError(result.error ?? 'Something went wrong.');
    }
  }

  return (
    <LinearGradient colors={[colors.blue300, colors.blue100]} style={{ flex: 1 }}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1 }}>
        <ScrollView contentContainerStyle={{ flexGrow: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
          <View style={{ width: '100%', maxWidth: cardMaxWidth, backgroundColor: colors.white, borderRadius: 18, overflow: 'hidden' }}>
            <LinearGradient
              colors={[colors.blue700, colors.blue500]}
              start={{ x: 0, y: 0 }}
              end={{ x: 1, y: 1 }}
              style={{ paddingVertical: 30, alignItems: 'center' }}
            >
              <View
                style={{
                  width: 72,
                  height: 72,
                  borderRadius: 36,
                  backgroundColor: 'white',
                  borderWidth: 2,
                  borderColor: 'rgba(255,255,255,0.6)',
                  alignItems: 'center',
                  justifyContent: 'center',
                  marginBottom: 10,
                  overflow: 'hidden',
                }}
              >
                <Image
                  source={require('../assets/mater-dei-logo.png')}
                  style={{ width: 62, height: 62 }}
                  resizeMode="contain"
                />
              </View>
              <Text style={{ color: 'white', fontSize: 18, fontWeight: '700' }}>Create Your Account</Text>
              <Text style={{ color: colors.blue100, fontSize: 11.5, marginTop: 2, textAlign: 'center' }}>
                For new OJT students joining InternTrack
              </Text>
            </LinearGradient>

            <View style={{ padding: 24 }}>
              <Field label="Full Name" icon="person-outline">
                <TextInput
                  value={name}
                  onChangeText={setName}
                  placeholder="Juan Dela Cruz"
                  autoCapitalize="words"
                  autoComplete="off"
                  textContentType="none"
                  importantForAutofill="no"
                  style={fieldInputStyle}
                />
              </Field>

              <Field label="Student ID" icon="card-outline">
                <TextInput
                  value={studentId}
                  onChangeText={setStudentId}
                  placeholder="2025-IT-001"
                  autoCapitalize="characters"
                  autoComplete="off"
                  textContentType="none"
                  importantForAutofill="no"
                  style={fieldInputStyle}
                />
              </Field>

              <Field label="Student Email" icon="mail-outline">
                <TextInput
                  value={email}
                  onChangeText={setEmail}
                  placeholder="juan.delacruz@student.mdc.edu.ph"
                  autoCapitalize="none"
                  keyboardType="email-address"
                  autoComplete="off"
                  textContentType="none"
                  importantForAutofill="no"
                  style={fieldInputStyle}
                />
              </Field>

              <Field label="Password" icon="lock-closed-outline">
                <TextInput
                  value={password}
                  onChangeText={setPassword}
                  placeholder="At least 6 characters"
                  secureTextEntry
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="off"
                  textContentType="none"
                  importantForAutofill="no"
                  style={fieldInputStyle}
                />
              </Field>

              <Field label="Confirm Password" icon="lock-closed-outline" last>
                <TextInput
                  value={confirmPassword}
                  onChangeText={setConfirmPassword}
                  placeholder="Re-enter your password"
                  secureTextEntry
                  autoCapitalize="none"
                  autoCorrect={false}
                  autoComplete="off"
                  textContentType="none"
                  importantForAutofill="no"
                  style={fieldInputStyle}
                />
              </Field>

              {error ? (
                <View
                  style={{
                    backgroundColor: colors.redBg,
                    borderWidth: 1,
                    borderColor: '#fecaca',
                    borderRadius: 10,
                    padding: 12,
                    marginBottom: 14,
                  }}
                >
                  <Text style={{ color: colors.redTx, fontSize: 12.5, textAlign: 'center' }}>{error}</Text>
                </View>
              ) : null}

              <Pressable onPress={onSubmit} disabled={!canSubmit}>
                <LinearGradient
                  colors={[colors.blue600, colors.blue500]}
                  start={{ x: 0, y: 0 }}
                  end={{ x: 1, y: 0 }}
                  style={{
                    borderRadius: 12,
                    paddingVertical: 14,
                    alignItems: 'center',
                    opacity: canSubmit ? 1 : 0.6,
                  }}
                >
                  {submitting ? (
                    <ActivityIndicator color="white" />
                  ) : (
                    <Text style={{ color: 'white', fontWeight: '600', fontSize: 14 }}>Create Account</Text>
                  )}
                </LinearGradient>
              </Pressable>

              <Pressable onPress={() => router.replace('/login')} style={{ marginTop: 14, alignItems: 'center' }}>
                <Text style={{ fontSize: 12.5, color: colors.gray600 }}>
                  Already have an account? <Text style={{ color: colors.blue600, fontWeight: '600' }}>Sign In</Text>
                </Text>
              </Pressable>
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

function Field({
  label,
  icon,
  children,
  last,
}: {
  label: string;
  icon: keyof typeof Ionicons.glyphMap;
  children: React.ReactNode;
  last?: boolean;
}) {
  return (
    <View style={{ marginBottom: last ? 16 : 12 }}>
      <Text style={{ fontSize: 11, fontWeight: '600', color: colors.gray600, marginBottom: 6 }}>{label}</Text>
      <View style={{ flexDirection: 'row', alignItems: 'center', borderWidth: 1.5, borderColor: colors.gray200, borderRadius: 10, overflow: 'hidden' }}>
        <View style={{ backgroundColor: colors.blue500, padding: 11 }}>
          <Ionicons name={icon} size={15} color="white" />
        </View>
        {children}
      </View>
    </View>
  );
}

const fieldInputStyle = {
  flex: 1,
  paddingHorizontal: 12,
  paddingVertical: 11,
  fontSize: 13,
};

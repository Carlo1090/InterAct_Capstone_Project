import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { useAutoSyncOutbox } from '../src/hooks/useAutoSyncOutbox';

export default function RootLayout() {
  // Mounted once for the whole app lifetime — flushes any offline-queued
  // journal entries as soon as connectivity returns, regardless of which
  // screen is currently active.
  useAutoSyncOutbox();

  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      <Stack screenOptions={{ headerShown: false }}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="login" />
        <Stack.Screen name="write" options={{ presentation: 'modal' }} />
        <Stack.Screen name="guide" />
        <Stack.Screen name="infosheet" />
        <Stack.Screen name="profile" />
        <Stack.Screen name="notifications" />
        <Stack.Screen name="activity-log" />
        <Stack.Screen name="reminder-settings" />
        <Stack.Screen name="change-password" options={{ gestureEnabled: false }} />
        <Stack.Screen name="paused" />
      </Stack>
    </SafeAreaProvider>
  );
}

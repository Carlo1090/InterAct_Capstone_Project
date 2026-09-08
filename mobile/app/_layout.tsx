import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';
import { useAutoSyncOutbox } from '../src/hooks/useAutoSyncOutbox';
import { useLocalReminderSync } from '../src/hooks/useLocalReminderSync';
import { SplashGate } from '../src/components/SplashGate';
import { ToastHost } from '../src/components/ToastHost';

export default function RootLayout() {
  // Mounted once for the whole app lifetime — flushes any offline-queued
  // journal entries as soon as connectivity returns, regardless of which
  // screen is currently active.
  useAutoSyncOutbox();

  // Re-arms the student's on-device journal reminders from their saved
  // preferences. These are OS-scheduled local notifications, so unlike the
  // server's hourly reminder command they still fire with no connection.
  useLocalReminderSync();

  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      <Stack screenOptions={{ headerShown: false }}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="login" />
        <Stack.Screen name="write" options={{ presentation: 'modal' }} />
        <Stack.Screen name="guide" />
        <Stack.Screen name="infosheet" />
        <Stack.Screen name="exit-interview" />
        <Stack.Screen name="weekly-activity/index" />
        <Stack.Screen name="weekly-activity/[id]" />
        <Stack.Screen name="profile" />
        <Stack.Screen name="notifications" />
        <Stack.Screen name="activity-log" />
        <Stack.Screen name="reminder-settings" />
        <Stack.Screen name="change-password" options={{ gestureEnabled: false }} />
        <Stack.Screen name="paused" />
      </Stack>

      {/* Above the navigator so an error is visible from every screen,
          including modals like Write Journal. */}
      <ToastHost />

      {/* Overlaid rather than wrapped: expo-router expects its navigator to
          be mounted, so the Stack always renders and the splash simply
          covers it during the one cache warm-up pass. */}
      <SplashGate />
    </SafeAreaProvider>
  );
}

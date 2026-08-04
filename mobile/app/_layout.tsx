import { Stack } from 'expo-router';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      <Stack screenOptions={{ headerShown: false }}>
        <Stack.Screen name="(tabs)" />
        <Stack.Screen name="login" />
        <Stack.Screen name="signup" />
        <Stack.Screen name="write" options={{ presentation: 'modal' }} />
        <Stack.Screen name="more" options={{ presentation: 'modal' }} />
        <Stack.Screen name="guide" />
        <Stack.Screen name="infosheet" />
        <Stack.Screen name="reports" />
        <Stack.Screen name="drafts" />
        <Stack.Screen name="profile" />
      </Stack>
    </SafeAreaProvider>
  );
}

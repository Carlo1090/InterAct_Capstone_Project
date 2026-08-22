import { Tabs, Redirect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { View, ActivityIndicator } from 'react-native';
import { useAuth } from '../../src/hooks/useAuth';
import { useCurrentUser } from '../../src/hooks/useCurrentUser';
import { colors } from '../../src/constants/colors';

/**
 * Mobile's analogue of the web SPA router's beforeEach guard: a gated
 * student (info sheet not yet approved) can only reach the Info Sheet; a
 * paused student (dropped from their batch) can only reach Paused/Info
 * Sheet; must_change_password force-routes to the Change Password screen
 * regardless of gate state.
 *
 * Tab order is Dashboard · Calendar · Scan · Journals · Weekly, putting Scan
 * dead centre as the one physical, in-the-moment action. Info Sheet is NOT a
 * tab — it lives in the header beside the notification bell (see TopBar),
 * since it is filled once at intake rather than visited daily.
 */
export default function TabsLayout() {
  const { isAuthenticated } = useAuth();
  const { user, loading, studentGated, studentPaused, mustChangePassword, dtrEnabled } = useCurrentUser();

  if (isAuthenticated === false) return <Redirect href="/login" />;

  if (isAuthenticated === null || (isAuthenticated && loading && !user)) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.gray50 }}>
        <ActivityIndicator color={colors.blue600} />
      </View>
    );
  }

  if (mustChangePassword) return <Redirect href="/change-password" />;
  if (studentGated) return <Redirect href="/infosheet" />;
  if (!studentGated && studentPaused) return <Redirect href="/paused" />;

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.blue600,
        tabBarInactiveTintColor: colors.gray400,
        tabBarStyle: { height: 64, borderTopColor: colors.gray200 },
        tabBarLabelStyle: { fontSize: 9, fontWeight: '500' },
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Dashboard',
          tabBarIcon: ({ color }) => <Ionicons name="grid-outline" size={20} color={color} />,
        }}
      />
      <Tabs.Screen
        name="calendar"
        options={{
          title: 'Calendar',
          tabBarIcon: ({ color }) => <Ionicons name="calendar-outline" size={20} color={color} />,
        }}
      />
      <Tabs.Screen
        name="scan"
        options={{
          title: 'Scan',
          tabBarIcon: ({ color }) => <Ionicons name="qr-code-outline" size={22} color={color} />,
          // Mirrors the web SPA, which hides its DTR nav item for a programme
          // whose coordinator has the Daily Time Record switched off. The
          // route still exists (the screen renders the server's own "not
          // enabled" message if reached directly) — only the tab is hidden.
          href: dtrEnabled ? undefined : null,
        }}
      />
      <Tabs.Screen
        name="journals"
        options={{
          title: 'Journals',
          tabBarIcon: ({ color }) => <Ionicons name="document-text-outline" size={20} color={color} />,
        }}
      />
      <Tabs.Screen
        name="weekly"
        options={{
          title: 'Weekly',
          tabBarIcon: ({ color }) => <Ionicons name="albums-outline" size={20} color={color} />,
        }}
      />
    </Tabs>
  );
}

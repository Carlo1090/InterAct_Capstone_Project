import { Tabs, router, Redirect } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useAuth } from '../../src/hooks/useAuth';
import { colors } from '../../src/constants/colors';

export default function TabsLayout() {
  const { isAuthenticated } = useAuth();

  if (isAuthenticated === false) return <Redirect href="/login" />;

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
      <Tabs.Screen
        name="_more-placeholder"
        options={{
          title: 'More',
          tabBarIcon: ({ color }) => <Ionicons name="ellipsis-horizontal" size={20} color={color} />,
        }}
        listeners={{
          tabPress: (e) => {
            e.preventDefault();
            router.push('/more');
          },
        }}
      />
    </Tabs>
  );
}

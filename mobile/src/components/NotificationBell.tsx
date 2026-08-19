import { View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';
import { useNotifications } from '../hooks/useNotifications';

/** Header bell icon + unread badge — the mobile analogue of web's
 * NotificationBell.vue, which sits directly beside the profile avatar. */
export function NotificationBell() {
  const { unreadCount } = useNotifications();
  const label = unreadCount > 9 ? '9+' : String(unreadCount);

  return (
    <Pressable
      onPress={() => router.push('/notifications')}
      style={{
        width: 34,
        height: 34,
        borderRadius: 17,
        alignItems: 'center',
        justifyContent: 'center',
      }}
      hitSlop={6}
    >
      <Ionicons name="notifications-outline" size={22} color="white" />
      {unreadCount > 0 ? (
        <View
          style={{
            position: 'absolute',
            top: 2,
            right: 2,
            minWidth: 16,
            height: 16,
            paddingHorizontal: 3,
            borderRadius: 8,
            backgroundColor: colors.red,
            alignItems: 'center',
            justifyContent: 'center',
            borderWidth: 1.5,
            borderColor: colors.blue900,
          }}
        >
          <Text style={{ color: 'white', fontSize: 9, fontWeight: '700' }}>{label}</Text>
        </View>
      ) : null}
    </Pressable>
  );
}

import { useEffect } from 'react';
import { View, Text, Pressable, FlatList, Alert } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { useNotifications } from '../src/hooks/useNotifications';
import { NotificationItem } from '../src/types/api';
import { colors } from '../src/constants/colors';

const toneForType: Record<string, { icon: keyof typeof Ionicons.glyphMap; color: string }> = {
  email: { icon: 'mail-outline', color: colors.blue600 },
  push: { icon: 'phone-portrait-outline', color: colors.blue600 },
  in_app: { icon: 'information-circle-outline', color: colors.blue600 },
};

function formatSentAt(iso: string) {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

export default function Notifications() {
  const { notifications, unreadCount, loading, error, reload, markAllRead, clearAll } = useNotifications();

  // Matches the web bell's behavior: opening the list marks everything read.
  useEffect(() => {
    if (unreadCount > 0) markAllRead();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function onClearAll() {
    Alert.alert('Clear all notifications?', 'This removes every notification permanently.', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Clear All', style: 'destructive', onPress: () => clearAll() },
    ]);
  }

  return (
    <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
      <View
        style={{
          paddingTop: 50,
          paddingHorizontal: 20,
          paddingBottom: 10,
          flexDirection: 'row',
          alignItems: 'center',
          gap: 12,
        }}
      >
        <Pressable onPress={() => router.back()}>
          <Ionicons name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black, flex: 1 }}>Notifications</Text>
        {notifications.length > 0 ? (
          <Pressable onPress={onClearAll}>
            <Text style={{ fontSize: 12, fontWeight: '600', color: colors.redDark }}>Clear all</Text>
          </Pressable>
        ) : null}
      </View>

      {loading && notifications.length === 0 ? (
        <LoadingState />
      ) : error && notifications.length === 0 ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <FlatList
          data={notifications}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ paddingTop: 4, paddingBottom: 24 }}
          renderItem={({ item }) => <NotificationRow item={item} />}
          ListEmptyComponent={
            <View style={{ alignItems: 'center', marginTop: 60, paddingHorizontal: 30 }}>
              <Ionicons name="notifications-off-outline" size={28} color={colors.gray300} />
              <Text style={{ marginTop: 10, fontSize: 12.5, color: colors.gray400, textAlign: 'center' }}>
                No notifications yet.
              </Text>
            </View>
          }
        />
      )}
    </View>
  );
}

function NotificationRow({ item }: { item: NotificationItem }) {
  const tone = toneForType[item.type] ?? toneForType.in_app;
  return (
    <View
      style={{
        flexDirection: 'row',
        gap: 12,
        marginHorizontal: 20,
        marginTop: 10,
        padding: 14,
        borderRadius: 12,
        backgroundColor: item.is_read ? colors.white : colors.blue50,
        borderWidth: 1,
        borderColor: item.is_read ? colors.gray200 : colors.blue200,
      }}
    >
      <Ionicons name={tone.icon} size={18} color={tone.color} style={{ marginTop: 2 }} />
      <View style={{ flex: 1 }}>
        <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black }}>{item.title}</Text>
        {item.message ? (
          <Text style={{ fontSize: 12, color: colors.gray600, marginTop: 3, lineHeight: 17 }}>{item.message}</Text>
        ) : null}
        <Text style={{ fontSize: 10.5, color: colors.gray400, marginTop: 6 }}>{formatSentAt(item.sent_at)}</Text>
      </View>
      {!item.is_read ? (
        <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: colors.blue500, marginTop: 4 }} />
      ) : null}
    </View>
  );
}

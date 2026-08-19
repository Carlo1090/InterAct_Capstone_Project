import { View, Text, Pressable, FlatList } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { useActivityLog } from '../src/hooks/useActivityLog';
import { SystemLogEntry } from '../src/types/api';
import { colors } from '../src/constants/colors';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * `logged_at` has no Eloquent cast, so the API returns it as a bare
 * "Y-m-d H:i:s" string with no timezone marker — matching web's
 * ActivityLogPanel.vue, which prints it raw rather than risk a `new Date()`
 * misinterpreting a marker-less string as the device's local time. Parsed
 * by splitting instead, never by constructing a Date from it.
 */
function formatLoggedAt(raw: string) {
  const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if (!match) return raw;
  const [, year, month, day, hour, minute] = match;
  const h = Number(hour);
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h % 12 === 0 ? 12 : h % 12;
  return `${MONTHS[Number(month) - 1]} ${Number(day)}, ${year}, ${hour12}:${minute} ${period}`;
}

export default function ActivityLog() {
  const { entries, loading, error, reload } = useActivityLog();

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
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Activity Log</Text>
      </View>

      {loading && entries.length === 0 ? (
        <LoadingState />
      ) : error && entries.length === 0 ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <FlatList
          data={entries}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ paddingTop: 4, paddingBottom: 24 }}
          renderItem={({ item }) => <ActivityRow item={item} />}
          ListEmptyComponent={
            <View style={{ alignItems: 'center', marginTop: 60, paddingHorizontal: 30 }}>
              <Ionicons name="time-outline" size={28} color={colors.gray300} />
              <Text style={{ marginTop: 10, fontSize: 12.5, color: colors.gray400, textAlign: 'center' }}>
                No activity recorded yet.
              </Text>
            </View>
          }
        />
      )}
    </View>
  );
}

function ActivityRow({ item }: { item: SystemLogEntry }) {
  return (
    <View
      style={{
        flexDirection: 'row',
        gap: 12,
        marginHorizontal: 20,
        marginTop: 10,
        paddingBottom: 10,
        borderBottomWidth: 1,
        borderBottomColor: colors.gray100,
      }}
    >
      <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: colors.blue500, marginTop: 6 }} />
      <View style={{ flex: 1 }}>
        <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black }}>{item.action}</Text>
        {item.description ? (
          <Text style={{ fontSize: 12, color: colors.gray600, marginTop: 2, lineHeight: 17 }}>{item.description}</Text>
        ) : null}
        <Text style={{ fontSize: 10.5, color: colors.gray400, marginTop: 4 }}>{formatLoggedAt(item.logged_at)}</Text>
      </View>
    </View>
  );
}

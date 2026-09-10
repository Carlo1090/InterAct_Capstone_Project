import { useMemo } from 'react';
import { View, Text, Pressable, SectionList, RefreshControl } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { OfflineNotice } from '../src/components/OfflineNotice';
import { ActivityRow } from '../src/components/ActivityRow';
import { useActivityLog } from '../src/hooks/useActivityLog';
import { useCurrentUser } from '../src/hooks/useCurrentUser';
import { dayHeading, logDayKey } from '../src/lib/datetime';
import { SystemLogEntry } from '../src/types/api';
import { colors } from '../src/constants/colors';

/**
 * The activity log, rewritten to be read rather than parsed.
 *
 * It used to be a flat list where every row looked identical: the same blue
 * dot, the system's own wording ("DTR Clocked In"), and a full absolute
 * timestamp on every line. Finding "when did I last clock in?" meant reading
 * every row in order, because nothing distinguished one kind of event from
 * another and nothing grouped them.
 *
 * Four changes, each doing one job:
 *  - Events are GROUPED BY DAY, so the list has structure to scan instead of
 *    a timestamp to decode on every line.
 *  - Recent times are RELATIVE ("2 hours ago"), which is how people actually
 *    read a recent event. The exact clock time stays beside it.
 *  - Each kind of event has its own ICON AND COLOUR, so a category can be
 *    found by shape rather than by reading.
 *  - Titles are in PLAIN LANGUAGE, not the audit table's vocabulary.
 *
 * The last three of those now live in src/lib/activityLog.ts and
 * src/components/ActivityRow.tsx, because the dashboard's Recent Activity card
 * renders the same rows and was describing them differently.
 */

export default function ActivityLog() {
  const { entries, loading, error, isOffline, reload } = useActivityLog();
  const { user } = useCurrentUser();

  // Grouped by calendar day, newest first, preserving the order the API
  // already returns rather than re-sorting it.
  const sections = useMemo(() => {
    const byDay = new Map<string, SystemLogEntry[]>();
    for (const entry of entries) {
      const key = logDayKey(entry.logged_at);
      const bucket = byDay.get(key);
      if (bucket) bucket.push(entry);
      else byDay.set(key, [entry]);
    }
    return Array.from(byDay, ([day, data]) => ({ day, title: dayHeading(day), data }));
  }, [entries]);

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
          backgroundColor: colors.white,
          borderBottomWidth: 1,
          borderBottomColor: colors.gray100,
        }}
      >
        <Pressable onPress={() => router.back()} hitSlop={10} accessibilityLabel="Go back">
          <Ionicons name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <View style={{ flex: 1 }}>
          <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Activity Log</Text>
        </View>
      </View>

      <OfflineNotice feature="activityLog" show={isOffline && entries.length > 0} />

      {loading && entries.length === 0 ? (
        <LoadingState />
      ) : error && entries.length === 0 ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <SectionList
          sections={sections}
          keyExtractor={(item) => String(item.id)}
          stickySectionHeadersEnabled
          contentContainerStyle={{ paddingBottom: 28 }}
          // Pull-to-refresh is the gesture people already try on a list like
          // this; without it the only way to refresh was to leave and return.
          refreshControl={
            <RefreshControl refreshing={loading && entries.length > 0} onRefresh={reload} tintColor={colors.blue600} />
          }
          renderSectionHeader={({ section }) => (
            <View
              style={{
                paddingHorizontal: 20,
                paddingTop: 16,
                paddingBottom: 6,
                backgroundColor: colors.gray50,
              }}
            >
              <Text
                style={{
                  fontSize: 10,
                  fontWeight: '700',
                  letterSpacing: 0.6,
                  textTransform: 'uppercase',
                  color: colors.gray400,
                }}
              >
                {section.title}
              </Text>
            </View>
          )}
          renderItem={({ item }) => <ActivityRow item={item} userName={user?.name} />}
          ListEmptyComponent={
            <View style={{ alignItems: 'center', marginTop: 60, paddingHorizontal: 30 }}>
              <Ionicons name="time-outline" size={28} color={colors.gray300} />
              <Text style={{ marginTop: 10, fontSize: 12.5, color: colors.gray400, textAlign: 'center' }}>
                Nothing here yet. Signing in, clocking in and submitting a journal all show up here.
              </Text>
            </View>
          }
        />
      )}
    </View>
  );
}

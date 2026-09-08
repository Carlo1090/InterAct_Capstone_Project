import { useMemo } from 'react';
import { View, Text, Pressable, SectionList, RefreshControl } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { OfflineNotice } from '../src/components/OfflineNotice';
import { useActivityLog } from '../src/hooks/useActivityLog';
import { useCurrentUser } from '../src/hooks/useCurrentUser';
import { dayHeading, logClockTime, logDayKey, relativeLogTime } from '../src/lib/datetime';
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
 */

type Category = 'session' | 'time' | 'submission' | 'account' | 'other';

const CATEGORY: Record<Category, { icon: keyof typeof Ionicons.glyphMap; tint: string; wash: string }> = {
  // Deliberately the quietest treatment: sign-ins are the most frequent rows
  // and the least interesting, so they must not dominate the page.
  session: { icon: 'log-in-outline', tint: colors.gray500, wash: colors.gray100 },
  time: { icon: 'time-outline', tint: colors.blue600, wash: colors.blue50 },
  submission: { icon: 'checkmark-circle-outline', tint: colors.greenTx, wash: colors.greenBg },
  account: { icon: 'person-circle-outline', tint: colors.warnTx, wash: colors.warnBg },
  other: { icon: 'ellipse-outline', tint: colors.gray500, wash: colors.gray100 },
};

/**
 * The server's action strings are audit-table vocabulary. This is the
 * student-facing wording, written in the first person because it is their own
 * history. Anything unmapped falls through to the raw action rather than
 * being hidden — an unexplained row is better than a missing one.
 */
const ACTIONS: Record<string, { title: string; category: Category; icon?: keyof typeof Ionicons.glyphMap }> = {
  'Logged In': { title: 'Signed in', category: 'session' },
  'Logged Out': { title: 'Signed out', category: 'session', icon: 'log-out-outline' },
  'DTR Clocked In': { title: 'Clocked in', category: 'time', icon: 'enter-outline' },
  'DTR Clocked Out': { title: 'Clocked out', category: 'time', icon: 'exit-outline' },
  'Daily Journal Submitted': { title: 'Submitted a daily journal', category: 'submission' },
  'Weekly Journal Submitted': { title: 'Submitted a weekly journal', category: 'submission' },
  'Weekly Journal Approved': { title: 'Weekly journal approved', category: 'submission' },
  'Weekly Journal Returned': {
    title: 'Weekly journal returned for changes',
    category: 'submission',
    icon: 'arrow-undo-outline',
  },
  'Exit Interview Submitted': { title: 'Submitted your exit interview', category: 'submission' },
  'Info Sheet Accepted': { title: 'Information sheet accepted', category: 'submission' },
  'Info Sheet Rejected': {
    title: 'Information sheet returned for changes',
    category: 'submission',
    icon: 'arrow-undo-outline',
  },
  'Profile Updated': { title: 'Updated your profile', category: 'account' },
  'Profile Photo Updated': { title: 'Changed your profile photo', category: 'account', icon: 'image-outline' },
  'Profile Photo Removed': { title: 'Removed your profile photo', category: 'account', icon: 'image-outline' },
  'Password Changed': { title: 'Changed your password', category: 'account', icon: 'key-outline' },
  'Temporary Password Issued': { title: 'A temporary password was issued', category: 'account', icon: 'key-outline' },
  'Credentials Resent': { title: 'Your login details were re-sent', category: 'account', icon: 'mail-outline' },
};

function describe(entry: SystemLogEntry) {
  const mapped = ACTIONS[entry.action];
  const category: Category = mapped?.category ?? 'other';
  const base = CATEGORY[category];
  return {
    title: mapped?.title ?? entry.action,
    icon: mapped?.icon ?? base.icon,
    tint: base.tint,
    wash: base.wash,
  };
}

/**
 * The description repeats the student's own name on nearly every row
 * ("Juan Dela Cruz logged in (mobile)"). In a personal timeline that is noise
 * on every single line, so a leading own-name is trimmed. Only an exact
 * prefix match is removed — anything else is left completely alone.
 */
function trimOwnName(description: string | null, name: string | undefined): string | null {
  if (!description) return null;
  if (!name || !description.startsWith(name)) return description;

  const rest = description.slice(name.length).trimStart();
  if (rest.length === 0) return null;
  return rest.charAt(0).toUpperCase() + rest.slice(1);
}

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
          <Text style={{ fontSize: 11, color: colors.gray500, marginTop: 1 }}>
            Everything done on your account, newest first.
          </Text>
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

function ActivityRow({ item, userName }: { item: SystemLogEntry; userName?: string }) {
  const { title, icon, tint, wash } = describe(item);
  const detail = trimOwnName(item.description, userName);

  return (
    <View
      style={{
        flexDirection: 'row',
        gap: 12,
        marginHorizontal: 20,
        marginBottom: 8,
        padding: 12,
        borderRadius: 12,
        backgroundColor: colors.white,
        borderWidth: 1,
        borderColor: colors.gray200,
      }}
    >
      {/* Icon and colour carry the category, so a kind of event can be found
          by shape rather than by reading every title. */}
      <View
        style={{
          width: 32,
          height: 32,
          borderRadius: 16,
          backgroundColor: wash,
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <Ionicons name={icon} size={17} color={tint} />
      </View>

      <View style={{ flex: 1, minWidth: 0 }}>
        <View style={{ flexDirection: 'row', alignItems: 'flex-start', gap: 8 }}>
          <Text style={{ flex: 1, fontSize: 13, fontWeight: '600', color: colors.black }}>{title}</Text>
          {/* Relative time leads because it is what the question usually is;
              the exact clock time sits under it so nothing is lost. */}
          <Text style={{ fontSize: 10.5, color: colors.gray500, fontWeight: '500' }}>
            {relativeLogTime(item.logged_at)}
          </Text>
        </View>

        {detail ? (
          <Text style={{ fontSize: 11.5, color: colors.gray600, marginTop: 3, lineHeight: 16 }}>{detail}</Text>
        ) : null}

        <Text style={{ fontSize: 10, color: colors.gray400, marginTop: 4 }}>{logClockTime(item.logged_at)}</Text>
      </View>
    </View>
  );
}

import { ScrollView, View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { colors } from '../src/constants/colors';
import { OFFLINE_CAPABILITIES, OfflineLevel } from '../src/lib/offlineCapability';

/**
 * The offline reference, generated from OFFLINE_CAPABILITIES rather than
 * written out by hand — so this page can never drift from what the app
 * actually does. Adding a feature to that file adds it here.
 */
const LABELS: Record<string, string> = {
  journalWrite: 'Writing a Daily Journal',
  dashboard: 'Dashboard',
  calendar: 'Journal Calendar',
  journalList: 'My Journals',
  weeklyLogs: 'Weekly Reports',
  weeklyActivityLog: 'Weekly and Time Log Summary',
  infoSheet: 'Student Info Sheet',
  exitInterview: 'Exit Interview',
  notifications: 'Notifications',
  activityLog: 'Activity Log',
  profile: 'Profile',
  reminderSettings: 'Reminder Settings',
  dtr: 'Clock In / Out (Daily Time Record)',
  pdf: 'Downloading a PDF',
};

const LEVEL: Record<OfflineLevel, { label: string; bg: string; tx: string; icon: keyof typeof Ionicons.glyphMap }> = {
  read_write: { label: 'Read & write', bg: colors.greenBg, tx: colors.greenTx, icon: 'create-outline' },
  read: { label: 'Read only', bg: colors.blue50, tx: colors.blue700, icon: 'eye-outline' },
  online_only: { label: 'Needs internet', bg: colors.redBg, tx: colors.redTx, icon: 'cloud-offline-outline' },
};

const ORDER: OfflineLevel[] = ['read_write', 'read', 'online_only'];

const SECTION_BLURB: Record<OfflineLevel, string> = {
  read_write:
    'Fully usable with no signal. What you write is saved on this phone and sent by itself once you reconnect.',
  read: 'You can open these and read your saved information, but saving changes needs a connection.',
  online_only: 'These cannot work offline at all. The reason is given for each.',
};

export default function OfflineGuide() {
  const entries = Object.entries(OFFLINE_CAPABILITIES) as [string, { level: OfflineLevel; note: string }][];

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 36 }}>
      <View
        style={{
          paddingTop: 50,
          paddingBottom: 12,
          paddingHorizontal: 20,
          flexDirection: 'row',
          alignItems: 'center',
          gap: 10,
          backgroundColor: colors.white,
          borderBottomWidth: 1,
          borderBottomColor: colors.gray100,
        }}
      >
        <Pressable
          onPress={() => router.back()}
          style={{
            width: 34,
            height: 34,
            borderRadius: 8,
            borderWidth: 1.5,
            borderColor: colors.gray200,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="chevron-back" size={18} color={colors.gray600} />
        </Pressable>
        <Text style={{ fontSize: 16, fontWeight: '700', color: colors.black, flex: 1 }}>Using InternTrack offline</Text>
      </View>

      <Banner variant="info">
        The app keeps a copy of your information on this phone, so most of it still opens with no signal. What
        changes offline is whether you can SAVE.
      </Banner>

      {ORDER.map((level) => {
        const rows = entries.filter(([, cap]) => cap.level === level);
        if (rows.length === 0) return null;
        const l = LEVEL[level];

        return (
          <View key={level} style={{ marginTop: 22 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 20 }}>
              <View
                style={{
                  flexDirection: 'row',
                  alignItems: 'center',
                  gap: 5,
                  backgroundColor: l.bg,
                  paddingHorizontal: 9,
                  paddingVertical: 4,
                  borderRadius: 999,
                }}
              >
                <Ionicons name={l.icon} size={13} color={l.tx} />
                <Text style={{ fontSize: 11, fontWeight: '700', color: l.tx }}>{l.label}</Text>
              </View>
              <Text style={{ fontSize: 11, color: colors.gray400, fontWeight: '600' }}>({rows.length})</Text>
            </View>

            <Text style={{ fontSize: 12, color: colors.gray500, paddingHorizontal: 20, marginTop: 7, lineHeight: 17 }}>
              {SECTION_BLURB[level]}
            </Text>

            <View
              style={{
                backgroundColor: colors.white,
                borderTopWidth: 1,
                borderBottomWidth: 1,
                borderColor: colors.gray200,
                marginTop: 11,
              }}
            >
              {rows.map(([key, cap], i) => (
                <View
                  key={key}
                  style={{
                    paddingVertical: 12,
                    paddingHorizontal: 20,
                    borderTopWidth: i === 0 ? 0 : 1,
                    borderTopColor: colors.gray100,
                    // A coloured left stripe carries the level a second time,
                    // so the grouping survives a black-and-white screenshot or
                    // a colour-blind reader.
                    borderLeftWidth: 3,
                    borderLeftColor: l.tx,
                  }}
                >
                  <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black }}>{LABELS[key] ?? key}</Text>
                  <Text style={{ fontSize: 12, color: colors.gray600, marginTop: 3, lineHeight: 17 }}>{cap.note}</Text>
                </View>
              ))}
            </View>
          </View>
        );
      })}

      <Banner variant="neutral">
        Anything you write offline is kept on this phone until it sends. It is never lost by closing the app — but
        it only reaches your coordinator once you reconnect.
      </Banner>
    </ScrollView>
  );
}

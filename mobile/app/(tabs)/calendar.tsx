import { ScrollView, View, Text, ActivityIndicator } from 'react-native';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { useJournalCalendar } from '../../src/hooks/useJournals';
import { colors } from '../../src/constants/colors';
import { CalDayState } from '../../src/services/mock/journals';

const dayStyle: Record<CalDayState, { bg: string; tx: string }> = {
  submitted: { bg: colors.blue100, tx: colors.blue700 },
  missing: { bg: colors.redBg, tx: colors.redTx },
  noentry: { bg: 'transparent', tx: colors.gray300 },
  today: { bg: colors.blue500, tx: colors.white },
  empty: { bg: 'transparent', tx: 'transparent' },
};

const weekdayHeaders = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

export default function CalendarScreen() {
  const { days, loading } = useJournalCalendar();

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 24 }}>
      <TopBar />
      <View
        style={{
          flexDirection: 'row',
          justifyContent: 'space-between',
          alignItems: 'center',
          marginHorizontal: 20,
          marginTop: 20,
        }}
      >
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Journal Calendar</Text>
        <Text style={{ fontSize: 13, fontWeight: '600', color: colors.gray600 }}>May 2025</Text>
      </View>

      <View style={{ flexDirection: 'row', gap: 14, marginHorizontal: 20, marginTop: 16, flexWrap: 'wrap' }}>
        <Legend color={colors.blue500} label="Submitted" />
        <Legend color={colors.red} label="Missing" />
        <Legend color={colors.gray300} label="Weekend/No Entry" />
      </View>

      {loading ? (
        <ActivityIndicator color={colors.blue600} style={{ marginTop: 30 }} />
      ) : (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', marginHorizontal: 20, marginTop: 14, gap: 3 }}>
          {weekdayHeaders.map((h, i) => (
            <View key={`h-${i}`} style={{ width: '13.5%', alignItems: 'center', paddingVertical: 4 }}>
              <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray400 }}>{h}</Text>
            </View>
          ))}
          {days.map((d, i) => {
            const s = dayStyle[d.state];
            return (
              <View
                key={i}
                style={{
                  width: '13.5%',
                  aspectRatio: 1,
                  borderRadius: 8,
                  alignItems: 'center',
                  justifyContent: 'center',
                  backgroundColor: s.bg,
                }}
              >
                {d.day ? (
                  <Text
                    style={{
                      fontSize: 12,
                      fontWeight: d.state === 'noentry' ? '500' : '600',
                      color: s.tx,
                    }}
                  >
                    {d.day}
                  </Text>
                ) : null}
              </View>
            );
          })}
        </View>
      )}

      <Banner variant="info">
        Email reminders are sent at 9:00 PM for missing entries. Journals compile every Monday at 12:00 AM.
      </Banner>
    </ScrollView>
  );
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
      <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: color }} />
      <Text style={{ fontSize: 11, color: colors.gray600 }}>{label}</Text>
    </View>
  );
}

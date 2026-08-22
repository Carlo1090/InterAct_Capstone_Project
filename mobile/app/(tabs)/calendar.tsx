import { ScrollView, View, Text, Pressable } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { useJournalCalendar } from '../../src/hooks/useJournals';
import { colors } from '../../src/constants/colors';
import { CalendarDayStatus } from '../../src/types/api';

const dayStyle: Record<CalendarDayStatus, { bg: string; tx: string }> = {
  submitted: { bg: colors.blue100, tx: colors.blue700 },
  draft: { bg: colors.gray100, tx: colors.gray600 },
  missing: { bg: colors.redBg, tx: colors.redTx },
  no_entry: { bg: 'transparent', tx: colors.gray300 },
  future: { bg: 'transparent', tx: colors.gray300 },
};

const weekdayHeaders = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

function monthLabel(month: string) {
  const [y, m] = month.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

const todayISO = new Date().toISOString().slice(0, 10);

export default function CalendarScreen() {
  const { days, loading, error, isOffline, month, shiftMonth, reload } = useJournalCalendar();

  // Leading blank cells so the 1st lands under the correct weekday column.
  const firstDay = days[0] ? new Date(`${days[0].date}T00:00:00`).getDay() : 0;
  const leadingBlanks = Array.from({ length: firstDay });

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
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
          <Pressable onPress={() => shiftMonth(-1)} hitSlop={8}>
            <Ionicons name="chevron-back" size={18} color={colors.gray600} />
          </Pressable>
          <Text style={{ fontSize: 13, fontWeight: '600', color: colors.gray600, minWidth: 96, textAlign: 'center' }}>
            {monthLabel(month)}
          </Text>
          <Pressable onPress={() => shiftMonth(1)} hitSlop={8}>
            <Ionicons name="chevron-forward" size={18} color={colors.gray600} />
          </Pressable>
        </View>
      </View>

      {isOffline && days.length > 0 ? (
        <Banner variant="neutral">You're offline — showing your last saved calendar.</Banner>
      ) : null}

      <View style={{ flexDirection: 'row', gap: 14, marginHorizontal: 20, marginTop: 16, flexWrap: 'wrap' }}>
        <Legend color={colors.blue500} label="Submitted" />
        <Legend color={colors.gray400} label="Draft" />
        <Legend color={colors.red} label="Missing" />
        <Legend color={colors.gray300} label="Weekend / No Entry" />
      </View>

      {loading && days.length === 0 ? (
        <LoadingState />
      ) : error && days.length === 0 ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', marginHorizontal: 20, marginTop: 14, gap: 3 }}>
          {weekdayHeaders.map((h, i) => (
            <View key={`h-${i}`} style={{ width: '13.5%', alignItems: 'center', paddingVertical: 4 }}>
              <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray400 }}>{h}</Text>
            </View>
          ))}
          {leadingBlanks.map((_, i) => (
            <View key={`blank-${i}`} style={{ width: '13.5%', aspectRatio: 1 }} />
          ))}
          {days.map((d) => {
            const s = dayStyle[d.status];
            const isToday = d.date === todayISO;
            const dayNum = Number(d.date.slice(8, 10));
            return (
              <Pressable
                key={d.date}
                onPress={() => {
                  if (d.status === 'submitted') router.push('/(tabs)/journals');
                  else if (d.status === 'missing' || d.status === 'draft') router.push(`/write?date=${d.date}`);
                }}
                style={{
                  width: '13.5%',
                  aspectRatio: 1,
                  borderRadius: 8,
                  alignItems: 'center',
                  justifyContent: 'center',
                  backgroundColor: isToday ? colors.blue500 : s.bg,
                  borderWidth: isToday ? 0 : 0,
                }}
              >
                <Text
                  style={{
                    fontSize: 12,
                    fontWeight: d.status === 'no_entry' || d.status === 'future' ? '500' : '600',
                    color: isToday ? colors.white : s.tx,
                  }}
                >
                  {dayNum}
                </Text>
              </Pressable>
            );
          })}
        </View>
      )}

      <Banner variant="info">
        You'll be reminded on your chosen days if a journal entry is missing (see Reminder Settings in Profile).
        Journals compile into your weekly report every Monday at 12:00 AM.
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

import { View, Text, Pressable } from 'react-native';
import { colors } from '../constants/colors';
import { JournalEntrySummary } from '../types/api';

const statusStyle = {
  submitted: { bg: colors.blue100, tx: colors.blue700, label: 'Submitted' },
  draft: { bg: colors.gray100, tx: colors.gray600, label: 'Draft' },
};

function dayParts(dateISO: string) {
  // Slice, never parse — entry_date is a date-cast column serialized at
  // midnight UTC; parsing with `new Date()` can land a day earlier once the
  // device timezone differs from the server's.
  const [, month, day] = dateISO.slice(0, 10).split('-');
  const d = new Date(Number(dateISO.slice(0, 4)), Number(month) - 1, Number(day));
  return {
    day: String(Number(day)),
    dayName: d.toLocaleDateString('en-US', { weekday: 'short' }),
    dateLabel: d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }),
  };
}

export function JournalListItem({ entry, onPress }: { entry: JournalEntrySummary; onPress?: () => void }) {
  const s = statusStyle[entry.status];
  const { day, dayName, dateLabel } = dayParts(entry.entry_date);
  return (
    <Pressable
      onPress={onPress}
      style={({ pressed }) => ({
        flexDirection: 'row',
        alignItems: 'flex-start',
        gap: 14,
        paddingVertical: 14,
        paddingHorizontal: 20,
        borderBottomWidth: 1,
        borderBottomColor: colors.gray100,
        backgroundColor: pressed ? colors.blue50 : 'transparent',
      })}
    >
      <View style={{ alignItems: 'center', minWidth: 36 }}>
        <Text style={{ fontSize: 15, fontWeight: '700', color: colors.black }}>{day}</Text>
        <Text style={{ fontSize: 10, color: colors.gray400 }}>{dayName}</Text>
      </View>
      <View style={{ flex: 1 }}>
        <Text style={{ fontSize: 13.5, fontWeight: '600', color: colors.black }}>{dateLabel}</Text>
        <Text style={{ fontSize: 11, color: colors.gray400, marginTop: 2 }}>{entry.word_count} words</Text>
      </View>
      <View style={{ backgroundColor: s.bg, paddingHorizontal: 8, paddingVertical: 3, borderRadius: 20 }}>
        <Text style={{ fontSize: 10, fontWeight: '600', color: s.tx }}>{s.label}</Text>
      </View>
      {onPress ? <Text style={{ color: colors.gray400, fontSize: 14, marginLeft: 6 }}>{'›'}</Text> : null}
    </Pressable>
  );
}

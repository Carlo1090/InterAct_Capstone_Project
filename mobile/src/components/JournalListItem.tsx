import { View, Text, Pressable } from 'react-native';
import { colors } from '../constants/colors';
import { formatDate, weekdayShort } from '../lib/datetime';
import { JournalEntrySummary } from '../types/api';

const statusStyle = {
  // Green matches the journal calendar's own Submitted colour.
  submitted: { bg: colors.greenBg, tx: colors.greenTx, label: 'Submitted' },
  draft: { bg: colors.gray100, tx: colors.gray600, label: 'Draft' },
};

function dayParts(dateISO: string) {
  return {
    day: String(Number(dateISO.slice(8, 10))),
    dayName: weekdayShort(dateISO),
    dateLabel: formatDate(dateISO),
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

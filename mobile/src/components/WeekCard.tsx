import { View, Text, Pressable } from 'react-native';
import { colors } from '../constants/colors';
import { WeekState } from '../types/api';

export const weekStatusStyle: Record<WeekState, { bg: string; tx: string; label: string }> = {
  draft: { bg: colors.gray100, tx: colors.gray600, label: 'Draft' },
  submitted: { bg: colors.amberBg, tx: colors.amberTx, label: 'Submitted — Awaiting Review' },
  approved: { bg: colors.greenBg, tx: colors.greenTx, label: 'Approved by Supervisor' },
  returned: { bg: colors.redBg, tx: colors.redTx, label: 'Returned for Revision' },
};

function dateRangeLabel(start: string, end: string) {
  const s = new Date(`${start.slice(0, 10)}T00:00:00`);
  const e = new Date(`${end.slice(0, 10)}T00:00:00`);
  const fmt = (d: Date) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  return `${fmt(s)} – ${fmt(e)}, ${e.getFullYear()}`;
}

export function WeekCard({
  weekStart,
  weekEnd,
  entriesCount,
  state,
  onPress,
}: {
  weekStart: string;
  weekEnd: string;
  entriesCount: number;
  state: WeekState;
  onPress?: () => void;
}) {
  const s = weekStatusStyle[state];
  return (
    <Pressable
      onPress={onPress}
      style={{
        marginHorizontal: 20,
        marginTop: 12,
        backgroundColor: colors.white,
        borderRadius: 14,
        borderWidth: 1,
        borderColor: colors.gray200,
        overflow: 'hidden',
      }}
    >
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          justifyContent: 'space-between',
          padding: 16,
        }}
      >
        <View style={{ flex: 1 }}>
          <Text style={{ fontSize: 14, fontWeight: '700', color: colors.black }}>
            Week of {dateRangeLabel(weekStart, weekEnd)}
          </Text>
          <Text style={{ fontSize: 11, color: colors.gray400, marginTop: 2 }}>
            {entriesCount} {entriesCount === 1 ? 'entry' : 'entries'}
          </Text>
        </View>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
          <View style={{ backgroundColor: s.bg, paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20 }}>
            <Text style={{ fontSize: 11, fontWeight: '600', color: s.tx }}>{s.label}</Text>
          </View>
          <Text style={{ color: colors.gray400, fontSize: 14 }}>{'›'}</Text>
        </View>
      </View>
    </Pressable>
  );
}

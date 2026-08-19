import { useMemo, useState } from 'react';
import { View, Text, FlatList, Pressable } from 'react-native';
import { router } from 'expo-router';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { WeekCard } from '../../src/components/WeekCard';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { useWeeklyLogs } from '../../src/hooks/useWeeklyLogs';
import { deriveWeekState, WeekState } from '../../src/types/api';
import { colors } from '../../src/constants/colors';

const FILTERS: { label: string; value: WeekState | 'all' }[] = [
  { label: 'All', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Submitted', value: 'submitted' },
  { label: 'Approved', value: 'approved' },
  { label: 'Returned', value: 'returned' },
];

export default function Weekly() {
  const { logs, loading, error, reload } = useWeeklyLogs();
  const [filterIndex, setFilterIndex] = useState(0);
  const filter = FILTERS[filterIndex];

  const withState = useMemo(
    () => logs.map((l) => ({ ...l, state: deriveWeekState(l.status, l.submitted_at) })),
    [logs]
  );

  const filteredLogs = useMemo(
    () => (filter.value === 'all' ? withState : withState.filter((l) => l.state === filter.value)),
    [withState, filter]
  );

  return (
    <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
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
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Weekly Journals</Text>
        <Pressable onPress={() => setFilterIndex((i) => (i + 1) % FILTERS.length)}>
          <Text style={{ fontSize: 12, fontWeight: '600', color: colors.blue500 }}>↓ {filter.label}</Text>
        </Pressable>
      </View>

      <Banner variant="info">
        Weekly compilations are auto-generated every Monday at 12:00 AM. Approved journals are forwarded to your
        coordinator.
      </Banner>

      {loading && logs.length === 0 ? (
        <LoadingState />
      ) : error && logs.length === 0 ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <FlatList
          data={filteredLogs}
          keyExtractor={(item) => item.week_start}
          contentContainerStyle={{ paddingTop: 4, paddingBottom: 24 }}
          renderItem={({ item }) => (
            <WeekCard
              weekStart={item.week_start}
              weekEnd={item.week_end}
              entriesCount={item.entries_count}
              state={item.state}
              onPress={() => router.push(`/weekly/${item.week_start}`)}
            />
          )}
          ListEmptyComponent={
            <Text style={{ textAlign: 'center', color: colors.gray400, marginTop: 30, fontSize: 12 }}>
              No {filter.value === 'all' ? '' : filter.label.toLowerCase() + ' '}weekly logs yet.
            </Text>
          }
        />
      )}
    </View>
  );
}

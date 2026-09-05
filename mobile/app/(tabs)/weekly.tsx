import { useMemo, useState } from 'react';
import { View, Text, FlatList, Pressable } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { Button } from '../../src/components/Button';
import { WeekCard } from '../../src/components/WeekCard';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { OfflineNotice } from '../../src/components/OfflineNotice';
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
  const { logs, loading, error, isOffline, reload } = useWeeklyLogs();
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
        {/* Boxed for the same reason as "Write" — and outlined rather than
            filled, because cycling a filter is not the page's main action. */}
        <Button
          label={filter.label}
          icon="funnel-outline"
          variant="secondary"
          size="sm"
          onPress={() => setFilterIndex((i) => (i + 1) % FILTERS.length)}
        />
      </View>

      <OfflineNotice feature="weeklyLogs" show={isOffline && logs.length > 0} />

      <Banner variant="info">
        Weekly compilations are auto-generated every Monday at 12:00 AM. Approved journals are forwarded to your
        coordinator.
      </Banner>

      {/* The Time Log Summary is the other weekly artifact, so it is reachable
          from here as well as from Profile. */}
      <Pressable
        onPress={() => router.push('/weekly-activity')}
        style={{
          marginHorizontal: 20,
          marginTop: 12,
          padding: 14,
          borderRadius: 12,
          borderWidth: 1.5,
          borderColor: colors.blue200,
          backgroundColor: colors.white,
          flexDirection: 'row',
          alignItems: 'center',
          gap: 10,
        }}
      >
        <Ionicons name="time-outline" size={18} color={colors.blue600} />
        <Text style={{ flex: 1, fontSize: 13, fontWeight: '600', color: colors.blue600 }}>
          Weekly and Time Log Summary
        </Text>
        <Ionicons name="chevron-forward" size={16} color={colors.blue400} />
      </Pressable>

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

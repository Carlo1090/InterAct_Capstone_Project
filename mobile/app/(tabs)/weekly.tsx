import { useMemo, useState } from 'react';
import { View, Text, FlatList, Pressable, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { WeekCard } from '../../src/components/WeekCard';
import { useWeeklyLogs } from '../../src/hooks/useWeeklyLogs';
import { WeekStatus } from '../../src/services/mock/weekly';
import { colors } from '../../src/constants/colors';

const FILTERS: { label: string; value: WeekStatus | 'all' }[] = [
  { label: 'All', value: 'all' },
  { label: 'Draft', value: 'draft' },
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Returned', value: 'returned' },
];

export default function Weekly() {
  const { logs, loading } = useWeeklyLogs();
  const [filterIndex, setFilterIndex] = useState(0);
  const filter = FILTERS[filterIndex];

  const filteredLogs = useMemo(
    () => (filter.value === 'all' ? logs : logs.filter((l) => l.status === filter.value)),
    [logs, filter]
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

      {loading ? (
        <ActivityIndicator color={colors.blue600} style={{ marginTop: 30 }} />
      ) : (
        <FlatList
          data={filteredLogs}
          keyExtractor={(item) => item.id}
          contentContainerStyle={{ paddingTop: 4, paddingBottom: 24 }}
          renderItem={({ item }) => (
            <WeekCard
              title={item.title}
              sub={item.sub}
              status={item.status}
              onPress={() => router.push(`/weekly/${item.id}`)}
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

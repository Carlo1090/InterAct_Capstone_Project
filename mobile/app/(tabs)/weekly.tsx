import { useMemo, useState } from 'react';
import { View, Text, FlatList, Pressable, Modal } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
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
  const [pickerOpen, setPickerOpen] = useState(false);
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
        {/* A real dropdown, not a button that cycles. Cycling meant reaching
            "Returned" took four taps and there was no way to see what the
            options even were without pressing through them. Outlined rather
            than filled — filtering is not the page's main action. */}
        <Pressable
          onPress={() => setPickerOpen(true)}
          accessibilityRole="button"
          accessibilityLabel={`Filter: ${filter.label}. Tap to change.`}
          style={{
            flexDirection: 'row',
            alignItems: 'center',
            gap: 6,
            paddingVertical: 9,
            paddingHorizontal: 14,
            borderRadius: 10,
            borderWidth: 1.5,
            borderColor: colors.blue600,
            backgroundColor: colors.white,
          }}
        >
          <Ionicons name="funnel-outline" size={14} color={colors.blue600} />
          <Text style={{ fontSize: 12.5, fontWeight: '600', color: colors.blue600 }}>{filter.label}</Text>
          <Ionicons name="chevron-down" size={14} color={colors.blue600} />
        </Pressable>
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

      <Modal visible={pickerOpen} transparent animationType="fade" onRequestClose={() => setPickerOpen(false)}>
        {/* Tapping the backdrop closes it — the expected way out of a
            dropdown, and the reason there is no Cancel button. */}
        <Pressable
          onPress={() => setPickerOpen(false)}
          style={{ flex: 1, backgroundColor: 'rgba(5,13,26,0.35)', justifyContent: 'flex-end' }}
        >
          <Pressable
            onPress={() => {}}
            style={{
              backgroundColor: colors.white,
              borderTopLeftRadius: 18,
              borderTopRightRadius: 18,
              paddingTop: 8,
              paddingBottom: 28,
            }}
          >
            <View style={{ alignItems: 'center', paddingVertical: 8 }}>
              <View style={{ width: 38, height: 4, borderRadius: 2, backgroundColor: colors.gray200 }} />
            </View>

            <Text
              style={{
                fontSize: 10,
                fontWeight: '700',
                letterSpacing: 0.6,
                textTransform: 'uppercase',
                color: colors.gray400,
                paddingHorizontal: 20,
                paddingTop: 4,
                paddingBottom: 6,
              }}
            >
              Show
            </Text>

            {FILTERS.map((option, index) => {
              const active = index === filterIndex;
              // The count is worth showing here: it is the difference between
              // picking a filter and discovering afterwards that it is empty.
              const count =
                option.value === 'all'
                  ? withState.length
                  : withState.filter((l) => l.state === option.value).length;

              return (
                <Pressable
                  key={option.value}
                  onPress={() => {
                    setFilterIndex(index);
                    setPickerOpen(false);
                  }}
                  style={{
                    flexDirection: 'row',
                    alignItems: 'center',
                    gap: 12,
                    paddingVertical: 14,
                    paddingHorizontal: 20,
                    backgroundColor: active ? colors.blue50 : colors.white,
                  }}
                >
                  <Text
                    style={{
                      flex: 1,
                      fontSize: 14,
                      fontWeight: active ? '700' : '500',
                      color: active ? colors.blue700 : colors.black,
                    }}
                  >
                    {option.label}
                  </Text>
                  <Text style={{ fontSize: 12, color: active ? colors.blue600 : colors.gray400, fontWeight: '600' }}>
                    {count}
                  </Text>
                  {active ? <Ionicons name="checkmark" size={17} color={colors.blue600} /> : null}
                </Pressable>
              );
            })}
          </Pressable>
        </Pressable>
      </Modal>
    </View>
  );
}

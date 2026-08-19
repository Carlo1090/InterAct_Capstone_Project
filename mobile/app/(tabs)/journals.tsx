import { useMemo } from 'react';
import { View, Text, FlatList, Pressable } from 'react-native';
import { router } from 'expo-router';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { JournalListItem } from '../../src/components/JournalListItem';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { useJournalList } from '../../src/hooks/useJournals';
import { colors } from '../../src/constants/colors';

export default function Journals() {
  const { entries, loading, error, reload } = useJournalList();

  const sorted = useMemo(
    () => [...entries].sort((a, b) => (a.entry_date < b.entry_date ? 1 : -1)),
    [entries]
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
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>My Journals</Text>
        <Pressable onPress={() => router.push('/write')}>
          <Text style={{ fontSize: 12, fontWeight: '600', color: colors.blue500 }}>+ Write</Text>
        </Pressable>
      </View>

      <Banner variant="info">
        Daily entries track submission status. Review happens after entries compile into your weekly journal.
      </Banner>

      {loading && entries.length === 0 ? (
        <LoadingState />
      ) : error && entries.length === 0 ? (
        <ErrorState message={error.message} onRetry={reload} />
      ) : (
        <FlatList
          data={sorted}
          keyExtractor={(item) => item.entry_date}
          contentContainerStyle={{ marginTop: 14, paddingBottom: 24 }}
          renderItem={({ item }) => (
            <JournalListItem entry={item} onPress={() => router.push(`/write?date=${item.entry_date.slice(0, 10)}`)} />
          )}
          ListEmptyComponent={
            <Text style={{ textAlign: 'center', color: colors.gray400, marginTop: 30, fontSize: 12 }}>
              No journal entries yet.
            </Text>
          }
        />
      )}
    </View>
  );
}

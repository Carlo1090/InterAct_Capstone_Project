import { useState, useMemo } from 'react';
import { View, Text, FlatList, Pressable, TextInput, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { TopBar } from '../../src/components/TopBar';
import { Banner } from '../../src/components/Banner';
import { JournalListItem } from '../../src/components/JournalListItem';
import { useJournalList } from '../../src/hooks/useJournals';
import { colors } from '../../src/constants/colors';

export default function Journals() {
  const { entries, loading, kind } = useJournalList();
  const [query, setQuery] = useState('');

  const filtered = useMemo(
    () => entries.filter((e) => e.title.toLowerCase().includes(query.toLowerCase())),
    [entries, query]
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
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 16 }}>
          <Pressable onPress={() => router.push('/drafts')}>
            <Text style={{ fontSize: 12, fontWeight: '600', color: colors.gray600 }}>Drafts</Text>
          </Pressable>
          <Pressable onPress={() => router.push('/write')}>
            <Text style={{ fontSize: 12, fontWeight: '600', color: colors.blue500 }}>+ Write</Text>
          </Pressable>
        </View>
      </View>

      <Banner variant="info">
        Daily entries track submission status. Review happens after entries compile into your weekly journal.
      </Banner>

      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          gap: 10,
          marginHorizontal: 20,
          marginTop: 14,
          backgroundColor: colors.white,
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 10,
          paddingHorizontal: 14,
          paddingVertical: 10,
        }}
      >
        <Ionicons name="search-outline" size={15} color={colors.gray400} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Search journals…"
          placeholderTextColor={colors.gray400}
          style={{ flex: 1, fontSize: 13, color: colors.black }}
        />
      </View>

      {loading ? (
        <ActivityIndicator color={colors.blue600} style={{ marginTop: 30 }} />
      ) : (
        <FlatList
          data={filtered}
          keyExtractor={(item) => item.id}
          contentContainerStyle={{ marginTop: 14, paddingBottom: 24 }}
          renderItem={({ item }) => (
            <JournalListItem
              entry={item}
              onPress={kind === 'new' ? () => router.push(`/write?entryId=${item.id}`) : undefined}
            />
          )}
          ListEmptyComponent={
            <Text style={{ textAlign: 'center', color: colors.gray400, marginTop: 30, fontSize: 12 }}>
              No journal entries match your search.
            </Text>
          }
        />
      )}
    </View>
  );
}

import { useCallback, useState } from 'react';
import { useFocusEffect } from 'expo-router';
import { View, Text, FlatList, Pressable, ActivityIndicator, Alert } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { mockDrafts } from '../src/services/mock/studentInfo';
import { getAccountKind } from '../src/services/accountKind';
import { getCurrentLocalProfile } from '../src/services/localAccounts';
import { getDrafts, deleteDraft, LocalDraft } from '../src/services/localData';
import { colors } from '../src/constants/colors';

type DraftItem = { id: string; title: string; body: string; date: string };

export default function Drafts() {
  const [drafts, setDrafts] = useState<DraftItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [email, setEmail] = useState<string | null>(null);
  const [isNewAccount, setIsNewAccount] = useState(false);

  useFocusEffect(
    useCallback(() => {
      let mounted = true;
      (async () => {
        const kind = await getAccountKind();
        if (kind === 'new') {
          const profile = await getCurrentLocalProfile();
          if (!mounted) return;
          setIsNewAccount(true);
          setEmail(profile?.email ?? null);
          if (profile?.email) {
            const local = await getDrafts(profile.email);
            if (mounted) setDrafts(local.map(toDraftItem));
          } else if (mounted) {
            setDrafts([]);
          }
        } else {
          if (!mounted) return;
          setIsNewAccount(false);
          setDrafts(mockDrafts);
        }
        if (mounted) setLoading(false);
      })();
      return () => {
        mounted = false;
      };
    }, [])
  );

  function toDraftItem(d: LocalDraft): DraftItem {
    return {
      id: d.id,
      title: d.title || 'Untitled Draft',
      body: d.body || 'No content yet.',
      date: new Date(d.updatedAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
    };
  }

  async function onDelete(id: string) {
    if (!email) return;
    Alert.alert('Delete Draft', 'This draft will be permanently deleted.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          await deleteDraft(email, id);
          setDrafts((prev) => prev.filter((d) => d.id !== id));
        },
      },
    ]);
  }

  return (
    <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
      <View style={{ paddingTop: 50, paddingHorizontal: 20, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
        <Pressable onPress={() => router.back()}>
          <Ionicons name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black, flex: 1 }}>Drafts</Text>
        {isNewAccount && (
          <Pressable onPress={() => router.push('/write')}>
            <Text style={{ fontSize: 12, fontWeight: '600', color: colors.blue500 }}>+ New</Text>
          </Pressable>
        )}
      </View>

      {loading ? (
        <ActivityIndicator color={colors.blue600} style={{ marginTop: 30 }} />
      ) : (
        <FlatList
          data={drafts}
          keyExtractor={(item) => item.id}
          contentContainerStyle={{ paddingTop: 4, paddingBottom: 24 }}
          ListEmptyComponent={
            <Text style={{ textAlign: 'center', color: colors.gray400, marginTop: 30, fontSize: 12 }}>
              No drafts yet. Start writing and tap "Save Draft" to keep one here.
            </Text>
          }
          renderItem={({ item }) => (
            <Pressable
              onPress={() => (isNewAccount ? router.push(`/write?draftId=${item.id}`) : router.push('/write'))}
              style={{
                marginHorizontal: 20,
                marginTop: 12,
                backgroundColor: colors.white,
                borderRadius: 12,
                borderWidth: 1,
                borderColor: colors.gray200,
                padding: 16,
              }}
            >
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 6 }}>
                <Text style={{ fontSize: 14, fontWeight: '600', color: colors.black, flex: 1 }}>{item.title}</Text>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
                  <View style={{ backgroundColor: colors.gray100, paddingHorizontal: 8, paddingVertical: 3, borderRadius: 20 }}>
                    <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray600 }}>Draft</Text>
                  </View>
                  {isNewAccount && (
                    <Pressable onPress={() => onDelete(item.id)} hitSlop={8}>
                      <Ionicons name="trash-outline" size={16} color={colors.redDark} />
                    </Pressable>
                  )}
                </View>
              </View>
              <Text style={{ fontSize: 12, color: colors.gray400, lineHeight: 18 }} numberOfLines={3}>
                {item.body}
              </Text>
              <Text style={{ fontSize: 11, color: colors.gray400, marginTop: 8 }}>{item.date}</Text>
            </Pressable>
          )}
        />
      )}
    </View>
  );
}

import { useEffect, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, ActivityIndicator } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../../src/components/Banner';
import { Card } from '../../src/components/Card';
import { weekStatusStyle } from '../../src/components/WeekCard';
import { useWeeklyLogDetail } from '../../src/hooks/useWeeklyLogs';
import { colors } from '../../src/constants/colors';

const NARRATIVE_LIMIT = 5000;

export default function WeeklyDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { log, loading, saveNarrative, submitNarrative } = useWeeklyLogDetail(id ?? '');
  const [narrative, setNarrative] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (log) setNarrative(log.narrative ?? '');
  }, [log]);

  if (loading || !log) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.blue600} />
      </View>
    );
  }

  const s = weekStatusStyle[log.status];
  const isApproved = log.status === 'approved';
  const isReturned = log.status === 'returned';
  const overLimit = narrative.length > NARRATIVE_LIMIT;

  async function onSave() {
    setSaving(true);
    try {
      await saveNarrative(narrative);
    } finally {
      setSaving(false);
    }
  }

  async function onSubmit() {
    if (overLimit) return;
    setSaving(true);
    try {
      await submitNarrative(narrative);
    } finally {
      setSaving(false);
    }
  }

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 40 }}>
      <View
        style={{
          paddingTop: 50,
          paddingBottom: 10,
          paddingHorizontal: 20,
          flexDirection: 'row',
          alignItems: 'center',
          gap: 12,
        }}
      >
        <Pressable onPress={() => router.back()}>
          <Ionicons name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <Text style={{ fontSize: 18, fontWeight: '700', color: colors.black, flex: 1 }}>{log.title}</Text>
        <View style={{ backgroundColor: s.bg, paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20 }}>
          <Text style={{ fontSize: 11, fontWeight: '600', color: s.tx }}>{s.label}</Text>
        </View>
      </View>

      <Banner variant={isReturned ? 'warn' : 'info'}>
        {isApproved
          ? 'This weekly journal has been approved and can no longer be edited.'
          : isReturned
          ? 'Your supervisor returned this week for revision — see their comment below, then revise and resubmit.'
          : log.status === 'pending'
          ? 'This week has been submitted and is awaiting your supervisor\'s review.'
          : 'Write your narrative for the week, save it as a draft as often as you like, then submit it for review.'}
      </Banner>

      {log.comment ? (
        <Card title="Supervisor Comment">
          <Text style={{ fontSize: 13, color: colors.gray800, lineHeight: 19 }}>{log.comment}</Text>
        </Card>
      ) : null}

      <Card title="Weekly Narrative">
        <TextInput
          value={narrative}
          onChangeText={setNarrative}
          editable={!isApproved}
          placeholder="Summarize your week, day by day..."
          multiline
          textAlignVertical="top"
          maxLength={NARRATIVE_LIMIT}
          style={{
            borderWidth: 1.5,
            borderColor: colors.gray200,
            borderRadius: 10,
            padding: 12,
            minHeight: 220,
            fontSize: 13,
            lineHeight: 19,
            backgroundColor: isApproved ? colors.gray50 : colors.white,
          }}
        />
        {!isApproved && (
          <Text
            style={{
              fontSize: 11,
              fontWeight: '600',
              color: overLimit ? colors.red : colors.gray400,
              marginTop: 8,
              textAlign: 'right',
            }}
          >
            {narrative.length} / {NARRATIVE_LIMIT} characters
          </Text>
        )}
      </Card>

      {!isApproved && (
        <View style={{ flexDirection: 'row', gap: 10, marginHorizontal: 20, marginTop: 16 }}>
          <Pressable
            onPress={onSave}
            disabled={saving}
            style={{
              flex: 1,
              paddingVertical: 14,
              borderRadius: 12,
              borderWidth: 1.5,
              borderColor: colors.gray200,
              alignItems: 'center',
            }}
          >
            <Text style={{ fontSize: 14, fontWeight: '600', color: colors.gray600 }}>Save Draft</Text>
          </Pressable>
          <Pressable
            onPress={onSubmit}
            disabled={saving || overLimit || narrative.trim().length === 0}
            style={{
              flex: 1,
              paddingVertical: 14,
              borderRadius: 12,
              alignItems: 'center',
              backgroundColor:
                saving || overLimit || narrative.trim().length === 0 ? colors.gray300 : colors.blue600,
            }}
          >
            <Text style={{ fontSize: 14, fontWeight: '600', color: 'white' }}>{isReturned ? 'Resubmit' : 'Submit'}</Text>
          </Pressable>
        </View>
      )}
    </ScrollView>
  );
}

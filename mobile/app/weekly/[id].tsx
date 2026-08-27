import { useEffect, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, ActivityIndicator, Alert } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../../src/components/Banner';
import { Button } from '../../src/components/Button';
import { Card } from '../../src/components/Card';
import { weekStatusStyle } from '../../src/components/WeekCard';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { useWeeklyLogDetail } from '../../src/hooks/useWeeklyLogs';
import { deriveWeekState } from '../../src/types/api';
import { downloadAndSharePdf, ApiError } from '../../src/services/api';
import { endpoints } from '../../src/services/endpoints';
import { colors } from '../../src/constants/colors';

const NARRATIVE_LIMIT = 5000;

const dailyStatusStyle = {
  submitted: { bg: colors.blue100, tx: colors.blue700, label: 'Submitted' },
  draft: { bg: colors.gray100, tx: colors.gray600, label: 'Draft' },
};

function formatShortDate(raw: string) {
  const d = new Date(`${raw.slice(0, 10)}T00:00:00`);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function dateRangeLabel(start: string, end: string) {
  const s = new Date(`${start.slice(0, 10)}T00:00:00`);
  const e = new Date(`${end.slice(0, 10)}T00:00:00`);
  const fmt = (d: Date) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  return `${fmt(s)} – ${fmt(e)}`;
}

/** Mirrors web's `Object.values(entry.content)[0] ?? ''` — the reference
 * table shows whichever field happens to be first in the content object,
 * matching StudentWeeklyJournalsPage.vue exactly rather than picking a
 * "smarter" field. */
function firstContentValue(content: Record<string, string>) {
  const values = Object.values(content);
  return values.length > 0 ? values[0] : '';
}

export default function WeeklyDetail() {
  // The route param is the week's Monday date (week_start) — the real
  // backend's only identifier for a week, not an opaque id.
  const { id: weekStart } = useLocalSearchParams<{ id: string }>();
  const { log, loading, error, reload, saveNarrative, submitNarrative } = useWeeklyLogDetail(weekStart ?? '');
  const [narrative, setNarrative] = useState('');
  const [saving, setSaving] = useState(false);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (log) setNarrative(log.narrative ?? '');
  }, [log]);

  if (loading && !log) return <LoadingState />;
  if (error && !log) return <ErrorState message={error.message} onRetry={reload} />;
  if (!log) return null;

  const state = deriveWeekState(log.status, log.submitted_at);
  const s = weekStatusStyle[state];
  const isApproved = state === 'approved';
  const isReturned = state === 'returned';
  const isEditable = state === 'draft' || state === 'returned';
  const overLimit = narrative.length > NARRATIVE_LIMIT;

  async function onSave() {
    setSaving(true);
    const result = await saveNarrative(narrative);
    setSaving(false);
    if (!result.ok) Alert.alert('Could not save', result.error);
  }

  async function onSubmit() {
    if (overLimit) return;
    setSaving(true);
    const result = await submitNarrative(narrative);
    setSaving(false);
    if (!result.ok) Alert.alert('Could not submit', result.error);
  }

  async function onDownloadPdf() {
    setDownloading(true);
    try {
      await downloadAndSharePdf(endpoints.weeklyLogPdf(weekStart!), `weekly-log-${weekStart}.pdf`);
    } catch (err) {
      Alert.alert('Could not download PDF', (err as ApiError).message);
    } finally {
      setDownloading(false);
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
        <Text style={{ fontSize: 16, fontWeight: '700', color: colors.black, flex: 1 }}>
          Week of {dateRangeLabel(log.week_start, log.week_end)}
        </Text>
        <View style={{ backgroundColor: s.bg, paddingHorizontal: 10, paddingVertical: 4, borderRadius: 20 }}>
          <Text style={{ fontSize: 11, fontWeight: '600', color: s.tx }}>{s.label}</Text>
        </View>
      </View>

      <Banner variant={isReturned ? 'warn' : 'info'}>
        {isApproved
          ? 'This weekly journal has been approved and can no longer be edited.'
          : isReturned
          ? "Your supervisor returned this week for revision — see their comment below, then revise and resubmit."
          : state === 'submitted'
          ? "This week has been submitted and is awaiting your supervisor's review."
          : 'Write your narrative for the week, save it as a draft as often as you like, then submit it for review.'}
      </Banner>

      <Card title="Daily Entries (Reference)">
        {log.daily_entries.length === 0 ? (
          <Text style={{ fontSize: 12.5, color: colors.gray400 }}>No daily entries this week.</Text>
        ) : (
          log.daily_entries.map((entry, i) => {
            const style = dailyStatusStyle[entry.status];
            return (
              <View
                key={entry.entry_date}
                style={{
                  paddingVertical: 10,
                  borderTopWidth: i === 0 ? 0 : 1,
                  borderTopColor: colors.gray100,
                }}
              >
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
                  <Text style={{ fontSize: 12.5, fontWeight: '600', color: colors.black }}>
                    {formatShortDate(entry.entry_date)}
                  </Text>
                  <View style={{ backgroundColor: style.bg, paddingHorizontal: 8, paddingVertical: 2, borderRadius: 20 }}>
                    <Text style={{ fontSize: 10, fontWeight: '600', color: style.tx }}>{style.label}</Text>
                  </View>
                </View>
                <Text style={{ fontSize: 12, color: colors.gray600, marginTop: 4, lineHeight: 17 }} numberOfLines={3}>
                  {firstContentValue(entry.content) || '—'}
                </Text>
              </View>
            );
          })
        )}
      </Card>

      {log.supervisor_comment ? (
        <Card title="Supervisor Comment">
          <Text style={{ fontSize: 13, color: colors.gray800, lineHeight: 19 }}>{log.supervisor_comment}</Text>
        </Card>
      ) : null}

      <Card title="Weekly Narrative">
        <TextInput
          value={narrative}
          onChangeText={setNarrative}
          editable={isEditable}
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
            backgroundColor: isEditable ? colors.white : colors.gray50,
          }}
        />
        {isEditable && (
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

      {isEditable ? (
        <View style={{ flexDirection: 'row', gap: 10, marginHorizontal: 20, marginTop: 16 }}>
          <Button label="Save Draft" variant="secondary" fullWidth disabled={saving} onPress={onSave} />
          <Button
            label={isReturned ? 'Resubmit' : 'Submit'}
            icon="checkmark"
            fullWidth
            loading={saving}
            disabled={saving || overLimit || narrative.trim().length === 0}
            onPress={onSubmit}
          />
        </View>
      ) : (
        <Button
          label="Download PDF"
          icon="download-outline"
          variant="secondary"
          loading={downloading}
          disabled={downloading}
          onPress={onDownloadPdf}
          style={{ marginHorizontal: 20, marginTop: 16 }}
        />
      )}
    </ScrollView>
  );
}

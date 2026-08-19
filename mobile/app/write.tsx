import { useCallback, useEffect, useMemo, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, ActivityIndicator, Alert } from 'react-native';
import { router, useFocusEffect, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Card } from '../src/components/Card';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { colors } from '../src/constants/colors';
import { apiGet, apiPost, downloadAndSharePdf, ApiError } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { JournalEntryDetail } from '../src/types/api';

function todayISO() {
  return new Date().toISOString().slice(0, 10);
}

function dateLabelFor(iso: string) {
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}

const LOCKED_REASON_COPY: Record<string, string> = {
  not_active: 'Your enrollment for this batch is not currently active, so this entry is read-only.',
  range: 'This date is outside your OJT range or is a future date, so it cannot be edited.',
  bundled: 'This week has already been compiled into your Weekly Log and can no longer be edited.',
};

export default function Write() {
  const { date: dateParam } = useLocalSearchParams<{ date?: string }>();
  const date = dateParam ?? todayISO();

  const [entry, setEntry] = useState<JournalEntryDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<ApiError | null>(null);
  const [content, setContent] = useState<Record<string, string>>({});
  const [optionalKeysShown, setOptionalKeysShown] = useState<string[]>([]);
  const [sippEnabled, setSippEnabled] = useState(false);
  const [saving, setSaving] = useState(false);
  const [downloading, setDownloading] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await apiGet<JournalEntryDetail>(endpoints.journalEntry(date));
      setEntry(res);
      setContent(res.content ?? {});
      setOptionalKeysShown(
        res.sections.filter((s) => !s.required && !s.sipp && (res.content?.[s.key] ?? '') !== '').map((s) => s.key)
      );
      setSippEnabled(res.sections.some((s) => s.sipp && (res.content?.[s.key] ?? '') !== ''));
    } catch (err) {
      setError(err as ApiError);
    } finally {
      setLoading(false);
    }
  }, [date]);

  useFocusEffect(
    useCallback(() => {
      load();
    }, [load])
  );

  const requiredSections = useMemo(() => entry?.sections.filter((s) => s.required && !s.sipp) ?? [], [entry]);
  const optionalSections = useMemo(() => entry?.sections.filter((s) => !s.required && !s.sipp) ?? [], [entry]);
  const sippSections = useMemo(() => entry?.sections.filter((s) => s.sipp) ?? [], [entry]);
  const addableOptional = optionalSections.filter((s) => !optionalKeysShown.includes(s.key));

  const activeKeys = useMemo(() => {
    const keys = [...requiredSections.map((s) => s.key), ...optionalKeysShown];
    if (sippEnabled) keys.push(...sippSections.map((s) => s.key));
    return keys;
  }, [requiredSections, optionalKeysShown, sippEnabled, sippSections]);

  const charLimit = entry?.char_limit ?? 1500;
  const charCount = activeKeys.reduce((sum, key) => sum + (content[key]?.length ?? 0), 0);
  const overLimit = charCount > charLimit;
  const requiredFilled = requiredSections.every((s) => (content[s.key] ?? '').trim().length > 0);
  const canSubmit = requiredFilled && !overLimit;
  const editable = entry?.editable ?? false;

  function setField(key: string, value: string) {
    setContent((prev) => ({ ...prev, [key]: value }));
  }

  function addOptional(key: string) {
    setOptionalKeysShown((prev) => [...prev, key]);
  }

  function removeOptional(key: string) {
    setOptionalKeysShown((prev) => prev.filter((k) => k !== key));
    setContent((prev) => ({ ...prev, [key]: '' }));
  }

  function buildPayload(): Record<string, string> {
    const payload: Record<string, string> = {};
    for (const key of activeKeys) {
      payload[key] = content[key] ?? '';
    }
    return payload;
  }

  async function saveDraft() {
    setSaving(true);
    try {
      await apiPost(endpoints.journalEntries, { entry_date: date, status: 'draft', content: buildPayload() });
      router.back();
    } catch (err) {
      Alert.alert('Could not save draft', (err as ApiError).message);
    } finally {
      setSaving(false);
    }
  }

  function confirmSubmit() {
    if (!canSubmit) return;
    Alert.alert('Submit this entry?', 'Once submitted you can still edit it until your week is compiled.', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Submit', onPress: submitEntry },
    ]);
  }

  async function submitEntry() {
    setSaving(true);
    try {
      await apiPost(endpoints.journalEntries, { entry_date: date, status: 'submitted', content: buildPayload() });
      router.back();
    } catch (err) {
      Alert.alert('Could not submit entry', (err as ApiError).message);
    } finally {
      setSaving(false);
    }
  }

  async function onDownloadPdf() {
    setDownloading(true);
    try {
      await downloadAndSharePdf(endpoints.journalEntryPdf(date), `daily-journal-${date}.pdf`);
    } catch (err) {
      Alert.alert('Could not download PDF', (err as ApiError).message);
    } finally {
      setDownloading(false);
    }
  }

  if (loading && !entry) return <LoadingState />;
  if (error && !entry) return <ErrorState message={error.message} onRetry={load} />;
  if (!entry) return null;

  return (
    <View style={{ flex: 1, backgroundColor: colors.gray50 }}>
      <View
        style={{
          paddingTop: 50,
          paddingBottom: 10,
          paddingHorizontal: 20,
          flexDirection: 'row',
          alignItems: 'center',
          gap: 10,
          backgroundColor: colors.white,
          borderBottomWidth: 1,
          borderBottomColor: colors.gray100,
        }}
      >
        <Pressable
          onPress={() => router.back()}
          style={{
            width: 34,
            height: 34,
            borderRadius: 8,
            borderWidth: 1.5,
            borderColor: colors.gray200,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="chevron-back" size={18} color={colors.gray600} />
        </Pressable>
        <Text style={{ fontSize: 15, fontWeight: '700', color: colors.black, flex: 1 }} numberOfLines={1}>
          {entry.day_label} ({date})
        </Text>
        {editable ? (
          <>
            <Pressable
              onPress={saveDraft}
              disabled={saving}
              style={{
                paddingVertical: 8,
                paddingHorizontal: 14,
                borderRadius: 8,
                borderWidth: 1.5,
                borderColor: colors.gray200,
              }}
            >
              <Text style={{ fontSize: 12, fontWeight: '600', color: colors.gray600 }}>Save Draft</Text>
            </Pressable>
            <Pressable
              onPress={confirmSubmit}
              disabled={saving || !canSubmit}
              style={{
                paddingVertical: 8,
                paddingHorizontal: 14,
                borderRadius: 8,
                backgroundColor: canSubmit ? colors.blue600 : colors.gray300,
              }}
            >
              <Text style={{ fontSize: 12, fontWeight: '600', color: 'white' }}>{'✓ Submit'}</Text>
            </Pressable>
          </>
        ) : (
          <Pressable onPress={onDownloadPdf} disabled={downloading} hitSlop={8}>
            {downloading ? (
              <ActivityIndicator size="small" color={colors.blue600} />
            ) : (
              <Ionicons name="download-outline" size={20} color={colors.blue600} />
            )}
          </Pressable>
        )}
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Banner variant={editable ? 'info' : 'warn'}>
          {editable
            ? `${entry.status === 'submitted' ? "You've submitted this entry — it" : 'This entry'} stays editable until your week is compiled (every Monday at 12:00 AM).`
            : LOCKED_REASON_COPY[entry.locked_reason ?? ''] ?? 'This entry is read-only.'}
        </Banner>

        {requiredSections.map((section) => (
          <Card key={section.key} title={section.label}>
            <TextInput
              value={content[section.key] ?? ''}
              onChangeText={(v) => setField(section.key, v)}
              editable={editable}
              placeholder={section.prompt ?? `Describe your ${section.label.toLowerCase()}...`}
              multiline
              textAlignVertical="top"
              style={{
                borderWidth: 1.5,
                borderColor: colors.gray200,
                borderRadius: 10,
                padding: 12,
                minHeight: 140,
                fontSize: 13,
                lineHeight: 19,
                backgroundColor: editable ? colors.white : colors.gray50,
                color: colors.black,
              }}
            />
          </Card>
        ))}

        {optionalKeysShown.map((key) => {
          const section = optionalSections.find((s) => s.key === key);
          if (!section) return null;
          return (
            <Card key={key} title={section.label}>
              <View style={{ flexDirection: 'row', justifyContent: 'flex-end', marginBottom: 6 }}>
                {editable ? (
                  <Pressable onPress={() => removeOptional(key)}>
                    <Text style={{ fontSize: 11, color: colors.redDark, fontWeight: '600' }}>Remove</Text>
                  </Pressable>
                ) : null}
              </View>
              <TextInput
                value={content[key] ?? ''}
                onChangeText={(v) => setField(key, v)}
                editable={editable}
                multiline
                textAlignVertical="top"
                style={{
                  borderWidth: 1.5,
                  borderColor: colors.gray200,
                  borderRadius: 10,
                  padding: 12,
                  minHeight: 100,
                  fontSize: 13,
                  lineHeight: 19,
                  backgroundColor: editable ? colors.white : colors.gray50,
                }}
              />
            </Card>
          );
        })}

        {editable && addableOptional.length > 0 ? (
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginHorizontal: 20, marginTop: 14 }}>
            {addableOptional.map((s) => (
              <Pressable
                key={s.key}
                onPress={() => addOptional(s.key)}
                style={{
                  flexDirection: 'row',
                  alignItems: 'center',
                  gap: 6,
                  paddingHorizontal: 12,
                  paddingVertical: 7,
                  borderRadius: 20,
                  borderWidth: 1.5,
                  borderColor: colors.blue200,
                  backgroundColor: colors.blue50,
                }}
              >
                <Ionicons name="add" size={14} color={colors.blue600} />
                <Text style={{ fontSize: 11.5, fontWeight: '600', color: colors.blue600 }}>{s.label}</Text>
              </Pressable>
            ))}
          </View>
        ) : null}

        {sippSections.length > 0 ? (
          <>
            {editable ? (
              <Pressable
                onPress={() => setSippEnabled((e) => !e)}
                style={{
                  flexDirection: 'row',
                  alignItems: 'center',
                  gap: 10,
                  marginHorizontal: 20,
                  marginTop: 14,
                  paddingHorizontal: 14,
                  paddingVertical: 10,
                  borderRadius: 10,
                  borderWidth: 1.5,
                  borderColor: colors.gray200,
                  backgroundColor: colors.white,
                }}
              >
                <Ionicons
                  name={sippEnabled ? 'checkbox' : 'square-outline'}
                  size={19}
                  color={sippEnabled ? colors.blue500 : colors.gray400}
                />
                <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black, flex: 1 }}>
                  Include SIPP Report (Annex C)
                </Text>
              </Pressable>
            ) : null}

            {sippEnabled ? (
              <Card title="SIPP Report (Annex C)">
                {sippSections.map((section, i) => (
                  <SectionField
                    key={section.key}
                    label={section.label}
                    placeholder={section.prompt ?? '...'}
                    value={content[section.key] ?? ''}
                    onChangeText={(v) => setField(section.key, v)}
                    editable={editable}
                    maxLength={300}
                    last={i === sippSections.length - 1}
                  />
                ))}
              </Card>
            ) : null}
          </>
        ) : null}

        {editable ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', marginHorizontal: 20, marginTop: 14, gap: 10 }}>
            <View style={{ flex: 1, height: 4, backgroundColor: colors.gray100, borderRadius: 999, overflow: 'hidden' }}>
              <View
                style={{
                  width: `${Math.min(100, Math.round((charCount / charLimit) * 100))}%`,
                  height: '100%',
                  backgroundColor: overLimit ? colors.red : colors.blue500,
                }}
              />
            </View>
            <Text style={{ fontSize: 11, fontWeight: '600', color: overLimit ? colors.red : colors.blue600 }}>
              {charCount} / {charLimit} characters
            </Text>
          </View>
        ) : (
          <Pressable
            onPress={onDownloadPdf}
            disabled={downloading}
            style={{
              marginHorizontal: 20,
              marginTop: 16,
              paddingVertical: 12,
              borderRadius: 10,
              borderWidth: 1.5,
              borderColor: colors.blue200,
              alignItems: 'center',
              flexDirection: 'row',
              justifyContent: 'center',
              gap: 8,
            }}
          >
            {downloading ? <ActivityIndicator size="small" color={colors.blue600} /> : <Ionicons name="download-outline" size={16} color={colors.blue600} />}
            <Text style={{ fontSize: 13, fontWeight: '600', color: colors.blue600 }}>Download PDF</Text>
          </Pressable>
        )}
      </ScrollView>
    </View>
  );
}

function SectionField({
  label,
  placeholder,
  value,
  onChangeText,
  editable,
  maxLength,
  last,
}: {
  label: string;
  placeholder: string;
  value: string;
  onChangeText: (v: string) => void;
  editable: boolean;
  maxLength?: number;
  last?: boolean;
}) {
  return (
    <View style={{ marginBottom: last ? 0 : 16 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 6 }}>
        <Text style={{ fontSize: 12, fontWeight: '600', color: colors.gray600 }}>{label}</Text>
        {maxLength ? (
          <Text style={{ fontSize: 10, color: colors.gray400 }}>
            {value.length} / {maxLength}
          </Text>
        ) : null}
      </View>
      <TextInput
        value={value}
        onChangeText={onChangeText}
        editable={editable}
        placeholder={placeholder}
        multiline
        textAlignVertical="top"
        maxLength={maxLength}
        style={{
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 10,
          padding: 12,
          minHeight: 100,
          fontSize: 13,
          lineHeight: 19,
          backgroundColor: editable ? colors.white : colors.gray50,
        }}
      />
    </View>
  );
}

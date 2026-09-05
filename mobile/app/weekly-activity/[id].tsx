import { useEffect, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, Alert, ActivityIndicator } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../../src/components/Banner';
import { Button } from '../../src/components/Button';
import { Card } from '../../src/components/Card';
import { ErrorState, LoadingState } from '../../src/components/ErrorState';
import { OfflineNotice } from '../../src/components/OfflineNotice';
import { useWeeklyActivityLog } from '../../src/hooks/useWeeklyActivityLogs';
import { downloadAndSharePdf, ApiError } from '../../src/services/api';
import { endpoints } from '../../src/services/endpoints';
import { dateRangeLabel } from '../../src/lib/datetime';
import { colors } from '../../src/constants/colors';
import { WeeklyActivityEntry } from '../../src/types/api';
import { showError } from '../../src/services/toast';

type RowDraft = {
  inclusive_date_start: string;
  inclusive_date_end: string;
  activities: string;
  documents_records: string;
  objectives: string;
  supervisor_name: string;
  supervisor_position: string;
};

const EMPTY_ROW: RowDraft = {
  inclusive_date_start: '',
  inclusive_date_end: '',
  activities: '',
  documents_records: '',
  objectives: '',
  supervisor_name: '',
  supervisor_position: '',
};

function toDraft(entry: WeeklyActivityEntry): RowDraft {
  return {
    inclusive_date_start: entry.inclusive_date_start?.slice(0, 10) ?? '',
    inclusive_date_end: entry.inclusive_date_end?.slice(0, 10) ?? '',
    activities: entry.activities ?? '',
    documents_records: entry.documents_records ?? '',
    objectives: entry.objectives ?? '',
    supervisor_name: entry.supervisor_name ?? '',
    supervisor_position: entry.supervisor_position ?? '',
  };
}

/** Empty strings are sent as null so a cleared field actually clears. */
function toPayload(draft: RowDraft) {
  const blankToNull = (v: string) => (v.trim() === '' ? null : v.trim());
  return {
    inclusive_date_start: blankToNull(draft.inclusive_date_start),
    inclusive_date_end: blankToNull(draft.inclusive_date_end),
    activities: blankToNull(draft.activities),
    documents_records: blankToNull(draft.documents_records),
    objectives: blankToNull(draft.objectives),
    supervisor_name: blankToNull(draft.supervisor_name),
    supervisor_position: blankToNull(draft.supervisor_position),
  };
}

export default function WeeklyActivityDetail() {
  const { id: rawId } = useLocalSearchParams<{ id?: string }>();
  const id = Number(rawId);

  const { sheet, loading, error, isOffline, reload, updateSheet, addEntry, updateEntry, deleteEntry } =
    useWeeklyActivityLog(id);

  const [hours, setHours] = useState('');
  const [area, setArea] = useState('');
  const [savingSheet, setSavingSheet] = useState(false);
  const [newRow, setNewRow] = useState<RowDraft>(EMPTY_ROW);
  const [addingRow, setAddingRow] = useState(false);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (!sheet) return;
    setHours(sheet.no_of_hours != null ? String(sheet.no_of_hours) : '');
    setArea(sheet.area_assigned ?? '');
  }, [sheet?.id, sheet?.no_of_hours, sheet?.area_assigned]);

  async function onSaveSheet() {
    setSavingSheet(true);
    const res = await updateSheet({ area_assigned: area.trim() || null, no_of_hours: hours.trim() || null });
    setSavingSheet(false);
    if (!res.ok) showError('Could not save', res.error);
  }

  async function onAddRow() {
    setAddingRow(true);
    const res = await addEntry(toPayload(newRow));
    setAddingRow(false);
    if (res.ok) setNewRow(EMPTY_ROW);
    else showError('Could not add the row', res.error);
  }

  function confirmDelete(entryId: number) {
    Alert.alert('Delete this row?', 'This cannot be undone.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          const res = await deleteEntry(entryId);
          if (!res.ok) showError('Could not delete', res.error);
        },
      },
    ]);
  }

  async function onDownloadPdf() {
    setDownloading(true);
    try {
      await downloadAndSharePdf(endpoints.weeklyActivityLogPdf(id), `weekly-time-log-${id}.pdf`);
    } catch (err) {
      showError('Could not download PDF', (err as ApiError).message);
    } finally {
      setDownloading(false);
    }
  }

  // A malformed :id would otherwise render an empty shell with no explanation.
  if (!Number.isFinite(id) || id <= 0) {
    return <ErrorState message="That log sheet could not be found." onRetry={() => router.back()} />;
  }
  if (loading && !sheet) return <LoadingState />;
  if (error && !sheet) return <ErrorState message={error.message} onRetry={reload} />;
  if (!sheet) return null;

  return (
    <ScrollView
      style={{ flex: 1, backgroundColor: colors.gray50 }}
      contentContainerStyle={{ paddingBottom: 40 }}
      keyboardShouldPersistTaps="handled"
    >
      <View
        style={{
          paddingTop: 50,
          paddingBottom: 12,
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
          {dateRangeLabel(sheet.week_start, sheet.week_end)}
        </Text>
        <Pressable onPress={onDownloadPdf} disabled={downloading} hitSlop={8}>
          {downloading ? (
            <ActivityIndicator size="small" color={colors.blue600} />
          ) : (
            <Ionicons name="download-outline" size={20} color={colors.blue600} />
          )}
        </Pressable>
      </View>

      <OfflineNotice feature="weeklyActivityLog" show={isOffline} />

      <Card title="Form Details">
        {/* Read-only: resolved from the active enrollment, never typed. */}
        <ReadOnly label="Name of Student" value={sheet.header.student_name} />
        <ReadOnly label="Program & Year" value={sheet.header.program_and_year} />
        <ReadOnly label="Faculty Adviser" value={sheet.header.faculty_adviser} />
        <ReadOnly label="Name of Company" value={sheet.header.company_name} />
        <ReadOnly label="Supervisor" value={sheet.header.supervisor_name} />

        <View style={{ height: 8 }} />
        <Field label="Area Assigned" value={area} onChange={setArea} editable={!isOffline} />
        <Field
          label="No. of Hours"
          value={hours}
          onChange={setHours}
          editable={!isOffline}
          keyboardType="numeric"
        />
        {/* Advisory only. A wrong DTR total (a forgotten clock-out, a session
            still awaiting adjustment) must stay correctable before printing,
            so a typed value is never overwritten. */}
        {sheet.dtr_hours != null ? (
          <Text style={{ fontSize: 11, color: colors.gray500, marginTop: -4, marginBottom: 10 }}>
            Your Daily Time Record shows {sheet.dtr_hours} hrs for this period.
          </Text>
        ) : null}

        <Button
          label="Save Form Details"
          variant="secondary"
          fullWidth
          loading={savingSheet}
          disabled={savingSheet || isOffline}
          onPress={onSaveSheet}
        />
      </Card>

      <Text
        style={{
          fontSize: 10,
          fontWeight: '600',
          color: colors.gray400,
          textTransform: 'uppercase',
          letterSpacing: 0.5,
          marginHorizontal: 20,
          marginTop: 20,
        }}
      >
        Activity Rows ({sheet.entries.length})
      </Text>

      {sheet.entries.map((entry, index) => (
        <EntryCard
          key={entry.id}
          index={index + 1}
          entry={entry}
          disabled={isOffline}
          onSave={(payload) => updateEntry(entry.id, payload)}
          onDelete={() => confirmDelete(entry.id)}
        />
      ))}

      <Card title="Add a Row">
        <RowFields draft={newRow} onChange={setNewRow} editable={!isOffline} />
        <Button
          label="Add Row"
          icon="add"
          fullWidth
          loading={addingRow}
          disabled={addingRow || isOffline}
          onPress={onAddRow}
        />
      </Card>

      <Banner variant="info">
        The Supervisor's Signature column is left blank on purpose — it is signed by hand on the printed form.
      </Banner>
    </ScrollView>
  );
}

function EntryCard({
  index,
  entry,
  disabled,
  onSave,
  onDelete,
}: {
  index: number;
  entry: WeeklyActivityEntry;
  disabled: boolean;
  onSave: (payload: ReturnType<typeof toPayload>) => Promise<{ ok: true } | { ok: false; error: string }>;
  onDelete: () => void;
}) {
  const [draft, setDraft] = useState<RowDraft>(toDraft(entry));
  const [saving, setSaving] = useState(false);

  // Re-sync when the server copy changes (e.g. after a sibling row's save
  // triggers a reload), but only when this row is not being edited.
  useEffect(() => {
    setDraft(toDraft(entry));
  }, [entry.id, entry.activities, entry.inclusive_date_start, entry.inclusive_date_end]);

  async function save() {
    setSaving(true);
    const res = await onSave(toPayload(draft));
    setSaving(false);
    if (!res.ok) showError('Could not save the row', res.error);
  }

  return (
    <Card title={`Row ${index}`}>
      <RowFields draft={draft} onChange={setDraft} editable={!disabled} />
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <Button
          label="Save"
          variant="secondary"
          fullWidth
          loading={saving}
          disabled={saving || disabled}
          onPress={save}
        />
        <Button label="Delete" variant="danger" fullWidth disabled={disabled} onPress={onDelete} />
      </View>
    </Card>
  );
}

function RowFields({
  draft,
  onChange,
  editable,
}: {
  draft: RowDraft;
  onChange: (d: RowDraft) => void;
  editable: boolean;
}) {
  const set = (key: keyof RowDraft) => (value: string) => onChange({ ...draft, [key]: value });

  return (
    <>
      <Field
        label="Inclusive Date — Start (YYYY-MM-DD)"
        value={draft.inclusive_date_start}
        onChange={set('inclusive_date_start')}
        editable={editable}
      />
      <Field
        label="Inclusive Date — End (YYYY-MM-DD)"
        value={draft.inclusive_date_end}
        onChange={set('inclusive_date_end')}
        editable={editable}
      />
      <Field label="Activities" value={draft.activities} onChange={set('activities')} editable={editable} multiline />
      <Field
        label="Document / Records"
        value={draft.documents_records}
        onChange={set('documents_records')}
        editable={editable}
        multiline
      />
      <Field label="Objective/s" value={draft.objectives} onChange={set('objectives')} editable={editable} multiline />
      <Field
        label="Supervisor's Name"
        value={draft.supervisor_name}
        onChange={set('supervisor_name')}
        editable={editable}
      />
      <Field
        label="Supervisor's Position"
        value={draft.supervisor_position}
        onChange={set('supervisor_position')}
        editable={editable}
      />
    </>
  );
}

function ReadOnly({ label, value }: { label: string; value: string | null }) {
  return (
    <View style={{ marginBottom: 10 }}>
      <Text style={{ fontSize: 10, color: colors.gray400, textTransform: 'uppercase', letterSpacing: 0.5 }}>
        {label}
      </Text>
      <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black, marginTop: 2 }}>{value ?? '—'}</Text>
    </View>
  );
}

function Field({
  label,
  value,
  onChange,
  editable = true,
  multiline = false,
  keyboardType,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  editable?: boolean;
  multiline?: boolean;
  keyboardType?: 'numeric';
}) {
  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={{ fontSize: 11, fontWeight: '600', color: colors.gray600, marginBottom: 6 }}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        editable={editable}
        multiline={multiline}
        keyboardType={keyboardType}
        placeholderTextColor={colors.gray400}
        autoCapitalize="none"
        style={{
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 10,
          paddingHorizontal: 12,
          paddingVertical: 10,
          fontSize: 13,
          color: editable ? colors.black : colors.gray500,
          backgroundColor: editable ? colors.white : colors.gray100,
          minHeight: multiline ? 70 : undefined,
          textAlignVertical: multiline ? 'top' : 'center',
        }}
      />
    </View>
  );
}

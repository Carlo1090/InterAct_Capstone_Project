import { useEffect, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, ActivityIndicator, Alert } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Button } from '../src/components/Button';
import { InfoSectionTitle, InfoField } from '../src/components/InfoField';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { useStudentInfo } from '../src/hooks/useStudentInfo';
import { downloadAndSharePdf, ApiError } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { InfoSheet, InfoSheetPersonal, InfoSheetAcademic, InfoSheetOjt } from '../src/types/api';
import { colors } from '../src/constants/colors';

const YEAR_LEVELS: { value: string; label: string }[] = [
  { value: '1st-year', label: '1st Year' },
  { value: '2nd-year', label: '2nd Year' },
  { value: '3rd-year', label: '3rd Year' },
  { value: '4th-year', label: '4th Year' },
];

function findMissingRequired(personal: InfoSheetPersonal, academic: InfoSheetAcademic, ojt: InfoSheetOjt): string[] {
  const missing: string[] = [];
  if (!personal.last_name.trim()) missing.push('Family Name');
  if (!personal.first_name.trim()) missing.push('First Name');
  if (!personal.parent_guardian_name.trim()) missing.push("Parent's / Guardian's Name");
  if (!academic.year_level) missing.push('Year Level');
  if (!ojt.company_id) missing.push('Host Company');
  return missing;
}

export default function InfoSheetScreen() {
  const { data, companies, loading, error, reload, save } = useStudentInfo();
  const [editing, setEditing] = useState(false);
  const [personal, setPersonal] = useState<InfoSheetPersonal | null>(null);
  const [academic, setAcademic] = useState<InfoSheetAcademic | null>(null);
  const [ojt, setOjt] = useState<InfoSheetOjt | null>(null);
  const [saving, setSaving] = useState(false);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (data) {
      setPersonal(data.personal_info);
      setAcademic(data.academic_info);
      setOjt(data.ojt_info);
    }
  }, [data]);

  if (loading && !data) return <LoadingState />;
  if (error && !data) return <ErrorState message={error.message} onRetry={reload} />;
  if (!data || !personal || !academic || !ojt) return null;

  function updatePersonal<K extends keyof InfoSheetPersonal>(field: K, value: InfoSheetPersonal[K]) {
    setPersonal((prev) => (prev ? { ...prev, [field]: value } : prev));
  }

  function updateOjt<K extends keyof InfoSheetOjt>(field: K, value: InfoSheetOjt[K]) {
    setOjt((prev) => (prev ? { ...prev, [field]: value } : prev));
  }

  function selectCompany(companyId: number, name: string) {
    setOjt((prev) => (prev ? { ...prev, company_id: companyId, host_company: name } : prev));
  }

  function selectYearLevel(value: string) {
    setAcademic((prev) => (prev ? { ...prev, year_level: value } : prev));
  }

  async function persist(status: 'draft' | 'submitted') {
    setSaving(true);
    const result = await save({ status, personal_info: personal!, academic_info: academic!, ojt_info: ojt!, emergency_contact: data!.emergency_contact ?? undefined });
    setSaving(false);
    if (result.ok) {
      setEditing(false);
    } else {
      Alert.alert('Could not save', result.error);
    }
  }

  async function onSaveChanges() {
    await persist(data!.submission_status === null || data!.submission_status === 'draft' || data!.submission_status === 'rejected' ? 'draft' : 'submitted');
  }

  async function onSubmit() {
    const missing = findMissingRequired(personal!, academic!, ojt!);
    if (missing.length > 0) {
      Alert.alert('Missing required fields', `Please complete: ${missing.join(', ')}`);
      return;
    }
    await persist('submitted');
  }

  function onCancel() {
    setPersonal(data!.personal_info);
    setAcademic(data!.academic_info);
    setOjt(data!.ojt_info);
    setEditing(false);
  }

  function confirmDiscard() {
    Alert.alert('Discard changes?', 'Your edits will not be saved.', [
      { text: 'Keep Editing', style: 'cancel' },
      { text: 'Discard', style: 'destructive', onPress: onCancel },
    ]);
  }

  async function onDownloadPdf() {
    setDownloading(true);
    try {
      await downloadAndSharePdf(endpoints.infoSheetPdf, 'student-information-sheet.pdf');
    } catch (err) {
      Alert.alert('Could not download PDF', (err as ApiError).message);
    } finally {
      setDownloading(false);
    }
  }

  const canSubmit = data.submission_status === null || data.submission_status === 'draft' || data.submission_status === 'rejected';
  // Program/Department/Coordinator are always locked (server-derived from
  // the assigned batch). Year Level and Host Company lock only once
  // approved, matching the real "Program & Year and the assigned Company
  // stay locked" post-approval rule.
  const fieldsLocked = data.submission_status === 'approved';
  const yearLevelLabel = YEAR_LEVELS.find((y) => y.value === academic.year_level)?.label ?? 'Not yet set';

  return (
    <ScrollView style={{ flex: 1, backgroundColor: colors.gray50 }} contentContainerStyle={{ paddingBottom: 24 }}>
      <View
        style={{
          paddingTop: 50,
          paddingHorizontal: 20,
          flexDirection: 'row',
          alignItems: 'center',
          justifyContent: 'space-between',
        }}
      >
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          <Pressable onPress={() => (editing ? confirmDiscard() : router.back())}>
            <Ionicons name="chevron-back" size={22} color={colors.black} />
          </Pressable>
          <Text style={{ fontSize: 20, fontWeight: '700', color: colors.black }}>Student Info Sheet</Text>
        </View>
        {!editing && (
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 16 }}>
            {data.submission_status ? (
              <Pressable onPress={onDownloadPdf} disabled={downloading} hitSlop={8}>
                {downloading ? (
                  <ActivityIndicator size="small" color={colors.blue600} />
                ) : (
                  <Ionicons name="download-outline" size={20} color={colors.blue600} />
                )}
              </Pressable>
            ) : null}
            <Pressable onPress={() => setEditing(true)}>
              <Text style={{ fontSize: 12, fontWeight: '600', color: colors.blue500 }}>Edit</Text>
            </Pressable>
          </View>
        )}
      </View>

      <StatusBanner status={data.submission_status} rejectionReason={data.rejection_reason} editing={editing} />

      <InfoSectionTitle>I. Personal Information</InfoSectionTitle>
      <EditableField label="Last Name" value={personal.last_name} editing={editing} onChangeText={(v) => updatePersonal('last_name', v)} />
      <EditableField label="First Name" value={personal.first_name} editing={editing} onChangeText={(v) => updatePersonal('first_name', v)} />
      <EditableField label="Middle Name" value={personal.middle_name ?? ''} editing={editing} onChangeText={(v) => updatePersonal('middle_name', v)} />
      <EditableField label="Date of Birth" value={personal.date_of_birth ?? ''} editing={editing} onChangeText={(v) => updatePersonal('date_of_birth', v)} />
      <EditableField label="Sex" value={personal.sex ?? ''} editing={editing} onChangeText={(v) => updatePersonal('sex', v)} />
      <EditableField label="Home Address" value={personal.home_address ?? ''} editing={editing} onChangeText={(v) => updatePersonal('home_address', v)} />
      <EditableField label="Contact Number" value={personal.contact_number ?? ''} editing={editing} onChangeText={(v) => updatePersonal('contact_number', v)} keyboardType="phone-pad" />
      <EditableField label="Email Address" value={personal.email ?? ''} editing={editing} onChangeText={(v) => updatePersonal('email', v)} keyboardType="email-address" />
      <EditableField label="Parent's / Guardian's Name *" value={personal.parent_guardian_name} editing={editing} onChangeText={(v) => updatePersonal('parent_guardian_name', v)} />
      <EditableField label="Parent's / Guardian's Contact" value={personal.parent_guardian_contact ?? ''} editing={editing} onChangeText={(v) => updatePersonal('parent_guardian_contact', v)} keyboardType="phone-pad" />

      <InfoSectionTitle>II. Academic Information</InfoSectionTitle>
      <InfoField label="Program / Course (assigned by your coordinator)" value={academic.program_course || 'Not yet set'} />
      <PickerField
        label="Year Level *"
        value={yearLevelLabel}
        options={YEAR_LEVELS.map((y) => y.label)}
        editable={editing && !fieldsLocked}
        onSelect={(label) => selectYearLevel(YEAR_LEVELS.find((y) => y.label === label)?.value ?? YEAR_LEVELS[0].value)}
      />
      <InfoField label="Department (assigned by your coordinator)" value={academic.department || 'Not yet set'} />
      <InfoField label="OJT Coordinator (assigned by your coordinator)" value={academic.internship_coordinator || 'Not yet assigned'} />

      <InfoSectionTitle>III. OJT / Internship Information</InfoSectionTitle>
      <PickerField
        label="Host Company *"
        value={ojt.host_company || 'Select a company'}
        options={companies.map((c) => c.name)}
        editable={editing && !fieldsLocked}
        onSelect={(name) => {
          const company = companies.find((c) => c.name === name);
          if (company) selectCompany(company.id, company.name);
        }}
      />
      <EditableField label="Company Address" value={ojt.company_address ?? ''} editing={editing} onChangeText={(v) => updateOjt('company_address', v)} />
      <EditableField label="Company Signatory (for MOA)" value={ojt.company_signatory_moa ?? ''} editing={editing} onChangeText={(v) => updateOjt('company_signatory_moa', v)} />
      <EditableField label="Office Designation" value={ojt.office_designation ?? ''} editing={editing} onChangeText={(v) => updateOjt('office_designation', v)} />
      <EditableField label="Supervisor Name" value={ojt.supervisor_name ?? ''} editing={editing} onChangeText={(v) => updateOjt('supervisor_name', v)} />
      <EditableField label="Supervisor Contact" value={ojt.supervisor_contact ?? ''} editing={editing} onChangeText={(v) => updateOjt('supervisor_contact', v)} keyboardType="phone-pad" />
      <EditableField label="Area Assigned" value={ojt.area_assigned ?? ''} editing={editing} onChangeText={(v) => updateOjt('area_assigned', v)} />
      <EditableField label="Duty Schedule" value={ojt.intern_duty_schedule ?? ''} editing={editing} onChangeText={(v) => updateOjt('intern_duty_schedule', v)} />
      <EditableField label="OJT Start Date" value={ojt.ojt_start_date ?? ''} editing={editing} onChangeText={(v) => updateOjt('ojt_start_date', v)} />
      <EditableField label="OJT End Date" value={ojt.ojt_end_date ?? ''} editing={editing} onChangeText={(v) => updateOjt('ojt_end_date', v)} />

      {editing && (
        <View style={{ paddingHorizontal: 20, paddingTop: 16, gap: 10 }}>
          {canSubmit ? (
            <Button label="Submit" icon="checkmark" loading={saving} disabled={saving} onPress={onSubmit} />
          ) : null}
          <Button
            label={canSubmit ? 'Save Draft' : 'Save Changes'}
            variant="secondary"
            loading={saving}
            disabled={saving}
            onPress={onSaveChanges}
          />
          <Button label="Cancel" variant="secondary" onPress={confirmDiscard} />
        </View>
      )}
    </ScrollView>
  );
}

function StatusBanner({
  status,
  rejectionReason,
  editing,
}: {
  status: InfoSheet['submission_status'];
  rejectionReason: string | null;
  editing: boolean;
}) {
  if (editing) {
    return <Banner variant="info">You are editing your information sheet. Save your changes, or go back to discard them.</Banner>;
  }
  if (status === 'rejected') {
    return <Banner variant="warn">Returned for changes: {rejectionReason || 'Please review and resubmit.'}</Banner>;
  }
  if (status === 'submitted') {
    return <Banner variant="info">Submitted — awaiting your coordinator's review.</Banner>;
  }
  if (status === 'approved') {
    return (
      <Banner variant="info">
        Approved. Program, Year Level, and Host Company are locked — everything else stays editable.
      </Banner>
    );
  }
  return (
    <Banner variant="warn">
      This is a draft. Complete the required fields (marked *) and submit it for your coordinator to review.
    </Banner>
  );
}

function EditableField({
  label,
  value,
  editing,
  onChangeText,
  keyboardType,
}: {
  label: string;
  value: string;
  editing: boolean;
  onChangeText: (v: string) => void;
  keyboardType?: 'default' | 'email-address' | 'phone-pad';
}) {
  return (
    <View style={{ paddingVertical: 10, paddingHorizontal: 20, borderBottomWidth: 1, borderBottomColor: colors.gray100 }}>
      <Text
        style={{
          fontSize: 10,
          color: colors.gray400,
          fontWeight: '500',
          textTransform: 'uppercase',
          letterSpacing: 0.5,
          marginBottom: editing ? 6 : 3,
        }}
      >
        {label}
      </Text>
      {editing ? (
        <TextInput
          value={value}
          onChangeText={onChangeText}
          keyboardType={keyboardType ?? 'default'}
          placeholder={`Enter ${label.toLowerCase()}`}
          style={{
            borderWidth: 1.5,
            borderColor: colors.blue200,
            borderRadius: 8,
            paddingHorizontal: 10,
            paddingVertical: 8,
            fontSize: 13.5,
            color: colors.black,
            backgroundColor: colors.blue50,
          }}
        />
      ) : (
        <Text style={{ fontSize: 14, color: value ? colors.black : colors.gray400, fontWeight: '500' }}>
          {value || 'Not yet set'}
        </Text>
      )}
    </View>
  );
}

// Tap-to-cycle picker for a constrained set of choices (Year Level, Host
// Company) — real backend requires these as dropdowns, not free text.
function PickerField({
  label,
  value,
  options,
  editable,
  onSelect,
}: {
  label: string;
  value: string;
  options: string[];
  editable: boolean;
  onSelect: (v: string) => void;
}) {
  return (
    <View style={{ paddingVertical: 10, paddingHorizontal: 20, borderBottomWidth: 1, borderBottomColor: colors.gray100 }}>
      <Text
        style={{
          fontSize: 10,
          color: colors.gray400,
          fontWeight: '500',
          textTransform: 'uppercase',
          letterSpacing: 0.5,
          marginBottom: editable ? 6 : 3,
        }}
      >
        {label}
      </Text>
      {editable ? (
        <Pressable
          onPress={() => {
            if (options.length === 0) return;
            const i = options.indexOf(value);
            onSelect(options[(i + 1) % options.length] ?? options[0]);
          }}
          style={{
            flexDirection: 'row',
            justifyContent: 'space-between',
            alignItems: 'center',
            borderWidth: 1.5,
            borderColor: colors.blue200,
            borderRadius: 8,
            paddingHorizontal: 10,
            paddingVertical: 8,
            backgroundColor: colors.blue50,
          }}
        >
          <Text style={{ fontSize: 13.5, color: colors.black }}>{value || options[0] || 'None available'}</Text>
          <Text style={{ color: colors.gray400 }}>{'▾'}</Text>
        </Pressable>
      ) : (
        <Text style={{ fontSize: 14, color: value ? colors.black : colors.gray400, fontWeight: '500' }}>
          {value || 'Not yet set'}
        </Text>
      )}
    </View>
  );
}

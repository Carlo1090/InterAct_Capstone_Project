import { useEffect, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, ActivityIndicator, Alert } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { InfoSectionTitle, InfoField } from '../src/components/InfoField';
import { useStudentInfo } from '../src/hooks/useStudentInfo';
import { StudentInfo, mockCompanies } from '../src/services/mock/studentInfo';
import { saveStudentInfo } from '../src/services/localData';
import { api } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { colors } from '../src/constants/colors';

const YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

function findMissingRequired(info: StudentInfo): string[] {
  const missing: string[] = [];
  if (!info.personal.lastName.trim()) missing.push('Last Name');
  if (!info.personal.firstName.trim()) missing.push('First Name');
  if (!info.personal.parentGuardianName.trim()) missing.push("Parent's/Guardian's Name");
  if (!YEAR_LEVELS.includes(info.academic.yearLevel)) missing.push('Year Level');
  if (!mockCompanies.some((c) => c.name === info.ojt.hostCompany)) missing.push('Host Company');
  return missing;
}

export default function InfoSheet() {
  const { data, loading, storageEmail, reload } = useStudentInfo();
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<StudentInfo | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (data) setDraft(data);
  }, [data]);

  if (loading || !draft) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.blue600} />
      </View>
    );
  }

  function update<S extends 'personal' | 'academic' | 'ojt'>(section: S, field: keyof StudentInfo[S], value: string) {
    setDraft((prev) => (prev ? { ...prev, [section]: { ...prev[section], [field]: value } } : prev));
  }

  function selectCompany(name: string) {
    const company = mockCompanies.find((c) => c.name === name);
    setDraft((prev) =>
      prev ? { ...prev, ojt: { ...prev.ojt, hostCompany: name, companyAddress: company?.address ?? prev.ojt.companyAddress } } : prev
    );
  }

  async function persist(next: StudentInfo) {
    setSaving(true);
    try {
      await api.patch(endpoints.studentInfoSheet, next);
    } catch {
      // Real backend unreachable — persist locally so edits actually stick.
      if (storageEmail) {
        await saveStudentInfo(storageEmail, next);
      }
    } finally {
      setSaving(false);
      setEditing(false);
      await reload();
    }
  }

  // A routine save never changes submission_status — matching the real
  // backend, which never lets an ordinary edit re-trip an approved sheet
  // back to draft/submitted, or a submitted sheet's queue position.
  async function onSaveChanges() {
    if (draft) await persist(draft);
  }

  async function onSubmit() {
    if (!draft) return;
    const missing = findMissingRequired(draft);
    if (missing.length > 0) {
      Alert.alert('Missing required fields', `Please complete: ${missing.join(', ')}`);
      return;
    }
    await persist({ ...draft, submissionStatus: 'submitted', rejectionReason: undefined });
  }

  function onCancel() {
    setDraft(data);
    setEditing(false);
  }

  function confirmDiscard() {
    Alert.alert('Discard changes?', 'Your edits will not be saved.', [
      { text: 'Keep Editing', style: 'cancel' },
      { text: 'Discard', style: 'destructive', onPress: onCancel },
    ]);
  }

  const canSubmit = draft.submissionStatus === 'draft' || draft.submissionStatus === 'rejected';
  // Program/Department/Coordinator are always locked (never student-typed —
  // the real backend derives them from the assigned batch). Year Level and
  // Host Company lock only once approved, matching the real "Program & Year
  // and the assigned Company stay locked" post-approval rule.
  const fieldsLocked = draft.submissionStatus === 'approved';

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
          <Pressable onPress={() => setEditing(true)}>
            <Text style={{ fontSize: 12, fontWeight: '600', color: colors.blue500 }}>Edit</Text>
          </Pressable>
        )}
      </View>

      <StatusBanner status={draft.submissionStatus} rejectionReason={draft.rejectionReason} editing={editing} />

      <InfoSectionTitle>I. Personal Information</InfoSectionTitle>
      <EditableField label="Last Name" value={draft.personal.lastName} editing={editing} onChangeText={(v) => update('personal', 'lastName', v)} />
      <EditableField label="First Name" value={draft.personal.firstName} editing={editing} onChangeText={(v) => update('personal', 'firstName', v)} />
      <EditableField label="Middle Name" value={draft.personal.middleName} editing={editing} onChangeText={(v) => update('personal', 'middleName', v)} />
      <EditableField label="Student ID" value={draft.personal.studentId} editing={editing} onChangeText={(v) => update('personal', 'studentId', v)} />
      <EditableField label="Date of Birth" value={draft.personal.dob} editing={editing} onChangeText={(v) => update('personal', 'dob', v)} />
      <EditableField label="Sex" value={draft.personal.sex} editing={editing} onChangeText={(v) => update('personal', 'sex', v)} />
      <EditableField label="Home Address" value={draft.personal.address} editing={editing} onChangeText={(v) => update('personal', 'address', v)} />
      <EditableField label="Contact Number" value={draft.personal.contact} editing={editing} onChangeText={(v) => update('personal', 'contact', v)} keyboardType="phone-pad" />
      <EditableField label="Email Address" value={draft.personal.email} editing={editing} onChangeText={(v) => update('personal', 'email', v)} keyboardType="email-address" />
      <EditableField label="Parent's / Guardian's Name *" value={draft.personal.parentGuardianName} editing={editing} onChangeText={(v) => update('personal', 'parentGuardianName', v)} />
      <EditableField label="Parent's / Guardian's Contact" value={draft.personal.parentGuardianContact} editing={editing} onChangeText={(v) => update('personal', 'parentGuardianContact', v)} keyboardType="phone-pad" />

      <InfoSectionTitle>II. Academic Information</InfoSectionTitle>
      <InfoField label="Program / Course (assigned by your coordinator)" value={draft.academic.program || 'Not yet set'} />
      <PickerField
        label="Year Level"
        value={draft.academic.yearLevel}
        options={YEAR_LEVELS}
        editable={editing && !fieldsLocked}
        onSelect={(v) => update('academic', 'yearLevel', v)}
      />
      <InfoField label="Department (assigned by your coordinator)" value={draft.academic.department || 'Not yet set'} />
      <InfoField label="OJT Coordinator (assigned by your coordinator)" value={draft.academic.coordinator || 'Not yet assigned'} />

      <InfoSectionTitle>III. OJT / Internship Information</InfoSectionTitle>
      <PickerField
        label="Host Company"
        value={draft.ojt.hostCompany}
        options={mockCompanies.map((c) => c.name)}
        editable={editing && !fieldsLocked}
        onSelect={selectCompany}
      />
      <EditableField label="Company Address" value={draft.ojt.companyAddress} editing={editing} onChangeText={(v) => update('ojt', 'companyAddress', v)} />
      <EditableField label="Supervisor Name" value={draft.ojt.supervisorName} editing={editing} onChangeText={(v) => update('ojt', 'supervisorName', v)} />
      <EditableField label="Supervisor Email" value={draft.ojt.supervisorEmail} editing={editing} onChangeText={(v) => update('ojt', 'supervisorEmail', v)} keyboardType="email-address" />
      <EditableField label="OJT Start Date" value={draft.ojt.startDate} editing={editing} onChangeText={(v) => update('ojt', 'startDate', v)} />
      <EditableField label="OJT End Date" value={draft.ojt.endDate} editing={editing} onChangeText={(v) => update('ojt', 'endDate', v)} />
      <EditableField label="Division Assigned" value={draft.ojt.division} editing={editing} onChangeText={(v) => update('ojt', 'division', v)} />

      {editing && (
        <View style={{ paddingHorizontal: 20, paddingTop: 16, gap: 10 }}>
          {canSubmit ? (
            <Pressable
              onPress={onSubmit}
              disabled={saving}
              style={{ paddingVertical: 12, alignItems: 'center', borderRadius: 10, backgroundColor: colors.blue600 }}
            >
              {saving ? <ActivityIndicator size="small" color="white" /> : <Text style={{ fontSize: 13, fontWeight: '600', color: 'white' }}>Submit</Text>}
            </Pressable>
          ) : null}
          <Pressable
            onPress={onSaveChanges}
            disabled={saving}
            style={{ paddingVertical: 12, alignItems: 'center', borderRadius: 10, borderWidth: 1.5, borderColor: colors.blue200 }}
          >
            {saving ? (
              <ActivityIndicator size="small" color={colors.blue600} />
            ) : (
              <Text style={{ fontSize: 13, fontWeight: '600', color: colors.blue600 }}>{canSubmit ? 'Save Draft' : 'Save Changes'}</Text>
            )}
          </Pressable>
          <Pressable
            onPress={confirmDiscard}
            style={{ paddingVertical: 12, alignItems: 'center', borderRadius: 10, borderWidth: 1.5, borderColor: colors.gray200 }}
          >
            <Text style={{ fontSize: 13, fontWeight: '600', color: colors.gray600 }}>Cancel</Text>
          </Pressable>
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
  status: StudentInfo['submissionStatus'];
  rejectionReason?: string;
  editing: boolean;
}) {
  if (editing) {
    return (
      <Banner variant="info">
        You are editing your SIPP information. Save your changes, or go back to discard them.
      </Banner>
    );
  }
  if (status === 'rejected') {
    return (
      <Banner variant="warn">
        Returned for changes: {rejectionReason || 'Please review and resubmit.'}
      </Banner>
    );
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
          <Text style={{ fontSize: 13.5, color: colors.black }}>{value || options[0]}</Text>
          <Text style={{ color: colors.gray400 }}>▾</Text>
        </Pressable>
      ) : (
        <Text style={{ fontSize: 14, color: value ? colors.black : colors.gray400, fontWeight: '500' }}>
          {value || 'Not yet set'}
        </Text>
      )}
    </View>
  );
}

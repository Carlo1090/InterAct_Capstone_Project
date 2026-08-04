import { useState, useMemo, useEffect } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, ActivityIndicator } from 'react-native';
import { router, useLocalSearchParams } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Card } from '../src/components/Card';
import { colors } from '../src/constants/colors';
import { api } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { getAccountKind } from '../src/services/accountKind';
import { getCurrentLocalProfile } from '../src/services/localAccounts';
import {
  addEntry,
  saveDraft,
  getDraft,
  deleteDraft,
  getEntryById,
  updateEntry,
  JournalSections,
} from '../src/services/localData';

const DEMO_SEED_ACCOMPLISHMENT =
  "Today, I was assigned to work on the UI component library for the InternTrack project. My tasks included designing and testing reusable components such as data tables, modal dialogs, badge indicators, and form elements following the established design system specifications.\n\nI collaborated with senior developer Engr. Beltran to review the component naming conventions and ensure consistency across all pages. We identified three accessibility issues in the form elements and filed them as tasks for tomorrow's sprint.\n\nIn the afternoon, I worked on integrating the Google Fonts API for the typography system and validated the responsive behavior of the sidebar layout across different screen sizes.";

// Matches the real backend exactly: a fixed 1500-character cap on the whole
// entry's combined content (not a word count, and there is no minimum), plus
// a 300-character cap on each individual SIPP field.
const TOTAL_CHAR_LIMIT = 1500;
const SIPP_FIELD_LIMIT = 300;

function todayISO() {
  return new Date().toISOString().slice(0, 10);
}

function dateLabelFor(iso: string) {
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
}

/** Flattens whichever fields are filled into one readable block of text —
 * this is what gets stored in `body`, so anything that just needs a
 * plain-text summary (Dashboard activity feed, etc.) doesn't need to know
 * about the section structure at all. */
function flattenSections(sections: JournalSections): { body: string; charCount: number } {
  const parts: string[] = [];
  if (sections.dailyAccomplishment.trim()) parts.push(`Daily Accomplishment\n${sections.dailyAccomplishment.trim()}`);
  if (sections.sipp && (sections.sipp.issues || sections.sipp.solutions || sections.sipp.recommendations)) {
    const sippParts: string[] = [];
    if (sections.sipp.issues?.trim()) sippParts.push(`Issues and Concerns Encountered\n${sections.sipp.issues.trim()}`);
    if (sections.sipp.solutions?.trim()) sippParts.push(`Solutions\n${sections.sipp.solutions.trim()}`);
    if (sections.sipp.recommendations?.trim())
      sippParts.push(`Recommendations\n${sections.sipp.recommendations.trim()}`);
    parts.push(`SIPP Report\n\n${sippParts.join('\n\n')}`);
  }
  const body = parts.join('\n\n');

  // The real cap is on the SUM of every field's own characters, not the
  // flattened display string (which adds headings/newlines the backend never
  // counts) — mirror that exactly so the on-screen counter matches what the
  // server would actually enforce.
  const charCount =
    sections.dailyAccomplishment.length +
    (sections.sipp?.issues?.length ?? 0) +
    (sections.sipp?.solutions?.length ?? 0) +
    (sections.sipp?.recommendations?.length ?? 0);

  return { body, charCount };
}

export default function Write() {
  const { draftId, entryId } = useLocalSearchParams<{ draftId?: string; entryId?: string }>();
  const [date, setDate] = useState(todayISO());
  const [title, setTitle] = useState('');
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(!!draftId || !!entryId);
  const [localEmail, setLocalEmail] = useState<string | null>(null);

  const [sippEnabled, setSippEnabled] = useState(false);
  const [dailyAccomplishment, setDailyAccomplishment] = useState('');
  const [sippTexts, setSippTexts] = useState({ issues: '', solutions: '', recommendations: '' });

  function loadSections(sections?: JournalSections) {
    if (!sections) return;
    setDailyAccomplishment(sections.dailyAccomplishment ?? '');
    setSippEnabled(!!(sections.sipp && (sections.sipp.issues || sections.sipp.solutions || sections.sipp.recommendations)));
    setSippTexts({
      issues: sections.sipp?.issues ?? '',
      solutions: sections.sipp?.solutions ?? '',
      recommendations: sections.sipp?.recommendations ?? '',
    });
  }

  useEffect(() => {
    (async () => {
      const kind = await getAccountKind();

      if (kind === 'demo') {
        // Demo account keeps its rich seeded example so the presentation
        // screens still look populated — it's never persisted anywhere.
        setTitle('UI Component Design & Testing');
        setDailyAccomplishment(DEMO_SEED_ACCOMPLISHMENT);
        setLoading(false);
        return;
      }

      if (kind === 'new') {
        const profile = await getCurrentLocalProfile();
        const email = profile?.email ?? null;
        setLocalEmail(email);

        if (draftId && email) {
          const draft = await getDraft(email, draftId);
          if (draft) {
            setDate(draft.dateISO);
            setTitle(draft.title);
            loadSections(draft.sections);
          }
        }

        if (entryId && email) {
          const existing = await getEntryById(email, entryId);
          if (existing) {
            setDate(existing.dateISO);
            setTitle(existing.title);
            loadSections(existing.sections);
          }
        }
      }
      setLoading(false);
    })();
  }, [draftId, entryId]);

  const sections: JournalSections = useMemo(
    () => ({
      dailyAccomplishment,
      sipp: sippEnabled
        ? { issues: sippTexts.issues, solutions: sippTexts.solutions, recommendations: sippTexts.recommendations }
        : undefined,
    }),
    [dailyAccomplishment, sippEnabled, sippTexts]
  );

  const { body, charCount } = useMemo(() => flattenSections(sections), [sections]);
  const overLimit = charCount > TOTAL_CHAR_LIMIT;
  const hasAccomplishment = dailyAccomplishment.trim().length > 0;
  const canSubmit = hasAccomplishment && !overLimit;

  async function saveDraftAndExit() {
    setSaving(true);
    try {
      await api.post(endpoints.journalDraft, { date, title, body, sections });
    } catch {
      if (localEmail) {
        await saveDraft(localEmail, draftId ?? null, { dateISO: date, title: title.trim(), body, charCount, sections });
      }
    } finally {
      setSaving(false);
      router.back();
    }
  }

  async function submitEntry() {
    if (!canSubmit) return;
    setSaving(true);
    try {
      if (entryId) {
        await api.put(`${endpoints.journalEntries}/${entryId}`, { date, title, body, sections, status: 'submitted' });
      } else {
        await api.post(endpoints.journalEntries, { date, title, body, sections, status: 'submitted' });
      }
    } catch {
      if (localEmail) {
        if (entryId) {
          await updateEntry(localEmail, entryId, { dateISO: date, title: title.trim(), body, charCount, sections });
        } else {
          await addEntry(localEmail, { dateISO: date, title: title.trim(), body, charCount, sections });
        }
        if (draftId) {
          await deleteDraft(localEmail, draftId);
        }
      }
    } finally {
      setSaving(false);
      router.back();
    }
  }

  if (loading) {
    return (
      <View style={{ flex: 1, backgroundColor: colors.gray50, alignItems: 'center', justifyContent: 'center' }}>
        <ActivityIndicator color={colors.blue600} />
      </View>
    );
  }

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
        <Text style={{ fontSize: 15, fontWeight: '700', color: colors.black, flex: 1 }}>
          {draftId ? 'Edit Draft' : entryId ? 'Edit Journal Entry' : 'Write Daily Journal'}
        </Text>
        {entryId ? null : (
          <Pressable
            onPress={saveDraftAndExit}
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
        )}
        <Pressable
          onPress={submitEntry}
          disabled={saving || !canSubmit}
          style={{
            paddingVertical: 8,
            paddingHorizontal: 14,
            borderRadius: 8,
            backgroundColor: canSubmit ? colors.blue600 : colors.gray300,
          }}
        >
          <Text style={{ fontSize: 12, fontWeight: '600', color: 'white' }}>
            {entryId ? '✓ Save Changes' : '✓ Submit'}
          </Text>
        </Pressable>
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Banner variant={entryId || draftId ? 'info' : 'warn'}>
          {entryId
            ? `You're editing your entry for ${dateLabelFor(date)}. Fix any mistakes and save your changes.`
            : draftId
            ? `You're continuing a draft saved for ${dateLabelFor(date)}.`
            : `Today is ${dateLabelFor(date)}. This entry stays editable until your week is compiled (every Monday at 12:00 AM).`}
        </Banner>

        <Card title="Entry Details">
          <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray600, marginBottom: 6 }}>Date & Day</Text>
          <View
            style={{
              borderWidth: 1.5,
              borderColor: colors.gray200,
              borderRadius: 10,
              padding: 10,
              marginBottom: 12,
              backgroundColor: colors.gray50,
            }}
          >
            <Text style={{ fontSize: 13, color: colors.gray600 }}>{dateLabelFor(date)}</Text>
          </View>
          <Text style={{ fontSize: 10, fontWeight: '600', color: colors.gray600, marginBottom: 6 }}>Title</Text>
          <TextInput
            value={title}
            onChangeText={setTitle}
            placeholder="e.g. Reviewed onboarding documentation"
            style={{ borderWidth: 1.5, borderColor: colors.gray200, borderRadius: 10, padding: 10, fontSize: 13 }}
          />
        </Card>

        <Card title="Daily Accomplishment">
          <TextInput
            value={dailyAccomplishment}
            onChangeText={setDailyAccomplishment}
            placeholder="Describe what you worked on today..."
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
            }}
          />
        </Card>

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

        {sippEnabled ? (
          <Card title="SIPP Report (Annex C)">
            <SectionField
              label="Issues and Concerns Encountered"
              placeholder="Any issues or concerns encountered..."
              value={sippTexts.issues}
              onChangeText={(v) => setSippTexts((t) => ({ ...t, issues: v }))}
              maxLength={SIPP_FIELD_LIMIT}
            />
            <SectionField
              label="Solutions"
              placeholder="How were they addressed..."
              value={sippTexts.solutions}
              onChangeText={(v) => setSippTexts((t) => ({ ...t, solutions: v }))}
              maxLength={SIPP_FIELD_LIMIT}
            />
            <SectionField
              label="Recommendations"
              placeholder="Any recommendations..."
              value={sippTexts.recommendations}
              onChangeText={(v) => setSippTexts((t) => ({ ...t, recommendations: v }))}
              maxLength={SIPP_FIELD_LIMIT}
              last
            />
          </Card>
        ) : null}

        <View style={{ flexDirection: 'row', alignItems: 'center', marginHorizontal: 20, marginTop: 14, gap: 10 }}>
          <View style={{ flex: 1, height: 4, backgroundColor: colors.gray100, borderRadius: 999, overflow: 'hidden' }}>
            <View
              style={{
                width: `${Math.min(100, Math.round((charCount / TOTAL_CHAR_LIMIT) * 100))}%`,
                height: '100%',
                backgroundColor: overLimit ? colors.red : colors.blue500,
              }}
            />
          </View>
          <Text style={{ fontSize: 11, fontWeight: '600', color: overLimit ? colors.red : colors.blue600 }}>
            {charCount} / {TOTAL_CHAR_LIMIT} characters
          </Text>
        </View>

        <View style={{ marginHorizontal: 20, marginTop: 14 }}>
          <View style={{ backgroundColor: colors.white, borderWidth: 1.5, borderColor: colors.gray200, borderRadius: 12, padding: 14 }}>
            <Text style={{ fontSize: 12, fontWeight: '700', color: colors.black, marginBottom: 10 }}>Checklist</Text>
            <ChecklistRow label="Title provided" checked={!!title} />
            <ChecklistRow label="Daily Accomplishment written" checked={hasAccomplishment} />
            <ChecklistRow label="Within the 1,500-character limit" checked={!overLimit} />
          </View>
        </View>

        <Card title="Guidelines" style={{ marginBottom: 8 }}>
          {[
            'Every entry needs a Daily Accomplishment',
            'Up to 1,500 characters total per entry — no minimum',
            'Describe specific tasks completed',
            'Note any challenges encountered',
            'Editable until your week is compiled (Monday 12:00 AM)',
          ].map((g, i) => (
            <Text key={i} style={{ fontSize: 12, color: colors.gray600, lineHeight: 18 }}>
              {'· '}
              {g}
            </Text>
          ))}
        </Card>
      </ScrollView>
    </View>
  );
}

function SectionField({
  label,
  placeholder,
  value,
  onChangeText,
  maxLength,
  last,
}: {
  label: string;
  placeholder: string;
  value: string;
  onChangeText: (v: string) => void;
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
        }}
      />
    </View>
  );
}

function ChecklistRow({ label, checked }: { label: string; checked: boolean }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, marginBottom: 8 }}>
      <Ionicons
        name={checked ? 'checkbox' : 'square-outline'}
        size={15}
        color={checked ? colors.blue500 : colors.gray400}
      />
      <Text style={{ fontSize: 11.5, color: colors.gray600 }}>{label}</Text>
    </View>
  );
}

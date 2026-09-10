import { useEffect, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, Alert, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Button } from '../src/components/Button';
import { Card } from '../src/components/Card';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { OfflineNotice } from '../src/components/OfflineNotice';
import { useExitInterview } from '../src/hooks/useExitInterview';
import { downloadAndSharePdf, ApiError } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { todayISO } from '../src/lib/datetime';
import { colors } from '../src/constants/colors';
import { showError } from '../src/services/toast';

/**
 * Question text copied verbatim from web's StudentExitInterviewPage so the
 * two clients cannot ask the same numbered question differently. The four
 * with a `choice` carry a printed Yes/No pair on the paper form; their free
 * text is the explanation beside it.
 */
const SECTIONS: {
  letter: string;
  title: string;
  questions: { key: string; number: number; label: string; choice?: string; explainLabel?: string }[];
}[] = [
  {
    letter: 'B',
    title: 'Internship Placement and Responsibilities',
    questions: [
      { key: 'q1', number: 1, label: 'What were your primary duties and responsibilities during your internship?' },
      {
        key: 'q2',
        number: 2,
        label: 'Were your assigned tasks relevant to your academic program?',
        choice: 'q2_choice',
        explainLabel: 'Please explain',
      },
    ],
  },
  {
    letter: 'C',
    title: 'Skills and Competencies Developed',
    questions: [
      { key: 'q3', number: 3, label: 'What technical skills did you learn or improve during your internship?' },
      {
        key: 'q4',
        number: 4,
        label:
          'What soft skills did you develop during your internship? (e.g., communication, teamwork, time management, professionalism)',
      },
      { key: 'q5', number: 5, label: 'Which skill do you think improved the most during your training?' },
    ],
  },
  {
    letter: 'D',
    title: 'Internship Experience',
    questions: [
      { key: 'q6', number: 6, label: 'How would you describe your overall internship experience?' },
      {
        key: 'q7',
        number: 7,
        label: 'Were you given adequate supervision and guidance by your company supervisor?',
        choice: 'q7_choice',
        explainLabel: 'Please explain',
      },
    ],
  },
  {
    letter: 'E',
    title: 'Challenges Encountered',
    questions: [
      {
        key: 'q8',
        number: 8,
        label: 'What challenges did you encounter during your internship? How did you address these challenges?',
      },
    ],
  },
  {
    letter: 'F',
    title: 'Learning and Career Insights',
    questions: [
      { key: 'q9', number: 9, label: 'What important lessons did you learn from your internship?' },
      {
        key: 'q10',
        number: 10,
        label: 'Did your internship influence your career plans?',
        choice: 'q10_choice',
        explainLabel: 'If yes, please explain',
      },
      {
        key: 'q11',
        number: 11,
        label: 'Do you feel prepared to enter the workforce after completing your OJT/INTERNSHIP?',
        choice: 'q11_choice',
        explainLabel: 'Please explain',
      },
    ],
  },
  {
    letter: 'G',
    title: 'Feedback and Recommendations',
    questions: [
      { key: 'q12', number: 12, label: 'What aspects of the OJT/INTERNSHIP program were most beneficial to you?' },
      { key: 'q13', number: 13, label: 'What improvements would you suggest for the OJT/INTERNSHIP program?' },
      { key: 'q14', number: 14, label: 'What advice would you give to future OJT/INTERNSHIP students?' },
    ],
  },
];

export default function ExitInterview() {
  const { data, loading, error, isOffline, reload, save } = useExitInterview();

  const [responses, setResponses] = useState<Record<string, string>>({});
  const [position, setPosition] = useState('');
  const [totalHours, setTotalHours] = useState('');
  const [interviewDate, setInterviewDate] = useState(todayISO());
  const [saving, setSaving] = useState(false);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (!data) return;
    setResponses(data.interview?.responses ?? {});
    const info = data.interview?.student_info ?? {};
    setPosition(info.department_position ?? '');
    setTotalHours(info.total_hours ?? (data.suggested_total_hours != null ? String(data.suggested_total_hours) : ''));
    setInterviewDate(info.date_of_interview ?? todayISO());
  }, [data?.interview?.id, data?.suggested_total_hours]);

  const submitted = data?.interview?.submission_status === 'submitted' || data?.interview?.submission_status === 'reviewed';
  const locked = submitted || isOffline;

  function setAnswer(key: string, value: string) {
    setResponses((prev) => ({ ...prev, [key]: value }));
  }

  async function persist(submit: boolean) {
    setSaving(true);
    const res = await save({
      submit,
      student_info: {
        department_position: position,
        total_hours: totalHours,
        date_of_interview: interviewDate,
      },
      responses,
    });
    setSaving(false);

    if (res.ok) {
      Alert.alert(submit ? 'Exit interview submitted' : 'Draft saved');
    } else {
      showError(submit ? 'Could not submit' : 'Could not save', res.error);
    }
  }

  function confirmSubmit() {
    Alert.alert(
      'Submit your exit interview?',
      'Your coordinator will review it. You will not be able to edit it afterwards.',
      [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Submit', onPress: () => persist(true) },
      ]
    );
  }

  async function onDownloadPdf() {
    setDownloading(true);
    try {
      await downloadAndSharePdf(endpoints.exitInterviewPdf, 'exit-interview.pdf');
    } catch (err) {
      showError('Could not download PDF', (err as ApiError).message);
    } finally {
      setDownloading(false);
    }
  }

  if (loading && !data) return <LoadingState />;
  if (error && !data) return <ErrorState message={error.message} onRetry={reload} />;
  if (!data) return null;

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
        <Text style={{ fontSize: 16, fontWeight: '700', color: colors.black, flex: 1 }}>Exit Interview</Text>
        {submitted ? (
          <Pressable onPress={onDownloadPdf} disabled={downloading} hitSlop={8}>
            {downloading ? (
              <ActivityIndicator size="small" color={colors.blue600} />
            ) : (
              <Ionicons name="download-outline" size={20} color={colors.blue600} />
            )}
          </Pressable>
        ) : null}
      </View>

      <OfflineNotice feature="exitInterview" show={isOffline} />

      {submitted ? (
        <Banner variant="info">
          You submitted this on {data.interview?.submitted_at?.slice(0, 10) ?? '—'}. Your coordinator reviews it from
          here — it is read-only now.
        </Banner>
      ) : !data.ojt_completed ? (
        // Warned, not blocked: a coordinator may well ask for this during
        // the final week rather than after the placement formally closes.
        <Banner variant="warn">
          OJT is not yet marked as completed. This can still be filled out if requested by the coordinator.
        </Banner>
      ) : null}

      <Card title="Your Details">
        <Field
          label="Department / Position Assigned"
          value={position}
          onChange={setPosition}
          editable={!locked}
        />
        <Field label="Total Hours Rendered" value={totalHours} onChange={setTotalHours} editable={!locked} />
        <Field
          label="Date of Interview (YYYY-MM-DD)"
          value={interviewDate}
          onChange={setInterviewDate}
          editable={!locked}
        />
      </Card>

      {SECTIONS.map((section) => (
        <Card key={section.letter} title={`${section.letter}. ${section.title}`}>
          {section.questions.map((q) => (
            <View key={q.key} style={{ marginBottom: 16 }}>
              <Text style={{ fontSize: 12.5, fontWeight: '600', color: colors.black, marginBottom: 8, lineHeight: 18 }}>
                {q.number}. {q.label}
              </Text>

              {q.choice ? (
                <View style={{ flexDirection: 'row', gap: 10, marginBottom: 8 }}>
                  {(['yes', 'no'] as const).map((option) => {
                    const active = responses[q.choice!] === option;
                    return (
                      <Pressable
                        key={option}
                        onPress={() => !locked && setAnswer(q.choice!, option)}
                        style={{
                          paddingVertical: 8,
                          paddingHorizontal: 20,
                          borderRadius: 10,
                          borderWidth: 1.5,
                          borderColor: active ? colors.blue600 : colors.gray200,
                          backgroundColor: active ? colors.blue600 : colors.white,
                          opacity: locked ? 0.6 : 1,
                        }}
                      >
                        <Text
                          style={{
                            fontSize: 12.5,
                            fontWeight: '600',
                            color: active ? colors.white : colors.gray600,
                          }}
                        >
                          {option === 'yes' ? 'Yes' : 'No'}
                        </Text>
                      </Pressable>
                    );
                  })}
                </View>
              ) : null}

              <AnswerBox
                value={responses[q.key] ?? ''}
                onChange={(v) => setAnswer(q.key, v)}
                editable={!locked}
                placeholder={q.explainLabel ?? 'Your answer'}
                limit={data.answer_char_limits[q.key] ?? data.answer_char_limit}
              />
            </View>
          ))}
        </Card>
      ))}

      {!submitted ? (
        <View style={{ flexDirection: 'row', gap: 10, marginHorizontal: 20, marginTop: 16 }}>
          <Button
            label="Save Draft"
            variant="secondary"
            fullWidth
            disabled={saving || isOffline}
            onPress={() => persist(false)}
          />
          <Button
            label="Submit"
            icon="checkmark"
            fullWidth
            loading={saving}
            disabled={saving || isOffline}
            onPress={confirmSubmit}
          />
        </View>
      ) : null}
    </ScrollView>
  );
}

function AnswerBox({
  value,
  onChange,
  editable,
  placeholder,
  limit,
}: {
  value: string;
  onChange: (v: string) => void;
  editable: boolean;
  placeholder: string;
  limit: number;
}) {
  const over = value.length > limit;

  return (
    <>
      <TextInput
        value={value}
        onChangeText={onChange}
        editable={editable}
        multiline
        placeholder={placeholder}
        placeholderTextColor={colors.gray400}
        style={{
          borderWidth: 1.5,
          borderColor: over ? colors.red : colors.gray200,
          borderRadius: 10,
          paddingHorizontal: 12,
          paddingVertical: 10,
          fontSize: 13,
          minHeight: 80,
          textAlignVertical: 'top',
          color: editable ? colors.black : colors.gray500,
          backgroundColor: editable ? colors.white : colors.gray100,
        }}
      />
      {/* The limit comes from the printed form's own line count, not an
          arbitrary cap — question 7 has four lines where the rest have five. */}
      <Text style={{ fontSize: 10.5, color: over ? colors.redTx : colors.gray400, marginTop: 4, textAlign: 'right' }}>
        {value.length} / {limit}
      </Text>
    </>
  );
}

function Field({
  label,
  value,
  onChange,
  editable,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  editable: boolean;
}) {
  return (
    <View style={{ marginBottom: 12 }}>
      <Text style={{ fontSize: 11, fontWeight: '600', color: colors.gray600, marginBottom: 6 }}>{label}</Text>
      <TextInput
        value={value}
        onChangeText={onChange}
        editable={editable}
        autoCapitalize="none"
        placeholderTextColor={colors.gray400}
        style={{
          borderWidth: 1.5,
          borderColor: colors.gray200,
          borderRadius: 10,
          paddingHorizontal: 12,
          paddingVertical: 10,
          fontSize: 13,
          color: editable ? colors.black : colors.gray500,
          backgroundColor: editable ? colors.white : colors.gray100,
        }}
      />
    </View>
  );
}

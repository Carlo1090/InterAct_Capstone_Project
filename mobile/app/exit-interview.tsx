import { useEffect, useState } from 'react';
import { ScrollView, View, Text, TextInput, Pressable, ActivityIndicator } from 'react-native';
import { router } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { Banner } from '../src/components/Banner';
import { Button } from '../src/components/Button';
import { Card } from '../src/components/Card';
import { ErrorState, LoadingState } from '../src/components/ErrorState';
import { OfflineNotice } from '../src/components/OfflineNotice';
import { useExitInterview } from '../src/hooks/useExitInterview';
import type { ExitInterviewResponse } from '../src/types/api';
import { downloadAndSharePdf, ApiError } from '../src/services/api';
import { endpoints } from '../src/services/endpoints';
import { todayISO } from '../src/lib/datetime';
import { colors } from '../src/constants/colors';
import { showError, showSuccess } from '../src/services/toast';
import { confirmAction } from '../src/services/confirm';

/**
 * No question list lives here. The payload carries `form` — the student's
 * own department's exit interview form (CAST, CABM, whichever the admin
 * assigned) — so this screen renders any of the hardcoded forms without a
 * transcription that could drift from the web page's. A `yes_no_text`
 * question carries a printed Yes/No pair whose answer is stored under
 * `choice`; a `scale` question is a row of options and no free text at all.
 */
type Question = ExitInterviewResponse['form']['sections'][number]['questions'][number];

const CHOICE_OPTIONS: Record<string, string> = { yes: 'Yes', no: 'No' };

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
      showSuccess(submit ? 'Exit interview submitted' : 'Draft saved');
    } else {
      showError(submit ? 'Could not submit' : 'Could not save', res.error);
    }
  }

  async function confirmSubmit() {
    const ok = await confirmAction({
      title: 'Submit your exit interview?',
      message: 'You cannot edit it after this.',
      confirmLabel: 'Submit',
    });
    if (ok) persist(true);
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

      <OfflineNotice feature="exitInterview" show={isOffline} error={error} />

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

      {data.form.sections.map((section) => (
        <Card key={section.heading} title={section.heading}>
          {section.questions.map((q: Question) => {
            // Where the choice lives and which options it offers: a Yes/No
            // pair answers under `choice`, a rating under the question's own
            // key. A plain question has neither.
            const choiceKey = q.type === 'yes_no_text' ? q.choice : q.type === 'scale' ? q.key : undefined;
            const options = q.type === 'scale' ? q.options ?? {} : q.type === 'yes_no_text' ? CHOICE_OPTIONS : null;

            return (
              <View key={q.key} style={{ marginBottom: 16 }}>
                <Text style={{ fontSize: 12.5, fontWeight: '600', color: colors.black, marginBottom: 8, lineHeight: 18 }}>
                  {q.n}. {q.text}
                </Text>

                {choiceKey && options ? (
                  <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 8 }}>
                    {Object.entries(options).map(([option, optionLabel]) => {
                      const active = responses[choiceKey] === option;
                      return (
                        <Pressable
                          key={option}
                          onPress={() => !locked && setAnswer(choiceKey, option)}
                          style={{
                            paddingVertical: 8,
                            paddingHorizontal: 16,
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
                            {optionLabel}
                          </Text>
                        </Pressable>
                      );
                    })}
                  </View>
                ) : null}

                {q.type !== 'scale' ? (
                  <AnswerBox
                    value={responses[q.key] ?? ''}
                    onChange={(v) => setAnswer(q.key, v)}
                    editable={!locked}
                    placeholder={q.label ? q.label.replace(/:$/, '') : 'Your answer'}
                    limit={data.answer_char_limits[q.key] ?? data.answer_char_limit}
                  />
                ) : null}
              </View>
            );
          })}
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
          arbitrary cap. */}
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

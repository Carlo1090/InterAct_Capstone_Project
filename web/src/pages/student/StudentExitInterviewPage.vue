<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import ToastHost from '@/components/ToastHost.vue'
import { confirmAction, showToast } from '@/lib/toast'
import { useFormDraft } from '@/lib/formDraft'
import ValidationErrorList from '@/components/ui/ValidationErrorList.vue'
import type { ExitInterviewForm, ExitInterviewQuestion, StudentExitInterviewResponse } from '@/types/api'

/**
 * The question set is NOT transcribed here. It arrives on the payload as
 * `form` — the student's own department's exit interview form (CAST, CABM,
 * whichever the admin assigned) — so this page renders any of the hardcoded
 * forms without knowing which. Section letters and question numbers are the
 * paper form's own and come from the server with the questions.
 */
const form = ref<ExitInterviewForm | null>(null)

const isScale = (question: ExitInterviewQuestion) => question.type === 'scale'
const isYesNo = (question: ExitInterviewQuestion) => question.type === 'yes_no_text'

type Answers = Record<string, string>
type Choices = Record<string, 'yes' | 'no' | ''>

const isLoading = ref(true)
const loadError = ref('')
const isSaving = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})

const header = ref<StudentExitInterviewResponse['header'] | null>(null)
const status = ref<'draft' | 'submitted' | 'reviewed' | null>(null)
const submittedAt = ref<string | null>(null)
const suggestedHours = ref<number | null>(null)
const ojtCompleted = ref(false)
/**
 * Per QUESTION, from the server, from the printed line count of each (a
 * rating question has no lines and so no entry here). This is a guide, not
 * the guarantee — the server measures the rendered width, since
 * the same character count fits five lines in prose and overruns in capitals.
 */
const charLimits = ref<Record<string, number>>({})
const limitFor = (key: string): number => charLimits.value[key] ?? 500

const info = reactive({ department_position: '', total_hours: '', date_of_interview: '' })
const answers = reactive<Answers>({})
const choices = reactive<Choices>({})

/**
 * Seed one entry per question of the form just received, so v-model has
 * somewhere to write and `unanswered` has something to count. A rating
 * question's answer lives in `answers` under its own key (an option key
 * rather than free text); a Yes/No pair's choice lives in `choices`.
 */
const seedAnswers = (received: ExitInterviewForm) => {
  for (const key of Object.keys(answers)) delete answers[key]
  for (const key of Object.keys(choices)) delete choices[key]
  for (const section of received.sections) {
    for (const question of section.questions) {
      answers[question.key] = ''
      if (isYesNo(question) && question.choice) choices[question.choice] = ''
    }
  }
}

// Locked the moment it is submitted: an exit interview is a statement handed
// to the coordinator, not a document that keeps changing under them.
const isLocked = computed(() => status.value !== null && status.value !== 'draft')

const statusLabel = computed(() => {
  if (status.value === 'reviewed') return 'Reviewed by your coordinator'
  if (status.value === 'submitted') return 'Submitted'
  if (status.value === 'draft') return 'Draft — not yet submitted'
  return 'Not started'
})

const statusClass = computed(() => {
  if (status.value === 'reviewed') return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
  if (status.value === 'submitted') return 'bg-blue-50 text-blue-700 ring-blue-200'
  if (status.value === 'draft') return 'bg-amber-50 text-amber-800 ring-amber-200'
  return 'bg-slate-100 text-slate-600 ring-slate-200'
})

const remaining = (key: string): number => limitFor(key) - (answers[key]?.length ?? 0)

const unanswered = computed(() => {
  const missing: string[] = []
  for (const section of form.value?.sections ?? []) {
    for (const question of section.questions) {
      if (!answers[question.key]?.trim()) missing.push(String(question.n))
      if (isYesNo(question) && question.choice && !choices[question.choice]) {
        if (!missing.includes(String(question.n))) missing.push(String(question.n))
      }
    }
  }
  return missing
})

const canSubmit = computed(
  () =>
    !isLocked.value &&
    unanswered.value.length === 0 &&
    info.department_position.trim() !== '' &&
    info.date_of_interview !== '',
)

// Never persisted server-side until Save/Submit, so a refresh mid-typing would
// otherwise lose everything. autoRestore:false because this page loads from the
// API on mount, and an eager restore would be overwritten by the response.
const draft = useFormDraft(
  'student-exit-interview',
  () => ({ info: { ...info }, answers: { ...answers }, choices: { ...choices } }),
  (stored) => {
    if (stored.info) Object.assign(info, stored.info)
    if (stored.answers) Object.assign(answers, stored.answers)
    if (stored.choices) Object.assign(choices, stored.choices)
  },
  { autoRestore: false },
)

const load = async () => {
  isLoading.value = true
  loadError.value = ''

  try {
    const { data } = await api.get<StudentExitInterviewResponse>('/api/student/exit-interview')

    form.value = data.form
    seedAnswers(data.form)
    header.value = data.header
    suggestedHours.value = data.suggested_total_hours
    charLimits.value = data.answer_char_limits
    ojtCompleted.value = data.ojt_completed

    if (data.interview) {
      status.value = data.interview.submission_status
      submittedAt.value = data.interview.submitted_at
      Object.assign(info, {
        department_position: data.interview.student_info.department_position ?? '',
        total_hours: data.interview.student_info.total_hours ?? '',
        date_of_interview: data.interview.student_info.date_of_interview ?? '',
      })
      for (const key of Object.keys(answers)) answers[key] = data.interview.responses[key] ?? ''
      for (const key of Object.keys(choices)) {
        const value = data.interview.responses[key]
        choices[key] = value === 'yes' || value === 'no' ? value : ''
      }
    } else {
      // Sensible starting points from what the system already knows. Both are
      // suggestions the student can overwrite — the DTR total especially, since
      // a forgotten clock-out must stay correctable before anyone signs it.
      info.department_position = header.value?.assigned_division ?? ''
      if (suggestedHours.value !== null) info.total_hours = String(suggestedHours.value)
      info.date_of_interview = new Date().toISOString().slice(0, 10)
    }

    if (!isLocked.value) draft.restore()
  } catch (error) {
    loadError.value =
      axios.isAxiosError(error) && error.response?.status === 422
        ? (error.response.data?.message ??
          'You are not enrolled in an OJT batch, so there is no internship to exit from yet.')
        : 'Unable to load your exit interview.'
  } finally {
    isLoading.value = false
  }
}

const save = async (submit: boolean) => {
  if (isLocked.value) return

  if (submit) {
    const ok = await confirmAction({
      title: 'Submit your exit interview?',
      message:
        'Your answers go to your OJT coordinator and cannot be edited afterwards. Save it as a draft instead if you still want to change anything.',
      confirmLabel: 'Submit exit interview',
    })
    if (!ok) return
  }

  isSaving.value = true
  fieldErrors.value = {}

  try {
    const payload = {
      submit,
      student_info: {
        department_position: info.department_position.trim() || null,
        total_hours: info.total_hours.trim() || null,
        date_of_interview: info.date_of_interview || null,
      },
      responses: {
        ...answers,
        ...Object.fromEntries(Object.entries(choices).map(([key, value]) => [key, value || null])),
      },
    }

    const { data } = await api.post<{ interview: StudentExitInterviewResponse['interview']; message: string }>(
      '/api/student/exit-interview',
      payload,
    )

    status.value = data.interview?.submission_status ?? status.value
    submittedAt.value = data.interview?.submitted_at ?? null
    draft.clear()
    showToast(data.message, 'success')
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      fieldErrors.value = error.response.data?.errors ?? {}
      showToast(error.response.data?.message ?? 'Please check the highlighted answers.', 'error')
    } else {
      showToast('Could not save your exit interview.', 'error')
    }
  } finally {
    isSaving.value = false
  }
}

const downloadPdf = () => {
  window.open('/api/student/exit-interview/pdf', '_blank')
}

onMounted(load)
</script>

<template>
  <section class="space-y-6">
    <ToastHost />

    <!-- Guidance: this tab is new, and nothing else in the app explains what
         an exit interview is or when it falls due. -->
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-900">
      <p class="font-semibold">About this form</p>
      <p class="mt-1">
        This is your department's official <strong>{{ form?.label ?? 'Exit Interview Form' }}</strong>. You fill
        it in at the end of your OJT. Your answers help the college evaluate the internship program and improve it for the
        students who come after you — they are <strong>not</strong> a grade and your company does not see them.
      </p>
      <ul class="mt-2 list-inside list-disc space-y-1 text-blue-800">
        <li>
          Answer all {{ form?.question_count ?? '' }} questions in your own words. Each written answer fits about
          five printed lines.
        </li>
        <li><strong>Save draft</strong> as often as you like — nothing is sent until you press Submit.</li>
        <li>Once you <strong>submit</strong>, your answers are locked and go to your OJT coordinator.</li>
        <li>Your coordinator completes the last block of the form and signs the printed copy.</li>
      </ul>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="loadError" class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ loadError }}</p>

    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ring-1" :class="statusClass">
          {{ statusLabel }}
        </span>
        <div class="flex items-center gap-2">
          <button
            v-if="status"
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            @click="downloadPdf"
          >
            Download PDF
          </button>
        </div>
      </div>

      <p
        v-if="!isLocked && !ojtCompleted"
        class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200/70"
      >
        OJT is still in progress. This can be filled out now and submitted after completing the required hours.
      </p>

      <p v-if="isLocked" class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200/70">
        You submitted this exit interview and it can no longer be edited. You can still download your copy above.
      </p>

      <ValidationErrorList v-if="Object.keys(fieldErrors).length" :errors="fieldErrors" />

      <!-- ── A. Student Information ─────────────────────────────────────── -->
      <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <h3 class="text-sm font-semibold text-slate-900">{{ form?.student_info_heading ?? 'Student Information' }}</h3>
        <p class="mt-1 text-xs text-slate-500">
          Most of this is filled in from your enrollment and cannot be changed here.
        </p>

        <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Student Name</dt>
            <dd class="mt-0.5 text-sm text-slate-800">{{ header?.student_name || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Program</dt>
            <dd class="mt-0.5 text-sm text-slate-800">{{ header?.program || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Company/Training Establishment</dt>
            <dd class="mt-0.5 text-sm text-slate-800">{{ header?.company || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Training Period</dt>
            <dd class="mt-0.5 text-sm text-slate-800">{{ header?.training_period || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">OJT/Internship Coordinator</dt>
            <dd class="mt-0.5 text-sm text-slate-800">{{ header?.coordinator_name || '—' }}</dd>
          </div>
        </dl>

        <div class="mt-5 grid gap-4 sm:grid-cols-3">
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Department/Position Assigned</span>
            <input
              v-model="info.department_position"
              type="text"
              maxlength="120"
              :disabled="isLocked"
              placeholder="e.g. Loans Department"
              class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
            />
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Total Hours Completed</span>
            <input
              v-model="info.total_hours"
              type="text"
              maxlength="20"
              :disabled="isLocked"
              class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
            />
            <span v-if="suggestedHours !== null" class="mt-1 block text-xs text-slate-400">
              Your Daily Time Record shows {{ suggestedHours }} hours. Correct it here if that is not right.
            </span>
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Date of Interview</span>
            <input
              v-model="info.date_of_interview"
              type="date"
              :disabled="isLocked"
              class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
            />
          </label>
        </div>
      </div>

      <!-- ── The questions, section by section as the form defines them ── -->
      <div
        v-for="section in form?.sections ?? []"
        :key="section.heading"
        class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70"
      >
        <h3 class="text-sm font-semibold text-slate-900">{{ section.heading }}</h3>

        <div class="mt-4 space-y-6">
          <div v-for="question in section.questions" :key="question.key">
            <p class="text-sm font-medium text-slate-800">{{ question.n }}. {{ question.text }}</p>

            <!-- A printed ☐ Yes ☐ No pair, then the explanation below it. -->
            <div v-if="isYesNo(question) && question.choice" class="mt-2 flex items-center gap-5">
              <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input
                  v-model="choices[question.choice]"
                  type="radio"
                  value="yes"
                  :disabled="isLocked"
                  class="h-4 w-4"
                />
                Yes
              </label>
              <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input
                  v-model="choices[question.choice]"
                  type="radio"
                  value="no"
                  :disabled="isLocked"
                  class="h-4 w-4"
                />
                No
              </label>
            </div>

            <!-- A rating row: one box per option and no free text at all. -->
            <div v-if="isScale(question)" class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-2">
              <label
                v-for="(optionLabel, optionKey) in question.options"
                :key="optionKey"
                class="inline-flex items-center gap-2 text-sm text-slate-700"
              >
                <input
                  v-model="answers[question.key]"
                  type="radio"
                  :value="optionKey"
                  :disabled="isLocked"
                  class="h-4 w-4"
                />
                {{ optionLabel }}
              </label>
            </div>

            <template v-else>
              <label class="mt-2 block">
                <span v-if="question.label" class="text-xs font-bold text-slate-600">
                  {{ question.label.replace(/:$/, '') }}
                </span>
                <textarea
                  v-model="answers[question.key]"
                  rows="4"
                  :maxlength="limitFor(question.key)"
                  :disabled="isLocked"
                  class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
                />
              </label>
              <p class="mt-1 text-right text-xs" :class="remaining(question.key) < 40 ? 'text-amber-600' : 'text-slate-400'">
                {{ remaining(question.key) }} characters left
              </p>
            </template>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div v-if="!isLocked" class="flex flex-wrap items-center justify-end gap-3">
        <p v-if="unanswered.length" class="mr-auto text-xs text-slate-500">
          Still to answer: question{{ unanswered.length === 1 ? '' : 's' }} {{ unanswered.join(', ') }}.
        </p>
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="isSaving"
          @click="save(false)"
        >
          Save draft
        </button>
        <button
          type="button"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="isSaving || !canSubmit"
          @click="save(true)"
        >
          {{ isSaving ? 'Saving...' : 'Submit exit interview' }}
        </button>
      </div>
    </template>
  </section>
</template>

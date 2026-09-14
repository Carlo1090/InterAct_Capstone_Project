<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import ToastHost from '@/components/ToastHost.vue'
import { showToast } from '@/lib/toast'
import type {
  CoordinatorExitInterviewDetail,
  CoordinatorExitInterviewRow,
  CoordinatorExitInterviewsResponse,
  CoordinatorExitInterviewSummaryResponse,
  ExitInterviewStatus,
} from '@/types/api'

/**
 * The fourteen questions of the CABM exit interview form, in printed order,
 * with the ☐ Yes ☐ No pairs attached to the four that carry them. This mirrors
 * StudentExitInterviewPage's own list — both are transcriptions of the same
 * paper form, so if one changes the other must.
 */
const QUESTIONS = [
  { key: 'q1', number: 1, section: 'B. Internship Placement and Responsibilities', label: 'What were your primary duties and responsibilities during your internship?' },
  { key: 'q2', number: 2, section: '', label: 'Were your assigned tasks relevant to your academic program?', choice: 'q2_choice' },
  { key: 'q3', number: 3, section: 'C. Skills and Competencies Developed', label: 'What technical skills did you learn or improve during your internship?' },
  { key: 'q4', number: 4, section: '', label: 'What soft skills did you develop during your internship?' },
  { key: 'q5', number: 5, section: '', label: 'Which skill do you think improved the most during your training?' },
  { key: 'q6', number: 6, section: 'D. Internship Experience', label: 'How would you describe your overall internship experience?' },
  { key: 'q7', number: 7, section: '', label: 'Were you given adequate supervision and guidance by your company supervisor?', choice: 'q7_choice' },
  { key: 'q8', number: 8, section: 'E. Challenges Encountered', label: 'What challenges did you encounter during your internship? How did you address these challenges?' },
  { key: 'q9', number: 9, section: 'F. Learning and Career Insights', label: 'What important lessons did you learn from your internship?' },
  { key: 'q10', number: 10, section: '', label: 'Did your internship influence your career plans?', choice: 'q10_choice' },
  { key: 'q11', number: 11, section: '', label: 'Do you feel prepared to enter the workforce after completing your OJT/INTERNSHIP?', choice: 'q11_choice' },
  { key: 'q12', number: 12, section: 'G. Feedback and Recommendations', label: 'What aspects of the OJT/INTERNSHIP program were most beneficial to you?' },
  { key: 'q13', number: 13, section: '', label: 'What improvements would you suggest for the OJT/INTERNSHIP program?' },
  { key: 'q14', number: 14, section: '', label: 'What advice would you give to future OJT/INTERNSHIP students?' },
] as const

const programId = ref<number | null>(null)
const status = ref<ExitInterviewStatus | ''>('')
const search = ref('')

const rows = ref<CoordinatorExitInterviewRow[]>([])
const programs = ref<{ id: number; name: string; code?: string }[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const isLoading = ref(true)

/** Slice, never parse — see PROJECT.md on Asia/Manila date drift. */
const formatDate = (raw: string | null | undefined): string => {
  const iso = (raw ?? '').slice(0, 10)
  if (!iso) return '—'
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const statusPill = (value: ExitInterviewStatus): string => {
  if (value === 'reviewed') return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
  if (value === 'submitted') return 'bg-blue-50 text-blue-700 ring-blue-200'
  return 'bg-slate-100 text-slate-600 ring-slate-200'
}

const statusText = (value: ExitInterviewStatus): string =>
  value === 'reviewed' ? 'Reviewed' : value === 'submitted' ? 'Submitted' : 'Draft'

const load = async () => {
  isLoading.value = true

  try {
    const params: Record<string, string | number> = { page: page.value }
    if (programId.value) params.program_id = programId.value
    if (status.value) params.status = status.value
    if (search.value.trim()) params.search = search.value.trim()

    const { data } = await api.get<CoordinatorExitInterviewsResponse>('/api/coordinator/exit-interviews', { params })
    rows.value = data.interviews.data
    programs.value = data.programs
    lastPage.value = data.interviews.last_page
    total.value = data.interviews.total
  } catch {
    showToast('Unable to load exit interviews.', 'error')
  } finally {
    isLoading.value = false
  }
}

const applyFilters = () => {
  page.value = 1
  load()
}

const resetFilters = () => {
  programId.value = null
  status.value = ''
  search.value = ''
  applyFilters()
}

const hasFilters = computed(
  () => programId.value !== null || status.value !== '' || search.value.trim() !== '',
)

const goToPage = (target: number) => {
  if (target < 1 || target > lastPage.value || target === page.value) return
  page.value = target
  load()
}

const downloadPdf = (id: number) => {
  window.open(`/api/coordinator/exit-interviews/${id}/pdf`, '_blank')
}

// --- Detail: the student's answers + the coordinator's own block ----------
const isDetailOpen = ref(false)
const isDetailLoading = ref(false)
const isSavingReview = ref(false)
const detailError = ref('')
const detail = ref<CoordinatorExitInterviewDetail | null>(null)

const review = reactive({ compliance: '' as '' | 'complete' | 'pending', pending_detail: '', remarks: '' })

const openDetail = async (row: CoordinatorExitInterviewRow) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detailError.value = ''
  detail.value = null
  Object.assign(review, { compliance: '', pending_detail: '', remarks: '' })

  try {
    const { data } = await api.get<CoordinatorExitInterviewDetail>(`/api/coordinator/exit-interviews/${row.id}`)
    detail.value = data
    Object.assign(review, {
      compliance: data.coordinator_section.compliance ?? '',
      pending_detail: data.coordinator_section.pending_detail ?? '',
      remarks: data.coordinator_section.remarks ?? '',
    })
  } catch (error) {
    detailError.value =
      axios.isAxiosError(error) && error.response?.status === 403
        ? 'This exit interview is not in your scope.'
        : 'Unable to load this exit interview.'
    showToast(detailError.value, 'error')
  } finally {
    isDetailLoading.value = false
  }
}

const closeDetail = () => {
  isDetailOpen.value = false
  detail.value = null
}

const saveReview = async () => {
  if (!detail.value) return
  isSavingReview.value = true

  try {
    const { data } = await api.put<{ submission_status: ExitInterviewStatus; reviewed_at: string | null; message: string }>(
      `/api/coordinator/exit-interviews/${detail.value.id}`,
      {
        compliance: review.compliance || null,
        pending_detail: review.pending_detail.trim() || null,
        remarks: review.remarks.trim() || null,
      },
    )

    detail.value.submission_status = data.submission_status
    detail.value.reviewed_at = data.reviewed_at
    showToast(data.message, 'success')
    load()
  } catch (error) {
    showToast(
      axios.isAxiosError(error) ? (error.response?.data?.message ?? 'Could not save.') : 'Could not save.',
      'error',
    )
  } finally {
    isSavingReview.value = false
  }
}

const answerOf = (key: string): string => (detail.value?.responses[key] ?? '').trim()

const choiceOf = (key: string | undefined): string => {
  if (!key) return ''
  const value = detail.value?.responses[key]
  return value === 'yes' ? 'Yes' : value === 'no' ? 'No' : '—'
}

// --- Summary tab: every intern's answer to Q1 gathered together, then Q2,
// and so on. Reached as a tab on this same page rather than a separate nav
// item — see PROJECT.md, Exit Interview → Summary Report. -------------------
const activeTab = ref<'by_student' | 'summary'>('by_student')
const summaryData = ref<CoordinatorExitInterviewSummaryResponse | null>(null)
const isSummaryLoading = ref(false)
const summaryProgramId = ref<number | null>(null)
const summaryAcademicYear = ref('')
const openQuestions = reactive(new Set<string>())

const loadSummary = async () => {
  isSummaryLoading.value = true

  try {
    const params: Record<string, string | number> = {}
    if (summaryProgramId.value) params.program_id = summaryProgramId.value
    if (summaryAcademicYear.value) params.academic_year = summaryAcademicYear.value

    const { data } = await api.get<CoordinatorExitInterviewSummaryResponse>(
      '/api/coordinator/exit-interviews/summary',
      { params },
    )
    summaryData.value = data
    summaryAcademicYear.value = data.academic_year ?? ''
  } catch {
    showToast('Unable to load the exit interview summary.', 'error')
  } finally {
    isSummaryLoading.value = false
  }
}

const selectTab = (tab: 'by_student' | 'summary') => {
  activeTab.value = tab
  if (tab === 'summary' && !summaryData.value) loadSummary()
}

const toggleQuestion = (key: string) => {
  if (openQuestions.has(key)) openQuestions.delete(key)
  else openQuestions.add(key)
}

const expandAllQuestions = () => {
  summaryData.value?.questions.forEach((q) => openQuestions.add(q.key))
}

const collapseAllQuestions = () => {
  openQuestions.clear()
}

const isNewSection = (index: number): boolean => {
  const questions = summaryData.value?.questions ?? []
  return index === 0 || questions[index - 1]?.section !== questions[index]?.section
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Every exit interview your interns have started appears here. Open one to read all fourteen answers, record
      the <strong>coordinator's compliance verification</strong> at the foot of the form, and download the printed
      MDC form for your SIPP file. There is no accept or reject — an exit interview is feedback, not an
      application.
    </div>

    <!-- Tabs: the roster view, and the Summary Report (every intern's answer
         to each question gathered together) added 2026-09-10. -->
    <div class="flex gap-2 border-b border-slate-200">
      <button
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-semibold transition"
        :class="
          activeTab === 'by_student'
            ? 'border-blue-600 text-blue-600'
            : 'border-transparent text-slate-500 hover:text-slate-700'
        "
        @click="selectTab('by_student')"
      >
        By Student
      </button>
      <button
        type="button"
        class="border-b-2 px-3 py-2 text-sm font-semibold transition"
        :class="
          activeTab === 'summary'
            ? 'border-blue-600 text-blue-600'
            : 'border-transparent text-slate-500 hover:text-slate-700'
        "
        @click="selectTab('summary')"
      >
        Summary Report
      </button>
    </div>

    <template v-if="activeTab === 'by_student'">
    <!-- Filters -->
    <div class="flex flex-wrap items-end gap-3">
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Program</span>
        <select
          v-model="programId"
          class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          @change="applyFilters"
        >
          <option :value="null">All Programs</option>
          <option v-for="program in programs" :key="program.id" :value="program.id">
            {{ program.code ?? program.name }}
          </option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Status</span>
        <select
          v-model="status"
          class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          @change="applyFilters"
        >
          <option value="">All Statuses</option>
          <option value="submitted">Submitted</option>
          <option value="reviewed">Reviewed</option>
          <option value="draft">Draft</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Student</span>
        <input
          v-model="search"
          type="search"
          placeholder="Search by name"
          class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          @keyup.enter="applyFilters"
          @search="applyFilters"
        />
      </label>
      <button
        type="button"
        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
        @click="applyFilters"
      >
        Search
      </button>
      <button
        type="button"
        class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
        @click="resetFilters"
      >
        Reset
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <div v-else class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <p class="text-sm font-semibold text-slate-700">Student Exit Interviews</p>
        <span class="text-xs text-slate-400">{{ total }} {{ total === 1 ? 'form' : 'forms' }}</span>
      </div>

      <!-- md and up: aligned table. -->
      <div class="hidden overflow-x-auto md:block">
        <table class="w-full table-fixed divide-y divide-slate-200">
          <colgroup>
            <col />
            <col class="w-24" />
            <col class="w-28" />
            <col class="w-36" />
            <col class="w-40" />
            <col class="w-44" />
          </colgroup>
          <thead class="bg-slate-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Compliance</th>
              <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="rows.length === 0">
              <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">
                {{
                  hasFilters
                    ? 'No exit interviews match these filters.'
                    : 'None of your interns has started an exit interview yet.'
                }}
                <button
                  v-if="hasFilters"
                  type="button"
                  class="mt-2 block w-full text-sm font-semibold text-blue-600 transition hover:text-blue-700"
                  @click="resetFilters"
                >
                  Clear filters
                </button>
              </td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td class="px-4 py-3">
                <p class="truncate text-sm font-semibold text-slate-900">{{ row.student_name }}</p>
                <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ row.program || '—' }}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1"
                  :class="statusPill(row.submission_status)"
                >
                  {{ statusText(row.submission_status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-slate-600">{{ formatDate(row.submitted_at) }}</td>
              <td class="px-4 py-3 text-sm text-slate-600">
                {{
                  row.compliance === 'complete'
                    ? 'All requirements'
                    : row.compliance === 'pending'
                      ? 'With pending'
                      : '—'
                }}
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="openDetail(row)"
                  >
                    View
                  </button>
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="downloadPdf(row.id)"
                  >
                    PDF
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Below md: one stacked card per form, so nothing scrolls sideways. -->
      <ul class="divide-y divide-slate-100 px-4 md:hidden">
        <li v-if="rows.length === 0" class="py-6 text-center text-sm text-slate-500">
          {{
            hasFilters
              ? 'No exit interviews match these filters.'
              : 'None of your interns has started an exit interview yet.'
          }}
          <button
            v-if="hasFilters"
            type="button"
            class="mt-2 block w-full text-sm font-semibold text-blue-600 transition hover:text-blue-700"
            @click="resetFilters"
          >
            Clear filters
          </button>
        </li>
        <li v-for="row in rows" :key="row.id" class="py-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-slate-900">{{ row.student_name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
            </div>
            <span
              class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1"
              :class="statusPill(row.submission_status)"
            >
              {{ statusText(row.submission_status) }}
            </span>
          </div>
          <p class="mt-1 truncate text-xs text-slate-500">{{ row.program || '—' }}</p>
          <p class="mt-1 text-xs text-slate-500">Submitted {{ formatDate(row.submitted_at) }}</p>
          <div class="mt-3 flex items-center gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              @click="openDetail(row)"
            >
              View
            </button>
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              @click="downloadPdf(row.id)"
            >
              PDF
            </button>
          </div>
        </li>
      </ul>

      <div v-if="lastPage > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3">
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:cursor-not-allowed disabled:grayscale"
          :disabled="page <= 1"
          @click="goToPage(page - 1)"
        >
          Previous
        </button>
        <span class="text-xs text-slate-500">Page {{ page }} of {{ lastPage }}</span>
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:cursor-not-allowed disabled:grayscale"
          :disabled="page >= lastPage"
          @click="goToPage(page + 1)"
        >
          Next
        </button>
      </div>
    </div>

    <!-- Detail. Three-part flex shell: header, the only scrolling body, footer. -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <section class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="shrink-0 border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">
            {{ detail?.header.student_name ?? 'Exit Interview' }}
          </h3>
          <p v-if="detail" class="mt-0.5 text-xs text-slate-500">
            {{ detail.header.program }} · {{ detail.header.company }} · submitted
            {{ formatDate(detail.submitted_at) }}
          </p>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <p v-if="isDetailLoading" class="text-sm text-slate-500">Loading...</p>
          <p v-else-if="detailError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ detailError }}</p>

          <div v-else-if="detail" class="space-y-6">
            <!-- A. Student Information -->
            <div>
              <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">A. Student Information</h4>
              <dl class="mt-2 grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                  <dt class="text-xs text-slate-400">Department/Position Assigned</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header.department_position || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-slate-400">Training Period</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header.training_period || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-slate-400">Total Hours Completed</dt>
                  <dd class="font-mono text-sm text-slate-800">{{ detail.header.total_hours || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-slate-400">Date of Interview</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header.date_of_interview || '—' }}</dd>
                </div>
              </dl>
            </div>

            <!-- B - G, the student's own answers -->
            <div v-for="question in QUESTIONS" :key="question.key">
              <h4 v-if="question.section" class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                {{ question.section }}
              </h4>
              <p class="text-sm font-medium text-slate-800">{{ question.number }}. {{ question.label }}</p>
              <p v-if="'choice' in question" class="mt-1 text-sm font-semibold text-slate-700">
                {{ choiceOf(question.choice) }}
              </p>
              <p class="mt-1 whitespace-pre-line text-sm text-slate-600">
                {{ answerOf(question.key) || '— not answered —' }}
              </p>
            </div>

            <!-- The coordinator's own block on the paper form -->
            <div class="rounded-lg bg-slate-50 p-4 ring-1 ring-slate-200/70">
              <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">
                Section for OJT/Internship Coordinator
              </h4>
              <p class="mt-1 text-xs text-slate-500">
                Did the student submit all required OJT/INTERNSHIP documents and reports? This prints at the foot of
                page 2.
              </p>

              <p
                v-if="detail.submission_status === 'draft'"
                class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800"
              >
                This student has not submitted their exit interview yet, so there is nothing to verify.
              </p>

              <template v-else>
                <div class="mt-3 space-y-2">
                  <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="review.compliance" type="radio" value="complete" class="h-4 w-4" />
                    Yes, all requirements completed
                  </label>
                  <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input v-model="review.compliance" type="radio" value="pending" class="h-4 w-4" />
                    With pending requirements
                  </label>
                </div>

                <label class="mt-3 block">
                  <span class="text-xs font-bold text-slate-600">If pending, specify</span>
                  <textarea
                    v-model="review.pending_detail"
                    rows="2"
                    maxlength="450"
                    class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                  />
                </label>

                <label class="mt-3 block">
                  <span class="text-xs font-bold text-slate-600">Remarks</span>
                  <textarea
                    v-model="review.remarks"
                    rows="3"
                    maxlength="450"
                    class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                  />
                </label>

                <p v-if="detail.reviewed_at" class="mt-2 text-xs text-slate-400">
                  Last saved {{ formatDate(detail.reviewed_at) }}{{ detail.reviewed_by ? ` by ${detail.reviewed_by}` : '' }}.
                </p>
              </template>
            </div>
          </div>
        </div>

        <div class="shrink-0 border-t border-slate-200 bg-white px-6 py-4">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="closeDetail"
            >
              Close
            </button>
            <button
              v-if="detail"
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="downloadPdf(detail.id)"
            >
              Download PDF
            </button>
            <button
              v-if="detail && detail.submission_status !== 'draft'"
              type="button"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isSavingReview"
              @click="saveReview"
            >
              {{ isSavingReview ? 'Saving...' : 'Save coordinator section' }}
            </button>
          </div>
        </div>
      </section>
    </div>
    </template>

    <!-- Summary Report: every in-scope intern's answer to Q1 gathered
         together, then Q2, and so on — read one question at a time rather
         than one student at a time. Live read, nothing persisted; drafts are
         excluded since only a submitted form is guaranteed to carry all
         fourteen answers. See PROJECT.md, Exit Interview → Summary Report. -->
    <template v-else>
      <div class="flex flex-wrap items-end gap-3">
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Program</span>
          <select
            v-model="summaryProgramId"
            class="mt-1 block w-full max-w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm sm:w-auto"
            @change="loadSummary()"
          >
            <option :value="null">All Programs</option>
            <option v-for="program in summaryData?.programs ?? []" :key="program.id" :value="program.id">
              {{ program.code ?? program.name }}
            </option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Academic Year</span>
          <select
            v-model="summaryAcademicYear"
            class="mt-1 block w-full max-w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm sm:w-auto"
            @change="loadSummary()"
          >
            <option v-if="!(summaryData?.academic_years?.length)" value="">No data yet</option>
            <option v-for="year in summaryData?.academic_years ?? []" :key="year" :value="year">{{ year }}</option>
          </select>
        </label>

        <div class="ml-auto flex items-center gap-2">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            @click="expandAllQuestions"
          >
            Expand all
          </button>
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            @click="collapseAllQuestions"
          >
            Collapse all
          </button>
        </div>
      </div>

      <p v-if="isSummaryLoading" class="text-sm text-slate-500">Loading...</p>

      <template v-else-if="summaryData">
        <p class="text-sm text-slate-500">
          Gathered from <strong>{{ summaryData.total_respondents }}</strong>
          {{ summaryData.total_respondents === 1 ? 'submitted exit interview' : 'submitted exit interviews' }}
          <span v-if="summaryAcademicYear">for {{ summaryAcademicYear }}</span>.
        </p>

        <p v-if="summaryData.total_respondents === 0" class="rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-500">
          None of your interns has submitted an exit interview for this academic year yet.
        </p>

        <div v-else class="space-y-3">
          <div v-for="(question, index) in summaryData.questions" :key="question.key">
            <h4
              v-if="isNewSection(index)"
              class="mb-1 mt-4 text-xs font-medium uppercase tracking-wide text-slate-400"
            >
              {{ question.section }}
            </h4>

            <div class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200/70">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left"
                @click="toggleQuestion(question.key)"
              >
                <span class="text-sm font-semibold text-slate-800">
                  {{ question.number }}. {{ question.text }}
                </span>
                <span class="flex shrink-0 items-center gap-3">
                  <span v-if="question.tally" class="hidden text-xs text-slate-500 sm:inline">
                    {{ question.tally.yes }} Yes · {{ question.tally.no }} No
                    <template v-if="question.tally.unanswered">· {{ question.tally.unanswered }} unanswered</template>
                  </span>
                  <span class="text-xs text-slate-400">{{ question.answers.length }} answers</span>
                  <span class="text-slate-400">{{ openQuestions.has(question.key) ? '−' : '+' }}</span>
                </span>
              </button>

              <div v-if="openQuestions.has(question.key)" class="border-t border-slate-100 px-4 py-3">
                <p v-if="question.tally" class="mb-3 text-xs font-medium text-slate-500 sm:hidden">
                  {{ question.tally.yes }} Yes · {{ question.tally.no }} No
                  <template v-if="question.tally.unanswered">· {{ question.tally.unanswered }} unanswered</template>
                </p>

                <p v-if="question.answers.length === 0" class="text-sm text-slate-500">
                  No answers to this question yet.
                </p>

                <ul v-else class="divide-y divide-slate-100">
                  <li v-for="answer in question.answers" :key="answer.student_id" class="py-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                      <p class="text-sm font-semibold text-slate-800">
                        {{ answer.student_name }}
                        <span class="font-normal text-slate-400">({{ answer.program || '—' }})</span>
                      </p>
                      <span
                        v-if="answer.choice"
                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                        :class="answer.choice === 'yes' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
                      >
                        {{ answer.choice === 'yes' ? 'Yes' : 'No' }}
                      </span>
                    </div>
                    <p v-if="answer.text" class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ answer.text }}</p>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </template>
    </template>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import ToastHost from '@/components/ToastHost.vue'
import WeeklyJournalPaperView from '@/components/journal/WeeklyJournalPaperView.vue'
import { confirmAction, showToast } from '@/lib/toast'
import { isNotEnrolledError } from '@/lib/enrollment'
import { useFormDraft } from '@/lib/formDraft'
import { useAuthStore } from '@/stores/auth'
import type { WeeklyLogDetail, WeeklyLogSummary } from '@/types/api'

const auth = useAuthStore()

/**
 * Fixed, non-configurable cap on the weekly narrative — separate from (and
 * larger than) the daily journal's fixed 1500-char limit, since this is a
 * compiled/edited summary of a full Mon-Fri week. Mirrors StoreWeeklyLogRequest::CHAR_LIMIT.
 */
const WEEKLY_NARRATIVE_CHAR_LIMIT = 5000

const narrativeLength = (weekStart: string): number => details[weekStart]?.narrative?.length ?? 0
const isNarrativeOverLimit = (weekStart: string): boolean => narrativeLength(weekStart) > WEEKLY_NARRATIVE_CHAR_LIMIT

// Daily-entry dates arrive as full ISO timestamps (2026-07-24T00:00:00.000000Z);
// show a clean human-readable date instead. Parse only the calendar day so a
// timezone offset never shifts it (matches StudentJournalsPage).
const formatDate = (raw: string): string => {
  const [year, month, day] = raw.slice(0, 10).split('-').map(Number)
  return new Date(year, month - 1, day).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const weeks = ref<WeeklyLogSummary[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const notEnrolled = ref(false)

const details = reactive<Record<string, WeeklyLogDetail>>({})
const loadingDetail = reactive<Record<string, boolean>>({})
const savingDetail = reactive<Record<string, boolean>>({})
const submittingDetail = reactive<Record<string, boolean>>({})
const saveMessage = reactive<Record<string, string>>({})

// Per-week Edit ⇄ Preview toggle for editable (draft/returned) weeks — mirrors
// the Write Daily Journal page: Preview renders the read-only paper document,
// while Save/Submit stay reachable so the student can submit straight from it.
const previewMode = reactive<Record<string, boolean>>({})

/**
 * weekly_logs.status defaults to 'pending' at the DB level even for a
 * never-submitted draft, so 'pending' alone can't tell "still drafting"
 * apart from "submitted, awaiting review" -- submitted_at is the signal.
 */
type WeekState = 'draft' | 'submitted' | 'approved' | 'returned'

const weekState = (week: WeeklyLogSummary): WeekState => {
  if (week.status === 'approved') return 'approved'
  if (week.status === 'returned') return 'returned'
  return week.submitted_at ? 'submitted' : 'draft'
}

// Draft or returned weeks are editable (the student can still write + submit);
// submitted/approved weeks are read-only and always show the paper document.
const isEditable = (week: WeeklyLogSummary): boolean => {
  const state = weekState(week)
  return state === 'draft' || state === 'returned'
}

/**
 * Keep unsaved weekly narratives alive across a refresh.
 *
 * Weeks load lazily (each <details> fetches on first open), so this holds a
 * weekStart -> narrative map and `restore()` is called after every loadDetail;
 * re-applying is idempotent. Only weeks that are still editable (draft/returned)
 * are restored — a submitted or approved week is read-only, so putting text back
 * into one would show writing the student can no longer save.
 */
const weeklyDraft = useFormDraft(
  'student:weekly-narratives',
  () => {
    const narratives: Record<string, string> = {}
    for (const [weekStart, detail] of Object.entries(details)) {
      if (detail && typeof detail.narrative === 'string') narratives[weekStart] = detail.narrative
    }
    return { narratives }
  },
  (draft) => {
    if (!draft.narratives) return
    for (const [weekStart, text] of Object.entries(draft.narratives)) {
      if (typeof text !== 'string') continue
      const detail = details[weekStart]
      const week = weeks.value.find((w) => w.week_start === weekStart)
      if (detail && week && isEditable(week)) detail.narrative = text
    }
  },
  { autoRestore: false },
)

const loadWeeks = async () => {
  isLoading.value = true
  errorMessage.value = ''
  notEnrolled.value = false

  try {
    const { data } = await api.get<{ weeks: WeeklyLogSummary[] }>('/api/student/weekly-logs')
    weeks.value = data.weeks
  } catch (error) {
    if (isNotEnrolledError(error)) {
      notEnrolled.value = true
    } else {
      errorMessage.value = 'Unable to load your weekly journals.'
    }
  } finally {
    isLoading.value = false
  }
}

const loadDetail = async (weekStart: string) => {
  if (details[weekStart] || loadingDetail[weekStart]) {
    return
  }

  loadingDetail[weekStart] = true

  try {
    const { data } = await api.get<WeeklyLogDetail>(`/api/student/weekly-logs/${weekStart}`)
    details[weekStart] = data
    // Server copy is in place — layer any unsaved narrative back on top.
    weeklyDraft.restore()
  } catch {
    saveMessage[weekStart] = 'Unable to load this week.'
  } finally {
    loadingDetail[weekStart] = false
  }
}

const downloadWeeklyLogPdf = (weekStart: string) => {
  window.open(`/api/student/weekly-logs/${weekStart}/pdf`, '_blank')
}

const saveNarrative = async (weekStart: string) => {
  const detail = details[weekStart]
  if (!detail) {
    return
  }

  savingDetail[weekStart] = true
  saveMessage[weekStart] = ''

  try {
    await api.post('/api/student/weekly-logs', {
      week_start: weekStart,
      narrative: detail.narrative,
    })
    saveMessage[weekStart] = 'Saved.'
    await loadWeeks()
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    saveMessage[weekStart] = data?.message ?? 'Unable to save.'
  } finally {
    savingDetail[weekStart] = false
  }
}

const submitWeek = async (week: WeeklyLogSummary) => {
  const isResubmit = weekState(week) === 'returned'

  const confirmed = await confirmAction({
    title: isResubmit ? 'Resubmit to your supervisor?' : 'Send to your supervisor?',
    message: isResubmit
      ? 'Resubmit this weekly narrative to your supervisor for review? You will not be able to edit it again until they return it.'
      : 'Submit this weekly narrative to your supervisor for review? You will not be able to edit it until it is returned.',
    confirmLabel: isResubmit ? 'Resubmit' : 'Send to Supervisor',
  })
  if (!confirmed) return

  submittingDetail[week.week_start] = true
  saveMessage[week.week_start] = ''

  try {
    await api.post(`/api/student/weekly-logs/${week.week_start}/submit`)
    delete details[week.week_start]
    previewMode[week.week_start] = false
    await Promise.all([loadWeeks(), loadDetail(week.week_start)])
    showToast(isResubmit ? 'Weekly log resubmitted for review.' : 'Weekly log submitted for review.')
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    showToast(data?.message ?? 'Unable to submit.', 'error')
  } finally {
    submittingDetail[week.week_start] = false
  }
}

const statusLabel = (week: WeeklyLogSummary): string => {
  const state = weekState(week)
  if (state === 'approved') return 'Approved by Supervisor'
  if (state === 'returned') return 'Returned for Revision'
  if (state === 'submitted') return 'Submitted — Awaiting Review'
  return 'Draft'
}

const statusClass = (week: WeeklyLogSummary): string => {
  const state = weekState(week)
  if (state === 'approved') return 'bg-green-50 text-green-700'
  if (state === 'returned') return 'bg-amber-50 text-amber-700'
  if (state === 'submitted') return 'bg-blue-50 text-blue-700'
  return 'bg-slate-100 text-slate-600'
}

onMounted(loadWeeks)
</script>

<template>
  <section class="space-y-4">
    <ToastHost />
    <div class="flex items-start justify-between gap-4 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      <p>Write a short narrative for each week alongside your daily entries. Approval happens with your company supervisor.</p>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <NotEnrolledNotice v-else-if="notEnrolled" />
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>
    <p v-else-if="weeks.length === 0" class="text-sm text-slate-500">No weeks found in your OJT range yet.</p>

    <details
      v-for="week in weeks"
      :key="week.week_start"
      class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200"
      @toggle="loadDetail(week.week_start)"
    >
      <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50">
        <div>
          <h2 class="text-sm font-bold text-slate-900">{{ formatDate(week.week_start) }} – {{ formatDate(week.week_end) }}</h2>
          <p class="mt-1 text-xs text-slate-500">{{ week.entries_count }} daily entries</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(week)">
          {{ statusLabel(week) }}
        </span>
      </summary>

      <div class="border-t border-slate-100 p-5">
        <p v-if="loadingDetail[week.week_start]" class="text-sm text-slate-500">Loading...</p>

        <template v-else-if="details[week.week_start]">
          <div
            v-if="weekState(week) === 'submitted'"
            class="mb-4 rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800"
          >
            Submitted — awaiting supervisor review.
          </div>
          <div
            v-else-if="weekState(week) === 'approved'"
            class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
          >
            Approved.
          </div>

          <div>
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Daily Entries (Reference)</h3>
            <div class="overflow-x-auto">
              <table class="mt-2 min-w-full divide-y divide-slate-200">
                <thead>
                  <tr>
                    <th class="whitespace-nowrap py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Date</th>
                    <th class="whitespace-nowrap py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                    <th class="whitespace-nowrap py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Summary</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-if="details[week.week_start].daily_entries.length === 0">
                    <td colspan="3" class="py-3 text-sm text-slate-400">No daily entries this week.</td>
                  </tr>
                  <tr v-for="entry in details[week.week_start].daily_entries" :key="entry.entry_date">
                    <td class="whitespace-nowrap py-3 text-sm text-slate-600">{{ formatDate(entry.entry_date) }}</td>
                    <td class="py-3 text-sm capitalize text-slate-600">{{ entry.status }}</td>
                    <td class="py-3 text-sm text-slate-800">{{ Object.values(entry.content)[0] ?? '' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="mt-5">
            <!-- Submitted/approved weeks are read-only: always the paper document. -->
            <template v-if="!isEditable(week)">
              <h3 class="text-sm font-bold text-slate-900">Weekly Narrative</h3>
              <div class="mt-2 rounded-md bg-slate-100 p-4 sm:p-6">
                <WeeklyJournalPaperView
                  :narrative="details[week.week_start].narrative ?? ''"
                  :student-name="auth.user?.name ?? ''"
                  :week-start="week.week_start"
                  :week-end="week.week_end"
                />
              </div>
            </template>

            <!-- Draft/returned weeks get an Edit ⇄ Preview switch; Save/Submit
                 stay in the action bar below so the student can submit from
                 Preview after seeing the rendered document. -->
            <template v-else>
              <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-bold text-slate-900">Weekly Narrative</h3>
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-100 p-0.5 text-sm font-semibold">
                  <button
                    type="button"
                    class="rounded-md px-4 py-1.5 transition"
                    :class="!previewMode[week.week_start] ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    @click="previewMode[week.week_start] = false"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    class="rounded-md px-4 py-1.5 transition"
                    :class="previewMode[week.week_start] ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                    @click="previewMode[week.week_start] = true"
                  >
                    Preview
                  </button>
                </div>
              </div>

              <div v-if="previewMode[week.week_start]" class="mt-2 rounded-md bg-slate-100 p-4 sm:p-6">
                <WeeklyJournalPaperView
                  :narrative="details[week.week_start].narrative ?? ''"
                  :student-name="auth.user?.name ?? ''"
                  :week-start="week.week_start"
                  :week-end="week.week_end"
                />
              </div>

              <div v-else class="mt-2">
                <p class="text-xs text-slate-400">
                  Summarize your week. This is what your supervisor reviews — write it in full sentences.
                </p>
                <textarea
                  v-model="details[week.week_start].narrative"
                  rows="14"
                  class="mt-2 min-h-72 w-full rounded-md border px-4 py-3 text-sm leading-6 text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                  :class="isNarrativeOverLimit(week.week_start) ? 'border-red-400' : 'border-slate-300'"
                />
                <p class="mt-1 text-right font-mono text-xs" :class="isNarrativeOverLimit(week.week_start) ? 'font-semibold text-red-600' : 'text-slate-400'">
                  {{ narrativeLength(week.week_start) }} / {{ WEEKLY_NARRATIVE_CHAR_LIMIT }}
                </p>
              </div>
            </template>
          </div>

          <div v-if="week.status === 'returned' && week.supervisor_comment" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Supervisor's note: {{ week.supervisor_comment }}
          </div>

          <div class="mt-5 flex flex-wrap items-center justify-end gap-3">
            <span v-if="saveMessage[week.week_start]" class="mr-auto text-sm text-slate-500">{{ saveMessage[week.week_start] }}</span>
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
              @click="downloadWeeklyLogPdf(week.week_start)"
            >
              Download PDF
            </button>
            <button
              v-if="isEditable(week)"
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:grayscale disabled:cursor-not-allowed"
              :disabled="savingDetail[week.week_start] || isNarrativeOverLimit(week.week_start)"
              @click="saveNarrative(week.week_start)"
            >
              {{ savingDetail[week.week_start] ? 'Saving...' : 'Save Narrative' }}
            </button>
            <button
              v-if="isEditable(week)"
              type="button"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:grayscale disabled:cursor-not-allowed"
              :disabled="submittingDetail[week.week_start] || !details[week.week_start].narrative?.trim() || isNarrativeOverLimit(week.week_start)"
              @click="submitWeek(week)"
            >
              {{ submittingDetail[week.week_start] ? 'Submitting...' : (weekState(week) === 'returned' ? 'Resubmit' : 'Send to Supervisor') }}
            </button>
          </div>
        </template>
      </div>
    </details>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import WeeklyJournalPaperView from '@/components/journal/WeeklyJournalPaperView.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { SupervisorJournalDetail, SupervisorJournalRow, SupervisorReviewStatus } from '@/types/api'

const tabs: { key: SupervisorReviewStatus; label: string }[] = [
  { key: 'pending', label: 'Pending' },
  { key: 'approved', label: 'Approved' },
  { key: 'returned', label: 'Returned' },
]

const activeStatus = ref<SupervisorReviewStatus>('pending')
const logs = ref<SupervisorJournalRow[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const isDetailOpen = ref(false)
const isDetailLoading = ref(false)
const detail = ref<SupervisorJournalDetail | null>(null)

const showReturnForm = ref(false)
const returnComment = ref('')
const isSubmitting = ref(false)
const reviewError = ref('')

const statusClass = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'bg-green-50 text-green-700'
  if (status === 'returned') return 'bg-red-50 text-red-700'
  return 'bg-amber-50 text-amber-700'
}

/**
 * This pill used to render the raw DB enum ("pending") relying on CSS
 * `capitalize`. Wording is kept identical to CoordinatorWeeklyJournalsPage —
 * both are staff reading someone else's journal, so they must agree. The
 * student's page deliberately says more ("Approved by Supervisor"), which is
 * right for them and would read oddly to the supervisor who did the approving.
 */
const statusLabel = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'Approved'
  if (status === 'returned') return 'Returned'
  return 'Pending Review'
}

/**
 * `submitted_at` / `reviewed_at` are genuine instants carrying a timezone
 * marker ("2026-07-20T21:00:00+00:00"), so parsing and formatting locally is
 * correct — the exact moment is what matters. The untouched original stays in a
 * tooltip beside it.
 */
const formatInstant = (iso: string | null | undefined): string | null => {
  if (!iso) return null
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return null

  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

const formatDateTime = (iso: string | null): string => (iso ? new Date(iso).toLocaleString() : '—')

/**
 * Date-only values are sliced, never parsed. `entry_date` is a `date`-cast
 * column serialised at midnight UTC ("2026-07-13T00:00:00.000000Z"); parsing it
 * and re-formatting lands on the 12th once the app runs in Asia/Manila.
 * `week_start`/`week_end` already arrive as a bare Y-m-d and pass through.
 */
const dateOnly = (value: string | null | undefined): string | null => {
  const trimmed = value?.trim()

  return trimmed ? trimmed.slice(0, 10) : null
}

/**
 * Journal fields come from coordinator-authored templates and differ per
 * program, so there is no fixed set to map and the API returns no label — only
 * the snake_case key. Derive the label from the key instead: a hardcoded map
 * would silently fall back to raw keys for any template it did not anticipate.
 */
const fieldLabel = (key: string): string =>
  key
    .split('_')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')

/** Skip blank fields rather than printing an empty label. */
const filledContent = (content: Record<string, string> | null | undefined): [string, string][] =>
  Object.entries(content ?? {}).filter(([, value]) => typeof value === 'string' && value.trim() !== '')

const entriesOpen = ref(true)

const EMPTY_STATE: Record<SupervisorReviewStatus, string> = {
  pending: 'Nothing waiting for review.',
  approved: 'No approved journals yet.',
  returned: 'No returned journals yet.',
}

const downloadPdf = () => {
  if (!detail.value) return
  window.open(`/api/supervisor/journals/${detail.value.id}/pdf`, '_blank')
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.get<{ status: SupervisorReviewStatus; logs: SupervisorJournalRow[] }>('/api/supervisor/journals', {
      params: { status: activeStatus.value },
    })
    logs.value = data.logs
  } catch {
    errorMessage.value = 'Unable to load weekly journals.'
  } finally {
    isLoading.value = false
  }
}

const selectTab = (status: SupervisorReviewStatus) => {
  if (status === activeStatus.value) return
  activeStatus.value = status
  load()
}

const openReview = async (row: SupervisorJournalRow) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detail.value = null
  showReturnForm.value = false
  returnComment.value = ''
  reviewError.value = ''

  try {
    const { data } = await api.get<SupervisorJournalDetail>(`/api/supervisor/journals/${row.id}`)
    detail.value = data
  } catch {
    reviewError.value = 'Unable to load this weekly journal.'
  } finally {
    isDetailLoading.value = false
  }
}

const closeDetail = () => {
  isDetailOpen.value = false
  detail.value = null
}

const approve = async () => {
  if (!detail.value) return
  isSubmitting.value = true
  reviewError.value = ''
  try {
    await api.post(`/api/supervisor/journals/${detail.value.id}/approve`)
    showToast('Weekly journal approved.')
    closeDetail()
    await load()
  } catch (error) {
    reviewError.value = axios.isAxiosError(error) ? error.response?.data?.message ?? 'Unable to approve.' : 'Unable to approve.'
  } finally {
    isSubmitting.value = false
  }
}

const submitReturn = async () => {
  if (!detail.value) return
  reviewError.value = ''

  if (!returnComment.value.trim()) {
    reviewError.value = 'Please explain what the student needs to fix.'
    return
  }
  const confirmed = await confirmAction({
    title: 'Return this journal for revision?',
    message:
      'Return this weekly journal to the student for revision? They will see your comment and can edit and resubmit it.',
    confirmLabel: 'Return Journal',
  })
  if (!confirmed) return

  isSubmitting.value = true
  try {
    await api.post(`/api/supervisor/journals/${detail.value.id}/return`, { supervisor_comment: returnComment.value })
    showToast('Weekly journal returned to the student.')
    closeDetail()
    await load()
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      reviewError.value = error.response.data.errors?.supervisor_comment?.[0] ?? 'Please fix the errors.'
    } else {
      reviewError.value = 'Unable to return this journal.'
    }
  } finally {
    isSubmitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <div class="rounded-xl border border-blue-100 bg-blue-50 p-6 text-sm text-blue-800">
      Review your interns' <strong>weekly narrative journals</strong>. Approve a journal, or return it with a comment so
      the student can revise it.
    </div>

    <div class="flex gap-1 border-b border-slate-200">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="border-b-2 px-5 py-3 text-sm font-medium transition"
        :class="tab.key === activeStatus ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700'"
        @click="selectTab(tab.key)"
      >
        {{ tab.label }}
      </button>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <p v-if="logs.length === 0" class="rounded-xl bg-white px-6 py-8 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200/70">
        {{ EMPTY_STATE[activeStatus] }}
      </p>

      <template v-else>
        <!-- md and up: aligned table. `table-fixed` makes the colgroup binding. -->
        <div class="hidden overflow-x-auto rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:block">
          <table class="w-full table-fixed">
            <colgroup>
              <col />
              <col class="w-[220px]" />
              <col class="w-[120px]" />
              <col class="w-[150px]" />
              <col class="w-[110px]" />
            </colgroup>
            <thead>
              <tr class="text-xs font-medium uppercase tracking-wide text-slate-400">
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pl-0 pr-4 pt-4 text-left font-medium">Student</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Week</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Daily Entries</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-right font-medium">Submitted</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pl-4 pr-0 pt-4 text-right font-medium">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="log in logs" :key="log.id" class="transition-colors hover:bg-slate-50/70">
                <td class="py-3.5 pl-0 pr-4">
                  <p class="truncate text-sm font-medium text-slate-900">{{ log.student_name }}</p>
                  <p class="truncate text-xs text-slate-400">{{ log.student_id_number ?? '—' }}</p>
                </td>
                <td class="whitespace-nowrap px-4 py-3.5 text-sm tabular-nums text-slate-500">
                  {{ dateOnly(log.week_start) ?? '—' }} – {{ dateOnly(log.week_end) ?? '—' }}
                </td>
                <td class="whitespace-nowrap px-4 py-3.5">
                  <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ log.entries_count }} entries</span>
                </td>
                <td class="whitespace-nowrap px-4 py-3.5 text-right text-sm tabular-nums text-slate-500">
                  <TooltipWrap v-if="formatInstant(log.submitted_at)" :label="log.submitted_at ?? ''" placement="top" align="end">
                    <span>{{ formatInstant(log.submitted_at) }}</span>
                  </TooltipWrap>
                  <span v-else class="text-slate-300">—</span>
                </td>
                <td class="whitespace-nowrap py-3.5 pl-4 pr-0 text-right">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    @click="openReview(log)"
                  >
                    Review
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Below md: one stacked block per journal, so nothing scrolls sideways. -->
        <ul class="divide-y divide-slate-100 rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:hidden">
          <li v-for="log in logs" :key="log.id" class="py-4">
            <div class="flex items-start justify-between gap-3">
              <p class="min-w-0 truncate text-sm font-medium text-slate-900">{{ log.student_name }}</p>
              <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                {{ log.entries_count }} entries
              </span>
            </div>
            <p class="mt-1 text-xs tabular-nums text-slate-500">
              {{ dateOnly(log.week_start) ?? '—' }} – {{ dateOnly(log.week_end) ?? '—' }}
            </p>
            <p class="mt-1 text-xs text-slate-400">Submitted {{ formatInstant(log.submitted_at) ?? '—' }}</p>
            <button
              type="button"
              class="mt-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
              @click="openReview(log)"
            >
              Review
            </button>
          </li>
        </ul>
      </template>
    </LoadStatus>

    <!-- Review modal: three-part flex shell, body is the only scroller. -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <section class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="shrink-0 border-b border-slate-200 px-6 py-4">
          <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
              <h3 class="truncate text-xl font-semibold text-slate-900">{{ detail?.student.name ?? 'Weekly Journal' }}</h3>
              <p v-if="detail" class="mt-0.5 text-sm text-slate-500">
                Week {{ dateOnly(detail.week_start) ?? '—' }} – {{ dateOnly(detail.week_end) ?? '—' }}
              </p>
            </div>
            <button type="button" class="shrink-0 text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeDetail">
              Close
            </button>
          </div>
          <div v-if="detail" class="mt-3 flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(detail.status)">{{ statusLabel(detail.status) }}</span>
            <span class="text-xs text-slate-400">
              Submitted
              <TooltipWrap v-if="formatInstant(detail.submitted_at)" :label="detail.submitted_at ?? ''" placement="bottom">
                <span>{{ formatInstant(detail.submitted_at) }}</span>
              </TooltipWrap>
              <span v-else>—</span>
            </span>
          </div>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <p v-if="isDetailLoading" class="text-sm text-slate-500">Loading...</p>

          <div v-else-if="detail" class="space-y-5">
            <!-- Document preview: the weekly narrative rendered as the same
                 typed document the PDF produces. Status/actions stay out here
                 in the page chrome. -->
            <div class="rounded-md bg-slate-100 p-4 sm:p-6">
              <WeeklyJournalPaperView
                :narrative="detail.narrative"
                :student-name="detail.student.name"
                :week-start="detail.week_start"
                :week-end="detail.week_end"
              />
            </div>

            <div v-if="detail.supervisor_comment">
              <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Your Previous Comment</h4>
              <p class="mt-2 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ detail.supervisor_comment }}</p>
            </div>

            <div class="rounded-lg ring-1 ring-slate-200">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-slate-50"
                :aria-expanded="entriesOpen"
                @click="entriesOpen = !entriesOpen"
              >
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">
                  Daily Entries This Week ({{ detail.daily_entries.length }})
                </span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  viewBox="0 0 24 24"
                  fill="none"
                  class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                  :class="entriesOpen && 'rotate-180'"
                >
                  <path d="m6 9.5 6 6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </button>

              <div v-if="entriesOpen" class="border-t border-slate-100 p-4">
                <p v-if="detail.daily_entries.length === 0" class="text-sm text-slate-400">No daily entries for this week.</p>

                <div v-else class="space-y-3">
                  <article v-for="entry in detail.daily_entries" :key="entry.entry_date" class="rounded-lg p-4 ring-1 ring-slate-200">
                    <div class="flex items-center justify-between gap-3">
                      <p class="text-sm font-medium tabular-nums text-slate-900">{{ dateOnly(entry.entry_date) ?? '—' }}</p>
                      <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold capitalize" :class="statusClass(entry.status as SupervisorReviewStatus)">
                        {{ entry.status }}
                      </span>
                    </div>

                    <!--
                      Labels are derived from the key, never mapped: these fields
                      come from coordinator-authored templates and vary per
                      program, so any hardcoded list would fall back to raw
                      snake_case for a template it did not anticipate.
                    -->
                    <dl class="mt-3 space-y-3">
                      <div v-for="[key, value] in filledContent(entry.content)" :key="key">
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ fieldLabel(key) }}</dt>
                        <dd class="mt-1 text-sm text-slate-700">{{ value }}</dd>
                      </div>
                    </dl>
                  </article>
                </div>
              </div>
            </div>

            <p v-if="reviewError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ reviewError }}</p>

            <div v-if="showReturnForm" class="space-y-3">
              <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Comment (what should the student fix?)</span>
                <textarea
                  v-model="returnComment"
                  rows="3"
                  maxlength="2000"
                  class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                  placeholder="Explain what needs revision..."
                ></textarea>
              </label>
            </div>

            <p v-if="!detail.reviewable" class="text-sm text-slate-500">
              This journal was reviewed {{ formatDateTime(detail.reviewed_at) }} and can no longer be changed.
            </p>
          </div>
        </div>

        <div v-if="detail" class="flex shrink-0 flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
          <button type="button" class="mr-auto text-sm font-semibold text-blue-600 transition hover:text-blue-700" @click="downloadPdf">
            Download PDF
          </button>

          <template v-if="detail.reviewable">
            <template v-if="!showReturnForm">
              <button
                type="button"
                class="rounded-md border border-red-300 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="isSubmitting"
                @click="showReturnForm = true"
              >
                Return with Comment
              </button>
              <button
                type="button"
                class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="isSubmitting"
                @click="approve"
              >
                {{ isSubmitting ? 'Working...' : 'Approve' }}
              </button>
            </template>

            <template v-else>
              <button
                type="button"
                class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                @click="showReturnForm = false"
              >
                Cancel
              </button>
              <button
                type="button"
                class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="isSubmitting"
                @click="submitReturn"
              >
                {{ isSubmitting ? 'Returning...' : 'Return Journal' }}
              </button>
            </template>
          </template>
        </div>
      </section>
    </div>
  </section>
</template>

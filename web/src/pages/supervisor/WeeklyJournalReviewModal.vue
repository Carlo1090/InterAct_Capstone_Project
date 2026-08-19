<script setup lang="ts">
import { ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import WeeklyJournalPaperView from '@/components/journal/WeeklyJournalPaperView.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { SupervisorJournalDetail, SupervisorReviewStatus } from '@/types/api'

// Standalone "review one weekly log" modal, shared by the Journals page (list
// scoped to all interns) and the Interns page's "View Journals" modal (list
// scoped to one intern) — both need the identical approve/return flow, so it
// lives here once rather than being duplicated per page.
const props = defineProps<{ weeklyLogId: number }>()
const emit = defineEmits<{ close: []; reviewed: [] }>()

const isDetailLoading = ref(false)
const detail = ref<SupervisorJournalDetail | null>(null)

const showReturnForm = ref(false)
const returnComment = ref('')
const isSubmitting = ref(false)
const reviewError = ref('')
const entriesOpen = ref(true)

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

const downloadPdf = () => {
  if (!detail.value) return
  window.open(`/api/supervisor/journals/${detail.value.id}/pdf`, '_blank')
}

const loadDetail = async () => {
  isDetailLoading.value = true
  detail.value = null
  showReturnForm.value = false
  returnComment.value = ''
  reviewError.value = ''

  try {
    const { data } = await api.get<SupervisorJournalDetail>(`/api/supervisor/journals/${props.weeklyLogId}`)
    detail.value = data
  } catch {
    reviewError.value = 'Unable to load this weekly journal.'
  } finally {
    isDetailLoading.value = false
  }
}

const approve = async () => {
  if (!detail.value) return
  isSubmitting.value = true
  reviewError.value = ''
  try {
    await api.post(`/api/supervisor/journals/${detail.value.id}/approve`)
    showToast('Weekly journal approved.')
    emit('reviewed')
    emit('close')
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
    emit('reviewed')
    emit('close')
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

watch(() => props.weeklyLogId, loadDetail, { immediate: true })
</script>

<template>
  <!-- Review modal: three-part flex shell, body is the only scroller. -->
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
    <section class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
      <div class="shrink-0 border-b border-slate-200 px-6 py-4">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h3 class="truncate text-xl font-semibold text-slate-900">{{ detail?.student.name ?? 'Weekly Journal' }}</h3>
            <p v-if="detail" class="mt-0.5 text-sm text-slate-500">
              Week {{ dateOnly(detail.week_start) ?? '—' }} – {{ dateOnly(detail.week_end) ?? '—' }}
            </p>
          </div>
          <button type="button" class="shrink-0 text-sm font-medium text-slate-500 hover:text-slate-900" @click="emit('close')">
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
        <p v-else-if="reviewError && !detail" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ reviewError }}</p>

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
</template>

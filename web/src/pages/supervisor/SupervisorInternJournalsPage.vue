<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import WeeklyJournalPaperView from '@/components/journal/WeeklyJournalPaperView.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type {
  SupervisorInternNotebook,
  SupervisorJournalDetail,
  SupervisorNotebookWeek,
  SupervisorReviewStatus,
} from '@/types/api'

/*
 * One intern's journal notebook: the index of every week they have handed in
 * down the left, the selected week rendered as the document itself on the
 * right. The Journals page is the cross-intern review QUEUE (one status at a
 * time, whoever submitted most recently); this is the opposite cut — one
 * intern, every week, front to back, the way a supervisor reads a paper
 * notebook. Review actions are the same endpoints either way.
 */

const route = useRoute()
const studentId = computed(() => Number(route.params.studentId))

const notebook = ref<SupervisorInternNotebook | null>(null)
const isLoading = ref(true)
const errorMessage = ref('')

// Week details are fetched on demand and kept, so paging back and forth
// through the notebook never re-requests a week already read.
const detailCache = new Map<number, SupervisorJournalDetail>()
const selectedId = ref<number | null>(null)
const detail = ref<SupervisorJournalDetail | null>(null)
const isDetailLoading = ref(false)
const detailError = ref('')

const statusFilter = ref<SupervisorReviewStatus | null>(null)

// Collapsed by default here, unlike the review modal. A modal shows one week
// and scrolls on its own; this page pages through a whole placement, and five
// fully-expanded daily entries per week buries the next week's document under
// a screen and a half of scroll. The count is on the toggle, and the choice
// deliberately persists as the supervisor moves between weeks.
const entriesOpen = ref(false)

const showReturnForm = ref(false)
const returnComment = ref('')
const isSubmitting = ref(false)
const reviewError = ref('')

const statusClass = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'bg-green-50 text-green-700'
  if (status === 'returned') return 'bg-red-50 text-red-700'
  return 'bg-amber-50 text-amber-700'
}

const statusDotClass = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'bg-green-500'
  if (status === 'returned') return 'bg-red-500'
  return 'bg-amber-500'
}

/** Wording kept identical to the review queue and the coordinator's copy. */
const statusLabel = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'Approved'
  if (status === 'returned') return 'Returned'
  return 'Pending Review'
}

/**
 * `submitted_at` / `reviewed_at` are genuine instants carrying a timezone
 * marker, so parsing and formatting locally is correct. Date-only values are
 * sliced instead (see `dateOnly`).
 */
const formatInstant = (iso: string | null | undefined): string | null => {
  if (!iso) return null
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return null

  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

/**
 * `week_start`/`week_end` are `date`-cast columns; parsing them and
 * re-formatting lands a day earlier once the app runs in Asia/Manila. Slice.
 */
const dateOnly = (value: string | null | undefined): string | null => {
  const trimmed = value?.trim()

  return trimmed ? trimmed.slice(0, 10) : null
}

/** "Jul 6" — the index column has no room for a full date on both ends. */
const shortDate = (value: string | null | undefined): string => {
  const iso = dateOnly(value)
  if (!iso) return '—'
  const [, month, day] = iso.split('-')
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

  return `${months[Number(month) - 1] ?? month} ${Number(day)}`
}

/**
 * Journal fields come from coordinator-authored templates and differ per
 * program, so the label is derived from the key rather than mapped — a
 * hardcoded list falls back to raw snake_case for any template it did not
 * anticipate.
 */
const fieldLabel = (key: string): string =>
  key
    .split('_')
    .filter(Boolean)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')

const filledContent = (content: Record<string, string> | null | undefined): [string, string][] =>
  Object.entries(content ?? {}).filter(([, value]) => typeof value === 'string' && value.trim() !== '')

const initials = computed(() => {
  const name = notebook.value?.student.name ?? ''

  return (
    name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.charAt(0).toUpperCase())
      .join('') || '?'
  )
})

const visibleWeeks = computed<SupervisorNotebookWeek[]>(() => {
  const weeks = notebook.value?.weeks ?? []

  return statusFilter.value ? weeks.filter((week) => week.status === statusFilter.value) : weeks
})

const selectedIndex = computed(() => visibleWeeks.value.findIndex((week) => week.id === selectedId.value))
const previousWeek = computed(() => (selectedIndex.value > 0 ? visibleWeeks.value[selectedIndex.value - 1] : null))
const nextWeek = computed(() =>
  selectedIndex.value >= 0 && selectedIndex.value < visibleWeeks.value.length - 1
    ? visibleWeeks.value[selectedIndex.value + 1]
    : null
)

const selectedWeek = computed(() => notebook.value?.weeks.find((week) => week.id === selectedId.value) ?? null)

/**
 * The tally pills double as the index filter — clicking "3 pending" narrows
 * the notebook to those weeks, clicking it again clears. One control, and it
 * reads as a summary whether or not you realise it is pressable.
 */
const toggleFilter = (status: SupervisorReviewStatus) => {
  statusFilter.value = statusFilter.value === status ? null : status
}

const tallyPills = computed(() => {
  const totals = notebook.value?.totals

  return [
    {
      key: 'pending' as SupervisorReviewStatus,
      label: 'pending',
      count: totals?.pending ?? 0,
      on: 'bg-amber-100 text-amber-800 ring-amber-300',
      off: 'bg-amber-50 text-amber-700 ring-transparent',
    },
    {
      key: 'approved' as SupervisorReviewStatus,
      label: 'approved',
      count: totals?.approved ?? 0,
      on: 'bg-green-100 text-green-800 ring-green-300',
      off: 'bg-green-50 text-green-700 ring-transparent',
    },
    {
      key: 'returned' as SupervisorReviewStatus,
      label: 'returned',
      count: totals?.returned ?? 0,
      on: 'bg-red-100 text-red-800 ring-red-300',
      off: 'bg-red-50 text-red-700 ring-transparent',
    },
  ]
})

const openWeek = async (week: SupervisorNotebookWeek) => {
  selectedId.value = week.id
  showReturnForm.value = false
  returnComment.value = ''
  reviewError.value = ''
  detailError.value = ''

  const cached = detailCache.get(week.id)
  if (cached) {
    detail.value = cached
    return
  }

  isDetailLoading.value = true
  detail.value = null
  try {
    const { data } = await api.get<SupervisorJournalDetail>(`/api/supervisor/journals/${week.id}`)
    detailCache.set(week.id, data)
    // A slower earlier request must not paint over a week selected since.
    if (selectedId.value === week.id) detail.value = data
  } catch {
    if (selectedId.value === week.id) detailError.value = 'Unable to load this week.'
  } finally {
    isDetailLoading.value = false
  }
}

/**
 * Open the week that most wants attention: the first one still awaiting
 * review, else the most recent week in the notebook.
 */
const defaultWeek = (weeks: SupervisorNotebookWeek[]): SupervisorNotebookWeek | null =>
  weeks.find((week) => week.reviewable) ?? weeks[weeks.length - 1] ?? null

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.get<SupervisorInternNotebook>(`/api/supervisor/interns/${studentId.value}/journals`)
    notebook.value = data

    const stillListed = data.weeks.some((week) => week.id === selectedId.value)
    if (!stillListed) {
      const target = defaultWeek(data.weeks)
      if (target) await openWeek(target)
      else selectedId.value = null
    }
  } catch (error) {
    errorMessage.value =
      axios.isAxiosError(error) && error.response?.status === 403
        ? 'This student is not one of your interns.'
        : 'Unable to load this intern\'s journals.'
  } finally {
    isLoading.value = false
  }
}

const downloadPdf = () => {
  if (!detail.value) return
  window.open(`/api/supervisor/journals/${detail.value.id}/pdf`, '_blank')
}

/** Refresh both the row (status, tallies) and the cached document after a verdict. */
const refreshAfterReview = async () => {
  if (selectedId.value !== null) detailCache.delete(selectedId.value)
  const reviewed = selectedId.value
  await load()
  if (reviewed !== null) {
    const week = notebook.value?.weeks.find((row) => row.id === reviewed)
    if (week) await openWeek(week)
  }
}

const approve = async () => {
  if (!detail.value) return
  isSubmitting.value = true
  reviewError.value = ''
  try {
    await api.post(`/api/supervisor/journals/${detail.value.id}/approve`)
    showToast('Weekly journal approved.')
    await refreshAfterReview()
  } catch (error) {
    reviewError.value = axios.isAxiosError(error)
      ? error.response?.data?.message ?? 'Unable to approve.'
      : 'Unable to approve.'
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
    showReturnForm.value = false
    returnComment.value = ''
    await refreshAfterReview()
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

// Navigating straight from one intern's notebook to another's keeps the
// component mounted, so the id has to be watched rather than read once.
watch(studentId, () => {
  detailCache.clear()
  selectedId.value = null
  detail.value = null
  statusFilter.value = null
  load()
})

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <RouterLink
      to="/supervisor/interns"
      class="inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 transition hover:text-slate-900"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
        <path d="M19 12H5M11 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      Back to Interns
    </RouterLink>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <div v-if="notebook" class="space-y-5">
        <!-- Identity header: who this notebook belongs to, and the shape of it. -->
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-center gap-4">
              <img
                v-if="notebook.student.avatar_url"
                :src="notebook.student.avatar_url"
                :alt="notebook.student.name"
                class="h-14 w-14 shrink-0 rounded-full object-cover ring-1 ring-slate-200"
              />
              <span
                v-else
                class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-blue-50 text-lg font-semibold text-blue-700"
              >
                {{ initials }}
              </span>
              <div class="min-w-0">
                <h2 class="truncate text-xl font-semibold tracking-tight text-slate-900">{{ notebook.student.name }}</h2>
                <p class="mt-0.5 truncate text-sm text-slate-500">
                  <span class="font-mono">{{ notebook.student.student_id_number ?? '—' }}</span>
                  <span v-if="notebook.student.program"> · {{ notebook.student.program }}</span>
                  <span v-if="notebook.student.company"> · {{ notebook.student.company }}</span>
                </p>
                <p v-if="notebook.student.batch" class="truncate text-xs text-slate-400">{{ notebook.student.batch }}</p>
              </div>
            </div>

            <!--
              Tallies double as the index filter. Rendered as real buttons with
              aria-pressed so the toggle is not hover-only knowledge.
            -->
            <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
              <button
                v-for="pill in tallyPills"
                :key="pill.key"
                type="button"
                class="rounded-full px-3 py-1 text-xs font-bold ring-1 transition disabled:cursor-default disabled:opacity-60"
                :class="statusFilter === pill.key ? pill.on : pill.off"
                :aria-pressed="statusFilter === pill.key"
                :aria-label="`Show only ${pill.label} weeks`"
                :disabled="pill.count === 0"
                @click="toggleFilter(pill.key)"
              >
                {{ pill.count }} {{ pill.label }}
              </button>
              <button
                v-if="statusFilter"
                type="button"
                class="rounded-full px-3 py-1 text-xs font-semibold text-slate-500 underline-offset-2 transition hover:text-slate-900 hover:underline"
                @click="statusFilter = null"
              >
                Show all
              </button>
            </div>
          </div>
        </div>

        <p v-if="notebook.weeks.length === 0" class="rounded-lg bg-white px-4 py-10 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
          {{ notebook.student.name }} has not submitted any weekly journals yet.
        </p>

        <div v-else class="grid gap-5 lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-start">
          <!-- Index. Sticky on desktop so the document can scroll past it. -->
          <div class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200 lg:sticky lg:top-6">
            <div class="flex items-baseline justify-between gap-2 border-b border-slate-100 px-4 py-3">
              <h3 class="text-xs font-medium uppercase tracking-wide text-slate-400">Weeks</h3>
              <span class="text-xs tabular-nums text-slate-400">{{ visibleWeeks.length }} of {{ notebook.totals.total }}</span>
            </div>

            <p v-if="visibleWeeks.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">
              No weeks match that filter.
            </p>

            <!--
              A row of chips below lg (the horizontal scroll is the container's
              own, so the page never scrolls sideways), a vertical list above.
            -->
            <ul
              v-else
              class="flex gap-2 overflow-x-auto px-3 py-3 lg:max-h-[28rem] lg:flex-col lg:gap-1 lg:overflow-y-auto lg:px-2"
            >
              <li v-for="week in visibleWeeks" :key="week.id" class="shrink-0 lg:shrink lg:w-full">
                <button
                  type="button"
                  class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left transition"
                  :class="
                    week.id === selectedId
                      ? 'bg-blue-50 ring-1 ring-blue-200'
                      : 'ring-1 ring-transparent hover:bg-slate-50 lg:ring-0'
                  "
                  :aria-current="week.id === selectedId ? 'true' : undefined"
                  @click="openWeek(week)"
                >
                  <span class="h-2 w-2 shrink-0 rounded-full" :class="statusDotClass(week.status)" />
                  <span class="min-w-0">
                    <span
                      class="block whitespace-nowrap text-sm font-semibold"
                      :class="week.id === selectedId ? 'text-blue-800' : 'text-slate-800'"
                    >
                      Week {{ week.week_number }}
                    </span>
                    <span class="block whitespace-nowrap text-xs tabular-nums text-slate-500">
                      {{ shortDate(week.week_start) }} – {{ shortDate(week.week_end) }}
                    </span>
                  </span>
                  <span
                    v-if="week.reviewable"
                    class="ml-auto hidden shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700 lg:inline"
                  >
                    Review
                  </span>
                </button>
              </li>
            </ul>

            <p
              v-if="notebook.totals.drafts_hidden > 0"
              class="border-t border-slate-100 px-4 py-3 text-xs text-slate-400"
            >
              {{ notebook.totals.drafts_hidden }}
              {{ notebook.totals.drafts_hidden === 1 ? 'week is' : 'weeks are' }} still being drafted and will appear
              here once submitted.
            </p>
          </div>

          <!-- The page itself. -->
          <div class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
            <div class="border-b border-slate-100 px-5 py-4">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                  <h3 class="text-sm font-semibold text-slate-900">
                    Week {{ selectedWeek?.week_number ?? '—' }}
                    <span class="font-normal tabular-nums text-slate-500">
                      · {{ dateOnly(selectedWeek?.week_start) ?? '—' }} – {{ dateOnly(selectedWeek?.week_end) ?? '—' }}
                    </span>
                  </h3>
                  <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                    <span>
                      Submitted
                      <TooltipWrap v-if="formatInstant(detail?.submitted_at)" :label="detail?.submitted_at ?? ''" placement="bottom">
                        <span>{{ formatInstant(detail?.submitted_at) }}</span>
                      </TooltipWrap>
                      <span v-else>—</span>
                    </span>
                    <span v-if="detail?.reviewed_at">
                      Reviewed
                      <TooltipWrap :label="detail.reviewed_at" placement="bottom">
                        <span>{{ formatInstant(detail.reviewed_at) }}</span>
                      </TooltipWrap>
                    </span>
                  </p>
                </div>
                <div class="flex items-center gap-2">
                  <span
                    v-if="selectedWeek"
                    class="rounded-full px-3 py-1 text-xs font-bold"
                    :class="statusClass(selectedWeek.status)"
                  >
                    {{ statusLabel(selectedWeek.status) }}
                  </span>
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!detail"
                    @click="downloadPdf"
                  >
                    Download PDF
                  </button>
                </div>
              </div>
            </div>

            <div class="px-4 py-5 sm:px-5">
              <p v-if="isDetailLoading" class="py-10 text-center text-sm text-slate-500">Loading this week...</p>
              <p v-else-if="detailError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ detailError }}</p>

              <div v-else-if="detail" class="space-y-5">
                <div class="rounded-md bg-slate-100 p-4 sm:p-6">
                  <WeeklyJournalPaperView
                    :narrative="detail.narrative"
                    :student-name="detail.student.name"
                    :week-start="detail.week_start"
                    :week-end="detail.week_end"
                  />
                </div>

                <div v-if="detail.supervisor_comment">
                  <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Your Comment on This Week</h4>
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
                    <p v-if="detail.daily_entries.length === 0" class="text-sm text-slate-400">
                      No daily entries for this week.
                    </p>

                    <div v-else class="space-y-3">
                      <article
                        v-for="entry in detail.daily_entries"
                        :key="entry.entry_date"
                        class="rounded-lg p-4 ring-1 ring-slate-200"
                      >
                        <div class="flex items-center justify-between gap-3">
                          <p class="text-sm font-medium tabular-nums text-slate-900">{{ dateOnly(entry.entry_date) ?? '—' }}</p>
                          <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold capitalize"
                            :class="statusClass(entry.status as SupervisorReviewStatus)"
                          >
                            {{ entry.status }}
                          </span>
                        </div>

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

                <div v-if="showReturnForm">
                  <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-400">
                      Comment (what should the student fix?)
                    </span>
                    <textarea
                      v-model="returnComment"
                      rows="3"
                      maxlength="2000"
                      class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                      placeholder="Explain what needs revision..."
                    ></textarea>
                  </label>
                </div>

                <p v-else-if="!detail.reviewable" class="text-sm text-slate-500">
                  This week was reviewed and can no longer be changed. It stays here as part of the notebook.
                </p>
              </div>
            </div>

            <!--
              Footer: paging through the notebook on the left, the verdict on
              the right. Both belong to the week on screen, so they sit
              together rather than the page turners living up in the index.
            -->
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="!previousWeek"
                  @click="previousWeek && openWeek(previousWeek)"
                >
                  ← Previous
                </button>
                <button
                  type="button"
                  class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                  :disabled="!nextWeek"
                  @click="nextWeek && openWeek(nextWeek)"
                >
                  Next →
                </button>
              </div>

              <div v-if="detail?.reviewable" class="flex flex-wrap items-center gap-3">
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
              </div>
            </div>
          </div>
        </div>
      </div>
    </LoadStatus>
  </section>
</template>

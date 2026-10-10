<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import CoordinatorActivityLog from '@/components/coordinator/CoordinatorActivityLog.vue'
import { categorizeError } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import type {
  CoordinatorDashboard,
  CoordinatorDashboardStats,
  CoordinatorInfoSheetRow,
  StudentBehind,
} from '@/types/api'

type StatIcon = 'people' | 'check' | 'briefcase'

/** Students Behind shows this many rows before "Show all N". */
const BEHIND_PREVIEW = 5

const auth = useAuthStore()

const stats = ref<CoordinatorDashboardStats>({
  active_interns: 0,
  journals_submitted_this_week: 0,
  journals_missing_this_week: 0,
  active_batches: 0,
  students_behind: 0,
})
const studentsBehind = ref<StudentBehind[]>([])
const week = ref<{ start: string; end: string }>({ start: '', end: '' })

const isLoading = ref(true)
const errorMessage = ref('')

// The info sheet card reads a different endpoint, so it carries its own error:
// a failure there must not blank out the whole dashboard.
const infoSheets = ref<CoordinatorInfoSheetRow[]>([])
const infoSheetError = ref('')

// A coordinator's own program_id is always null, so program.department is
// never the source here — their department comes through
// departments_coordinated instead (see CoordinatorLayout.vue's header, which
// had the identical bug: this always fell through to the fallback before).
const department = computed(() => auth.user?.departments_coordinated?.[0]?.code ?? 'your department')

// The sidebar's own "Daily Journal Activities" target, kept identical to the nav.
const JOURNAL_ACTIVITIES_ROUTE = '/coordinator/journal-activities'

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const firstName = computed(() => (auth.user?.name ?? '').trim().split(/\s+/)[0] ?? '')
const greeting = computed(() => (firstName.value ? `Hello, ${firstName.value}` : 'Hello'))

/**
 * The role label, not the department: `/api/user` loads only `program.department`
 * and a coordinator's `program_id` is null, so no department name reaches the
 * frontend. Showing one here would mean inventing it.
 */
const heroSubline = computed(() => 'Coordinator')

/**
 * "Mon, Oct 5" from a Y-m-d. Split, never `new Date('2026-10-05')` — that
 * parses as UTC midnight and can land a day early. (PROJECT.md)
 */
const formatWeekday = (raw: string): string => {
  const iso = raw.slice(0, 10)
  if (!iso) return ''
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
}

const weekStartLabel = computed(() => formatWeekday(week.value.start))

/**
 * Three cards. The old fourth, Missing This Week, duplicated Students Behind
 * below it. The last card spans both columns at sm so the 2-column step never
 * leaves an orphan beside empty space.
 */
const statCards = computed<
  { label: string; value: number; sub: string; card: string; tile: string; icon: StatIcon; span?: string }[]
>(() => [
  {
    label: 'My Interns',
    value: stats.value.active_interns,
    sub: 'Active enrollments in scope',
    card: 'bg-blue-50/40',
    tile: 'bg-white ring-1 ring-slate-200/70 text-blue-600',
    icon: 'people',
  },
  {
    label: 'Submitted This Week',
    value: stats.value.journals_submitted_this_week,
    sub: `Since ${weekStartLabel.value}`,
    card: 'bg-emerald-50/40',
    tile: 'bg-white ring-1 ring-slate-200/70 text-emerald-600',
    icon: 'check',
  },
  {
    label: 'Active Batches',
    value: stats.value.active_batches,
    sub: 'Running in your programs',
    card: 'bg-amber-50/40',
    tile: 'bg-white ring-1 ring-slate-200/70 text-amber-600',
    icon: 'briefcase',
    span: 'sm:col-span-2 lg:col-span-1',
  },
])

const showAllBehind = ref(false)
const visibleBehind = computed(() =>
  showAllBehind.value ? studentsBehind.value : studentsBehind.value.slice(0, BEHIND_PREVIEW),
)

const behindMeta = (student: StudentBehind): string =>
  [student.program, student.company || 'No company on file'].filter(Boolean).join(' · ')

const missingLabel = (count: number): string => `${count} ${count === 1 ? 'day' : 'days'} missing`

/**
 * The endpoint returns one row per information sheet ON FILE — a student who has
 * never started one has no row at all, so there is no honest "not started"
 * figure to show and the card is captioned accordingly.
 */
const infoSheetBreakdown = computed(() => {
  const counts = { approved: 0, submitted: 0, draft: 0, rejected: 0 }

  for (const sheet of infoSheets.value) {
    if (sheet.submission_status && sheet.submission_status in counts) {
      counts[sheet.submission_status] += 1
    }
  }

  const total = counts.approved + counts.submitted + counts.draft + counts.rejected
  const share = (count: number): number =>
    total > 0 ? Math.min(100, Math.max(0, (count / total) * 100)) : 0

  return {
    total,
    segments: [
      { key: 'approved', label: 'Approved', count: counts.approved, share: share(counts.approved), bar: 'bg-emerald-500', dot: 'bg-emerald-500' },
      { key: 'submitted', label: 'Submitted', count: counts.submitted, share: share(counts.submitted), bar: 'bg-amber-500', dot: 'bg-amber-500' },
      { key: 'draft', label: 'Draft', count: counts.draft, share: share(counts.draft), bar: 'bg-slate-400', dot: 'bg-slate-400' },
      // 'rejected' is surfaced as "Returned" everywhere in the coordinator UI.
      { key: 'rejected', label: 'Returned', count: counts.rejected, share: share(counts.rejected), bar: 'bg-rose-500', dot: 'bg-rose-500' },
    ],
  }
})

const infoSheetAriaLabel = computed(() => {
  const { total, segments } = infoSheetBreakdown.value
  if (total === 0) return 'Info sheet completion: no info sheets yet'

  return `Info sheet completion across ${total} sheets: ${segments
    .map((segment) => `${segment.count} ${segment.label.toLowerCase()}`)
    .join(', ')}`
})

const loadInfoSheets = async () => {
  infoSheetError.value = ''

  try {
    const { data } = await api.get<{ students: CoordinatorInfoSheetRow[] }>('/api/coordinator/info-sheets')
    infoSheets.value = data.students
  } catch (error) {
    infoSheetError.value = categorizeError(error, 'Unable to load info sheet progress.').message
  }
}

const loadDashboard = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<CoordinatorDashboard>('/api/coordinator/dashboard')
    stats.value = data.stats
    studentsBehind.value = data.students_behind
    week.value = data.week
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to load your dashboard.').message
  } finally {
    isLoading.value = false
  }
}

const load = async () => {
  await Promise.all([loadDashboard(), loadInfoSheets()])
}

onMounted(load)
</script>

<template>
  <section class="space-y-6">
    <div class="flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200/70">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 shrink-0 text-slate-400">
        <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
        <path d="M12 11v5M12 8h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
      <span>This workspace is scoped to <strong class="font-semibold text-slate-700">{{ department }}</strong>.</span>
      <TooltipWrap label="Every stat, list and report on this page counts only students in the programs assigned to you." placement="bottom" class="ml-auto shrink-0">
        <span
          aria-label="Every stat, list and report on this page counts only students in the programs assigned to you."
          class="flex h-5 w-5 items-center justify-center rounded-full text-slate-400"
          tabindex="0"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
            <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
            <path d="M9.8 9.6a2.2 2.2 0 1 1 2.9 2.1c-.5.2-.7.6-.7 1.1v.4M12 16.4h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
          </svg>
        </span>
      </TooltipWrap>
    </div>

    <!-- Hero: greeting + primary action. No meta grid — the coordinator
         dashboard endpoint returns only stats, students_behind and week, none
         of which belongs in a hero, and no department name reaches the client. -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-center gap-4">
          <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-blue-50 text-lg font-semibold text-blue-700">
            {{ initials }}
          </span>
          <div class="min-w-0">
            <p class="text-xl font-semibold tracking-tight text-slate-900">{{ greeting }}</p>
            <p class="mt-0.5 truncate text-sm text-slate-500">{{ heroSubline }}</p>
          </div>
        </div>

        <TooltipWrap label="Monitor today's journal activity" placement="bottom" class="sm:shrink-0">
          <RouterLink
            :to="JOURNAL_ACTIVITIES_ROUTE"
            aria-label="Monitor today's journal activity"
            class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
          >
            Journal Activities
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
              <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </RouterLink>
        </TooltipWrap>
      </div>
    </section>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <article
          v-for="card in statCards"
          :key="card.label"
          class="flex h-full flex-col rounded-xl p-6 ring-1 ring-slate-200/60"
          :class="[card.card, card.span]"
        >
          <div class="flex items-center gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" :class="card.tile">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                <g v-if="card.icon === 'people'">
                  <circle cx="9" cy="8.5" r="3.2" stroke="currentColor" stroke-width="1.6" />
                  <path d="M3.5 19c.9-3 3.1-4.6 5.5-4.6s4.6 1.6 5.5 4.6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                  <path d="M16 6.2a3 3 0 0 1 0 5.6M17.5 14.8c1.6.6 2.7 2 3.2 4.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                </g>
                <g v-else-if="card.icon === 'check'">
                  <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                  <path d="M8.5 12.3l2.4 2.4 4.6-4.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </g>
                <g v-else>
                  <rect x="3.5" y="7.5" width="17" height="12" rx="2" stroke="currentColor" stroke-width="1.6" />
                  <path d="M9 7.5V6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 6v1.5M3.5 12.5h17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </g>
              </svg>
            </span>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-600">{{ card.label }}</p>
          </div>

          <p class="mt-4 text-3xl font-semibold tracking-tight text-slate-900">{{ card.value }}</p>
          <p class="mt-1 text-xs text-slate-600">{{ card.sub }}</p>
        </article>
      </div>

      <div>
        <h3 class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-600">This week</h3>
        <div class="grid grid-cols-1 items-stretch gap-6 xl:grid-cols-2">
        <section class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <h2 class="text-sm font-semibold text-slate-900">Info Sheet Completion</h2>
          <p class="mt-1 text-xs text-slate-500">Across information sheets on file in your programs.</p>

          <p v-if="infoSheetError" class="mt-4 text-sm text-red-600">{{ infoSheetError }}</p>

          <template v-else>
            <div
              class="mt-5 flex h-3 w-full overflow-hidden rounded-full bg-slate-100"
              role="img"
              :aria-label="infoSheetAriaLabel"
            >
              <span
                v-for="segment in infoSheetBreakdown.segments"
                v-show="segment.share > 0"
                :key="segment.key"
                class="h-full"
                :class="segment.bar"
                :style="{ width: `${segment.share}%` }"
              />
            </div>

            <p v-if="infoSheetBreakdown.total === 0" class="mt-4 text-sm text-slate-500">No info sheets yet</p>

            <div v-else class="mt-5 space-y-2">
              <div
                v-for="segment in infoSheetBreakdown.segments"
                :key="segment.key"
                class="flex items-center gap-2 text-sm"
              >
                <span class="h-2 w-2 shrink-0 rounded-full" :class="segment.dot" />
                <span class="text-slate-600">{{ segment.label }}</span>
                <span class="ml-auto font-semibold text-slate-900">{{ segment.count }}</span>
              </div>
            </div>
          </template>
        </section>

        <section class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
              <h2 class="text-sm font-semibold text-slate-900">Students Behind This Week</h2>
              <p class="mt-1 text-xs text-slate-500">Working days with no submitted journal since {{ weekStartLabel }}.</p>
            </div>
            <span
              class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold"
              :class="stats.students_behind === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
            >
              {{ stats.students_behind }} flagged
            </span>
          </div>

          <div
            v-if="studentsBehind.length === 0"
            class="flex flex-1 flex-col items-center justify-center py-10 text-center"
          >
            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                <path d="M5.5 12.5l4 4 9-9.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </span>
            <p class="mt-4 text-sm font-semibold text-slate-900">Everyone's on track</p>
            <p class="mt-1 max-w-xs text-sm text-slate-500">
              No in-scope intern has missed a working day since {{ weekStartLabel }}.
            </p>
          </div>

          <template v-else>
            <ul id="students-behind-list" class="mt-4 divide-y divide-slate-100 border-t border-slate-100">
              <li v-for="student in visibleBehind" :key="student.student_id" class="flex items-center justify-between gap-4 py-3">
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-900">{{ student.name }}</p>
                  <p class="mt-1 truncate text-xs text-slate-500">{{ behindMeta(student) }}</p>
                </div>
                <span class="shrink-0 whitespace-nowrap rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">
                  {{ missingLabel(student.missing_count) }}
                </span>
              </li>
            </ul>

            <button
              v-if="studentsBehind.length > BEHIND_PREVIEW"
              type="button"
              class="mt-1 inline-flex min-h-11 items-center gap-1.5 self-start text-sm font-semibold text-blue-600 transition hover:text-blue-700"
              aria-controls="students-behind-list"
              :aria-expanded="showAllBehind"
              @click="showAllBehind = !showAllBehind"
            >
              {{ showAllBehind ? 'Show fewer' : `Show all ${studentsBehind.length}` }}
              <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                fill="none"
                class="h-4 w-4 transition-transform motion-reduce:transition-none"
                :class="showAllBehind ? 'rotate-180' : ''"
                aria-hidden="true"
              >
                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
            </button>
          </template>
        </section>
        </div>
      </div>
    </LoadStatus>

    <CoordinatorActivityLog />
  </section>
</template>

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

type StatIcon = 'people' | 'check' | 'alert' | 'briefcase'

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

const department = computed(() => auth.user?.program?.department?.name ?? 'your department')

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

const statCards = computed<
  { label: string; value: number; sub: string; card: string; tile: string; icon: StatIcon }[]
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
    sub: `Journals since ${week.value.start}`,
    card: 'bg-emerald-50/40',
    tile: 'bg-white ring-1 ring-slate-200/70 text-emerald-600',
    icon: 'check',
  },
  {
    label: 'Missing This Week',
    value: stats.value.journals_missing_this_week,
    sub: 'Unsubmitted daily journals',
    card: 'bg-rose-50/40',
    tile: 'bg-white ring-1 ring-slate-200/70 text-rose-600',
    icon: 'alert',
  },
  {
    label: 'Active Batches',
    value: stats.value.active_batches,
    sub: 'Running in your programs',
    card: 'bg-amber-50/40',
    tile: 'bg-white ring-1 ring-slate-200/70 text-amber-600',
    icon: 'briefcase',
  },
])

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
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article
          v-for="card in statCards"
          :key="card.label"
          class="flex h-full flex-col rounded-xl p-6 ring-1 ring-slate-200/60"
          :class="card.card"
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
                <g v-else-if="card.icon === 'alert'">
                  <path d="M12 8v4.5M12 16h.01" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                  <path d="M10.3 4.4 3.1 17.1a2 2 0 0 0 1.7 3h14.4a2 2 0 0 0 1.7-3L13.7 4.4a2 2 0 0 0-3.4 0Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                </g>
                <g v-else>
                  <rect x="3.5" y="7.5" width="17" height="12" rx="2" stroke="currentColor" stroke-width="1.6" />
                  <path d="M9 7.5V6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 6v1.5M3.5 12.5h17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </g>
              </svg>
            </span>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ card.label }}</p>
          </div>

          <p class="mt-4 text-3xl font-semibold tracking-tight text-slate-900">{{ card.value }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ card.sub }}</p>
        </article>
      </div>

      <div>
        <h3 class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">This week</h3>
        <div class="grid items-stretch gap-6 xl:grid-cols-2">
        <section class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <h2 class="text-sm font-semibold text-slate-900">Info Sheet Completion</h2>
          <p class="mt-1 text-xs text-slate-400">Across information sheets on file in your programs.</p>

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

            <p v-if="infoSheetBreakdown.total === 0" class="mt-4 text-sm text-slate-400">No info sheets yet</p>

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
              <p class="mt-1 text-xs text-slate-400">In-scope interns with a missing daily entry this week.</p>
            </div>
            <span
              class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold"
              :class="stats.students_behind === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'"
            >
              {{ stats.students_behind }} flagged
            </span>
          </div>

          <p v-if="studentsBehind.length === 0" class="mt-4 text-sm text-slate-400">
            No students have missing daily journals this week. 🎉
          </p>

          <div v-else class="mt-4 divide-y divide-slate-100">
            <div v-for="student in studentsBehind" :key="student.student_id" class="flex items-center justify-between gap-4 py-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900">{{ student.name }}</p>
                <p class="mt-1 truncate text-xs text-slate-500">{{ student.company || 'No company on file' }}</p>
              </div>
              <span class="shrink-0 whitespace-nowrap rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">
                {{ student.missing_count }} missing {{ student.missing_count === 1 ? 'entry' : 'entries' }}
              </span>
            </div>
          </div>
        </section>
        </div>
      </div>
    </LoadStatus>

    <CoordinatorActivityLog />
  </section>
</template>

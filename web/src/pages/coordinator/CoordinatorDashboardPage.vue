<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
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

const statCards = computed<
  { label: string; value: number; sub: string; card: string; tile: string; icon: StatIcon }[]
>(() => [
  {
    label: 'My Interns',
    value: stats.value.active_interns,
    sub: 'Active enrollments in scope',
    card: 'bg-blue-50/50',
    tile: 'bg-blue-100 text-blue-600',
    icon: 'people',
  },
  {
    label: 'Submitted This Week',
    value: stats.value.journals_submitted_this_week,
    sub: `Journals since ${week.value.start}`,
    card: 'bg-emerald-50/50',
    tile: 'bg-emerald-100 text-emerald-600',
    icon: 'check',
  },
  {
    label: 'Missing This Week',
    value: stats.value.journals_missing_this_week,
    sub: 'Unsubmitted daily journals',
    card: 'bg-rose-50/50',
    tile: 'bg-rose-100 text-rose-600',
    icon: 'alert',
  },
  {
    label: 'Active Batches',
    value: stats.value.active_batches,
    sub: 'Running in your programs',
    card: 'bg-amber-50/50',
    tile: 'bg-amber-100 text-amber-600',
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
    <div class="rounded-xl bg-blue-50 px-5 py-4 text-sm text-blue-800 ring-1 ring-blue-100">
      This workspace is scoped to <strong>{{ department }}</strong>. Stats below reflect your assigned programs only.
    </div>

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

      <div class="grid gap-6 xl:grid-cols-2">
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
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

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-slate-900">Students Behind This Week</h2>
            <span class="shrink-0 rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700">
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
    </LoadStatus>

    <CoordinatorActivityLog />
  </section>
</template>

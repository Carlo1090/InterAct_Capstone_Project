<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import { categorizeError } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import type {
  SupervisorDashboard,
  SupervisorInternRow,
  SupervisorJournalRow,
  SupervisorReviewStatus,
} from '@/types/api'

/**
 * Every number on this page comes from an endpoint that already returns it:
 *  - the four stats, the donut, and Recently Reviewed  -> GET supervisor/dashboard
 *  - the pending queue                                 -> GET supervisor/journals?status=pending
 *  - the company name in the header                    -> GET supervisor/interns (`company`)
 *
 * Each block owns its own loading/error state, so one failing endpoint degrades
 * that block rather than blanking the page.
 */
const PENDING_PREVIEW = 5

const auth = useAuthStore()

const greetingName = computed(() => (auth.user?.name ?? '').trim().split(/\s+/)[0] || 'Supervisor')

// The sidebar's own "Journals" target, kept identical to the nav.
const JOURNALS_ROUTE = '/supervisor/journals'

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

/** The company when one is resolvable, otherwise the role label — never invented. */
const heroSubline = computed(() => companyName.value || 'Supervisor')

/**
 * Date-only, by slicing rather than parsing. `week_start` arrives as a bare
 * Y-m-d and `submitted_at`/`reviewed_at` as ISO instants; slicing gives the
 * same answer for both and cannot drift a day in UTC+8 the way re-formatting a
 * midnight-UTC value can. The untouched original stays in a tooltip where the
 * time is still wanted.
 */
const dateOnly = (value: string | null | undefined): string | null => {
  const trimmed = value?.trim()

  return trimmed ? trimmed.slice(0, 10) : null
}

const clampPercent = (value: number): number => {
  if (!Number.isFinite(value)) return 0

  return Math.min(100, Math.max(0, value))
}

const safeCount = (value: number | undefined): number => {
  if (!Number.isFinite(value ?? NaN)) return 0

  return Math.max(0, Math.trunc(value as number))
}

// ------------------------------------------------------------- dashboard

const dashboard = ref<SupervisorDashboard | null>(null)
const isLoading = ref(true)
const errorMessage = ref('')

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<SupervisorDashboard>('/api/supervisor/dashboard')
    dashboard.value = data
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to load your dashboard.').message
  } finally {
    isLoading.value = false
  }
}

type StatCard = {
  label: string
  value: number
  caption: string
  to: string
  card: string
  tile: string
  icon: 'people' | 'clock' | 'check' | 'return'
}

const statCards = computed<StatCard[]>(() => {
  const stats = dashboard.value?.stats

  return [
    {
      label: 'My Interns',
      value: safeCount(stats?.my_interns),
      caption: 'Assigned to you',
      to: '/supervisor/interns',
      card: 'bg-blue-50/40',
      tile: 'bg-white ring-1 ring-slate-200/70 text-blue-600',
      icon: 'people',
    },
    {
      // The Journals page keeps its status in a local ref driven by tabs and
      // never reads route.query, so a ?status= link would navigate without
      // filtering. These point at the plain page until that changes.
      label: 'Pending Reviews',
      value: safeCount(stats?.pending_reviews),
      caption: 'Weekly journals to review',
      to: '/supervisor/journals',
      card: 'bg-amber-50/40',
      tile: 'bg-white ring-1 ring-slate-200/70 text-amber-600',
      icon: 'clock',
    },
    {
      label: 'Approved',
      value: safeCount(stats?.approved_total),
      caption: 'Weekly journals',
      to: '/supervisor/journals',
      card: 'bg-emerald-50/40',
      tile: 'bg-white ring-1 ring-slate-200/70 text-emerald-600',
      icon: 'check',
    },
    {
      label: 'Returned',
      value: safeCount(stats?.returned_total),
      caption: 'Sent back for revision',
      to: '/supervisor/journals',
      card: 'bg-rose-50/40',
      tile: 'bg-white ring-1 ring-slate-200/70 text-rose-600',
      icon: 'return',
    },
  ]
})

// ------------------------------------------------------- W1: review donut

const DONUT_SEGMENTS = [
  { key: 'pending', label: 'Pending', stroke: '#f59e0b', dot: 'bg-amber-500' },
  { key: 'approved', label: 'Approved', stroke: '#10b981', dot: 'bg-emerald-500' },
  { key: 'returned', label: 'Returned', stroke: '#f43f5e', dot: 'bg-rose-500' },
] as const

const reviewCounts = computed<Record<string, number>>(() => {
  const stats = dashboard.value?.stats

  return {
    pending: safeCount(stats?.pending_reviews),
    approved: safeCount(stats?.approved_total),
    returned: safeCount(stats?.returned_total),
  }
})

const reviewTotal = computed(() =>
  DONUT_SEGMENTS.reduce((sum, segment) => sum + (reviewCounts.value[segment.key] ?? 0), 0),
)

/**
 * `pathLength="100"` re-bases the circle to 100 units, so a share maps straight
 * onto the dash array with no circumference maths. The offset seats each
 * segment after the ones before it.
 */
const reviewSegments = computed(() => {
  const total = reviewTotal.value
  let consumed = 0

  return DONUT_SEGMENTS.map((segment) => {
    const count = reviewCounts.value[segment.key] ?? 0
    const share = total > 0 ? clampPercent((count / total) * 100) : 0
    const offset = -consumed
    consumed += share

    return { ...segment, count, share, offset }
  })
})

/**
 * Zero-share segments are filtered out rather than drawn at length 0 — with
 * `stroke-linecap="round"` a `0 100` dash still paints a dot, so an empty
 * status would show a phantom pip. (`v-if` cannot sit beside `v-for` on one
 * element: Vue 3 evaluates it first and the loop alias would be undefined.)
 */
const drawnSegments = computed(() => reviewSegments.value.filter((segment) => segment.share > 0))

const donutAriaLabel = computed(
  () =>
    `Weekly journals by review status: ${reviewSegments.value
      .map((segment) => `${segment.count} ${segment.label.toLowerCase()}`)
      .join(', ')}`,
)

// ------------------------------------------------------ W2: pending queue

const pendingLogs = ref<SupervisorJournalRow[]>([])
const isPendingLoading = ref(true)
const pendingError = ref('')

const loadPending = async () => {
  
  isPendingLoading.value = true
  pendingError.value = ''

  try {
    // The endpoint takes a real `status` filter, so this is a genuine pending
    // list rather than a client-side slice of a partial page.
    const { data } = await api.get<{ status: SupervisorReviewStatus; logs: SupervisorJournalRow[] }>(
      '/api/supervisor/journals',
      { params: { status: 'pending' } },
    )
    pendingLogs.value = data.logs
  } catch (error) {
    pendingError.value = categorizeError(error, 'Unable to load the review queue.').message
  } finally {
    isPendingLoading.value = false
  }
}

const pendingPreview = computed(() => pendingLogs.value.slice(0, PENDING_PREVIEW))

// -------------------------------------------------------- header company

const companyName = ref('')

const loadCompany = async () => {
  try {
    // `AuthUser` carries no company, but every intern row does — and a login
    // supervisor represents one company, so the first row is authoritative.
    const { data } = await api.get<{ interns: SupervisorInternRow[] }>('/api/supervisor/interns')
    companyName.value = data.interns[0]?.company ?? ''
  } catch {
    // Non-fatal: the header just omits the company line.
  }
}

// ------------------------------------------------------ recently reviewed

const reviewedDotClass = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'bg-emerald-500'
  if (status === 'returned') return 'bg-rose-500'

  return 'bg-amber-500'
}

const reviewedOutcome = (status: SupervisorReviewStatus): string => {
  if (status === 'approved') return 'approved'
  if (status === 'returned') return 'returned for revision'

  return status
}

onMounted(() => {
  void load()
  void loadPending()
  void loadCompany()
})
</script>

<template>
  <section class="space-y-6">
    <div class="flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-200/70">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 shrink-0 text-slate-400">
        <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
        <path d="M12 11v5M12 8h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
      <span>You review the <strong class="font-semibold text-slate-700">weekly narrative journals</strong> of your interns.</span>
      <TooltipWrap label="Approve a journal, or return it with a comment explaining what the student needs to fix." placement="bottom" class="ml-auto shrink-0">
        <span
          aria-label="Approve a journal, or return it with a comment explaining what the student needs to fix."
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

    <!-- Hero: greeting + primary action. No meta grid — the supervisor
         dashboard endpoint returns only stats and recently_reviewed. -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-center gap-4">
          <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-blue-50 text-lg font-semibold text-blue-700">
            {{ initials }}
          </span>
          <div class="min-w-0">
            <p class="text-xl font-semibold tracking-tight text-slate-900">Hello, {{ greetingName }}</p>
            <p class="mt-0.5 truncate text-sm text-slate-500">{{ heroSubline }}</p>
          </div>
        </div>

        <TooltipWrap label="Review your interns' weekly journals" placement="bottom" class="sm:shrink-0">
          <RouterLink
            :to="JOURNALS_ROUTE"
            aria-label="Review your interns' weekly journals"
            class="inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
          >
            Review Journals
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
              <path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </RouterLink>
        </TooltipWrap>
      </div>
    </section>

    <!-- B. Stat row -->
    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <RouterLink
          v-for="card in statCards"
          :key="card.label"
          :to="card.to"
          class="group flex h-full flex-col rounded-xl p-6 ring-1 ring-slate-200/60 transition hover:shadow-md hover:ring-blue-200 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
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
                <g v-else-if="card.icon === 'clock'">
                  <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                  <path d="M12 7.5V12l3 1.8" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </g>
                <g v-else-if="card.icon === 'check'">
                  <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                  <path d="M8.5 12.3l2.4 2.4 4.6-4.9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </g>
                <g v-else>
                  <path d="M9.5 5.5 4.5 10l5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                  <path d="M4.5 10h9a6 6 0 0 1 0 12h-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </g>
              </svg>
            </span>
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ card.label }}</p>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="none"
              class="ml-auto h-4 w-4 shrink-0 text-slate-400 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
            >
              <path d="m9.5 6 6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </div>
          <p class="mt-4 text-3xl font-semibold tracking-tight tabular-nums text-slate-900">{{ card.value }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ card.caption }}</p>
        </RouterLink>
      </div>
    </LoadStatus>

    <!-- C. Two-column row -->
    <div>
      <h3 class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">Your review workload</h3>
      <div class="grid items-stretch gap-6 xl:grid-cols-2">
      <section class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <h2 class="text-sm font-semibold text-slate-900">Review Status</h2>
        <p class="mt-1 text-xs text-slate-400">Every submitted weekly journal from your interns, by outcome.</p>

        <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
          <div class="relative mx-auto mt-5 w-full max-w-[180px]" role="img" :aria-label="donutAriaLabel">
            <svg viewBox="0 0 120 120" class="h-auto w-full">
              <g transform="rotate(-90 60 60)">
                <circle cx="60" cy="60" r="48" fill="none" stroke="#f1f5f9" stroke-width="14" pathLength="100" />
                <circle
                  v-for="segment in drawnSegments"
                  :key="segment.key"
                  cx="60"
                  cy="60"
                  r="48"
                  fill="none"
                  :stroke="segment.stroke"
                  stroke-width="14"
                  stroke-linecap="round"
                  pathLength="100"
                  :stroke-dasharray="`${segment.share} 100`"
                  :stroke-dashoffset="segment.offset"
                />
              </g>
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
              <p class="text-2xl font-semibold tracking-tight tabular-nums text-slate-900">{{ reviewTotal }}</p>
              <p class="text-xs text-slate-400">Weekly journals</p>
            </div>
          </div>

          <p v-if="reviewTotal === 0" class="mt-5 text-center text-sm text-slate-400">No weekly journals yet.</p>

          <ul v-else class="mt-5 space-y-2">
            <li v-for="segment in reviewSegments" :key="segment.key" class="flex items-center gap-2 text-sm">
              <span class="h-2 w-2 shrink-0 rounded-full" :class="segment.dot" />
              <span class="text-slate-600">{{ segment.label }}</span>
              <span class="ml-auto font-medium tabular-nums text-slate-900">{{ segment.count }}</span>
            </li>
          </ul>
        </LoadStatus>
      </section>

      <section class="flex h-full flex-col rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="min-w-0">
            <h2 class="text-sm font-semibold text-slate-900">Pending Queue</h2>
            <p class="mt-1 text-xs text-slate-400">Submitted journals still waiting on your review.</p>
          </div>
          <RouterLink to="/supervisor/journals" class="text-sm font-semibold text-blue-600 transition hover:text-blue-700">
            Review all
          </RouterLink>
        </div>

        <LoadStatus :loading="isPendingLoading" :error="pendingError" :retry="loadPending">
          <p v-if="pendingLogs.length === 0" class="mt-5 text-sm text-slate-400">Nothing waiting for review.</p>

          <ul v-else class="mt-3 divide-y divide-slate-100">
            <li v-for="log in pendingPreview" :key="log.id">
              <RouterLink
                to="/supervisor/journals"
                class="flex items-baseline justify-between gap-4 py-3 transition-colors hover:bg-slate-50/70"
              >
                <span class="min-w-0">
                  <span class="block truncate text-sm font-medium text-slate-900">{{ log.student_name }}</span>
                  <span class="mt-0.5 block truncate text-xs text-slate-400">
                    Week of {{ dateOnly(log.week_start) ?? '—' }} – {{ dateOnly(log.week_end) ?? '—' }}
                  </span>
                </span>
                <span class="shrink-0 text-xs tabular-nums text-slate-400">
                  <TooltipWrap v-if="dateOnly(log.submitted_at)" :label="log.submitted_at ?? ''" placement="top" align="end">
                    <span>{{ dateOnly(log.submitted_at) }}</span>
                  </TooltipWrap>
                  <span v-else class="text-slate-300">—</span>
                </span>
              </RouterLink>
            </li>
          </ul>
        </LoadStatus>
      </section>
      </div>
    </div>

    <!-- D. Recently Reviewed -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-900">Recently Reviewed</h2>
        <RouterLink to="/supervisor/journals" class="text-sm font-semibold text-blue-600 transition hover:text-blue-700">
          Review journals →
        </RouterLink>
      </div>

      <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
        <p v-if="(dashboard?.recently_reviewed.length ?? 0) === 0" class="mt-5 text-sm text-slate-400">
          You haven't reviewed any weekly journals yet.
        </p>

        <!--
          The endpoint returns a fixed 5 most-recent rows (`limit(5)`, no page or
          per-page param), so there is nothing further to reveal — no "Show more".
        -->
        <ol v-else class="mt-4 space-y-4">
          <li
            v-for="log in dashboard?.recently_reviewed ?? []"
            :key="log.id"
            class="relative flex gap-3 pb-4 last:pb-0"
          >
            <span class="absolute bottom-0 left-[3px] top-4 w-px bg-slate-100" aria-hidden="true" />
            <span class="relative mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :class="reviewedDotClass(log.status)" />
            <div class="flex min-w-0 flex-1 items-baseline justify-between gap-4">
              <p class="min-w-0 text-sm text-slate-600">
                <span class="font-medium text-slate-900">{{ log.student_name }}</span>
                {{ ' ' }}— week of {{ dateOnly(log.week_start) ?? '—' }} {{ reviewedOutcome(log.status) }}
              </p>
              <span class="shrink-0 text-xs tabular-nums text-slate-400">
                <TooltipWrap v-if="dateOnly(log.reviewed_at)" :label="log.reviewed_at ?? ''" placement="top" align="end">
                  <span>{{ dateOnly(log.reviewed_at) }}</span>
                </TooltipWrap>
                <span v-else class="text-slate-300">—</span>
              </span>
            </div>
          </li>
        </ol>
      </LoadStatus>
    </section>
  </section>
</template>

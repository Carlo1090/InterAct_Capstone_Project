<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import AdminActivityFeed from '@/components/admin/AdminActivityFeed.vue'
import { categorizeError } from '@/lib/apiError'
import { useAuthStore } from '@/stores/auth'
import type { Batch, Department, Program } from '@/types/api'

/**
 * There is no admin dashboard endpoint — no `Admin\DashboardController`, no
 * `admin/dashboard` route. Every number here is composed from the list
 * endpoints the admin pages already use, and nothing is displayed that one of
 * them does not actually return.
 *
 * Each widget owns its own loading/error state so a single failing endpoint
 * degrades one card instead of blanking the page.
 */
type AdminPage<T> = {
  data: T[]
  total: number
  current_page: number
  last_page: number
}

/** The three roles `admin/users` can report — it filters out `admin` itself. */
const DONUT_ROLES = [
  { key: 'student', label: 'Students', stroke: '#3b82f6', dot: 'bg-blue-500' },
  { key: 'coordinator', label: 'Coordinators', stroke: '#10b981', dot: 'bg-emerald-500' },
  { key: 'supervisor', label: 'Supervisors', stroke: '#f59e0b', dot: 'bg-amber-500' },
] as const

const auth = useAuthStore()

const lastUpdated = ref<Date | null>(null)

const lastUpdatedLabel = computed(() =>
  lastUpdated.value ? lastUpdated.value.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '—',
)

const activityFeed = ref<InstanceType<typeof AdminActivityFeed> | null>(null)

const greetingName = computed(() => (auth.user?.name ?? '').trim().split(/\s+/)[0] || 'Admin')

const roleLabel = computed(() => {
  const role = auth.user?.role ?? ''

  return role ? role.charAt(0).toUpperCase() + role.slice(1) : ''
})

/** Guards every rendered count: finite, non-negative, whole. */
const safeCount = (value: number | undefined): number => {
  if (!Number.isFinite(value ?? NaN)) return 0

  return Math.max(0, Math.trunc(value as number))
}

const clampPercent = (value: number): number => {
  if (!Number.isFinite(value)) return 0

  return Math.min(100, Math.max(0, value))
}

// ---------------------------------------------------------------- stat row

const totalUsers = ref(0)
const totalBatches = ref(0)
const totalPrograms = ref(0)
const totalDepartments = ref(0)

const statsLoading = ref(true)
const statsError = ref('')

const loadStats = async () => {
  statsLoading.value = true
  statsError.value = ''

  try {
    const [users, batches, programs, departments] = await Promise.all([
      api.get<AdminPage<unknown>>('/api/admin/users'),
      api.get<AdminPage<Batch>>('/api/admin/batches'),
      api.get<Program[]>('/api/admin/programs'),
      api.get<Department[]>('/api/admin/departments'),
    ])

    totalUsers.value = safeCount(users.data.total)
    totalBatches.value = safeCount(batches.data.total)
    // Programs and departments are unpaginated arrays, so length is the real count.
    totalPrograms.value = programs.data.length
    totalDepartments.value = departments.data.length
  } catch (error) {
    statsError.value = categorizeError(error, 'Unable to load dashboard stats.').message
  } finally {
    statsLoading.value = false
  }
}

type StatCard = {
  label: string
  value: number
  caption: string
  to: string
  card: string
  tile: string
  icon: 'people' | 'batch' | 'program' | 'department'
}

const statCards = computed<StatCard[]>(() => [
  {
    label: 'Total Users',
    value: totalUsers.value,
    caption: 'Excludes admin accounts',
    to: '/admin/users',
    card: 'bg-blue-50/50',
    tile: 'bg-blue-100 text-blue-600',
    icon: 'people',
  },
  {
    label: 'Total Batches',
    value: totalBatches.value,
    caption: 'All academic years',
    to: '/admin/batches',
    card: 'bg-emerald-50/50',
    tile: 'bg-emerald-100 text-emerald-600',
    icon: 'batch',
  },
  {
    label: 'Total Programs',
    value: totalPrograms.value,
    caption: 'Across all departments',
    to: '/admin/programs',
    card: 'bg-amber-50/50',
    tile: 'bg-amber-100 text-amber-600',
    icon: 'program',
  },
  {
    label: 'Total Departments',
    value: totalDepartments.value,
    caption: 'Top-level units',
    to: '/admin/departments',
    card: 'bg-violet-50/50',
    tile: 'bg-violet-100 text-violet-600',
    icon: 'department',
  },
])

// ------------------------------------------------------------ W1: by role

const roleCounts = ref<Record<string, number>>({})
const rolesLoading = ref(true)
const rolesError = ref('')

const loadRoles = async () => {
  rolesLoading.value = true
  rolesError.value = ''

  try {
    // `admin/users` accepts a real `role` param and reports a true `total`, so
    // each role's count is exact rather than a tally of one 20-row page.
    const responses = await Promise.all(
      DONUT_ROLES.map((role) => api.get<AdminPage<unknown>>('/api/admin/users', { params: { role: role.key } })),
    )

    const counts: Record<string, number> = {}
    DONUT_ROLES.forEach((role, index) => {
      counts[role.key] = safeCount(responses[index].data.total)
    })

    roleCounts.value = counts
  } catch (error) {
    rolesError.value = categorizeError(error, 'Unable to load users by role.').message
  } finally {
    rolesLoading.value = false
  }
}

const roleTotal = computed(() => DONUT_ROLES.reduce((sum, role) => sum + (roleCounts.value[role.key] ?? 0), 0))

/**
 * Each segment carries its share plus the offset that seats it after the ones
 * before it. `pathLength="100"` re-bases the circle to 100 units, so no
 * circumference maths is needed regardless of the radius.
 */
const roleSegments = computed(() => {
  const total = roleTotal.value
  let consumed = 0

  return DONUT_ROLES.map((role) => {
    const count = roleCounts.value[role.key] ?? 0
    const share = total > 0 ? clampPercent((count / total) * 100) : 0
    const offset = -consumed
    consumed += share

    return { ...role, count, share, offset }
  })
})

/**
 * Only the segments that actually have a share get rendered. Filtering here
 * rather than with `v-show` in the template keeps a zero-share role out of the
 * DOM entirely — and `v-if` cannot be used alongside `v-for` on one element,
 * since Vue 3 evaluates `v-if` first and the loop alias would be undefined.
 */
const drawnSegments = computed(() => roleSegments.value.filter((segment) => segment.share > 0))

const roleAriaLabel = computed(
  () => `Users by role: ${roleSegments.value.map((segment) => `${segment.count} ${segment.label.toLowerCase()}`).join(', ')}`,
)

// --------------------------------------------------------- W2: batch status

const activeBatches = ref(0)
const inactiveBatches = ref(0)
const batchesLoading = ref(true)
const batchesError = ref('')

const loadBatchStatus = async () => {
  batchesLoading.value = true
  batchesError.value = ''

  try {
    // No status filter and no `per_page` on this endpoint, so an exact split
    // means walking every page. Page 1 reports how many there are; the rest go
    // out in parallel.
    const first = await api.get<AdminPage<Batch>>('/api/admin/batches', { params: { page: 1 } })

    const records = [...first.data.data]
    const lastPage = Math.max(1, safeCount(first.data.last_page) || 1)

    if (lastPage > 1) {
      const rest = await Promise.all(
        Array.from({ length: lastPage - 1 }, (_, index) =>
          api.get<AdminPage<Batch>>('/api/admin/batches', { params: { page: index + 2 } }),
        ),
      )

      for (const response of rest) records.push(...response.data.data)
    }

    activeBatches.value = records.filter((batch) => batch.is_active !== false).length
    inactiveBatches.value = records.length - activeBatches.value
  } catch (error) {
    batchesError.value = categorizeError(error, 'Unable to load batch status.').message
  } finally {
    batchesLoading.value = false
  }
}

const batchTotal = computed(() => activeBatches.value + inactiveBatches.value)

const batchBars = computed(() => [
  { label: 'Active', count: activeBatches.value, bar: 'bg-emerald-500' },
  { label: 'Inactive', count: inactiveBatches.value, bar: 'bg-slate-400' },
])

const batchPercent = (count: number): number =>
  batchTotal.value > 0 ? clampPercent((count / batchTotal.value) * 100) : 0

// ------------------------------------------------------------------ shared

const refreshAll = () => {
  void loadStats()
  void loadRoles()
  void loadBatchStatus()
  activityFeed.value?.reload()
  lastUpdated.value = new Date()
}

onMounted(refreshAll)
</script>

<template>
  <section class="space-y-6">
    <!-- A. Header strip -->
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-xl font-semibold text-slate-900">Hello, {{ greetingName }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ roleLabel }}</p>
      </div>
      <div class="flex items-center gap-3">
        <p class="text-xs text-slate-400">Last updated {{ lastUpdatedLabel }}</p>
        <TooltipWrap label="Refresh dashboard" placement="bottom" align="end">
          <button
            type="button"
            aria-label="Refresh dashboard"
            class="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-slate-600 ring-1 ring-slate-200 transition hover:bg-slate-50 hover:text-slate-900 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
            @click="refreshAll"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
              <path
                d="M20 11a8 8 0 1 0-.6 4"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
              />
              <path d="M20 4.5V11h-6.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>
        </TooltipWrap>
      </div>
    </div>

    <!-- B. Stat row -->
    <LoadStatus :loading="statsLoading" :error="statsError" :retry="loadStats">
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
                <g v-else-if="card.icon === 'batch'">
                  <rect x="3.5" y="5" width="17" height="15" rx="2.2" stroke="currentColor" stroke-width="1.6" />
                  <path d="M8 3.5v3M16 3.5v3M3.5 10h17" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </g>
                <g v-else-if="card.icon === 'program'">
                  <path d="M12 4.2 21 8.6l-9 4.4-9-4.4 9-4.4Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                  <path d="M6.5 11v5c0 1.4 2.5 2.7 5.5 2.7s5.5-1.3 5.5-2.7v-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </g>
                <g v-else>
                  <path d="M4 20V6.5a1.5 1.5 0 0 1 1.5-1.5h6A1.5 1.5 0 0 1 13 6.5V20" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                  <path d="M13 20V11h5.5A1.5 1.5 0 0 1 20 12.5V20M3 20h18M7 9h2M7 12.5h2M7 16h2M16 14.5h1.5M16 17.5h1.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
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
          <p class="mt-4 text-3xl font-semibold tracking-tight text-slate-900 tabular-nums">{{ card.value }}</p>
          <p class="mt-1 text-xs text-slate-400">{{ card.caption }}</p>
        </RouterLink>
      </div>
    </LoadStatus>

    <!-- C. Two-column row -->
    <div class="grid gap-6 xl:grid-cols-2">
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <h2 class="text-sm font-semibold text-slate-900">Users by Role</h2>

        <LoadStatus :loading="rolesLoading" :error="rolesError" :retry="loadRoles">
          <div class="relative mx-auto mt-5 w-full max-w-[180px]" role="img" :aria-label="roleAriaLabel">
            <svg viewBox="0 0 120 120" class="h-auto w-full">
              <g transform="rotate(-90 60 60)">
                <circle cx="60" cy="60" r="48" fill="none" stroke="#f1f5f9" stroke-width="14" pathLength="100" />
                <!--
                  A zero-share segment is omitted rather than drawn at length 0:
                  with stroke-linecap="round" a `0 100` dash still paints a dot,
                  so a role with no users would show a phantom pip.
                -->
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
              <p class="text-2xl font-semibold tracking-tight text-slate-900 tabular-nums">{{ roleTotal }}</p>
              <p class="text-xs text-slate-400">accounts</p>
            </div>
          </div>

          <p v-if="roleTotal === 0" class="mt-4 text-center text-sm text-slate-400">No users yet.</p>

          <ul v-else class="mt-5 space-y-2">
            <li v-for="segment in roleSegments" :key="segment.key" class="flex items-center gap-2 text-sm">
              <span class="h-2 w-2 shrink-0 rounded-full" :class="segment.dot" />
              <span class="text-slate-600">{{ segment.label }}</span>
              <span class="ml-auto font-medium tabular-nums text-slate-900">{{ segment.count }}</span>
            </li>
          </ul>

          <p class="mt-4 text-xs text-slate-400">Admin accounts are not included in this breakdown.</p>
        </LoadStatus>
      </section>

      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
        <h2 class="text-sm font-semibold text-slate-900">Batches by Status</h2>

        <LoadStatus :loading="batchesLoading" :error="batchesError" :retry="loadBatchStatus">
          <p v-if="batchTotal === 0" class="mt-5 text-sm text-slate-400">No batches yet.</p>

          <div v-else class="mt-5 space-y-4">
            <div v-for="bar in batchBars" :key="bar.label">
              <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="text-slate-600">{{ bar.label }}</span>
                <span class="font-medium tabular-nums text-slate-900">{{ bar.count }}</span>
              </div>
              <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-2 rounded-full" :class="bar.bar" :style="{ width: `${batchPercent(bar.count)}%` }" />
              </div>
            </div>
            <p class="text-xs text-slate-400">{{ batchTotal }} batches in total.</p>
          </div>
        </LoadStatus>
      </section>
    </div>

    <!-- D. Recent activity -->
    <AdminActivityFeed ref="activityFeed" />
  </section>
</template>

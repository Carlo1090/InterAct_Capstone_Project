<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import { categorizeError } from '@/lib/apiError'
import type { SystemLogRecord } from '@/types/api'

/**
 * Recent system activity, sourced entirely from `GET admin/audit-logs`.
 *
 * Two shape facts drive the design:
 *  1. The endpoint paginates at a fixed 20 (no `per_page`), ordered by
 *     `logged_at` desc — so "show more" walks pages rather than growing a limit.
 *  2. It accepts a real `action` filter, and `admin/audit-logs/actions` returns
 *     the distinct values. The filter is therefore server-side; filtering the
 *     one page we happen to hold would silently hide matching older rows.
 */
const PAGE_SIZE = 20
const REVEAL_STEP = 10

/**
 * Laravel's paginator, typed locally. `types/api.ts` is read-only here and its
 * shared `PaginatedResponse` declares only `data` + `total?`, which is not
 * enough to know whether another page exists.
 */
type AdminPage<T> = {
  data: T[]
  total: number
  current_page: number
  last_page: number
}

const rows = ref<SystemLogRecord[]>([])
const actions = ref<string[]>([])
const actionFilter = ref('')

const visibleCount = ref(REVEAL_STEP)
const loadedPage = ref(0)
const lastPage = ref(1)

const isLoading = ref(true)
const isLoadingMore = ref(false)
const errorMessage = ref('')

const visibleRows = computed(() => rows.value.slice(0, visibleCount.value))

/** More to show if we are holding unrevealed rows, or another page exists. */
const hasMore = computed(() => visibleCount.value < rows.value.length || loadedPage.value < lastPage.value)

const fetchPage = async (page: number): Promise<AdminPage<SystemLogRecord>> => {
  const { data } = await api.get<AdminPage<SystemLogRecord>>('/api/admin/audit-logs', {
    params: {
      page,
      ...(actionFilter.value ? { action: actionFilter.value } : {}),
    },
  })

  return data
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const page = await fetchPage(1)

    rows.value = page.data
    loadedPage.value = page.current_page
    lastPage.value = page.last_page
    visibleCount.value = REVEAL_STEP
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to load recent activity.').message
  } finally {
    isLoading.value = false
  }
}

const showMore = async () => {
  // Reveal what is already held first; only reach for another page once the
  // held rows run out.
  if (visibleCount.value < rows.value.length) {
    visibleCount.value += REVEAL_STEP

    // Prefetch so the next click is instant, but never past the last page.
    if (visibleCount.value >= rows.value.length && loadedPage.value < lastPage.value) await fetchMorePages()

    return
  }

  await fetchMorePages()
  visibleCount.value += REVEAL_STEP
}

const fetchMorePages = async () => {
  if (isLoadingMore.value || loadedPage.value >= lastPage.value) return

  isLoadingMore.value = true

  try {
    const page = await fetchPage(loadedPage.value + 1)

    rows.value = [...rows.value, ...page.data]
    loadedPage.value = page.current_page
    lastPage.value = page.last_page
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to load more activity.').message
  } finally {
    isLoadingMore.value = false
  }
}

const loadActions = async () => {
  try {
    const { data } = await api.get<string[]>('/api/admin/audit-logs/actions')
    actions.value = data
  } catch {
    // Non-fatal — the feed still works, the filter just stays at "All actions".
  }
}

const onFilterChange = () => {
  void load()
}

/**
 * Y-m-d in the viewer's own timezone. `new Date(isoString)` then reading the
 * UTC date can land a day off; this reads the local parts, matching the
 * `toLocalDate()` approach already used on the student journal pages.
 */
const dayKey = (value: string): string => {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''

  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${month}-${day}`
}

const todayKey = (): string => dayKey(new Date().toISOString())

const yesterdayKey = (): string => {
  const date = new Date()
  date.setDate(date.getDate() - 1)

  return dayKey(date.toISOString())
}

const headingFor = (key: string): string => {
  if (key === '') return 'Unknown date'
  if (key === todayKey()) return 'Today'
  if (key === yesterdayKey()) return 'Yesterday'

  const [year, month, day] = key.split('-').map(Number)

  return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-US', {
    timeZone: 'UTC',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

/**
 * Whether the description already names the actor.
 *
 * `SystemLog::record` descriptions are genuinely mixed: self-actions embed the
 * actor ("Test Admin logged in"), admin/coordinator actions embed a different
 * SUBJECT ("Created coordinator account (Jane)", logged by an admin), and some
 * name nobody ("Updated system settings"). So neither "description only" nor
 * "always show both" is right for every row — the branch is decided per row.
 *
 * This only chooses what to render. The description string is never modified.
 */
const describesActor = (row: SystemLogRecord): boolean => {
  const name = row.user?.name?.trim()

  return Boolean(name && row.description?.includes(name))
}

const formatTime = (value: string): string => {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'

  return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}

/** Consecutive runs, preserving the endpoint's newest-first order. */
const groups = computed(() => {
  const result: { key: string; heading: string; rows: SystemLogRecord[] }[] = []

  for (const row of visibleRows.value) {
    const key = dayKey(row.logged_at)
    const current = result[result.length - 1]

    if (current && current.key === key) current.rows.push(row)
    else result.push({ key, heading: headingFor(key), rows: [row] })
  }

  return result
})

const reload = () => {
  void load()
}

onMounted(() => {
  void load()
  void loadActions()
})

defineExpose({ reload })
</script>

<template>
  <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-sm font-semibold text-slate-900">Recent Activity</h2>
      <div class="flex flex-wrap items-center gap-3">
        <label v-if="actions.length > 0" class="w-full sm:w-auto">
          <span class="sr-only">Filter by action</span>
          <select
            v-model="actionFilter"
            class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 sm:w-auto"
            @change="onFilterChange"
          >
            <option value="">All actions</option>
            <option v-for="action in actions" :key="action" :value="action">{{ action }}</option>
          </select>
        </label>
        <RouterLink to="/admin/audit-logs" class="text-sm font-semibold text-blue-600 transition hover:text-blue-700">
          View all
        </RouterLink>
      </div>
    </div>

    <div class="mt-5">
      <LoadStatus :loading="isLoading" :error="errorMessage" :retry="reload">
        <p v-if="rows.length === 0" class="text-sm text-slate-400">No recent activity.</p>

        <template v-else>
          <div v-for="group in groups" :key="group.key" class="mt-5 first:mt-0">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ group.heading }}</p>
            <ul class="mt-2 divide-y divide-slate-100">
              <li v-for="row in group.rows" :key="row.id" class="flex items-baseline justify-between gap-4 py-2.5">
                <p v-if="describesActor(row)" class="min-w-0 text-sm text-slate-700">{{ row.description }}</p>
                <p v-else class="min-w-0 text-sm text-slate-500">
                  <span class="font-medium text-slate-900">{{ row.user?.name ?? 'Unknown' }}</span>
                  {{ ' ' }}{{ row.description ?? row.action }}
                </p>
                <span class="shrink-0 text-xs tabular-nums text-slate-400">{{ formatTime(row.logged_at) }}</span>
              </li>
            </ul>
          </div>

          <button
            v-if="hasMore"
            type="button"
            class="mt-4 text-sm font-semibold text-blue-600 transition hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="isLoadingMore"
            @click="showMore"
          >
            {{ isLoadingMore ? 'Loading…' : 'Show more' }}
          </button>
        </template>
      </LoadStatus>
    </div>
  </section>
</template>

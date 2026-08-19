<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import { categorizeError } from '@/lib/apiError'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { JournalActivityResponse, JournalActivityRow } from '@/types/api'

/**
 * Daily activity for the coordinator's interns.
 *
 * Built against what `GET coordinator/journal-activities` actually provides. Two
 * shape facts drive the whole design:
 *  1. It accepts only `from`/`to`/`company_id`/`program_id`/`status` — there is
 *     no batch or year dimension, so the third filter is a date RANGE.
 *  2. A row is one aggregate per active enrollment, not a timestamped event, so
 *     the table reports a submitted COUNT per intern rather than a chronology.
 */
type RangeKey = 'today' | 'week' | 'month'

const PAGE_SIZE = 10
const DEBOUNCE_MS = 250

const RANGE_LABELS: Record<RangeKey, string> = {
  today: 'Today',
  week: 'Last 7 days',
  month: 'Last 30 days',
}

const programId = ref<number | null>(null)
const companyId = ref<number | null>(null)
const range = ref<RangeKey>('week')

const rows = ref<JournalActivityRow[]>([])
const programs = ref<JournalActivityResponse['programs']>([])
const companies = ref<JournalActivityResponse['companies']>([])
const from = ref('')
const to = ref('')

const isLoading = ref(true)
const errorMessage = ref('')
const visibleCount = ref(PAGE_SIZE)

let debounceId: ReturnType<typeof setTimeout> | null = null
let inFlight: AbortController | null = null

const visibleRows = computed(() => rows.value.slice(0, visibleCount.value))
const hasMore = computed(() => rows.value.length > visibleCount.value)
const hasFilters = computed(
  () => programId.value !== null || companyId.value !== null || range.value !== 'week',
)

const summaryLine = computed(() => {
  const count = rows.value.length
  const noun = count === 1 ? 'intern' : 'interns'
  const span = from.value && to.value ? ` · ${from.value} – ${to.value}` : ''

  return `${count} ${noun}${span}`
})

/** Local Y-m-d; toISOString would shift the day for anyone behind UTC. */
const toDateString = (date: Date): string => {
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${month}-${day}`
}

const rangeParams = (): { from: string; to: string } => {
  const today = new Date()
  const start = new Date(today)

  if (range.value === 'week') start.setDate(start.getDate() - 6)
  if (range.value === 'month') start.setDate(start.getDate() - 29)

  return { from: toDateString(start), to: toDateString(today) }
}

/**
 * Zero submissions is the whole reason a coordinator scans this table, so it is
 * the one value that carries colour.
 */
const submittedClass = (count: number): string =>
  count === 0 ? 'text-amber-600 font-medium' : 'text-slate-700'

const load = async () => {
  // Cancel any request still in flight so a slow earlier response cannot land
  // after a newer one and paint rows for filters that are no longer selected.
  inFlight?.abort()
  const controller = new AbortController()
  inFlight = controller

  isLoading.value = true
  errorMessage.value = ''

  const span = rangeParams()

  try {
    const { data } = await api.get<JournalActivityResponse>('/api/coordinator/journal-activities', {
      signal: controller.signal,
      params: {
        from: span.from,
        to: span.to,
        ...(programId.value !== null ? { program_id: programId.value } : {}),
        ...(companyId.value !== null ? { company_id: companyId.value } : {}),
      },
    })

    rows.value = data.rows
    programs.value = data.programs
    companies.value = data.companies
    from.value = data.from
    to.value = data.to
    visibleCount.value = PAGE_SIZE

    // The server re-scopes the company list to the chosen program, so a company
    // that no longer applies is dropped back to "All" rather than silently
    // filtering against something the user can no longer see.
    if (companyId.value !== null && !data.companies.some((company) => company.id === companyId.value)) {
      companyId.value = null
    }
  } catch (error) {
    // An aborted request is a superseded one, not a failure.
    if (controller.signal.aborted) return

    errorMessage.value = categorizeError(error, 'Unable to load activity.').message
  } finally {
    if (inFlight === controller) {
      inFlight = null
      isLoading.value = false
    }
  }
}

const scheduleLoad = () => {
  if (debounceId !== null) clearTimeout(debounceId)
  debounceId = setTimeout(load, DEBOUNCE_MS)
}

const clearFilters = () => {
  programId.value = null
  companyId.value = null
  range.value = 'week'
}

watch([programId, companyId, range], scheduleLoad)

onMounted(load)

onBeforeUnmount(() => {
  if (debounceId !== null) clearTimeout(debounceId)
  inFlight?.abort()
})
</script>

<template>
  <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
    <h2 class="text-sm font-semibold text-slate-900">Intern Activity</h2>

    <div class="mb-5 mt-4 flex flex-wrap items-center gap-3">
      <label class="w-full sm:w-auto">
        <span class="sr-only">Program</span>
        <select
          v-model="programId"
          class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 sm:w-auto"
        >
          <option :value="null">All programs</option>
          <option v-for="program in programs" :key="program.id" :value="program.id">
            {{ program.code ?? program.name }}
          </option>
        </select>
      </label>

      <label class="w-full sm:w-auto">
        <span class="sr-only">Company</span>
        <select
          v-model="companyId"
          class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 sm:w-auto"
        >
          <option :value="null">All companies</option>
          <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
        </select>
      </label>

      <label class="w-full sm:w-auto">
        <span class="sr-only">Date range</span>
        <select
          v-model="range"
          class="w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 sm:w-auto"
        >
          <option v-for="(label, key) in RANGE_LABELS" :key="key" :value="key">{{ label }}</option>
        </select>
      </label>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
      <p class="mb-5 text-xs text-slate-400">{{ summaryLine }}</p>

      <div v-if="rows.length === 0" class="mt-4">
        <p class="text-sm text-slate-400">No activity for this selection.</p>
        <button
          v-if="hasFilters"
          type="button"
          class="mt-2 text-sm font-semibold text-blue-600 transition hover:text-blue-700"
          @click="clearFilters"
        >
          Clear filters
        </button>
      </div>

      <template v-else>
        <!--
          md and up: aligned table. Widths live in the colgroup and are enforced
          by `table-fixed` — without it a browser treats col widths as hints and
          a long student name can still crush the narrow columns.

          Padding is written as pr-4 / px-4 / pl-4 rather than `px-4 pl-0` on the
          edges: both utilities set the same property, and which one wins depends
          on their order in the generated stylesheet, not on the class attribute.
          Spelling out one side per edge cell gets the flush alignment without
          relying on that.
        -->
        <table class="hidden w-full table-fixed md:table">
          <colgroup>
            <col />
            <col class="w-[140px]" />
            <col class="w-[120px]" />
            <col class="w-[260px]" />
          </colgroup>
          <thead>
            <tr class="text-xs font-medium uppercase tracking-wide text-slate-400">
              <th class="sticky top-0 whitespace-nowrap border-b border-slate-200 bg-white pb-3 pr-4 text-left font-medium">Student</th>
              <th class="sticky top-0 whitespace-nowrap border-b border-slate-200 bg-white px-4 pb-3 text-right font-medium">Submitted</th>
              <th class="sticky top-0 whitespace-nowrap border-b border-slate-200 bg-white px-4 pb-3 text-left font-medium">Program</th>
              <th class="sticky top-0 whitespace-nowrap border-b border-slate-200 bg-white pb-3 pl-4 text-right font-medium">Company</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in visibleRows" :key="row.student_id" class="transition-colors hover:bg-slate-50/70">
              <td class="truncate py-3.5 pr-4 text-sm font-medium text-slate-900">{{ row.student_name }}</td>
              <td class="whitespace-nowrap px-4 py-3.5 text-right text-sm tabular-nums" :class="submittedClass(row.submitted_count)">
                <span>{{ row.submitted_count }}</span>
                <span class="ml-1 text-xs text-slate-400">submitted</span>
              </td>
              <td class="whitespace-nowrap px-4 py-3.5 text-left text-sm text-slate-500">{{ row.program }}</td>
              <td class="py-3.5 pl-4 text-right text-sm text-slate-500">
                <TooltipWrap :label="row.company" placement="top" align="end" class="max-w-full">
                  <span class="block max-w-full truncate">{{ row.company }}</span>
                </TooltipWrap>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Below md: one stacked block per intern, so nothing scrolls sideways. -->
        <ul class="divide-y divide-slate-100 md:hidden">
          <li v-for="row in visibleRows" :key="row.student_id" class="py-3">
            <div class="flex items-baseline justify-between gap-3">
              <span class="min-w-0 truncate text-sm font-medium text-slate-900">{{ row.student_name }}</span>
              <span class="shrink-0 text-sm tabular-nums" :class="submittedClass(row.submitted_count)">
                <span>{{ row.submitted_count }}</span>
                <span class="ml-1 text-xs text-slate-400">submitted</span>
              </span>
            </div>
            <p class="mt-1 truncate text-xs text-slate-500">
              {{ [row.program, row.company].filter(Boolean).join(' · ') }}
            </p>
          </li>
        </ul>

        <button
          v-if="hasMore"
          type="button"
          class="mt-4 text-sm font-semibold text-blue-600 transition hover:text-blue-700"
          @click="visibleCount += PAGE_SIZE"
        >
          Show more
        </button>
      </template>
    </LoadStatus>
  </section>
</template>

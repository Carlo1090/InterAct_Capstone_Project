<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import ToastHost from '@/components/ToastHost.vue'
import { showToast } from '@/lib/toast'
import type {
  CoordinatorWeeklyActivityLogDetail,
  CoordinatorWeeklyActivityLogRow,
  CoordinatorWeeklyActivityLogsResponse,
} from '@/types/api'

const programId = ref<number | null>(null)
const search = ref('')
const from = ref('')
const to = ref('')

const rows = ref<CoordinatorWeeklyActivityLogRow[]>([])
const programs = ref<{ id: number; name: string; code?: string }[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const isLoading = ref(true)

/**
 * Dates arrive either as a `date`-cast column serialised at midnight UTC or as
 * a bare Y-m-d. Slice, never parse — `new Date()` on either can land a day
 * earlier once APP_TIMEZONE is Asia/Manila. (PROJECT.md)
 */
const dateOnly = (raw: string | null | undefined): string => (raw ?? '').slice(0, 10)

const formatDate = (raw: string | null | undefined): string => {
  const iso = dateOnly(raw)
  if (!iso) return ''
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const formatRange = (start: string | null | undefined, end: string | null | undefined): string => {
  const a = formatDate(start)
  const b = formatDate(end)
  if (!a && !b) return '—'
  if (!a || !b) return a || b
  return `${a} – ${b}`
}

const formatHours = (value: string | number | null): string => {
  if (value === null || value === '') return '—'
  // decimal(5,1) comes back as "40.0" — drop a pointless trailing zero.
  const n = Number(value)
  return Number.isNaN(n) ? String(value) : String(Number(n.toFixed(1)))
}

const load = async () => {
  isLoading.value = true

  try {
    const params: Record<string, string | number> = { page: page.value }
    if (programId.value) params.program_id = programId.value
    if (search.value.trim()) params.search = search.value.trim()
    if (from.value) params.from = from.value
    if (to.value) params.to = to.value

    const { data } = await api.get<CoordinatorWeeklyActivityLogsResponse>(
      '/api/coordinator/weekly-activity-logs',
      { params },
    )
    rows.value = data.logs.data
    programs.value = data.programs
    lastPage.value = data.logs.last_page
    total.value = data.logs.total
  } catch {
    showToast('Unable to load weekly and time log summaries.', 'error')
  } finally {
    isLoading.value = false
  }
}

const applyFilters = () => {
  page.value = 1
  load()
}

const resetFilters = () => {
  programId.value = null
  search.value = ''
  from.value = ''
  to.value = ''
  applyFilters()
}

// Tells "nobody has started one yet" apart from "your filters excluded
// everything", so the empty state only offers a way back when there is one.
const hasFilters = computed(
  () => programId.value !== null || search.value.trim() !== '' || from.value !== '' || to.value !== '',
)

const goToPage = (target: number) => {
  if (target < 1 || target > lastPage.value || target === page.value) return
  page.value = target
  load()
}

const downloadPdf = (id: number) => {
  window.open(`/api/coordinator/weekly-activity-logs/${id}/pdf`, '_blank')
}

// --- Detail: read-only preview of one sheet -------------------------------
const isDetailOpen = ref(false)
const isDetailLoading = ref(false)
const detailError = ref('')
const detail = ref<CoordinatorWeeklyActivityLogDetail | null>(null)

const openDetail = async (row: CoordinatorWeeklyActivityLogRow) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detailError.value = ''
  detail.value = null

  try {
    const { data } = await api.get<CoordinatorWeeklyActivityLogDetail>(
      `/api/coordinator/weekly-activity-logs/${row.id}`,
    )
    detail.value = data
  } catch (error) {
    detailError.value =
      axios.isAxiosError(error) && error.response?.status === 403
        ? 'This log sheet is not in your scope.'
        : 'Unable to load this log sheet.'
    showToast(detailError.value, 'error')
  } finally {
    isDetailLoading.value = false
  }
}

const closeDetail = () => {
  isDetailOpen.value = false
  detail.value = null
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This view is <strong>read-only</strong>. Every Weekly Activity Log and Time Log Summary your interns have
      started appears here — open one to read it, or download the printed MDC form to file with your SIPP
      documents. The paper copy is signed by the company supervisor, not approved in the app.
    </div>

    <!-- Filters: program + student + period range -->
    <div class="flex flex-wrap items-end gap-3">
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Program</span>
        <select
          v-model="programId"
          class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          @change="applyFilters"
        >
          <option :value="null">All Programs</option>
          <option v-for="program in programs" :key="program.id" :value="program.id">
            {{ program.code ?? program.name }}
          </option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Student</span>
        <input
          v-model="search"
          type="search"
          placeholder="Search by name"
          class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          @keyup.enter="applyFilters"
          @search="applyFilters"
        />
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Period From</span>
        <input
          v-model="from"
          type="date"
          class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          @change="applyFilters"
        />
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Period To</span>
        <input
          v-model="to"
          type="date"
          class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
          @change="applyFilters"
        />
      </label>
      <button
        type="button"
        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
        @click="applyFilters"
      >
        Search
      </button>
      <button
        type="button"
        class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
        @click="resetFilters"
      >
        Reset
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <div v-else class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <p class="text-sm font-semibold text-slate-700">Weekly and Time Log Summaries</p>
        <span class="text-xs text-slate-400">{{ total }} {{ total === 1 ? 'sheet' : 'sheets' }}</span>
      </div>

      <!-- md and up: aligned table. -->
      <div class="hidden overflow-x-auto md:block">
        <table class="min-w-full table-fixed divide-y divide-slate-200">
          <colgroup>
            <col />
            <col class="w-24" />
            <col class="w-56" />
            <col class="w-40" />
            <col class="w-20" />
            <col class="w-48" />
          </colgroup>
          <thead class="bg-slate-50">
            <tr>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Period Covered</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Area Assigned</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Hours</th>
              <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="rows.length === 0">
              <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">
                {{
                  hasFilters
                    ? 'No log sheets match these filters.'
                    : 'None of your interns has started a Weekly and Time Log Summary yet.'
                }}
                <button
                  v-if="hasFilters"
                  type="button"
                  class="mt-2 block w-full text-sm font-semibold text-blue-600 transition hover:text-blue-700"
                  @click="resetFilters"
                >
                  Clear filters
                </button>
              </td>
            </tr>
            <tr v-for="row in rows" :key="row.id">
              <td class="px-4 py-3">
                <p class="truncate text-sm font-semibold text-slate-900">{{ row.student_name }}</p>
                <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ row.program || '—' }}</td>
              <td class="px-4 py-3 text-sm text-slate-700">
                {{ formatRange(row.week_start, row.week_end) }}
                <span class="block text-xs text-slate-400">{{ row.entries_count }} {{ row.entries_count === 1 ? 'row' : 'rows' }}</span>
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ row.area_assigned || '—' }}</td>
              <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ formatHours(row.no_of_hours) }}</td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="openDetail(row)"
                  >
                    View
                  </button>
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="downloadPdf(row.id)"
                  >
                    PDF
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Below md: one stacked card per sheet, so nothing scrolls sideways. -->
      <ul class="divide-y divide-slate-100 px-4 md:hidden">
        <li v-if="rows.length === 0" class="py-6 text-center text-sm text-slate-500">
          {{
            hasFilters
              ? 'No log sheets match these filters.'
              : 'None of your interns has started a Weekly and Time Log Summary yet.'
          }}
          <button
            v-if="hasFilters"
            type="button"
            class="mt-2 block w-full text-sm font-semibold text-blue-600 transition hover:text-blue-700"
            @click="resetFilters"
          >
            Clear filters
          </button>
        </li>
        <li v-for="row in rows" :key="row.id" class="py-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-slate-900">{{ row.student_name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
            </div>
            <span class="shrink-0 font-mono text-xs text-slate-500">{{ formatHours(row.no_of_hours) }} hrs</span>
          </div>
          <p class="mt-1 truncate text-xs text-slate-500">{{ row.program || '—' }}</p>
          <p class="mt-1 text-xs text-slate-500">{{ formatRange(row.week_start, row.week_end) }}</p>
          <p class="mt-0.5 text-xs text-slate-400">
            {{ row.entries_count }} {{ row.entries_count === 1 ? 'row' : 'rows' }} · {{ row.area_assigned || 'No area assigned' }}
          </p>
          <div class="mt-3 flex items-center gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              @click="openDetail(row)"
            >
              View
            </button>
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              @click="downloadPdf(row.id)"
            >
              PDF
            </button>
          </div>
        </li>
      </ul>

      <div v-if="lastPage > 1" class="flex items-center justify-between border-t border-slate-100 px-4 py-3">
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
          :disabled="page <= 1"
          @click="goToPage(page - 1)"
        >
          Previous
        </button>
        <span class="text-xs text-slate-500">Page {{ page }} of {{ lastPage }}</span>
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
          :disabled="page >= lastPage"
          @click="goToPage(page + 1)"
        >
          Next
        </button>
      </div>
    </div>

    <!-- Read-only sheet preview. Three-part flex shell: header, the only
         scrolling body, footer. (PROJECT.md modal convention.) -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <section class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="shrink-0 border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">
            {{ detail?.header.student_name ?? 'Weekly and Time Log Summary' }}
          </h3>
          <p v-if="detail" class="mt-0.5 text-xs text-slate-500">
            Period covered {{ formatRange(detail.week_start, detail.week_end) }}
          </p>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <p v-if="isDetailLoading" class="text-sm text-slate-500">Loading...</p>
          <p v-else-if="detailError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ detailError }}</p>

          <div v-else-if="detail" class="space-y-5">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Program &amp; Year</dt>
                <dd class="mt-0.5 text-sm text-slate-800">{{ detail.header.program_and_year || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Faculty Adviser</dt>
                <dd class="mt-0.5 text-sm text-slate-800">{{ detail.header.faculty_adviser || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Name of Company</dt>
                <dd class="mt-0.5 text-sm text-slate-800">{{ detail.header.company_name || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Supervisor</dt>
                <dd class="mt-0.5 text-sm text-slate-800">{{ detail.header.supervisor_name || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Area Assigned</dt>
                <dd class="mt-0.5 text-sm text-slate-800">{{ detail.area_assigned || '—' }}</dd>
              </div>
              <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">No. of Hours</dt>
                <dd class="mt-0.5 font-mono text-sm text-slate-800">{{ formatHours(detail.no_of_hours) }}</dd>
              </div>
            </dl>

            <div>
              <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Activity Log</h4>
              <p class="mt-2 text-xs text-slate-400 sm:hidden">Scroll sideways to see every column.</p>
              <div class="mt-2 overflow-x-auto">
                <table class="min-w-[52rem] divide-y divide-slate-200 border border-slate-200">
                  <thead class="bg-slate-50">
                    <tr>
                      <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Inclusive Dates</th>
                      <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Activities</th>
                      <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Document/Records</th>
                      <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Objective/s</th>
                      <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Supervisor</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100">
                    <tr v-if="detail.entries.length === 0">
                      <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-400">
                        This student has not filled in any rows yet.
                      </td>
                    </tr>
                    <tr v-for="entry in detail.entries" :key="entry.id" class="align-top">
                      <td class="px-3 py-2 text-sm text-slate-700">
                        {{ formatRange(entry.inclusive_date_start, entry.inclusive_date_end) }}
                      </td>
                      <td class="whitespace-pre-line px-3 py-2 text-sm text-slate-800">{{ entry.activities || '—' }}</td>
                      <td class="whitespace-pre-line px-3 py-2 text-sm text-slate-600">{{ entry.documents_records || '—' }}</td>
                      <td class="whitespace-pre-line px-3 py-2 text-sm text-slate-600">{{ entry.objectives || '—' }}</td>
                      <td class="px-3 py-2 text-sm text-slate-600">
                        <span class="block">{{ entry.supervisor_name || '—' }}</span>
                        <span class="block text-xs text-slate-400">{{ entry.supervisor_position || '' }}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="shrink-0 border-t border-slate-200 bg-white px-6 py-4">
          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="closeDetail"
            >
              Close
            </button>
            <button
              v-if="detail"
              type="button"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
              @click="downloadPdf(detail.id)"
            >
              Download PDF
            </button>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

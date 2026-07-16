<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import WeeklyJournalPaperView from '@/components/journal/WeeklyJournalPaperView.vue'
import type {
  CoordinatorWeeklyJournalDetail,
  CoordinatorWeeklyJournalRow,
  CoordinatorWeeklyJournalsResponse,
  SupervisorReviewStatus,
} from '@/types/api'

const programId = ref<number | null>(null)
const status = ref<'' | SupervisorReviewStatus>('')
const from = ref<string>('')
const to = ref<string>('')

const rows = ref<CoordinatorWeeklyJournalRow[]>([])
const programs = ref<{ id: number; name: string; code?: string }[]>([])
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const isLoading = ref(true)

const statusLabel = (value: SupervisorReviewStatus): string => {
  if (value === 'approved') return 'Approved'
  if (value === 'returned') return 'Returned'
  return 'Pending Review'
}

const statusClass = (value: SupervisorReviewStatus): string => {
  if (value === 'approved') return 'bg-green-50 text-green-700'
  if (value === 'returned') return 'bg-red-50 text-red-700'
  return 'bg-amber-50 text-amber-700'
}

const formatDateTime = (iso: string | null): string => (iso ? new Date(iso).toLocaleString() : '—')

const load = async () => {
  isLoading.value = true

  try {
    const params: Record<string, string | number> = { page: page.value }
    if (programId.value) params.program_id = programId.value
    if (status.value) params.status = status.value
    if (from.value) params.from = from.value
    if (to.value) params.to = to.value

    const { data } = await api.get<CoordinatorWeeklyJournalsResponse>('/api/coordinator/weekly-journals', { params })
    rows.value = data.logs.data
    programs.value = data.programs
    lastPage.value = data.logs.last_page
    total.value = data.logs.total
  } catch {
    showToast('Unable to load weekly journals.', 'error')
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
  status.value = ''
  from.value = ''
  to.value = ''
  applyFilters()
}

const goToPage = (target: number) => {
  if (target < 1 || target > lastPage.value || target === page.value) return
  page.value = target
  load()
}

// --- Detail: read-only document preview of one weekly journal --------------
const isDetailOpen = ref(false)
const isDetailLoading = ref(false)
const detailError = ref('')
const detail = ref<CoordinatorWeeklyJournalDetail | null>(null)

const openDetail = async (row: CoordinatorWeeklyJournalRow) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detailError.value = ''
  detail.value = null

  try {
    const { data } = await api.get<CoordinatorWeeklyJournalDetail>(`/api/coordinator/weekly-journals/${row.id}`)
    detail.value = data
  } catch (error) {
    detailError.value =
      axios.isAxiosError(error) && error.response?.status === 403
        ? 'You do not have access to this weekly journal.'
        : 'Unable to load this weekly journal.'
    showToast(detailError.value, 'error')
  } finally {
    isDetailLoading.value = false
  }
}

const closeDetail = () => {
  isDetailOpen.value = false
  detail.value = null
}

const downloadPdf = () => {
  if (!detail.value) return
  window.open(`/api/coordinator/weekly-journals/${detail.value.id}/pdf`, '_blank')
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This view is <strong>read-only</strong>. Coordinators observe their interns' weekly narrative journals;
      approval and return-for-revision remain with company supervisors.
    </div>

    <!-- Filters: program + status + week range -->
    <div class="flex flex-wrap items-end gap-3">
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Program</span>
        <select v-model="programId" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="applyFilters">
          <option :value="null">All Programs</option>
          <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.code ?? program.name }}</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Status</span>
        <select v-model="status" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="applyFilters">
          <option value="">All Statuses</option>
          <option value="pending">Pending Review</option>
          <option value="approved">Approved</option>
          <option value="returned">Returned</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Week From</span>
        <input v-model="from" type="date" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="applyFilters" />
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Week To</span>
        <input v-model="to" type="date" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="applyFilters" />
      </label>
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="resetFilters">
        Reset
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <p class="text-sm font-semibold text-slate-700">Submitted weekly journals</p>
        <span class="text-xs text-slate-400">{{ total }} {{ total === 1 ? 'journal' : 'journals' }}</span>
      </div>

      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Week</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Journal</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="rows.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">No weekly journals match these filters.</td>
          </tr>
          <tr v-for="row in rows" :key="row.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ row.student_name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ row.program || '—' }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ row.week_start }} – {{ row.week_end }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span>
            </td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ formatDateTime(row.submitted_at) }}</td>
            <td class="px-4 py-3">
              <button
                type="button"
                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                @click="openDetail(row)"
              >
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>

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

    <!-- Document preview modal — read-only, no verdict actions -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-3xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">{{ detail?.student.name ?? 'Weekly Journal' }}</h3>
            <p v-if="detail" class="mt-0.5 text-xs text-slate-500">Week {{ detail.week_start }} – {{ detail.week_end }}</p>
          </div>
          <div class="flex items-center gap-3">
            <button v-if="detail" type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="downloadPdf">Download PDF</button>
            <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeDetail">Close</button>
          </div>
        </div>

        <p v-if="isDetailLoading" class="mt-5 text-sm text-slate-500">Loading...</p>
        <p v-else-if="detailError" class="mt-5 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ detailError }}</p>

        <div v-else-if="detail" class="mt-5 space-y-5">
          <div class="flex items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(detail.status)">{{ statusLabel(detail.status) }}</span>
            <span class="text-xs text-slate-400">Submitted {{ formatDateTime(detail.submitted_at) }}</span>
          </div>

          <!-- Document preview: the weekly narrative rendered as the same
               typed document the PDF produces. -->
          <div class="rounded-md bg-slate-100 p-4 sm:p-6">
            <WeeklyJournalPaperView
              :narrative="detail.narrative"
              :student-name="detail.student.name"
              :week-start="detail.week_start"
              :week-end="detail.week_end"
            />
          </div>

          <div v-if="detail.supervisor_comment">
            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">Supervisor's Comment</h4>
            <p class="mt-2 rounded-md bg-amber-50 p-3 text-sm text-amber-800">{{ detail.supervisor_comment }}</p>
          </div>

          <details class="rounded-md border border-slate-200">
            <summary class="cursor-pointer px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-500 transition hover:text-slate-700">
              Daily Entries This Week ({{ detail.daily_entries.length }})
            </summary>
            <div class="border-t border-slate-100 p-3">
              <div v-if="detail.daily_entries.length === 0" class="text-sm text-slate-400">No daily entries for this week.</div>
              <div v-else class="space-y-2">
                <div v-for="entry in detail.daily_entries" :key="entry.entry_date" class="rounded-md border border-slate-200 p-3">
                  <div class="flex items-center justify-between">
                    <p class="font-mono text-xs font-semibold text-slate-600">{{ entry.entry_date }}</p>
                    <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">{{ entry.status }}</span>
                  </div>
                  <p v-for="(value, key) in entry.content" :key="key" class="mt-1 text-xs text-slate-600">
                    <span class="font-semibold text-slate-500">{{ key }}:</span> {{ value }}
                  </p>
                </div>
              </div>
            </div>
          </details>
        </div>
      </section>
    </div>
  </section>
</template>

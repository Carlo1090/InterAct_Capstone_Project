<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { JournalActivityDetail, JournalActivityResponse, JournalActivityRow } from '@/types/api'

const today = new Date().toISOString().slice(0, 10)

const from = ref<string>(today)
const to = ref<string>(today)
const companyId = ref<number | null>(null)
const programId = ref<number | null>(null)
const status = ref<'' | 'submitted' | 'missing'>('')

const rows = ref<JournalActivityRow[]>([])
const companies = ref<{ id: number; name: string }[]>([])
const programs = ref<{ id: number; name: string; code?: string }[]>([])
const isSingleDay = ref(true)

const isLoading = ref(true)
const errorMessage = ref('')

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const params: Record<string, string | number> = {}
    if (from.value) params.from = from.value
    if (to.value) params.to = to.value
    if (companyId.value) params.company_id = companyId.value
    if (programId.value) params.program_id = programId.value
    if (status.value) params.status = status.value

    const { data } = await api.get<JournalActivityResponse>('/api/coordinator/journal-activities', { params })
    rows.value = data.rows
    companies.value = data.companies
    programs.value = data.programs
    isSingleDay.value = data.is_single_day
    // Reflect any server-side normalization (e.g. reversed range).
    from.value = data.from
    to.value = data.to
  } catch {
    errorMessage.value = 'Unable to load journal activity.'
  } finally {
    isLoading.value = false
  }
}

const resetToToday = () => {
  from.value = today
  to.value = today
  companyId.value = null
  programId.value = null
  status.value = ''
  load()
}

const formatTime = (iso: string | null): string => {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

// --- Detail view: read-only view of one student's full entry for a day -----
const isDetailOpen = ref(false)
const isDetailLoading = ref(false)
const detailError = ref('')
const detail = ref<JournalActivityDetail | null>(null)

const openDetail = async (row: JournalActivityRow, date: string) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detailError.value = ''
  detail.value = null

  try {
    const { data } = await api.get<JournalActivityDetail>(`/api/coordinator/journal-activities/${row.student_id}/${date}`)
    detail.value = data
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 403) {
      detailError.value = 'You do not have access to this student.'
    } else {
      detailError.value = 'Unable to load this journal entry.'
    }
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
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This view is <strong>read-only</strong>. Coordinators monitor daily journal activity; weekly approval remains with company supervisors.
    </div>

    <!-- Filters: date range (calendar inputs) + company + program + status -->
    <div class="flex flex-wrap items-end gap-3">
      <label class="block">
        <span class="text-xs font-bold text-slate-600">From</span>
        <input v-model="from" type="date" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="load" />
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">To</span>
        <input v-model="to" type="date" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="load" />
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Company</span>
        <select v-model="companyId" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="load">
          <option :value="null">All Companies</option>
          <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Program</span>
        <select v-model="programId" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="load">
          <option :value="null">All Programs</option>
          <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.code ?? program.name }}</option>
        </select>
      </label>
      <label class="block">
        <span class="text-xs font-bold text-slate-600">Status</span>
        <select v-model="status" class="mt-1 block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="load">
          <option value="">All Statuses</option>
          <option value="submitted">Submitted</option>
          <option value="missing">Missing</option>
        </select>
      </label>
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="resetToToday">
        Today
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <p class="text-sm font-semibold text-slate-700">
          {{ isSingleDay ? `Journals for ${from}` : `Range: ${from} → ${to}` }}
        </p>
        <span class="text-xs text-slate-400">{{ rows.length }} {{ rows.length === 1 ? 'student' : 'students' }}</span>
      </div>

      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
            <template v-if="isSingleDay">
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted At</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            </template>
            <template v-else>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Missing</th>
            </template>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Entry</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="rows.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">No interns match these filters.</td>
          </tr>
          <tr v-for="row in rows" :key="row.student_id">
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ row.student_name }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ row.company || '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ row.program || '—' }}</td>
            <template v-if="isSingleDay">
              <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ formatTime(row.submitted_at) }}</td>
              <td class="px-4 py-3">
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="row.day_status === 'submitted' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
                >
                  {{ row.day_status === 'submitted' ? 'Submitted' : 'Missing' }}
                </span>
              </td>
            </template>
            <template v-else>
              <td class="px-4 py-3">
                <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-bold text-green-700">{{ row.submitted_count }}</span>
              </td>
              <td class="px-4 py-3">
                <span class="rounded-full px-3 py-1 text-xs font-bold" :class="row.missing_count > 0 ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500'">
                  {{ row.missing_count }}
                </span>
              </td>
            </template>
            <td class="px-4 py-3">
              <button
                type="button"
                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                @click="openDetail(row, isSingleDay ? from : to)"
              >
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Journal entry detail modal — read-only -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">{{ detail?.student_name ?? 'Journal Entry' }}</h3>
            <p v-if="detail" class="mt-0.5 text-xs text-slate-500">{{ detail.entry_date }} · Submitted {{ formatTime(detail.submitted_at) }}</p>
          </div>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeDetail">Close</button>
        </div>

        <p v-if="isDetailLoading" class="mt-5 text-sm text-slate-500">Loading...</p>
        <p v-else-if="detailError" class="mt-5 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ detailError }}</p>

        <div v-else-if="detail" class="mt-5 space-y-4">
          <span
            class="inline-block rounded-full px-3 py-1 text-xs font-bold"
            :class="detail.status === 'submitted' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
          >
            {{ detail.status === 'submitted' ? 'Submitted' : 'Missing' }}
          </span>

          <p v-if="detail.sections.length === 0" class="text-sm text-slate-500">This batch has no journal template configured.</p>

          <div v-for="section in detail.sections" :key="section.key" class="rounded-md border border-slate-200 p-3">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ section.label }}</p>
            <p class="mt-1 whitespace-pre-wrap text-sm text-slate-800">{{ section.text || '—' }}</p>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import type {
  AnnualSippIndex,
  AnnualSippMeta,
  AnnualSippProgram,
  AnnualSippReport,
  AnnualSippRow,
} from '@/types/api'

const SIPP_CHAR_LIMIT = 300

const programs = ref<AnnualSippProgram[]>([])
const academicYears = ref<string[]>([])
const activeProgramId = ref<number | null>(null)
const academicYear = ref<string>('')

const rows = ref<AnnualSippRow[]>([])
const deletedIds = ref<number[]>([])
const meta = ref<AnnualSippMeta>({
  heading: '',
  signatory_prepared_name: '',
  signatory_prepared_title: '',
  signatory_certified_name: '',
  signatory_certified_title: '',
})
const status = ref<'draft' | 'finalized'>('draft')

const isLoadingIndex = ref(true)
const isLoadingReport = ref(false)
const isSaving = ref(false)
const isDownloading = ref(false)
const errorMessage = ref('')
const statusMessage = ref('')

const activeProgram = () => programs.value.find((program) => program.id === activeProgramId.value)

const loadIndex = async () => {
  isLoadingIndex.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<AnnualSippIndex>('/api/coordinator/annual-sipp')
    programs.value = data.programs
    academicYears.value = data.academic_years

    activeProgramId.value = data.programs[0]?.id ?? null
    academicYear.value = data.academic_years[0] ?? ''

    if (activeProgramId.value && academicYear.value) {
      await loadReport()
    }
  } catch {
    errorMessage.value = 'Unable to load your Annual SIPP programs.'
  } finally {
    isLoadingIndex.value = false
  }
}

const loadReport = async () => {
  if (!activeProgramId.value || !academicYear.value) {
    rows.value = []
    return
  }

  isLoadingReport.value = true
  errorMessage.value = ''
  statusMessage.value = ''

  try {
    const { data } = await api.get<AnnualSippReport>(`/api/coordinator/annual-sipp/${activeProgramId.value}`, {
      params: { academic_year: academicYear.value },
    })
    applyReport(data)
  } catch {
    errorMessage.value = 'Unable to load this report.'
  } finally {
    isLoadingReport.value = false
  }
}

const applyReport = (data: AnnualSippReport) => {
  rows.value = data.rows
  meta.value = data.meta
  status.value = data.status
  deletedIds.value = []
}

const selectProgram = async (programId: number) => {
  if (programId === activeProgramId.value) return
  activeProgramId.value = programId
  await loadReport()
}

const onYearChange = async () => {
  await loadReport()
}

const deleteRow = async (row: AnnualSippRow) => {
  if (!(await confirmAction('Remove this row from the report? It will be excluded when you save.'))) return
  rows.value = rows.value.filter((candidate) => candidate.id !== row.id)
  if (!deletedIds.value.includes(row.id)) {
    deletedIds.value.push(row.id)
  }
}

const save = async (nextStatus: 'draft' | 'finalized') => {
  if (!activeProgramId.value || !academicYear.value) return

  isSaving.value = true
  errorMessage.value = ''
  statusMessage.value = ''

  try {
    const { data } = await api.post<AnnualSippReport>(`/api/coordinator/annual-sipp/${activeProgramId.value}`, {
      academic_year: academicYear.value,
      heading: meta.value.heading,
      status: nextStatus,
      signatory_prepared_name: meta.value.signatory_prepared_name,
      signatory_prepared_title: meta.value.signatory_prepared_title,
      signatory_certified_name: meta.value.signatory_certified_name,
      signatory_certified_title: meta.value.signatory_certified_title,
      rows: rows.value.map((row) => ({
        id: row.id,
        issues_concerns: row.issues_concerns,
        solutions: row.solutions,
        recommendations: row.recommendations,
        included: row.included,
      })),
      deleted_ids: deletedIds.value,
    })
    applyReport(data)
    showToast(nextStatus === 'finalized' ? 'Report finalized.' : 'Draft saved.')
  } catch (error) {
    const responseData = axios.isAxiosError(error) ? error.response?.data : null
    errorMessage.value = responseData?.message ?? 'Unable to save the report.'
  } finally {
    isSaving.value = false
  }
}

const downloadPdf = async () => {
  if (!activeProgramId.value || !academicYear.value) return

  isDownloading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get(`/api/coordinator/annual-sipp/${activeProgramId.value}/pdf`, {
      params: { academic_year: academicYear.value },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `annual-sipp-report-${activeProgram()?.code ?? activeProgramId.value}-${academicYear.value}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch {
    errorMessage.value = 'Unable to download the PDF.'
  } finally {
    isDownloading.value = false
  }
}

onMounted(loadIndex)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />
    <div>
      <h2 class="text-2xl font-bold text-slate-950">Annual SIPP Report</h2>
      <p class="mt-1 text-sm text-slate-500">
        Curate students' SIPP notes (issues, solutions, recommendations) per program, then export the official report.
      </p>
    </div>

    <p v-if="isLoadingIndex" class="text-sm text-slate-500">Loading...</p>

    <div v-else-if="programs.length === 0" class="rounded-lg bg-white px-4 py-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
      You have no programs assigned yet. Ask an admin to assign you to a department.
    </div>

    <template v-else>
      <!-- Secondary nav: one tab per in-scope program -->
      <nav class="overflow-x-auto border-b border-slate-200">
        <div class="flex min-w-max gap-1">
          <button
            v-for="program in programs"
            :key="program.id"
            type="button"
            class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-semibold transition"
            :class="program.id === activeProgramId
              ? 'border-blue-600 text-blue-700'
              : 'border-transparent text-slate-500 hover:text-slate-800'"
            @click="selectProgram(program.id)"
          >
            {{ program.code ?? program.name }}
          </button>
        </div>
      </nav>

      <!-- Controls -->
      <div class="flex flex-wrap items-end justify-between gap-4 rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <label class="block">
          <span class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-slate-500">Academic Year</span>
          <select
            v-model="academicYear"
            class="w-56 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
            :disabled="academicYears.length === 0"
            @change="onYearChange"
          >
            <option v-if="academicYears.length === 0" value="">No batches yet</option>
            <option v-for="year in academicYears" :key="year" :value="year">{{ year }}</option>
          </select>
        </label>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isSaving || isLoadingReport"
            @click="save('draft')"
          >
            Save Draft
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isSaving || isLoadingReport"
            @click="save('finalized')"
          >
            Finalize
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isDownloading || isLoadingReport"
            @click="downloadPdf"
          >
            {{ isDownloading ? 'Preparing...' : 'Download PDF' }}
          </button>
        </div>
      </div>

      <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
      <p v-if="statusMessage" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>

      <p v-if="isLoadingReport" class="text-sm text-slate-500">Loading report...</p>

      <template v-else>
        <!-- Document meta: editable heading -->
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-bold text-slate-900">Report Heading</h3>
            <span
              class="rounded-full px-3 py-1 text-xs font-bold"
              :class="status === 'finalized' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'"
            >
              {{ status === 'finalized' ? 'Finalized' : 'Draft' }}
            </span>
          </div>
          <label class="mt-3 block">
            <span class="text-xs font-bold text-slate-600">Degree Program (heading)</span>
            <input
              v-model="meta.heading"
              type="text"
              maxlength="200"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
          </label>
        </section>

        <!-- Curation table -->
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h3 class="text-sm font-bold text-slate-900">
            SIPP Entries
            <span class="ml-1 text-xs font-normal text-slate-400">({{ rows.length }} candidate {{ rows.length === 1 ? 'row' : 'rows' }})</span>
          </h3>

          <p v-if="rows.length === 0" class="mt-4 text-sm text-slate-400">
            No journal entries with SIPP content were found for this program and academic year.
          </p>

          <div v-else class="mt-4 overflow-x-auto">
            <table class="min-w-full border-collapse text-sm">
              <thead>
                <tr class="border-b border-slate-200 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                  <th class="w-24 px-2 py-2">Include</th>
                  <th class="w-40 px-2 py-2">Student / Date</th>
                  <th class="px-2 py-2">Issues &amp; Concerns</th>
                  <th class="px-2 py-2">Solutions</th>
                  <th class="px-2 py-2">Recommendations</th>
                  <th class="w-16 px-2 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="row in rows" :key="row.id" :class="row.included ? '' : 'opacity-50'">
                  <td class="px-2 py-3 align-top">
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                      <input v-model="row.included" type="checkbox" />
                      {{ row.included ? 'In' : 'Out' }}
                    </label>
                  </td>
                  <td class="px-2 py-3 align-top">
                    <p class="font-semibold text-slate-800">{{ row.student_name }}</p>
                    <p class="text-xs text-slate-400">{{ row.entry_date }}</p>
                  </td>
                  <td class="px-2 py-3 align-top">
                    <textarea
                      v-model="row.issues_concerns"
                      :maxlength="SIPP_CHAR_LIMIT"
                      rows="3"
                      class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs leading-5"
                    />
                    <span class="text-[10px] text-slate-400">{{ row.issues_concerns.length }}/{{ SIPP_CHAR_LIMIT }}</span>
                  </td>
                  <td class="px-2 py-3 align-top">
                    <textarea
                      v-model="row.solutions"
                      :maxlength="SIPP_CHAR_LIMIT"
                      rows="3"
                      class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs leading-5"
                    />
                    <span class="text-[10px] text-slate-400">{{ row.solutions.length }}/{{ SIPP_CHAR_LIMIT }}</span>
                  </td>
                  <td class="px-2 py-3 align-top">
                    <textarea
                      v-model="row.recommendations"
                      :maxlength="SIPP_CHAR_LIMIT"
                      rows="3"
                      class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs leading-5"
                    />
                    <span class="text-[10px] text-slate-400">{{ row.recommendations.length }}/{{ SIPP_CHAR_LIMIT }}</span>
                  </td>
                  <td class="px-2 py-3 text-right align-top">
                    <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-700" @click="deleteRow(row)">
                      Delete
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            <p class="mt-2 text-xs text-slate-400">Only included rows appear in the exported PDF. Deletes are saved when you Save/Finalize.</p>
          </div>
        </section>

        <!-- Signatories -->
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h3 class="text-sm font-bold text-slate-900">Signatories</h3>
          <div class="mt-4 grid gap-5 md:grid-cols-2">
            <div class="space-y-3">
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Prepared By</p>
              <label class="block">
                <span class="text-xs font-medium text-slate-600">Name</span>
                <input v-model="meta.signatory_prepared_name" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="block">
                <span class="text-xs font-medium text-slate-600">Title</span>
                <input v-model="meta.signatory_prepared_title" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </label>
            </div>
            <div class="space-y-3">
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Certified Correct</p>
              <label class="block">
                <span class="text-xs font-medium text-slate-600">Name</span>
                <input v-model="meta.signatory_certified_name" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="block">
                <span class="text-xs font-medium text-slate-600">Title</span>
                <input v-model="meta.signatory_certified_title" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </label>
            </div>
          </div>
        </section>
      </template>
    </template>
  </section>
</template>

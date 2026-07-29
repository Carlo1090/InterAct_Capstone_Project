<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import ReportEditorBar from '@/components/coordinator/ReportEditorBar.vue'
import HtePaperView from '@/components/coordinator/HtePaperView.vue'
import type { HteIndex, HteMeta, HteReport, HteRow } from '@/types/api'
import { useFormDraft } from '@/lib/formDraft'

const academicYears = ref<string[]>([])
const academicYear = ref<string>('')

const rows = ref<HteRow[]>([])
const deletedIds = ref<number[]>([])
const meta = ref<HteMeta>({
  signatory_prepared_name: '',
  signatory_prepared_title: '',
  signatory_certified_name: '',
  signatory_certified_title: '',
})
const status = ref<'draft' | 'finalized'>('draft')
const mode = ref<'edit' | 'preview'>('edit')

const isLoadingIndex = ref(true)
const isLoadingReport = ref(false)
const isSaving = ref(false)
const isDownloading = ref(false)
const errorMessage = ref('')
const statusMessage = ref('')

const loadIndex = async () => {
  isLoadingIndex.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<HteIndex>('/api/coordinator/hte')
    academicYears.value = data.academic_years
    academicYear.value = data.academic_years[0] ?? ''

    if (academicYear.value) {
      await loadReport()
    }
  } catch {
    errorMessage.value = 'Unable to load your HTE academic years.'
  } finally {
    isLoadingIndex.value = false
  }
}

const loadReport = async () => {
  if (!academicYear.value) {
    rows.value = []
    return
  }

  isLoadingReport.value = true
  errorMessage.value = ''
  statusMessage.value = ''
  mode.value = 'edit'

  try {
    const { data } = await api.get<HteReport>(`/api/coordinator/hte/${academicYear.value}`)
    applyReport(data)
    // Server copy is in place — layer unsaved curation back on top.
    hteDraft.restore()
  } catch {
    errorMessage.value = 'Unable to load this HTE list.'
  } finally {
    isLoadingReport.value = false
  }
}

const applyReport = (data: HteReport) => {
  rows.value = data.rows
  meta.value = data.meta
  status.value = data.status
  deletedIds.value = []
}

/**
 * Keep unsaved curation alive across a refresh.
 *
 * Keyed by academic year inside the payload: the year selector re-runs
 * loadReport() without remounting, so a draft from 2025 must never be applied
 * over 2026's rows.
 *
 * Unlike the student narrative drafts, this one is genuinely cleared after a
 * successful save — the rows are derived from live enrollments, so a lingering
 * draft could later mask legitimately refreshed server data.
 */
const hteDraft = useFormDraft(
  'coordinator:hte',
  () => ({
    year: academicYear.value,
    rows: rows.value,
    meta: meta.value,
    deletedIds: deletedIds.value,
  }),
  (draft) => {
    if (draft.year !== academicYear.value) return
    if (Array.isArray(draft.rows)) rows.value = draft.rows
    if (draft.meta) meta.value = draft.meta
    if (Array.isArray(draft.deletedIds)) deletedIds.value = draft.deletedIds
  },
  { autoRestore: false },
)

/**
 * Drop the draft after the server copy has been applied. The nextTick matters:
 * applyReport() mutates the watched refs, so a write is already queued — clear()
 * must run after that scheduling to cancel its timer, otherwise the just-saved
 * server state would be written straight back as a "draft".
 */
const clearDraftAfterSave = async () => {
  await nextTick()
  hteDraft.clear()
}

const onYearChange = async () => {
  await loadReport()
}

const addManualRow = () => {
  rows.value.push({
    id: `manual-${Date.now()}`,
    host_establishment: '',
    student_name: '',
    program: '',
    gender: '',
    duration: '',
    included: true,
    is_manual: true,
  })
}

const deleteRow = async (row: HteRow) => {
  if (!(await confirmAction('Remove this row from the list? It will be excluded when you save.'))) return
  if (!row.is_manual && typeof row.id === 'number' && !deletedIds.value.includes(row.id)) {
    deletedIds.value.push(row.id)
  }
  rows.value = rows.value.filter((candidate) => candidate.id !== row.id)
}

const save = async (nextStatus: 'draft' | 'finalized') => {
  if (!academicYear.value) return

  isSaving.value = true
  errorMessage.value = ''
  statusMessage.value = ''

  const payloadRow = (row: HteRow) => ({
    id: row.id,
    host_establishment: row.host_establishment,
    student_name: row.student_name,
    program: row.program,
    gender: row.gender,
    duration: row.duration,
    included: row.included,
  })

  try {
    const { data } = await api.post<HteReport>(`/api/coordinator/hte/${academicYear.value}`, {
      academic_year: academicYear.value,
      status: nextStatus,
      signatory_prepared_name: meta.value.signatory_prepared_name,
      signatory_prepared_title: meta.value.signatory_prepared_title,
      signatory_certified_name: meta.value.signatory_certified_name,
      signatory_certified_title: meta.value.signatory_certified_title,
      rows: rows.value.filter((row) => !row.is_manual).map(payloadRow),
      manual_rows: rows.value
        .filter((row) => row.is_manual)
        .map((row) => ({ ...payloadRow(row), id: String(row.id) })),
      deleted_ids: deletedIds.value,
    })
    applyReport(data)
    await clearDraftAfterSave()
    showToast(nextStatus === 'finalized' ? 'HTE list finalized.' : 'Draft saved.')
  } catch (error) {
    const responseData = axios.isAxiosError(error) ? error.response?.data : null
    errorMessage.value = responseData?.message ?? 'Unable to save the HTE list.'
  } finally {
    isSaving.value = false
  }
}

const downloadPdf = async () => {
  if (!academicYear.value) return

  isDownloading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get(`/api/coordinator/hte/${academicYear.value}/pdf`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `hte-student-interns-list-${academicYear.value}.pdf`
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
      <h2 class="text-xl font-bold text-slate-950 md:text-2xl">HTE &amp; Student Interns List</h2>
      <p class="mt-1 text-sm text-slate-500">
        Host Training Establishments and the interns placed with them, auto-populated from enrollments. Curate the
        list, then export the official SIPP report.
      </p>
    </div>

    <p v-if="isLoadingIndex" class="text-sm text-slate-500">Loading...</p>

    <template v-else>
      <!-- Filter -->
      <div class="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <label class="block w-full sm:w-auto">
          <span class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-slate-500">Academic Year</span>
          <select
            v-model="academicYear"
            class="w-full sm:w-56 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
            :disabled="academicYears.length === 0"
            @change="onYearChange"
          >
            <option v-if="academicYears.length === 0" value="">No batches yet</option>
            <option v-for="year in academicYears" :key="year" :value="year">{{ year }}</option>
          </select>
        </label>
      </div>

      <!-- Edit ⇄ Preview + actions -->
      <ReportEditorBar
        :mode="mode"
        :status="status"
        :saving="isSaving"
        :downloading="isDownloading"
        :disabled="isLoadingReport"
        @update:mode="mode = $event"
        @save="save('draft')"
        @finalize="save('finalized')"
        @download="downloadPdf"
      />

      <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
      <p v-if="statusMessage" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>

      <p v-if="isLoadingReport" class="text-sm text-slate-500">Loading list...</p>

      <!-- PREVIEW — read-only document -->
      <div v-else-if="mode === 'preview'" class="overflow-x-auto rounded-lg bg-slate-100 p-4 sm:p-6">
        <HtePaperView :rows="rows" :meta="meta" :academic-year="academicYear" />
      </div>

      <template v-else>
        <!-- Curation table -->
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h3 class="text-sm font-bold text-slate-900">
            Host Training Establishments &amp; Interns
            <span class="ml-1 text-xs font-normal text-slate-400">({{ rows.length }} {{ rows.length === 1 ? 'row' : 'rows' }})</span>
          </h3>

          <p v-if="rows.length === 0" class="mt-4 text-sm text-slate-400">
            No enrolled interns were found for this academic year. Use “Add Row” to enter one manually.
          </p>

          <div v-else class="mt-4 overflow-x-auto">
            <table class="min-w-full border-collapse text-sm">
              <thead>
                <tr class="border-b border-slate-200 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                  <th class="w-20 px-2 py-2">Include</th>
                  <th class="px-2 py-2">Host Establishment</th>
                  <th class="px-2 py-2">Student Intern</th>
                  <th class="w-28 px-2 py-2">Program</th>
                  <th class="w-28 px-2 py-2">Gender</th>
                  <th class="px-2 py-2">Duration</th>
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
                    <span v-if="row.is_manual" class="mt-1 inline-block rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700">Manual</span>
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.host_establishment" type="text" maxlength="200" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.student_name" type="text" maxlength="200" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.program" type="text" maxlength="100" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <select v-model="row.gender" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs">
                      <option value="">—</option>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                    </select>
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.duration" type="text" maxlength="100" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 text-right align-top">
                    <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-700" @click="deleteRow(row)">
                      Delete
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>

            <p class="mt-2 text-xs text-slate-400">Only included rows appear in the exported PDF. Deletes and edits are saved when you Save/Finalize.</p>
          </div>

          <button
            type="button"
            class="mt-4 rounded-md border border-dashed border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:border-blue-400 hover:text-blue-700"
            @click="addManualRow"
          >
            + Add Row
          </button>
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

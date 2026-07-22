<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import type {
  GroupInfoSheet,
  GroupInfoSheetCompany,
  GroupInfoSheetCompanyOption,
  GroupInfoSheetIndex,
  GroupInfoSheetRow,
} from '@/types/api'

const academicYears = ref<string[]>([])
const companies = ref<GroupInfoSheetCompanyOption[]>([])
const academicYear = ref('')
const companyId = ref<number | null>(null)

const rows = ref<GroupInfoSheetRow[]>([])
const deletedIds = ref<number[]>([])
const departmentLine = ref('')
const status = ref<'draft' | 'finalized'>('draft')

const emptyCompany = (): GroupInfoSheetCompany => ({
  host_company: '',
  company_address: '',
  company_signatory_moa: '',
  office_designation: '',
  supervisor_name: '',
  supervisor_contact: '',
  intern_duty_schedule: '',
  area_assigned: '',
  ojt_start_date: '',
  ojt_end_date: '',
})

const company = ref<GroupInfoSheetCompany>(emptyCompany())

const isLoadingIndex = ref(true)
const isLoadingSheet = ref(false)
const isSaving = ref(false)
const isDownloading = ref(false)
const indexError = ref('')
const errorMessage = ref('')

/** Only companies that host interns in the selected academic year. */
const companiesForYear = computed(() =>
  companies.value.filter((option) => option.academic_years.includes(academicYear.value)),
)

const includedCount = computed(() => rows.value.filter((row) => row.included).length)

const loadIndex = async () => {
  isLoadingIndex.value = true
  indexError.value = ''

  try {
    const { data } = await api.get<GroupInfoSheetIndex>('/api/coordinator/group-info-sheets')
    academicYears.value = data.academic_years
    companies.value = data.companies
    academicYear.value = data.academic_years[0] ?? ''
    companyId.value = companiesForYear.value[0]?.id ?? null

    if (companyId.value) {
      await loadSheet()
    }
  } catch (error) {
    indexError.value = categorizeError(error, 'Unable to load your companies.').message
  } finally {
    isLoadingIndex.value = false
  }
}

const loadSheet = async () => {
  if (!academicYear.value || !companyId.value) {
    rows.value = []
    company.value = emptyCompany()
    return
  }

  isLoadingSheet.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<GroupInfoSheet>(
      `/api/coordinator/group-info-sheets/${companyId.value}/${academicYear.value}`,
    )
    applySheet(data)
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to load this group information sheet.').message
  } finally {
    isLoadingSheet.value = false
  }
}

const applySheet = (data: GroupInfoSheet) => {
  rows.value = data.rows
  company.value = data.company
  departmentLine.value = data.department_line
  status.value = data.status
  deletedIds.value = []
}

/** Changing the year can invalidate the picked company — re-anchor it first. */
const onYearChange = async () => {
  if (!companiesForYear.value.some((option) => option.id === companyId.value)) {
    companyId.value = companiesForYear.value[0]?.id ?? null
  }
  await loadSheet()
}

const addManualRow = () => {
  rows.value.push({
    id: `manual-${Date.now()}`,
    last_name: '',
    first_name: '',
    middle_initial: '',
    program_year: '',
    contact_number: '',
    parent_guardian_name: '',
    parent_guardian_contact: '',
    included: true,
    is_manual: true,
  })
}

const deleteRow = async (row: GroupInfoSheetRow) => {
  if (!(await confirmAction('Remove this intern from the sheet? They will be excluded when you save.'))) return

  if (!row.is_manual && typeof row.id === 'number' && !deletedIds.value.includes(row.id)) {
    deletedIds.value.push(row.id)
  }
  rows.value = rows.value.filter((candidate) => candidate.id !== row.id)
}

const save = async (nextStatus: 'draft' | 'finalized') => {
  if (!academicYear.value || !companyId.value) return

  isSaving.value = true
  errorMessage.value = ''

  const payloadRow = (row: GroupInfoSheetRow) => ({
    id: row.id,
    last_name: row.last_name,
    first_name: row.first_name,
    middle_initial: row.middle_initial,
    program_year: row.program_year,
    contact_number: row.contact_number,
    parent_guardian_name: row.parent_guardian_name,
    parent_guardian_contact: row.parent_guardian_contact,
    included: row.included,
  })

  try {
    const { data } = await api.post<GroupInfoSheet>(
      `/api/coordinator/group-info-sheets/${companyId.value}/${academicYear.value}`,
      {
        status: nextStatus,
        department_line: departmentLine.value,
        company: company.value,
        rows: rows.value.filter((row) => !row.is_manual).map(payloadRow),
        manual_rows: rows.value
          .filter((row) => row.is_manual)
          .map((row) => ({ ...payloadRow(row), id: String(row.id) })),
        deleted_ids: deletedIds.value,
      },
    )
    applySheet(data)
    showToast(nextStatus === 'finalized' ? 'Group information sheet finalized.' : 'Draft saved.')
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to save the group information sheet.').message
  } finally {
    isSaving.value = false
  }
}

const downloadPdf = async () => {
  if (!academicYear.value || !companyId.value) return

  isDownloading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get(
      `/api/coordinator/group-info-sheets/${companyId.value}/${academicYear.value}/pdf`,
      { responseType: 'blob' },
    )
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `group-student-information-sheet-${academicYear.value}.pdf`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to download the PDF.').message
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
      <h2 class="text-2xl font-bold text-slate-950">Group Info Sheets</h2>
      <p class="mt-1 text-sm text-slate-500">
        One official Student Information Sheet per company. The intern roster is pulled from each student's own
        information sheet; you type the company details once, so they never disagree between students.
      </p>
    </div>

    <LoadStatus :loading="isLoadingIndex" :error="indexError" :retry="loadIndex">
      <!-- Controls -->
      <div class="flex flex-wrap items-end justify-between gap-4 rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex flex-wrap items-end gap-4">
          <label class="block">
            <span class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-slate-500">Academic Year</span>
            <select
              v-model="academicYear"
              class="w-48 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
              :disabled="academicYears.length === 0"
              @change="onYearChange"
            >
              <option v-if="academicYears.length === 0" value="">No batches yet</option>
              <option v-for="year in academicYears" :key="year" :value="year">{{ year }}</option>
            </select>
          </label>

          <label class="block">
            <span class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-slate-500">Company</span>
            <select
              v-model="companyId"
              class="w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm disabled:bg-slate-100"
              :disabled="companiesForYear.length === 0"
              @change="loadSheet"
            >
              <option v-if="companiesForYear.length === 0" :value="null">No companies with interns</option>
              <option v-for="option in companiesForYear" :key="option.id" :value="option.id">{{ option.name }}</option>
            </select>
          </label>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isSaving || isLoadingSheet || !companyId"
            @click="save('draft')"
          >
            Save Draft
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isSaving || isLoadingSheet || !companyId"
            @click="save('finalized')"
          >
            Finalize
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isDownloading || isLoadingSheet || !companyId"
            @click="downloadPdf"
          >
            {{ isDownloading ? 'Preparing...' : 'Download PDF' }}
          </button>
        </div>
      </div>

      <p v-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

      <p v-if="!companyId" class="rounded-md bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
        No company hosts interns from your program(s) for this academic year yet.
      </p>

      <p v-else-if="isLoadingSheet" class="text-sm text-slate-500">Loading sheet...</p>

      <template v-else>
        <!-- Document header line -->
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h3 class="text-sm font-bold text-slate-900">Document Header</h3>
          <label class="mt-3 block">
            <span class="text-xs font-medium text-slate-600">Department Name (printed under "Tubigon, Bohol")</span>
            <input
              v-model="departmentLine"
              type="text"
              maxlength="150"
              placeholder="College of Accountancy, Business and Management"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            />
          </label>
        </section>

        <!-- Roster -->
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <div class="flex items-center justify-between gap-3">
            <h3 class="text-sm font-bold text-slate-900">
              Student Trainee Information
              <span class="ml-1 text-xs font-normal text-slate-400">
                ({{ includedCount }} of {{ rows.length }} included)
              </span>
            </h3>
            <span
              class="rounded-full px-3 py-1 text-xs font-bold"
              :class="status === 'finalized' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'"
            >
              {{ status === 'finalized' ? 'Finalized' : 'Draft' }}
            </span>
          </div>

          <p v-if="rows.length === 0" class="mt-4 text-sm text-slate-400">
            No interns are placed at this company for this academic year. Use “Add Intern” to enter one manually.
          </p>

          <div v-else class="mt-4 overflow-x-auto">
            <table class="min-w-full border-collapse text-sm">
              <thead>
                <tr class="border-b border-slate-200 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
                  <th class="w-20 px-2 py-2">Include</th>
                  <th class="px-2 py-2">Family Name</th>
                  <th class="px-2 py-2">First Name</th>
                  <th class="w-16 px-2 py-2">MI</th>
                  <th class="w-36 px-2 py-2">Program &amp; Year</th>
                  <th class="w-36 px-2 py-2">Contact No.</th>
                  <th class="px-2 py-2">Parent's / Guardian's Name</th>
                  <th class="w-36 px-2 py-2">Parent's Contact No.</th>
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
                    <span
                      v-if="row.is_manual"
                      class="mt-1 inline-block rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700"
                    >
                      Manual
                    </span>
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.last_name" type="text" maxlength="100" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.first_name" type="text" maxlength="100" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.middle_initial" type="text" maxlength="10" class="w-full rounded-md border border-slate-300 px-2 py-1 text-center text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.program_year" type="text" maxlength="100" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.contact_number" type="text" maxlength="30" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.parent_guardian_name" type="text" maxlength="150" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 align-top">
                    <input v-model="row.parent_guardian_contact" type="text" maxlength="30" class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs" />
                  </td>
                  <td class="px-2 py-3 text-right align-top">
                    <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-700" @click="deleteRow(row)">
                      Delete
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>

            <p class="mt-2 text-xs text-slate-400">
              Rows are pulled from each intern's own information sheet. Editing a cell here changes only this document —
              the student's sheet is never modified. Only included rows appear in the exported PDF.
            </p>
          </div>

          <button
            type="button"
            class="mt-4 rounded-md border border-dashed border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:border-blue-400 hover:text-blue-700"
            @click="addManualRow"
          >
            + Add Intern
          </button>
        </section>

        <!-- Coordinator-typed company block -->
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h3 class="text-sm font-bold text-slate-900">Internship Company Information</h3>
          <p class="mt-1 text-xs text-slate-500">
            You fill these in, not the students — every intern above shares this one company block.
          </p>

          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <label class="block md:col-span-2">
              <span class="text-xs font-medium text-slate-600">Name of Company</span>
              <input v-model="company.host_company" type="text" maxlength="200" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block md:col-span-2">
              <span class="text-xs font-medium text-slate-600">Company Address</span>
              <input v-model="company.company_address" type="text" maxlength="255" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Complete Name of Official Company Signatory (for MOA)</span>
              <input v-model="company.company_signatory_moa" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Office Designation / Position</span>
              <input v-model="company.office_designation" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Name of Supervisor / Office Head</span>
              <input v-model="company.supervisor_name" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Contact Number</span>
              <input v-model="company.supervisor_contact" type="text" maxlength="30" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Intern's Duty Schedule</span>
              <input v-model="company.intern_duty_schedule" type="text" maxlength="150" placeholder="8:00 AM - 5:00 PM, Mon-Fri" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Area Assigned</span>
              <input v-model="company.area_assigned" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Start of Internship Duty</span>
              <input v-model="company.ojt_start_date" type="date" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium text-slate-600">Estimated Date to Finish Internship</span>
              <input v-model="company.ojt_end_date" type="date" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
          </div>
        </section>
      </template>
    </LoadStatus>
  </section>
</template>

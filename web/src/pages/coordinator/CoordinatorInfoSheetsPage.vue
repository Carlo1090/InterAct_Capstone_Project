<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction, promptAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import type { CoordinatorInfoSheetDetail, CoordinatorInfoSheetRow, InfoSheetStatus } from '@/types/api'

const students = ref<CoordinatorInfoSheetRow[]>([])
const programs = ref<{ id: number; name: string; code?: string }[]>([])
const search = ref('')
const statusFilter = ref<InfoSheetStatus | ''>('')
const programFilter = ref<number | null>(null)
const isLoading = ref(true)
const errorMessage = ref('')
const isActing = ref(false)

const detail = ref<CoordinatorInfoSheetDetail | null>(null)
const isDetailOpen = ref(false)
const isDetailLoading = ref(false)

// Separates "no sheets on file" from "your filters excluded them all" — only the
// second has a recovery, so only the second gets a Clear filters button.
const hasFilters = computed(
  () => search.value !== '' || statusFilter.value !== '' || programFilter.value !== null,
)

const clearFilters = () => {
  search.value = ''
  statusFilter.value = ''
  programFilter.value = null
}

const statusClass = (status: string | null): string => {
  if (status === 'submitted') return 'bg-blue-50 text-blue-700'
  if (status === 'approved') return 'bg-green-50 text-green-700'
  if (status === 'rejected') return 'bg-red-50 text-red-700'
  if (status === 'draft') return 'bg-amber-50 text-amber-700'
  return 'bg-slate-100 text-slate-500'
}

const statusLabel = (status: string | null): string => {
  if (status === 'submitted') return 'Submitted'
  if (status === 'approved') return 'Approved'
  if (status === 'rejected') return 'Returned'
  if (status === 'draft') return 'Draft'
  return 'Not Started'
}

const detailStatus = computed(() => detail.value?.sheet?.submission_status ?? null)
const canActOnDetail = computed(() => detailStatus.value === 'submitted')

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const params: Record<string, string | number> = {}
    if (search.value) params.search = search.value
    if (statusFilter.value) params.status = statusFilter.value
    if (programFilter.value) params.program_id = programFilter.value
    const { data } = await api.get<{ students: CoordinatorInfoSheetRow[]; programs: typeof programs.value }>(
      '/api/coordinator/info-sheets',
      { params },
    )
    students.value = data.students
    programs.value = data.programs ?? []
  } catch {
    errorMessage.value = 'Unable to load student info sheets.'
  } finally {
    isLoading.value = false
  }
}

const viewSheet = async (row: CoordinatorInfoSheetRow) => {
  isDetailOpen.value = true
  isDetailLoading.value = true
  detail.value = null

  try {
    const { data } = await api.get<CoordinatorInfoSheetDetail>(`/api/coordinator/info-sheets/${row.student_id}`)
    detail.value = data
  } catch {
    errorMessage.value = 'Unable to load this info sheet.'
    isDetailOpen.value = false
  } finally {
    isDetailLoading.value = false
  }
}

const closeDetail = () => {
  isDetailOpen.value = false
  detail.value = null
}

const downloadPdf = () => {
  if (!detail.value?.student) return
  window.open(`/api/coordinator/info-sheets/${detail.value.student.id}/pdf`, '_blank')
}

/**
 * What Accept would create, from the server's own preview. A `blocker` is the
 * exact refusal Accept would answer with — shown before the click, instead of
 * as an error toast after the confirm dialog.
 */
const placement = computed(() => detail.value?.placement ?? null)
const acceptBlocked = computed(() => Boolean(placement.value?.blocker))

const accept = async () => {
  const student = detail.value?.student
  if (!student || acceptBlocked.value) return
  const where = placement.value?.batch && placement.value.company
    ? ` into ${placement.value.batch.name} at ${placement.value.company.name}`
    : ' into their batch'
  const confirmed = await confirmAction({
    title: 'Accept and enroll this student?',
    message: `Accept ${student.name}'s information sheet? This enrolls them${where} and gives them full access.`,
    confirmLabel: 'Accept & Enroll',
  })
  if (!confirmed) return

  isActing.value = true
  try {
    await api.post(`/api/coordinator/info-sheets/${student.id}/accept`)
    closeDetail()
    await load()
    showToast(`${student.name} accepted and enrolled.`)
  } catch (error) {
    const message = axios.isAxiosError(error) ? error.response?.data?.message : null
    showToast(message ?? 'Unable to accept this sheet.', 'error')
  } finally {
    isActing.value = false
  }
}

const reject = async () => {
  const student = detail.value?.student
  if (!student) return
  const reason = await promptAction({
    title: 'Return for changes',
    message: `Tell ${student.name} what needs fixing. They'll see this reason and can edit and resubmit.`,
    placeholder: 'Reason for returning this sheet…',
    confirmLabel: 'Return Sheet',
    required: true,
    requiredError: 'A reason is required to return the sheet.',
  })
  if (reason === null) return

  isActing.value = true
  try {
    await api.post(`/api/coordinator/info-sheets/${student.id}/reject`, { reason })
    closeDetail()
    await load()
    showToast(`${student.name}'s sheet returned for changes.`)
  } catch (error) {
    const message = axios.isAxiosError(error) ? error.response?.data?.message : null
    showToast(message ?? 'Unable to return this sheet.', 'error')
  } finally {
    isActing.value = false
  }
}

/**
 * The info sheet's fields in the order and wording of the student's own form
 * (and the printed sheet). The JSON columns come back in whatever order they
 * were written, so iterating them raw put "Company Id 10" and "Location Zoom"
 * in front of the coordinator. A key not listed here is still shown, after the
 * known ones, under its humanised key — nothing a student typed is dropped.
 */
const SECTION_FIELDS: { title: string; source: 'personal_info' | 'academic_info' | 'ojt_info'; fields: [string, string][] }[] = [
  {
    title: 'Student Trainee Information',
    source: 'personal_info',
    fields: [
      ['last_name', 'Family Name'],
      ['first_name', 'First Name'],
      ['middle_name', 'Middle Name'],
      ['sex', 'Sex'],
      ['date_of_birth', 'Date of Birth'],
      ['student_id_number', 'Student ID Number'],
      ['contact_number', 'Contact No.'],
      ['email', 'Email'],
      ['home_address', 'Home Address'],
      ['parent_guardian_name', "Parent's / Guardian's Name"],
      ['parent_guardian_contact', "Parent's / Guardian's Contact No."],
    ],
  },
  {
    title: 'Academic Information',
    source: 'academic_info',
    fields: [
      ['program_course', 'Program'],
      ['year_level', 'Year'],
      ['department', 'Department'],
      ['internship_coordinator', 'Internship Coordinator'],
      ['coordinator_contact_no', 'Coordinator Contact No.'],
    ],
  },
  {
    title: 'Internship Company Information',
    source: 'ojt_info',
    fields: [
      ['host_company', 'Name of Company'],
      ['company_address', 'Company Address'],
      ['company_signatory_moa', 'Company Signatory (MOA)'],
      ['office_designation', 'Office Designation / Position'],
      ['supervisor_name', 'Name of Supervisor / Office Head'],
      ['supervisor_contact', 'Supervisor Contact No.'],
      ['intern_duty_schedule', "Intern's Duty Schedule"],
      ['area_assigned', 'Area Assigned'],
      ['division_assigned', 'Division Assigned'],
      ['ojt_start_date', 'Start of Internship Duty'],
      ['ojt_end_date', 'Estimated Date to Finish Internship'],
    ],
  },
]

// Internal values with no meaning to a reader: the company is already named
// by host_company, and the pin is summarised as one "Company Location" row.
const HIDDEN_KEYS = new Set(['company_id', 'location_lat', 'location_lng', 'location_zoom', 'location_label'])

const labelize = (key: string): string => key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

const displayValue = (key: string, value: unknown): string => {
  if (value === null || value === undefined || value === '') return '—'
  const text = String(value)
  if (key === 'sex') return text.charAt(0).toUpperCase() + text.slice(1)
  if (key === 'year_level') return text.replace('-year', ' Year')
  return text
}

const sections = computed(() => {
  const sheet = detail.value?.sheet
  if (!sheet) return []
  return SECTION_FIELDS.map(({ title, source, fields }) => {
    const data = (sheet[source] ?? {}) as Record<string, unknown>
    const known = new Set(fields.map(([key]) => key))
    const rows = fields.map(([key, label]) => ({ key, label, value: displayValue(key, data[key]) }))
    for (const [key, value] of Object.entries(data)) {
      if (!known.has(key) && !HIDDEN_KEYS.has(key)) rows.push({ key, label: labelize(key), value: displayValue(key, value) })
    }
    if (source === 'ojt_info' && data.location_lat != null && data.location_lng != null) {
      rows.push({
        key: 'location',
        label: 'Company Location',
        value: data.location_label ? `Pinned: ${String(data.location_label)}` : `Pinned (${data.location_lat}, ${data.location_lng})`,
      })
    }
    return { title, rows }
  }).filter((section) => section.rows.some((row) => row.value !== '—'))
})

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Review submitted Student Information Sheets. <strong>Accept</strong> to enroll the student into their batch, or <strong>Return</strong> with a reason so they can fix and resubmit.
    </div>

    <div class="flex flex-wrap gap-3">
      <input
        v-model="search"
        class="w-full sm:w-auto sm:min-w-60 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
        placeholder="Search student..."
        @keyup.enter="load"
      />
      <select v-model="statusFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="load">
        <option value="">All statuses</option>
        <option value="submitted">Submitted</option>
        <option value="approved">Approved</option>
        <option value="rejected">Returned</option>
        <option value="draft">Draft</option>
      </select>
      <select v-model.number="programFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" @change="load">
        <option :value="null">All programs</option>
        <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.code ?? program.name }}</option>
      </select>
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="load">
        Search
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <template v-else>
      <!-- md and up: aligned table. -->
      <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 md:block">
        <table class="min-w-full divide-y divide-slate-200">
          <thead class="bg-slate-50">
            <tr>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Chosen Company</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Info Sheet</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="students.length === 0">
              <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="5">
                {{ hasFilters ? 'No sheets match these filters.' : 'No students in your programs yet.' }}
                <button
                  v-if="hasFilters"
                  type="button"
                  class="mt-2 block w-full text-sm font-semibold text-blue-600 transition hover:text-blue-700"
                  @click="clearFilters"
                >
                  Clear filters
                </button>
              </td>
            </tr>
            <tr v-for="row in students" :key="row.student_id">
              <td class="px-4 py-3">
                <p class="text-sm font-semibold text-slate-900">{{ row.name }}</p>
                <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ row.program || '—' }}</td>
              <td class="px-4 py-3 text-sm text-slate-700">{{ row.company || '—' }}</td>
              <td class="px-4 py-3">
                <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(row.submission_status)">{{ statusLabel(row.submission_status) }}</span>
              </td>
              <td class="px-4 py-3">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="viewSheet(row)">
                  Review
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Below md: one stacked card per student, so nothing scrolls sideways. -->
      <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden">
        <li v-if="students.length === 0" class="py-6 text-center text-sm text-slate-500">
          {{ hasFilters ? 'No sheets match these filters.' : 'No students in your programs yet.' }}
          <button
            v-if="hasFilters"
            type="button"
            class="mt-2 block w-full text-sm font-semibold text-blue-600 transition hover:text-blue-700"
            @click="clearFilters"
          >
            Clear filters
          </button>
        </li>
        <li v-for="row in students" :key="row.student_id" class="py-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-slate-900">{{ row.name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ row.student_id_number ?? '—' }}</p>
            </div>
            <span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(row.submission_status)">{{ statusLabel(row.submission_status) }}</span>
          </div>
          <p class="mt-1 truncate text-xs text-slate-500">{{ row.program || '—' }} · {{ row.company || '—' }}</p>
          <div class="mt-3">
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="viewSheet(row)">
              Review
            </button>
          </div>
        </li>
      </ul>
    </template>

    <!-- Detail / review modal -->
    <div v-if="isDetailOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">{{ detail?.student.name ?? 'Info Sheet' }}</h3>
            <span
              v-if="detailStatus"
              class="mt-1 inline-block rounded-full px-3 py-0.5 text-xs font-bold"
              :class="statusClass(detailStatus)"
            >{{ statusLabel(detailStatus) }}</span>
          </div>
          <div class="flex items-center gap-3">
            <button v-if="detail?.sheet" type="button" class="text-sm font-semibold text-blue-700 hover:text-blue-900" @click="downloadPdf">Download PDF</button>
            <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeDetail">Close</button>
          </div>
        </div>

        <p v-if="isDetailLoading" class="mt-5 text-sm text-slate-500">Loading...</p>
        <p v-else-if="!detail?.sheet" class="mt-5 rounded-md bg-slate-50 px-3 py-3 text-sm text-slate-500">
          This student has not started their information sheet yet.
        </p>

        <div v-else class="mt-5 space-y-5">
          <!-- What Accept will create — only while there is a decision to make. -->
          <div
            v-if="canActOnDetail && placement"
            class="rounded-md border px-4 py-3 text-sm"
            :class="acceptBlocked ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-slate-50'"
          >
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">On Accept</p>
            <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-[auto_1fr]">
              <dt class="text-slate-500">Batch</dt>
              <dd class="font-medium text-slate-900">
                {{ placement.batch ? [placement.batch.program, placement.batch.name].filter(Boolean).join(' · ') : '—' }}
              </dd>
              <dt class="text-slate-500">Company</dt>
              <dd class="font-medium text-slate-900">{{ placement.company?.name ?? '—' }}</dd>
              <dt class="text-slate-500">Supervisor</dt>
              <dd class="text-slate-900">
                <template v-if="placement.coordinator_centered">None. Coordinator-centered batch: you review the journals.</template>
                <template v-else-if="placement.supervisor">
                  {{ placement.supervisor.name }}
                  <span class="text-slate-500">({{ placement.supervisor.email || `@${placement.supervisor.username}` }})</span>
                </template>
                <template v-else>—</template>
              </dd>
            </dl>
            <p v-if="placement.blocker" class="mt-3 text-sm font-medium text-amber-900" role="alert">
              {{ placement.blocker }}
              <RouterLink
                v-if="placement.company && !placement.coordinator_centered && !placement.supervisor"
                to="/coordinator/companies"
                class="font-semibold underline underline-offset-2"
              >
                Open Partner Companies
              </RouterLink>
            </p>
          </div>

          <div v-if="detail.sheet.rejection_reason" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            Last returned with reason: {{ detail.sheet.rejection_reason }}
          </div>

          <div v-for="section in sections" :key="section.title">
            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ section.title }}</h4>
            <dl class="mt-2 grid gap-x-6 gap-y-2 md:grid-cols-2">
              <div v-for="row in section.rows" :key="row.key" class="border-b border-slate-100 pb-1">
                <dt class="text-xs text-slate-400">{{ row.label }}</dt>
                <dd class="text-sm text-slate-800">{{ row.value }}</dd>
              </div>
            </dl>
          </div>
        </div>

        <div v-if="canActOnDetail" class="mt-6 flex justify-end gap-3 border-t border-slate-100 pt-4">
          <button
            type="button"
            class="rounded-md border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isActing"
            @click="reject"
          >
            Return for Changes
          </button>
          <button
            type="button"
            class="rounded-md bg-green-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isActing || acceptBlocked"
            :title="acceptBlocked ? (placement?.blocker ?? undefined) : undefined"
            @click="accept"
          >
            {{ isActing ? 'Working...' : 'Accept & Enroll' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import api from '@/lib/axios'
import type { Department, InfoSheetDetail, PaginatedResponse, StudentInfoSheetSummary } from '@/types/api'

type SippStatus = 'submitted' | 'draft' | 'not-started'

const students = ref<StudentInfoSheetSummary[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const search = ref('')
const departmentFilter = ref('')
const statusFilter = ref('')

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedSheet = ref<InfoSheetDetail | null>(null)

let searchDebounce: ReturnType<typeof setTimeout> | undefined

const statusLabel: Record<SippStatus, string> = { submitted: 'Submitted', draft: 'Draft', 'not-started': 'Not Started' }
const statusClass: Record<SippStatus, string> = {
  submitted: 'bg-green-50 text-green-700',
  draft: 'bg-amber-50 text-amber-700',
  'not-started': 'bg-red-50 text-red-700',
}

const sippStatus = (status: StudentInfoSheetSummary['submission_status']): SippStatus =>
  status === 'submitted' || status === 'approved' ? 'submitted' : status === 'draft' ? 'draft' : 'not-started'

const loadDepartments = async () => {
  try {
    const response = await api.get<Department[]>('/api/admin/departments')
    departments.value = response.data
  } catch {
    // Filter dropdown just stays empty; not critical to the page loading.
  }
}

const loadStudents = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<StudentInfoSheetSummary>>('/api/admin/info-sheets', {
      params: {
        search: search.value || undefined,
        department_id: departmentFilter.value || undefined,
        status: statusFilter.value || undefined,
      },
    })
    students.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load students.'
  } finally {
    isLoading.value = false
  }
}

watch(search, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(loadStudents, 300)
})
watch([departmentFilter, statusFilter], loadStudents)

const openViewModal = async (student: StudentInfoSheetSummary) => {
  isViewOpen.value = true
  isViewLoading.value = true
  viewError.value = ''
  viewedSheet.value = null

  try {
    const response = await api.get<InfoSheetDetail>(`/api/admin/info-sheets/${student.id}`)
    viewedSheet.value = response.data
  } catch {
    viewError.value = 'Unable to load this student\'s SIPP.'
  } finally {
    isViewLoading.value = false
  }
}

const closeViewModal = () => {
  isViewOpen.value = false
  viewedSheet.value = null
}

onMounted(() => {
  loadDepartments()
  loadStudents()
})
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This list spans all departments.
    </div>

    <div class="flex flex-wrap gap-3">
      <input v-model="search" class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search student..." />
      <select v-model="departmentFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Departments</option>
        <option v-for="department in departments" :key="department.id" :value="department.id">
          {{ department.name }}
        </option>
      </select>
      <select v-model="statusFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Status</option>
        <option value="submitted">Submitted</option>
        <option value="draft">Draft</option>
        <option value="not-started">Not Started</option>
      </select>
      <button type="button" class="ml-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
        Export All
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Department</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">SIPP Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="students.length === 0">
            <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">No students found.</td>
          </tr>
          <tr v-for="student in students" :key="student.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ student.name }}</p>
              <p class="font-mono text-xs text-slate-400">#{{ student.id }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ student.program?.department?.name ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ student.program?.name ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ student.batch_enrollment?.company?.name ?? '—' }}</td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-3 py-1 text-xs font-bold"
                :class="statusClass[sippStatus(student.submission_status)]"
              >
                {{ statusLabel[sippStatus(student.submission_status)] }}
              </span>
            </td>
            <td class="px-4 py-3">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                @click="openViewModal(student)"
              >
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- View (read-only SIPP preview) modal -->
    <div v-if="isViewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Student Information Sheet</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <p v-if="isViewLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
        <p v-else-if="viewError" class="mt-6 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

        <div v-else-if="viewedSheet" class="mt-6 space-y-6">
          <div>
            <h4 class="text-xl font-bold text-slate-950">{{ viewedSheet.student.name }}</h4>
            <p class="text-sm text-slate-500">{{ viewedSheet.student.email }}</p>
            <span
              class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-bold"
              :class="statusClass[sippStatus(viewedSheet.submission_status)]"
            >
              {{ statusLabel[sippStatus(viewedSheet.submission_status)] }}
            </span>
          </div>

          <div class="rounded-lg bg-slate-50 p-4">
            <h5 class="text-xs font-bold uppercase tracking-wide text-blue-700">I. Personal Information</h5>
            <div class="mt-3 grid gap-x-6 gap-y-3 text-sm md:grid-cols-2">
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Last Name</span>{{ viewedSheet.personal_info?.last_name ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">First Name</span>{{ viewedSheet.personal_info?.first_name ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Middle Name</span>{{ viewedSheet.personal_info?.middle_name ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Student ID Number</span>{{ viewedSheet.personal_info?.student_id_number ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Date of Birth</span>{{ viewedSheet.personal_info?.date_of_birth ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Sex</span>{{ viewedSheet.personal_info?.sex ?? '—' }}</div>
              <div class="md:col-span-2"><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Home Address</span>{{ viewedSheet.personal_info?.home_address ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Contact Number</span>{{ viewedSheet.personal_info?.contact_number ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Email Address</span>{{ viewedSheet.personal_info?.email ?? '—' }}</div>
            </div>
          </div>

          <div class="rounded-lg bg-slate-50 p-4">
            <h5 class="text-xs font-bold uppercase tracking-wide text-blue-700">II. Academic Information</h5>
            <div class="mt-3 grid gap-x-6 gap-y-3 text-sm md:grid-cols-2">
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Program / Course</span>{{ viewedSheet.academic_info?.program_course ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Year Level</span>{{ viewedSheet.academic_info?.year_level ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Department</span>{{ viewedSheet.academic_info?.department ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">OJT Coordinator</span>{{ viewedSheet.academic_info?.internship_coordinator ?? '—' }}</div>
            </div>
          </div>

          <div class="rounded-lg bg-slate-50 p-4">
            <h5 class="text-xs font-bold uppercase tracking-wide text-blue-700">III. Internship Assignment</h5>
            <div class="mt-3 grid gap-x-6 gap-y-3 text-sm md:grid-cols-2">
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Host Company</span>{{ viewedSheet.ojt_info?.host_company ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Company Supervisor</span>{{ viewedSheet.ojt_info?.supervisor_name ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Area Assigned</span>{{ viewedSheet.ojt_info?.area_assigned ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Start Date</span>{{ viewedSheet.ojt_info?.ojt_start_date ?? '—' }}</div>
              <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">End Date</span>{{ viewedSheet.ojt_info?.ojt_end_date ?? '—' }}</div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

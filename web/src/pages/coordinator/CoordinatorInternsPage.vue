<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type {
  BatchStudentRecord,
  BatchStudentStatus,
  EnrollableStudent,
  EnrollmentOptions,
  RosterFilters,
} from '@/types/api'

const students = ref<BatchStudentRecord[]>([])
const filters = ref<RosterFilters>({ batches: [], statuses: [] })
const isLoading = ref(true)
const errorMessage = ref('')

const selectedBatchId = ref<number | null>(null)
const selectedStatus = ref<BatchStudentStatus | ''>('')

const isModalOpen = ref(false)
const isSaving = ref(false)
const modalErrors = ref<Record<string, string[]>>({})
const modalMessage = ref('')

const enrollableStudents = ref<EnrollableStudent[]>([])
const enrollmentOptions = ref<EnrollmentOptions>({ companies: [], supervisors: [] })

const enrollForm = reactive({
  batch_id: null as number | null,
  student_id: null as number | null,
  company_id: null as number | null,
  supervisor_id: null as number | null,
  assigned_division: '',
})

// Account creation — SEPARATE from enrollment. Creating a student/supervisor
// only makes their login; enrollment (above) still places them into a batch.
const successMessage = ref('')

const isAccountModalOpen = ref(false)
const isCreatingAccount = ref(false)
const accountErrors = ref<Record<string, string[]>>({})
const accountMessage = ref('')

const accountForm = reactive({
  role: 'student' as 'student' | 'supervisor',
  name: '',
  email: '',
  password: '',
  program_id: null as number | null,
  student_id_number: '',
})

const openAccountModal = async () => {
  accountForm.role = 'student'
  accountForm.name = ''
  accountForm.email = ''
  accountForm.password = ''
  accountForm.program_id = null
  accountForm.student_id_number = ''
  accountErrors.value = {}
  accountMessage.value = ''
  isAccountModalOpen.value = true
  await loadEnrollmentData()
}

const closeAccountModal = () => {
  isAccountModalOpen.value = false
}

const submitAccount = async () => {
  const label = accountForm.role === 'student' ? 'student' : 'supervisor'
  if (!window.confirm(`Create a new ${label} account for ${accountForm.name || 'this person'}?`)) return

  isCreatingAccount.value = true
  accountErrors.value = {}
  accountMessage.value = ''

  try {
    const payload: Record<string, unknown> = {
      role: accountForm.role,
      name: accountForm.name,
      email: accountForm.email,
      password: accountForm.password,
    }
    if (accountForm.role === 'student') {
      payload.program_id = accountForm.program_id
      if (accountForm.student_id_number) payload.student_id_number = accountForm.student_id_number
    }

    await api.post('/api/coordinator/accounts', payload)
    successMessage.value = `${label.charAt(0).toUpperCase() + label.slice(1)} account created for ${accountForm.name}.`
    closeAccountModal()
    if (accountForm.role === 'student') await loadEnrollmentData()
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      accountErrors.value = error.response.data.errors ?? {}
      accountMessage.value = error.response.data.message ?? 'Please fix the errors below.'
    } else {
      accountMessage.value = 'Unable to create the account.'
    }
  } finally {
    isCreatingAccount.value = false
  }
}

const loadRoster = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const params: Record<string, number | string> = {}
    if (selectedBatchId.value) params.batch_id = selectedBatchId.value
    if (selectedStatus.value) params.status = selectedStatus.value

    const { data } = await api.get<{ students: BatchStudentRecord[]; filters: RosterFilters }>('/api/coordinator/roster', { params })
    students.value = data.students
    filters.value = data.filters
  } catch {
    errorMessage.value = 'Unable to load your roster.'
  } finally {
    isLoading.value = false
  }
}

watch([selectedBatchId, selectedStatus], loadRoster)

const loadEnrollmentData = async () => {
  try {
    const [enrollableResponse, optionsResponse] = await Promise.all([
      api.get<EnrollableStudent[]>('/api/coordinator/students/enrollable'),
      api.get<EnrollmentOptions>('/api/coordinator/enrollment-options'),
    ])
    enrollableStudents.value = enrollableResponse.data
    enrollmentOptions.value = optionsResponse.data
  } catch {
    modalMessage.value = 'Unable to load enrollable students, companies, or supervisors.'
  }
}

const openEnrollModal = async () => {
  enrollForm.batch_id = filters.value.batches[0]?.id ?? null
  enrollForm.student_id = null
  enrollForm.company_id = null
  enrollForm.supervisor_id = null
  enrollForm.assigned_division = ''
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
  await loadEnrollmentData()
}

const closeModal = () => {
  isModalOpen.value = false
}

const submitEnrollment = async () => {
  isSaving.value = true
  modalErrors.value = {}
  modalMessage.value = ''

  try {
    await api.post('/api/coordinator/enrollments', enrollForm)
    await loadRoster()
    closeModal()
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      modalErrors.value = error.response.data.errors ?? {}
      modalMessage.value = error.response.data.message ?? 'Please fix the errors below.'
    } else if (axios.isAxiosError(error) && error.response?.status === 403) {
      modalMessage.value = 'You are not allowed to enroll into this batch.'
    } else {
      modalMessage.value = 'Unable to enroll this student.'
    }
  } finally {
    isSaving.value = false
  }
}

const updateStatus = async (record: BatchStudentRecord, status: BatchStudentStatus) => {
  errorMessage.value = ''

  try {
    await api.put(`/api/coordinator/enrollments/${record.id}`, { status })
    await loadRoster()
  } catch {
    errorMessage.value = 'Unable to update this student\'s status.'
  }
}

const statusBadgeClass = (status: BatchStudentStatus): string => {
  if (status === 'active') return 'bg-green-50 text-green-700'
  if (status === 'completed') return 'bg-blue-50 text-blue-700'
  return 'bg-slate-100 text-slate-500'
}

onMounted(loadRoster)
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
      <select v-model="selectedBatchId" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option :value="null">All Batches</option>
        <option v-for="batch in filters.batches" :key="batch.id" :value="batch.id">{{ batch.name }}</option>
      </select>
      <select v-model="selectedStatus" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Statuses</option>
        <option v-for="status in filters.statuses" :key="status" :value="status">{{ status }}</option>
      </select>
      <div class="ml-auto flex items-center gap-2">
        <button type="button" class="rounded-md border border-blue-600 bg-white px-4 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-50" @click="openAccountModal">
          + Create Account
        </button>
        <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700" @click="openEnrollModal">
          + Enroll Student
        </button>
      </div>
    </div>

    <p v-if="successMessage" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-700">{{ successMessage }}</p>
    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Batch</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Supervisor</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="students.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">No students match these filters.</td>
          </tr>
          <tr v-for="record in students" :key="record.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ record.student.name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ record.student.student_id_number ?? '—' }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ record.batch.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ record.company.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ record.supervisor.name }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-3 py-1 text-xs font-bold capitalize" :class="statusBadgeClass(record.status)">
                {{ record.status }}
              </span>
            </td>
            <td class="px-4 py-3">
              <select
                class="rounded-md border border-slate-300 px-2 py-1.5 text-xs"
                :value="record.status"
                @change="updateStatus(record, ($event.target as HTMLSelectElement).value as BatchStudentStatus)"
              >
                <option value="active">Active</option>
                <option value="completed">Completed</option>
                <option value="dropped">Dropped</option>
              </select>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Enroll Student</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="mt-5 space-y-4">
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="enroll-batch">Batch</label>
            <select id="enroll-batch" v-model.number="enrollForm.batch_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option :value="null">Select Batch</option>
              <option v-for="batch in filters.batches" :key="batch.id" :value="batch.id">{{ batch.name }}</option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="enroll-student">Student</label>
            <select id="enroll-student" v-model.number="enrollForm.student_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option :value="null">Select Student</option>
              <option v-for="student in enrollableStudents" :key="student.id" :value="student.id">
                {{ student.name }} ({{ student.student_id_number ?? student.email }})
              </option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="enroll-company">Company</label>
            <select id="enroll-company" v-model.number="enrollForm.company_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option :value="null">Select Company</option>
              <option v-for="company in enrollmentOptions.companies" :key="company.id" :value="company.id">{{ company.name }}</option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="enroll-supervisor">Supervisor</label>
            <select id="enroll-supervisor" v-model.number="enrollForm.supervisor_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option :value="null">Select Supervisor</option>
              <option v-for="supervisor in enrollmentOptions.supervisors" :key="supervisor.id" :value="supervisor.id">
                {{ supervisor.name }} ({{ supervisor.email }})
              </option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="enroll-division">Assigned Division (optional)</label>
            <input id="enroll-division" v-model="enrollForm.assigned_division" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
        </div>

        <div v-if="Object.keys(modalErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
          <p v-for="(messages, field) in modalErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
        </div>
        <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:bg-blue-300"
            :disabled="isSaving"
            @click="submitEnrollment"
          >
            {{ isSaving ? 'Enrolling...' : 'Enroll' }}
          </button>
        </div>
      </section>
    </div>

    <!-- Create Account modal — separate from enrollment (login only) -->
    <div v-if="isAccountModalOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">Create Account</h3>
            <p class="mt-0.5 text-xs text-slate-500">Creates a login only. Enroll the student separately to place them into a batch.</p>
          </div>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeAccountModal">Cancel</button>
        </div>

        <div class="mt-5 space-y-4">
          <div>
            <span class="mb-2 block text-sm font-medium text-slate-700">Account Type</span>
            <div class="flex gap-2">
              <label class="flex flex-1 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm" :class="accountForm.role === 'student' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 text-slate-600'">
                <input v-model="accountForm.role" type="radio" value="student" /> Student
              </label>
              <label class="flex flex-1 cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm" :class="accountForm.role === 'supervisor' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 text-slate-600'">
                <input v-model="accountForm.role" type="radio" value="supervisor" /> Supervisor
              </label>
            </div>
          </div>

          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-name">Full Name</label>
            <input id="acct-name" v-model="accountForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-email">Email</label>
            <input id="acct-email" v-model="accountForm.email" type="email" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-password">Password (min 8)</label>
            <input id="acct-password" v-model="accountForm.password" type="password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>

          <template v-if="accountForm.role === 'student'">
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-program">Program</label>
              <select id="acct-program" v-model.number="accountForm.program_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option :value="null">Select Program</option>
                <option v-for="program in enrollmentOptions.programs ?? []" :key="program.id" :value="program.id">
                  {{ program.code ?? program.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-sid">Student ID Number (optional)</label>
              <input id="acct-sid" v-model="accountForm.student_id_number" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </div>
          </template>
        </div>

        <div v-if="Object.keys(accountErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
          <p v-for="(messages, field) in accountErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
        </div>
        <p v-if="accountMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ accountMessage }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeAccountModal">Cancel</button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:bg-blue-300"
            :disabled="isCreatingAccount"
            @click="submitAccount"
          >
            {{ isCreatingAccount ? 'Creating...' : 'Create Account' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import type {
  CoordinatorCompany,
  CoordinatorInternUser,
  CoordinatorSupervisorUser,
  EnrollableStudent,
  EnrollmentOptions,
} from '@/types/api'

type UsersTab = 'interns' | 'supervisors'

const activeTab = ref<UsersTab>('interns')

const interns = ref<CoordinatorInternUser[]>([])
const supervisors = ref<CoordinatorSupervisorUser[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const enrolledCount = computed(() => interns.value.filter((student) => student.enrolled).length)
const notEnrolledCount = computed(() => interns.value.length - enrolledCount.value)

// --- Interns tab: program filter ---------------------------------------------
const programOptions = ref<{ id: number; name: string; code?: string }[]>([])
const internsProgramFilter = ref<number | null>(null)

const loadInterns = async () => {
  const params: Record<string, number> = {}
  if (internsProgramFilter.value) params.program_id = internsProgramFilter.value

  const { data } = await api.get<CoordinatorInternUser[]>('/api/coordinator/users/interns', { params })
  interns.value = data
}

watch(internsProgramFilter, () => {
  loadInterns().catch(() => {
    errorMessage.value = 'Unable to load interns for that program.'
  })
})

// --- Supervisors tab: company + batch filters (client-side) -----------------
const supervisorCompanyFilter = ref<number | null>(null)
const supervisorBatchFilter = ref<number | null>(null)

const loadSupervisors = async () => {
  const { data } = await api.get<CoordinatorSupervisorUser[]>('/api/coordinator/users/supervisors')
  supervisors.value = data
}

const companyFilterOptions = computed(() => {
  const seen = new Map<number, string>()
  supervisors.value.forEach((supervisor) => supervisor.companies.forEach((company) => seen.set(company.id, company.name)))
  return Array.from(seen, ([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
})

const batchFilterOptions = computed(() => {
  const seen = new Map<number, string>()
  supervisors.value.forEach((supervisor) => supervisor.batches.forEach((batch) => seen.set(batch.id, batch.name)))
  return Array.from(seen, ([id, name]) => ({ id, name })).sort((a, b) => a.name.localeCompare(b.name))
})

const filteredSupervisors = computed(() =>
  supervisors.value.filter((supervisor) => {
    const matchesCompany =
      !supervisorCompanyFilter.value || supervisor.companies.some((company) => company.id === supervisorCompanyFilter.value)
    const matchesBatch =
      !supervisorBatchFilter.value || supervisor.batches.some((batch) => batch.id === supervisorBatchFilter.value)
    return matchesCompany && matchesBatch
  }),
)

// --- Enroll (places a student into a batch) --------------------------------
const isModalOpen = ref(false)
const isSaving = ref(false)
const modalErrors = ref<Record<string, string[]>>({})
const modalMessage = ref('')

const enrollBatches = ref<{ id: number; name: string }[]>([])
const enrollableStudents = ref<EnrollableStudent[]>([])
const enrollmentOptions = ref<EnrollmentOptions>({ companies: [], supervisors: [] })

const enrollForm = reactive({
  batch_id: null as number | null,
  student_id: null as number | null,
  company_id: null as number | null,
  supervisor_id: null as number | null,
  assigned_division: '',
})

// Supervisor is always a Company Supervisor — the dropdown only lists
// supervisors attached to the currently selected company.
const enrollSupervisorOptions = computed(() =>
  enrollForm.company_id
    ? enrollmentOptions.value.supervisors.filter((supervisor) => supervisor.company_ids.includes(enrollForm.company_id as number))
    : [],
)

watch(
  () => enrollForm.company_id,
  () => {
    if (!enrollSupervisorOptions.value.some((supervisor) => supervisor.id === enrollForm.supervisor_id)) {
      enrollForm.supervisor_id = null
    }
  },
)

// --- Create Student Account (login only — SEPARATE from enrollment) ---------
const isAccountModalOpen = ref(false)
const isCreatingAccount = ref(false)
const accountErrors = ref<Record<string, string[]>>({})
const accountMessage = ref('')

const accountForm = reactive({
  name: '',
  username: '',
  password: '',
  program_id: null as number | null,
  batch_id: null as number | null,
  student_id_number: '',
})

// Intended batch is scoped to the pre-set program — the coordinator picks a
// program first, then a batch belonging to it.
const accountBatchOptions = computed(() =>
  accountForm.program_id
    ? (enrollmentOptions.value.batches ?? []).filter((batch) => batch.program_id === accountForm.program_id)
    : [],
)

watch(
  () => accountForm.program_id,
  () => {
    if (!accountBatchOptions.value.some((batch) => batch.id === accountForm.batch_id)) {
      accountForm.batch_id = null
    }
  },
)

// --- Create Supervisor (Supervisors tab only — REQUIRES a company) ----------
const isSupervisorModalOpen = ref(false)
const isCreatingSupervisor = ref(false)
const supervisorErrors = ref<Record<string, string[]>>({})
const supervisorMessage = ref('')
const scopedCompanies = ref<CoordinatorCompany[]>([])

const supervisorForm = reactive({
  company_id: null as number | null,
  position: '',
  name: '',
  email: '',
  password: '',
})

// A company may have at most one login-bearing supervisor (the "company
// account") — mirrors the guard enforced server-side in CoordinatorCompanyController.
const selectedCompanyHasLogin = computed(() => {
  const company = scopedCompanies.value.find((c) => c.id === supervisorForm.company_id)
  return (company?.supervisors ?? []).some((sup) => sup.is_login)
})

const canSubmitSupervisor = computed(
  () =>
    !!supervisorForm.company_id &&
    !selectedCompanyHasLogin.value &&
    supervisorForm.name.trim() !== '' &&
    supervisorForm.email.trim() !== '' &&
    supervisorForm.password.length >= 8,
)

const loadAll = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    await Promise.all([loadInterns(), loadSupervisors()])
  } catch {
    errorMessage.value = 'Unable to load users.'
  } finally {
    isLoading.value = false
  }
}

const loadProgramOptions = async () => {
  try {
    const { data } = await api.get<EnrollmentOptions>('/api/coordinator/enrollment-options')
    programOptions.value = data.programs ?? []
  } catch {
    // Non-fatal — the program filter just stays empty if this fails.
  }
}

const loadEnrollmentData = async () => {
  try {
    const [enrollableResponse, optionsResponse, rosterResponse] = await Promise.all([
      api.get<EnrollableStudent[]>('/api/coordinator/students/enrollable'),
      api.get<EnrollmentOptions>('/api/coordinator/enrollment-options'),
      // filters.batches = only batches this coordinator owns (valid to enrol into).
      api.get<{ filters: { batches: { id: number; name: string }[] } }>('/api/coordinator/roster'),
    ])
    enrollableStudents.value = enrollableResponse.data
    enrollmentOptions.value = optionsResponse.data
    enrollBatches.value = rosterResponse.data.filters.batches
  } catch {
    modalMessage.value = 'Unable to load enrollable students, companies, or supervisors.'
  }
}

const openEnrollModal = async () => {
  enrollForm.batch_id = null
  enrollForm.student_id = null
  enrollForm.company_id = null
  enrollForm.supervisor_id = null
  enrollForm.assigned_division = ''
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
  await loadEnrollmentData()
  enrollForm.batch_id = enrollBatches.value[0]?.id ?? null
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
    await loadInterns()
    closeModal()
    showToast('Student enrolled.')
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

const openAccountModal = async () => {
  accountForm.name = ''
  accountForm.username = ''
  accountForm.password = ''
  accountForm.program_id = null
  accountForm.batch_id = null
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
  isCreatingAccount.value = true
  accountErrors.value = {}
  accountMessage.value = ''

  try {
    await api.post('/api/coordinator/accounts', {
      role: 'student',
      name: accountForm.name,
      username: accountForm.username,
      password: accountForm.password,
      program_id: accountForm.program_id,
      batch_id: accountForm.batch_id,
      ...(accountForm.student_id_number ? { student_id_number: accountForm.student_id_number } : {}),
    })
    closeAccountModal()
    await loadInterns()
    showToast(`Student account created for ${accountForm.name}.`)
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

const loadScopedCompanies = async () => {
  try {
    const { data } = await api.get<CoordinatorCompany[]>('/api/coordinator/companies')
    scopedCompanies.value = data
  } catch {
    supervisorMessage.value = 'Unable to load your companies.'
  }
}

const openSupervisorModal = async () => {
  supervisorForm.company_id = null
  supervisorForm.position = ''
  supervisorForm.name = ''
  supervisorForm.email = ''
  supervisorForm.password = ''
  supervisorErrors.value = {}
  supervisorMessage.value = ''
  isSupervisorModalOpen.value = true
  await loadScopedCompanies()
}

const closeSupervisorModal = () => {
  isSupervisorModalOpen.value = false
}

const submitSupervisor = async () => {
  isCreatingSupervisor.value = true
  supervisorErrors.value = {}
  supervisorMessage.value = ''

  try {
    await api.post(`/api/coordinator/companies/${supervisorForm.company_id}/supervisors/new`, {
      name: supervisorForm.name,
      email: supervisorForm.email,
      password: supervisorForm.password,
      position: supervisorForm.position || null,
    })
    closeSupervisorModal()
    await loadSupervisors()
    showToast(`Supervisor account created for ${supervisorForm.name}.`)
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      supervisorErrors.value = error.response.data.errors ?? {}
      supervisorMessage.value = error.response.data.message ?? 'Please fix the errors below.'
    } else if (axios.isAxiosError(error) && error.response?.status === 403) {
      supervisorMessage.value = 'You do not have access to that company.'
    } else {
      supervisorMessage.value = 'Unable to create the supervisor.'
    }
  } finally {
    isCreatingSupervisor.value = false
  }
}

onMounted(() => {
  loadAll()
  loadProgramOptions()
})
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold text-slate-950">Users</h2>
        <p class="mt-1 text-sm text-slate-500">Interns and supervisors across your department's programs.</p>
      </div>
      <!-- Header actions are tab-contextual: only what belongs to the active tab. -->
      <div v-if="activeTab === 'interns'" class="flex items-center gap-2">
        <button type="button" class="rounded-md border border-blue-600 bg-white px-4 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-50" @click="openAccountModal">
          + Create Student Account
        </button>
        <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700" @click="openEnrollModal">
          + Enroll Student
        </button>
      </div>
      <div v-else class="flex items-center gap-2">
        <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700" @click="openSupervisorModal">
          + Create Supervisor
        </button>
      </div>
    </div>

    <!-- Secondary nav -->
    <div class="flex gap-1 border-b border-slate-200">
      <button
        type="button"
        class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition"
        :class="activeTab === 'interns' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="activeTab = 'interns'"
      >
        Interns
        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ interns.length }}</span>
      </button>
      <button
        type="button"
        class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition"
        :class="activeTab === 'supervisors' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="activeTab = 'supervisors'"
      >
        Supervisors
        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ supervisors.length }}</span>
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <!-- Interns tab -->
    <template v-else-if="activeTab === 'interns'">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-slate-500">
          <span class="font-semibold text-green-700">{{ enrolledCount }}</span> enrolled ·
          <span class="font-semibold text-amber-700">{{ notEnrolledCount }}</span> not yet enrolled
        </p>
        <select v-model.number="internsProgramFilter" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm">
          <option :value="null">All Programs</option>
          <option v-for="program in programOptions" :key="program.id" :value="program.id">{{ program.code ?? program.name }}</option>
        </select>
      </div>
      <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full divide-y divide-slate-200">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Enrollment</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Batch</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Supervisor</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="interns.length === 0">
              <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">No students match this filter.</td>
            </tr>
            <tr v-for="student in interns" :key="student.id">
              <td class="px-4 py-3">
                <p class="text-sm font-semibold text-slate-900">{{ student.name }}</p>
                <p class="font-mono text-xs text-slate-400">{{ student.student_id_number ?? '—' }}</p>
              </td>
              <td class="px-4 py-3 text-sm text-slate-700">{{ student.program?.code ?? student.program?.name ?? '—' }}</td>
              <td class="px-4 py-3">
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="student.enrolled ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'"
                >
                  {{ student.enrolled ? 'ENROLLED' : 'NOT ENROLLED' }}
                </span>
              </td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ student.enrollment?.batch?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ student.enrollment?.company?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ student.enrollment?.supervisor?.name ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Supervisors tab -->
    <template v-else>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-xs text-slate-500">Showing {{ filteredSupervisors.length }} of {{ supervisors.length }} supervisors</p>
        <div class="flex items-center gap-2">
          <select v-model.number="supervisorCompanyFilter" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm">
            <option :value="null">All Companies</option>
            <option v-for="company in companyFilterOptions" :key="company.id" :value="company.id">{{ company.name }}</option>
          </select>
          <select v-model.number="supervisorBatchFilter" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm">
            <option :value="null">All Batches</option>
            <option v-for="batch in batchFilterOptions" :key="batch.id" :value="batch.id">{{ batch.name }}</option>
          </select>
        </div>
      </div>
      <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
        <table class="min-w-full divide-y divide-slate-200">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Supervisor</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Email</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Companies</th>
              <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Batches</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="filteredSupervisors.length === 0">
              <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="5">No supervisors match these filters.</td>
            </tr>
            <tr v-for="supervisor in filteredSupervisors" :key="supervisor.id">
              <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ supervisor.name }}</td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ supervisor.email }}</td>
              <td class="px-4 py-3">
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="supervisor.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                >
                  {{ supervisor.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div v-if="supervisor.companies.length" class="flex flex-wrap gap-1.5">
                  <span
                    v-for="company in supervisor.companies"
                    :key="company.id"
                    class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700"
                    :title="company.position ?? ''"
                  >
                    {{ company.name }}<span v-if="company.position" class="text-slate-400"> · {{ company.position }}</span>
                  </span>
                </div>
                <span v-else class="text-sm text-slate-400">—</span>
              </td>
              <td class="px-4 py-3">
                <div v-if="supervisor.batches.length" class="flex flex-wrap gap-1.5">
                  <span v-for="batch in supervisor.batches" :key="batch.id" class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700">
                    {{ batch.name }}
                  </span>
                </div>
                <span v-else class="text-sm text-slate-400">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Enroll modal -->
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
              <option v-for="batch in enrollBatches" :key="batch.id" :value="batch.id">{{ batch.name }}</option>
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
            <select
              id="enroll-supervisor"
              v-model.number="enrollForm.supervisor_id"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400"
              :disabled="!enrollForm.company_id"
            >
              <option :value="null">Select Supervisor</option>
              <option v-for="supervisor in enrollSupervisorOptions" :key="supervisor.id" :value="supervisor.id">
                {{ supervisor.name }} ({{ supervisor.email }})
              </option>
            </select>
            <p v-if="!enrollForm.company_id" class="mt-1 text-xs text-slate-500">Select a company first.</p>
            <p v-else-if="enrollSupervisorOptions.length === 0" class="mt-1 text-xs text-amber-600">This company has no supervisors yet.</p>
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
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">Cancel</button>
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

    <!-- Create Student Account modal — login only, separate from enrollment -->
    <div v-if="isAccountModalOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">Create Student Account</h3>
            <p class="mt-0.5 text-xs text-slate-500">Creates a login with a pre-set program + intended batch. The student completes their Info Sheet, then you Accept it to enroll them.</p>
          </div>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeAccountModal">Cancel</button>
        </div>

        <div class="mt-5 space-y-4">
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-name">Full Name</label>
            <input id="acct-name" v-model="accountForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-username">Username</label>
            <input id="acct-username" v-model="accountForm.username" type="text" autocomplete="off" placeholder="e.g. 2021-IT-001" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            <p class="mt-1 text-xs text-slate-500">The student signs in with this. Letters, numbers, dots, dashes and underscores.</p>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-password">Password (min 8)</label>
            <input id="acct-password" v-model="accountForm.password" type="password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
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
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-batch">Batch</label>
            <select
              id="acct-batch"
              v-model.number="accountForm.batch_id"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400"
              :disabled="!accountForm.program_id"
            >
              <option :value="null">Select Batch</option>
              <option v-for="batch in accountBatchOptions" :key="batch.id" :value="batch.id">{{ batch.name }}</option>
            </select>
            <p v-if="!accountForm.program_id" class="mt-1 text-xs text-slate-500">Select a program first.</p>
            <p v-else-if="accountBatchOptions.length === 0" class="mt-1 text-xs text-amber-600">You have no batches for this program yet.</p>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="acct-sid">Student ID Number (optional)</label>
            <input id="acct-sid" v-model="accountForm.student_id_number" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
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

    <!-- Create Supervisor modal — Supervisors tab only, REQUIRES a company (a supervisor is a Company Supervisor) -->
    <div v-if="isSupervisorModalOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">Create Supervisor</h3>
            <p class="mt-0.5 text-xs text-slate-500">Every supervisor is a Company Supervisor — pick the company first.</p>
          </div>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeSupervisorModal">Cancel</button>
        </div>

        <div class="mt-5 space-y-4">
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="sup-company">Company <span class="text-red-500">*</span></label>
            <select id="sup-company" v-model.number="supervisorForm.company_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option :value="null">Select Company</option>
              <option v-for="company in scopedCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
            </select>
            <p v-if="selectedCompanyHasLogin" class="mt-1 text-xs text-slate-500">
              This company already has a supervisor login — detach it on the Partner Companies page before creating a different one.
            </p>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="sup-position">Position (optional)</label>
            <input id="sup-position" v-model="supervisorForm.position" type="text" placeholder="e.g. Operations Supervisor" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="sup-name">Full Name</label>
            <input id="sup-name" v-model="supervisorForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="sup-email">Email</label>
            <input id="sup-email" v-model="supervisorForm.email" type="email" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="sup-password">Password (min 8)</label>
            <input id="sup-password" v-model="supervisorForm.password" type="password" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
        </div>

        <div v-if="Object.keys(supervisorErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
          <p v-for="(messages, field) in supervisorErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
        </div>
        <p v-if="supervisorMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ supervisorMessage }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeSupervisorModal">Cancel</button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:bg-blue-300"
            :disabled="isCreatingSupervisor || !canSubmitSupervisor"
            @click="submitSupervisor"
          >
            {{ isCreatingSupervisor ? 'Creating...' : 'Create Supervisor' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>

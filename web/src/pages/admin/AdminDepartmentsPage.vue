<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { Department, DepartmentDetail, PaginatedResponse, User } from '@/types/api'

type DepartmentForm = {
  code: string
  name: string
  dean_name: string
  is_active: boolean
}

type ProgramForm = {
  code: string
  name: string
  is_active: boolean
}

const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedDepartment = ref<DepartmentDetail | null>(null)

const coordinatorOptions = ref<User[]>([])
const coordinatorToAssign = ref('')
const isAssigningCoordinator = ref(false)
const removingCoordinatorId = ref<number | null>(null)
const coordinatorError = ref('')

const isModalOpen = ref(false)
const editingDepartmentId = ref<number | null>(null)
const isSaving = ref(false)
const modalError = ref('')
const departmentForm = ref<DepartmentForm>({ code: '', name: '', dean_name: '', is_active: true })

// Adding a program is done from inside the department it belongs to, so the
// department is context rather than another field to pick. The Programs page
// carries the same action with a department picker, for the times an admin is
// already there.
const isProgramModalOpen = ref(false)
const isSavingProgram = ref(false)
const programModalError = ref('')
const programForm = ref<ProgramForm>({ code: '', name: '', is_active: true })

const loadDepartments = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<Department[]>('/api/admin/departments')
    departments.value = response.data
  } catch {
    errorMessage.value = 'Unable to load departments.'
  } finally {
    isLoading.value = false
  }
}

const loadCoordinatorOptions = async () => {
  try {
    const response = await api.get<PaginatedResponse<User>>('/api/admin/users', {
      params: { role: 'coordinator' },
    })
    coordinatorOptions.value = response.data.data
  } catch {
    // Assign picker just stays empty; not critical to the page loading.
  }
}

const openViewModal = async (department: Department) => {
  isViewOpen.value = true
  isViewLoading.value = true
  viewError.value = ''
  coordinatorError.value = ''
  coordinatorToAssign.value = ''
  viewedDepartment.value = null

  try {
    const response = await api.get<DepartmentDetail>(`/api/admin/departments/${department.id}`)
    viewedDepartment.value = response.data
  } catch {
    viewError.value = 'Unable to load department details.'
  } finally {
    isViewLoading.value = false
  }
}

const closeViewModal = () => {
  isViewOpen.value = false
  viewedDepartment.value = null
}

const assignCoordinator = async () => {
  if (!viewedDepartment.value || !coordinatorToAssign.value) return

  isAssigningCoordinator.value = true
  coordinatorError.value = ''

  try {
    const response = await api.post<DepartmentDetail['coordinators']>(
      `/api/admin/departments/${viewedDepartment.value.id}/coordinators`,
      { user_id: coordinatorToAssign.value },
    )
    viewedDepartment.value.coordinators = response.data
    coordinatorToAssign.value = ''
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    coordinatorError.value = data?.message ?? 'Unable to assign coordinator.'
  } finally {
    isAssigningCoordinator.value = false
  }
}

const removeCoordinator = async (coordinatorId: number, coordinatorName: string) => {
  if (!viewedDepartment.value) return
  const confirmed = await confirmAction({
    title: 'Remove this coordinator?',
    message: `Remove ${coordinatorName} as coordinator of "${viewedDepartment.value.name}"? They will lose access to this department's batches, interns, and reports.`,
    confirmLabel: 'Remove Coordinator',
    tone: 'danger',
  })
  if (!confirmed) return

  removingCoordinatorId.value = coordinatorId
  coordinatorError.value = ''

  try {
    const response = await api.delete<DepartmentDetail['coordinators']>(
      `/api/admin/departments/${viewedDepartment.value.id}/coordinators/${coordinatorId}`,
    )
    viewedDepartment.value.coordinators = response.data
    showToast(`${coordinatorName} removed as coordinator.`)
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    coordinatorError.value = data?.message ?? 'Unable to remove coordinator.'
  } finally {
    removingCoordinatorId.value = null
  }
}

const openProgramModal = () => {
  programForm.value = { code: '', name: '', is_active: true }
  programModalError.value = ''
  isProgramModalOpen.value = true
}

const closeProgramModal = () => {
  isProgramModalOpen.value = false
}

const saveProgram = async () => {
  if (!viewedDepartment.value) return

  isSavingProgram.value = true
  programModalError.value = ''

  const departmentId = viewedDepartment.value.id
  const code = programForm.value.code

  try {
    await api.post('/api/admin/programs', {
      department_id: departmentId,
      code,
      name: programForm.value.name,
      is_active: programForm.value.is_active,
    })
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    const firstFieldError = data?.errors ? Object.values(data.errors as Record<string, string[]>)[0]?.[0] : null
    programModalError.value = firstFieldError ?? data?.message ?? 'Unable to add program. Please check the fields and try again.'
    isSavingProgram.value = false

    return
  }

  // THE CREATE IS COMMITTED FROM HERE ON, so nothing below may report a failure
  // to create. Refreshing used to sit in the same `try` as the POST, and a blip
  // on the re-fetch then told the admin "Unable to add program — check the
  // fields and try again" for a program that HAD been created; the retry answered
  // "this department already has a program with that code", which is advice that
  // cannot be followed. Same shape as the bulk import's stranded-row bug.
  isProgramModalOpen.value = false
  showToast(`Program ${code} added.`)
  isSavingProgram.value = false

  try {
    // Re-fetch the detail rather than pushing the new row in by hand: the
    // Programs table here carries per-program intern tallies the create response
    // has no way to know, and the header's Programs count moves with it.
    const response = await api.get<DepartmentDetail>(`/api/admin/departments/${departmentId}`)
    viewedDepartment.value = response.data

    // The list behind the modal shows `programs_count`, which just changed.
    await loadDepartments()
  } catch {
    // The program exists; only this view is stale. Say so plainly rather than
    // implying the save failed.
    showToast('Program added, but the list could not be refreshed. Reopen the department to see it.', 'error')
  }
}

const resetForm = () => {
  departmentForm.value = { code: '', name: '', dean_name: '', is_active: true }
  modalError.value = ''
}

const openCreateModal = () => {
  editingDepartmentId.value = null
  resetForm()
  isModalOpen.value = true
}

const openEditModal = (department: Department) => {
  editingDepartmentId.value = department.id
  departmentForm.value = {
    code: department.code,
    name: department.name,
    dean_name: department.dean_name ?? '',
    is_active: department.is_active,
  }
  modalError.value = ''
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
  resetForm()
}

const saveDepartment = async () => {
  isSaving.value = true
  modalError.value = ''

  try {
    if (editingDepartmentId.value) {
      await api.put(`/api/admin/departments/${editingDepartmentId.value}`, {
        name: departmentForm.value.name,
        dean_name: departmentForm.value.dean_name || null,
        is_active: departmentForm.value.is_active,
      })
    } else {
      await api.post('/api/admin/departments', {
        code: departmentForm.value.code,
        name: departmentForm.value.name,
        dean_name: departmentForm.value.dean_name || null,
      })
    }
    closeModal()
    await loadDepartments()
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    modalError.value = data?.message ?? 'Unable to save department. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}

onMounted(() => {
  loadDepartments()
  loadCoordinatorOptions()
})
</script>

<template>
  <section class="space-y-5">
    <ToastHost />
    <div class="flex flex-wrap items-center justify-end gap-4">
      <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700" @click="openCreateModal">+ Add Department</button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <template v-else>
      <!--
        md and up: aligned table, the same shape every other list page in the app
        uses. Widths live in the colgroup and are enforced by `table-fixed`, so a
        long department or dean name can never squeeze the Actions cell into
        wrapping onto two lines.
      -->
      <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 md:block">
        <table class="w-full table-fixed divide-y divide-slate-200">
          <colgroup>
            <col class="w-32" />
            <col />
            <col class="w-56" />
            <col class="w-28" />
            <col class="w-28" />
            <col class="w-44" />
          </colgroup>
          <thead class="bg-slate-50">
            <tr>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Code</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Dean</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Programs</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
              <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="departments.length === 0">
              <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">No departments found.</td>
            </tr>
            <tr v-for="department in departments" :key="department.id">
              <td class="px-4 py-3">
                <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-blue-700">
                  {{ department.code }}
                </span>
              </td>
              <!--
                Department names are full sentences ("College of Accountancy,
                Business and Management – Business Department"), so this column
                genuinely truncates at common widths. Both `max-w-full`s are
                load-bearing: TooltipWrap's root is an inline-flex, which sizes
                to its CONTENT, so without them `truncate` has no constrained
                width to measure against and the text paints over the next
                column instead of ellipsing. See the Gotchas section.
              -->
              <td class="px-4 py-3 text-sm font-semibold text-slate-900">
                <TooltipWrap :label="department.name" placement="top" class="max-w-full">
                  <span class="block max-w-full truncate">{{ department.name }}</span>
                </TooltipWrap>
              </td>
              <td class="truncate px-4 py-3 text-sm text-slate-500">{{ department.dean_name || '—' }}</td>
              <td class="px-4 py-3 text-sm tabular-nums text-slate-700">{{ department.programs_count ?? 0 }}</td>
              <td class="px-4 py-3">
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="department.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                >
                  {{ department.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                  <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="openViewModal(department)">View</button>
                  <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditModal(department)">Edit</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Below md: one stacked block per department, so nothing scrolls sideways. -->
      <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden">
        <li v-if="departments.length === 0" class="py-6 text-center text-sm text-slate-500">No departments found.</li>
        <li v-for="department in departments" :key="department.id" class="py-4">
          <!--
            Code as its own chip with the name on the line below, NOT the old
            single truncated "CODE · Name" line. Real department names run to
            ~70 characters, so on a phone that line truncated inside the code
            and the name — the thing being identified — was never visible at
            all. It also read as duplication back when both fields held the
            same string.
          -->
          <div class="flex items-start justify-between gap-3">
            <span class="inline-flex shrink-0 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-blue-700">
              {{ department.code }}
            </span>
            <span
              class="shrink-0 rounded-full px-3 py-1 text-xs font-bold"
              :class="department.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ department.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <p class="mt-1.5 text-sm font-semibold wrap-break-word text-slate-900">{{ department.name }}</p>
          <p class="mt-1 truncate text-xs text-slate-500">
            Dean: {{ department.dean_name || '—' }} · {{ department.programs_count ?? 0 }} program{{ (department.programs_count ?? 0) === 1 ? '' : 's' }}
          </p>
          <div class="mt-3 flex items-center gap-2">
            <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="openViewModal(department)">View</button>
            <button type="button" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="openEditModal(department)">Edit</button>
          </div>
        </li>
      </ul>
    </template>

    <!-- Create / Edit modal: three-part flex shell, body is the only scroller. -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <section class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingDepartmentId ? 'Edit Department' : 'Add Department' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
          <!--
            The helpers below say what each field is FOR, not what format it
            takes (the server rule on Code is `required|string|max:20|unique`,
            with no format constraint). They exist because the two fields were
            genuinely indistinguishable until 2026-09-08: every seeded
            department had `name` set to the same string as `code`, so the list
            showed "CABM-B / CABM-B" and nothing on this form said why you would
            ever type two different things. Code is also the identifier every
            lookup in the project keys off, which is why it cannot be edited
            after creation — hence the `v-if` on it.
          -->
          <div v-if="!editingDepartmentId">
            <label class="mb-1.5 block text-xs font-bold text-slate-600" for="department-code">Code</label>
            <input id="department-code" v-model="departmentForm.code" type="text" placeholder="CABM-B" class="h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
            <p class="mt-1.5 text-xs text-slate-500">
              Short identifier used across the system and on printed forms. Cannot be changed later.
            </p>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-bold text-slate-600" for="department-name">Name</label>
            <input
              id="department-name"
              v-model="departmentForm.name"
              type="text"
              placeholder="College of Accountancy, Business and Management – Business Department"
              class="h-10 w-full rounded-md border border-slate-300 px-3 text-sm"
            />
            <p class="mt-1.5 text-xs text-slate-500">
              The full department name, written out as it should read to a person. Do not repeat the code here.
            </p>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-bold text-slate-600" for="department-dean-name">Dean's Name (optional)</label>
            <input id="department-dean-name" v-model="departmentForm.dean_name" type="text" class="h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
          </div>
          <div v-if="editingDepartmentId">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="departmentForm.is_active" type="checkbox" />
              Active
            </label>
          </div>

          <p v-if="modalError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalError }}</p>
        </div>

        <div class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="isSaving" @click="saveDepartment">
            {{ isSaving ? 'Saving...' : editingDepartmentId ? 'Save Department' : 'Add Department' }}
          </button>
        </div>
      </section>
    </div>

    <!-- View (read-only preview) modal -->
    <div v-if="isViewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <!-- Three-part flex shell: the body is the only scrolling element. -->
      <section class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">Department Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <p v-if="isViewLoading" class="text-sm text-slate-500">Loading...</p>
          <p v-else-if="viewError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

          <div v-else-if="viewedDepartment" class="space-y-6">
            <div>
              <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-blue-700">
                {{ viewedDepartment.code }}
              </span>
              <h4 class="mt-2 text-xl font-bold text-slate-950">{{ viewedDepartment.name }}</h4>
            </div>

            <div class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Dean</span>
                {{ viewedDepartment.dean_name ?? '—' }}
              </div>
              <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Programs</span>
                {{ viewedDepartment.programs.length }}
              </div>
              <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Active Interns (Dept-wide)</span>
                {{ viewedDepartment.active_interns_count }}
              </div>
            </div>

            <div>
              <div class="flex flex-wrap items-center justify-between gap-2">
                <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Programs</h5>
                <button
                  type="button"
                  class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                  @click="openProgramModal"
                >
                  + Add Program
                </button>
              </div>
              <template v-if="viewedDepartment.programs.length > 0">
                <!-- md and up: aligned table. -->
                <div class="mt-2 hidden overflow-x-auto rounded-lg ring-1 ring-slate-200 md:block">
                  <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                      <tr>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Code</th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Active Interns</th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Total (All-time)</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                      <tr v-for="program in viewedDepartment.programs" :key="program.id">
                        <td class="px-3 py-2 text-sm font-medium text-slate-900">{{ program.code ?? '—' }}</td>
                        <td class="px-3 py-2 text-sm text-slate-700">{{ program.name }}</td>
                        <td class="px-3 py-2">
                          <span
                            class="rounded-full px-2 py-0.5 text-xs font-bold"
                            :class="program.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                          >
                            {{ program.is_active ? 'Active' : 'Inactive' }}
                          </span>
                        </td>
                        <td class="px-3 py-2 font-mono text-sm font-bold text-slate-800">{{ program.active_interns_count }}</td>
                        <td class="px-3 py-2 font-mono text-sm text-slate-500">{{ program.total_interns_count }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <!-- Below md: one stacked card per program. -->
                <ul class="mt-2 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200 md:hidden">
                  <li v-for="program in viewedDepartment.programs" :key="program.id" class="px-3 py-2.5">
                    <div class="flex items-center justify-between gap-3">
                      <p class="text-sm font-medium text-slate-900">{{ program.code ?? '—' }} · {{ program.name }}</p>
                      <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold"
                        :class="program.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                      >
                        {{ program.is_active ? 'Active' : 'Inactive' }}
                      </span>
                    </div>
                    <p class="mt-1 font-mono text-xs text-slate-500">
                      {{ program.active_interns_count }} active · {{ program.total_interns_count }} all-time
                    </p>
                  </li>
                </ul>
              </template>
              <p v-else class="mt-2 text-sm text-slate-400">No programs under this department yet.</p>
            </div>

            <div>
              <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Students</h5>
              <template v-if="viewedDepartment.students.length > 0">
                <!-- md and up: aligned table. -->
                <div class="mt-2 hidden overflow-x-auto rounded-lg ring-1 ring-slate-200 md:block">
                  <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                      <tr>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Email</th>
                        <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                      <tr v-for="student in viewedDepartment.students" :key="student.id">
                        <td class="px-3 py-2 text-sm font-medium text-slate-900">{{ student.name }}</td>
                        <td class="px-3 py-2 text-sm text-slate-700">{{ student.email }}</td>
                        <td class="px-3 py-2 text-sm text-slate-700">{{ student.program?.name ?? '—' }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <!-- Below md: one stacked card per student. -->
                <ul class="mt-2 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200 md:hidden">
                  <li v-for="student in viewedDepartment.students" :key="student.id" class="px-3 py-2.5">
                    <p class="text-sm font-medium text-slate-900">{{ student.name }}</p>
                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ student.email }} · {{ student.program?.name ?? '—' }}</p>
                  </li>
                </ul>
              </template>
              <p v-else class="mt-2 text-sm text-slate-400">No students assigned yet.</p>
            </div>

            <div>
              <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Partner Companies</h5>
              <div v-if="viewedDepartment.companies.length > 0" class="mt-2 flex flex-wrap gap-2">
                <span
                  v-for="company in viewedDepartment.companies"
                  :key="company.id"
                  class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
                >
                  {{ company.name }}
                </span>
              </div>
              <p v-else class="mt-2 text-sm text-slate-400">No partner companies yet.</p>
            </div>

            <div>
              <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Assigned Coordinators</h5>

              <div v-if="viewedDepartment.coordinators.length > 0" class="mt-2 space-y-2">
                <div
                  v-for="coordinator in viewedDepartment.coordinators"
                  :key="coordinator.id"
                  class="flex items-center justify-between rounded-md px-3 py-2 ring-1 ring-slate-200"
                >
                  <div>
                    <p class="text-sm font-semibold text-slate-900">{{ coordinator.name }}</p>
                    <p class="text-xs text-slate-500">{{ coordinator.email }}</p>
                  </div>
                  <button
                    type="button"
                    class="text-sm font-semibold text-red-600 disabled:text-slate-400"
                    :disabled="removingCoordinatorId === coordinator.id"
                    @click="removeCoordinator(coordinator.id, coordinator.name)"
                  >
                    {{ removingCoordinatorId === coordinator.id ? 'Removing...' : 'Remove' }}
                  </button>
                </div>
              </div>
              <p v-else class="mt-2 text-sm text-slate-400">No coordinators assigned yet.</p>

              <div class="mt-3 flex gap-2">
                <select v-model="coordinatorToAssign" class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm">
                  <option value="">Select a coordinator...</option>
                  <option
                    v-for="coordinator in coordinatorOptions"
                    :key="coordinator.id"
                    :value="coordinator.id"
                  >
                    {{ coordinator.name }} ({{ coordinator.email }})
                  </option>
                </select>
                <button
                  type="button"
                  class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                  :disabled="!coordinatorToAssign || isAssigningCoordinator"
                  @click="assignCoordinator"
                >
                  {{ isAssigningCoordinator ? 'Assigning...' : 'Assign' }}
                </button>
              </div>

              <p v-if="coordinatorError" class="mt-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ coordinatorError }}</p>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!--
      Add Program, opened from inside the department detail above — hence `z-60`
      rather than the app's usual `z-50`, so it layers over the modal that opened
      it instead of rendering behind it.
    -->
    <div v-if="isProgramModalOpen" class="fixed inset-0 z-60 flex items-center justify-center bg-slate-950/50 p-4">
      <!-- Three-part flex shell: the body is the only scrolling element. -->
      <section class="flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <div class="min-w-0">
            <h3 class="text-lg font-semibold text-slate-950">Add Program</h3>
            <p class="truncate text-xs text-slate-500">{{ viewedDepartment?.name }}</p>
          </div>
          <button type="button" class="shrink-0 text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeProgramModal">Close</button>
        </div>

        <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveProgram">
          <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
            <p v-if="programModalError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ programModalError }}</p>

            <div>
              <label for="dept-program-code" class="mb-1 block text-xs font-bold text-slate-600">Code</label>
              <input
                id="dept-program-code"
                v-model="programForm.code"
                type="text"
                required
                maxlength="20"
                placeholder="BSIT"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
              />
              <p class="mt-1 text-xs text-slate-500">Short identifier printed on the SIPP forms. Unique within this department.</p>
            </div>

            <div>
              <label for="dept-program-name" class="mb-1 block text-xs font-bold text-slate-600">Name</label>
              <input
                id="dept-program-name"
                v-model="programForm.name"
                type="text"
                required
                maxlength="200"
                placeholder="BS Information Technology"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
              />
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input v-model="programForm.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300" />
              Active
            </label>
          </div>

          <div class="flex shrink-0 items-center justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="closeProgramModal"
            >
              Cancel
            </button>
            <button
              type="submit"
              :disabled="isSavingProgram"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {{ isSavingProgram ? 'Saving...' : 'Add Program' }}
            </button>
          </div>
        </form>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { Department, DepartmentDetail, PaginatedResponse, User } from '@/types/api'

type DepartmentForm = {
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
const departmentForm = ref<DepartmentForm>({ code: '', name: '', is_active: true })

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

const removeCoordinator = async (coordinatorId: number) => {
  if (!viewedDepartment.value) return

  removingCoordinatorId.value = coordinatorId
  coordinatorError.value = ''

  try {
    const response = await api.delete<DepartmentDetail['coordinators']>(
      `/api/admin/departments/${viewedDepartment.value.id}/coordinators/${coordinatorId}`,
    )
    viewedDepartment.value.coordinators = response.data
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    coordinatorError.value = data?.message ?? 'Unable to remove coordinator.'
  } finally {
    removingCoordinatorId.value = null
  }
}

const resetForm = () => {
  departmentForm.value = { code: '', name: '', is_active: true }
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
        is_active: departmentForm.value.is_active,
      })
    } else {
      await api.post('/api/admin/departments', {
        code: departmentForm.value.code,
        name: departmentForm.value.name,
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
    <div class="flex items-center justify-between gap-4">
      <h2 class="text-2xl font-bold text-slate-950">Departments</h2>
      <button
        type="button"
        class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
        @click="openCreateModal"
      >
        + Add Department
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else-if="departments.length === 0" class="rounded-lg bg-white px-4 py-6 text-center text-sm text-slate-500 shadow-sm ring-1 ring-slate-200">
      No departments found.
    </div>

    <div v-else class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <div
        v-for="department in departments"
        :key="department.id"
        class="flex flex-col justify-between rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200"
      >
        <div>
          <div class="flex items-center justify-between gap-2">
            <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-blue-700">
              {{ department.code }}
            </span>
            <span
              class="rounded-full px-2 py-0.5 text-xs font-bold"
              :class="department.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ department.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <h3 class="mt-3 text-base font-bold text-slate-950">{{ department.name }}</h3>
          <p class="mt-2 text-sm text-slate-500">
            {{ department.programs_count ?? 0 }} program{{ (department.programs_count ?? 0) === 1 ? '' : 's' }}
          </p>
        </div>

        <div class="mt-4 flex gap-2">
          <button
            type="button"
            class="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
            @click="openViewModal(department)"
          >
            View
          </button>
          <button
            type="button"
            class="flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
            @click="openEditModal(department)"
          >
            Edit
          </button>
        </div>
      </div>
    </div>

    <!-- Create / Edit modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingDepartmentId ? 'Edit Department' : 'Add Department' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="mt-6 space-y-4">
          <div v-if="!editingDepartmentId">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="department-code">Code</label>
            <input id="department-code" v-model="departmentForm.code" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="department-name">Name</label>
            <input id="department-name" v-model="departmentForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div v-if="editingDepartmentId">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="departmentForm.is_active" type="checkbox" />
              Active
            </label>
          </div>
        </div>

        <p v-if="modalError" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalError }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400"
            :disabled="isSaving"
            @click="saveDepartment"
          >
            {{ isSaving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </section>
    </div>

    <!-- View (read-only preview) modal -->
    <div v-if="isViewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Department Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <p v-if="isViewLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
        <p v-else-if="viewError" class="mt-6 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

        <div v-else-if="viewedDepartment" class="mt-6 space-y-6">
          <div>
            <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-blue-700">
              {{ viewedDepartment.code }}
            </span>
            <h4 class="mt-2 text-xl font-bold text-slate-950">{{ viewedDepartment.name }}</h4>
          </div>

          <div class="grid gap-x-6 gap-y-3 text-sm md:grid-cols-2">
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
            <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Programs</h5>
            <div v-if="viewedDepartment.programs.length > 0" class="mt-2 overflow-hidden rounded-lg ring-1 ring-slate-200">
              <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                  <tr>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Code</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Active Interns</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Total (All-time)</th>
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
            <p v-else class="mt-2 text-sm text-slate-400">No programs under this department yet.</p>
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
                  @click="removeCoordinator(coordinator.id)"
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
                class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400"
                :disabled="!coordinatorToAssign || isAssigningCoordinator"
                @click="assignCoordinator"
              >
                {{ isAssigningCoordinator ? 'Assigning...' : 'Assign' }}
              </button>
            </div>

            <p v-if="coordinatorError" class="mt-2 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ coordinatorError }}</p>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

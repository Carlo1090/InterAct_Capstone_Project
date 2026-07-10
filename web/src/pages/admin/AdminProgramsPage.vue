<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { Department, Program } from '@/types/api'

type ProgramForm = {
  department_id: number | null
  code: string
  name: string
  is_active: boolean
}

const programs = ref<Program[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const departmentFilter = ref('')

const isModalOpen = ref(false)
const editingProgramId = ref<number | null>(null)
const isSaving = ref(false)
const modalError = ref('')

const emptyForm = (): ProgramForm => ({ department_id: null, code: '', name: '', is_active: true })
const programForm = ref<ProgramForm>(emptyForm())

const filteredPrograms = computed(() => {
  if (!departmentFilter.value) return programs.value

  return programs.value.filter((program) => program.department?.id === Number(departmentFilter.value))
})

const loadPrograms = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<Program[]>('/api/admin/programs')
    programs.value = response.data
  } catch {
    errorMessage.value = 'Unable to load programs.'
  } finally {
    isLoading.value = false
  }
}

const loadDepartments = async () => {
  try {
    const response = await api.get<Department[]>('/api/admin/departments')
    departments.value = response.data
  } catch {
    // Filter/create dropdowns just stay empty; not critical to the page loading.
  }
}

const resetForm = () => {
  programForm.value = emptyForm()
  modalError.value = ''
}

const openCreateModal = () => {
  editingProgramId.value = null
  resetForm()
  isModalOpen.value = true
}

const openEditModal = (program: Program) => {
  editingProgramId.value = program.id
  programForm.value = {
    department_id: program.department?.id ?? null,
    code: program.code ?? '',
    name: program.name,
    is_active: program.is_active,
  }
  modalError.value = ''
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
  resetForm()
}

const saveProgram = async () => {
  isSaving.value = true
  modalError.value = ''

  try {
    if (editingProgramId.value) {
      await api.put(`/api/admin/programs/${editingProgramId.value}`, {
        name: programForm.value.name,
        code: programForm.value.code,
        is_active: programForm.value.is_active,
      })
    } else {
      await api.post('/api/admin/programs', {
        department_id: programForm.value.department_id,
        code: programForm.value.code,
        name: programForm.value.name,
      })
    }
    closeModal()
    await loadPrograms()
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    modalError.value = data?.message ?? 'Unable to save program. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}

onMounted(() => {
  loadPrograms()
  loadDepartments()
})
</script>

<template>
  <section class="space-y-5">
    <div class="flex items-center justify-between gap-4">
      <h2 class="text-2xl font-bold text-slate-950">Programs</h2>
      <button
        type="button"
        class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
        @click="openCreateModal"
      >
        + Add Program
      </button>
    </div>

    <div class="flex flex-wrap gap-3">
      <select v-model="departmentFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Departments</option>
        <option v-for="department in departments" :key="department.id" :value="department.id">
          {{ department.name }}
        </option>
      </select>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Code</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Department</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="filteredPrograms.length === 0">
            <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No programs found.</td>
          </tr>
          <tr v-for="program in filteredPrograms" :key="program.id">
            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ program.code ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ program.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ program.department?.name ?? '—' }}</td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-3 py-1 text-xs font-bold"
                :class="program.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ program.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                @click="openEditModal(program)"
              >
                Edit
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create / Edit modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingProgramId ? 'Edit Program' : 'Add Program' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="mt-6 space-y-4">
          <div v-if="!editingProgramId">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="program-department">Department</label>
            <select id="program-department" v-model="programForm.department_id" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option :value="null">Select Department</option>
              <option v-for="department in departments" :key="department.id" :value="department.id">
                {{ department.name }}
              </option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="program-code">Code</label>
            <input id="program-code" v-model="programForm.code" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="program-name">Name</label>
            <input id="program-name" v-model="programForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div v-if="editingProgramId">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="programForm.is_active" type="checkbox" />
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
            @click="saveProgram"
          >
            {{ isSaving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { Department, Program } from '@/types/api'

type ProgramForm = {
  department_id: string
  code: string
  name: string
  is_active: boolean
}

const programs = ref<Program[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const departmentFilter = ref('')

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedProgram = ref<Program | null>(null)

const isModalOpen = ref(false)
const editingProgramId = ref<number | null>(null)
const isSaving = ref(false)
const modalError = ref('')
const programForm = ref<ProgramForm>({ department_id: '', code: '', name: '', is_active: true })

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
    // Filter dropdown just stays empty; not critical to the page loading.
  }
}

const openViewModal = async (program: Program) => {
  isViewOpen.value = true
  isViewLoading.value = true
  viewError.value = ''
  viewedProgram.value = null

  try {
    const response = await api.get<Program>(`/api/admin/programs/${program.id}`)
    viewedProgram.value = response.data
  } catch {
    viewError.value = 'Unable to load program details.'
  } finally {
    isViewLoading.value = false
  }
}

const closeViewModal = () => {
  isViewOpen.value = false
  viewedProgram.value = null
}

const resetForm = () => {
  programForm.value = { department_id: '', code: '', name: '', is_active: true }
  modalError.value = ''
}

const openCreateModal = () => {
  editingProgramId.value = null
  resetForm()
  // Creating from the Programs page while a department filter is applied almost
  // always means "another one of these", so the filter seeds the picker. It stays
  // editable — this is a default, not a lock.
  programForm.value.department_id = departmentFilter.value
  isModalOpen.value = true
}

const openEditModal = (program: Program) => {
  editingProgramId.value = program.id
  programForm.value = {
    department_id: String(program.department?.id ?? ''),
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
      // `department_id` is deliberately not sent: the API refuses to re-parent a
      // program, since that would hand every batch and intern under it to another
      // department's coordinators in one silent write.
      await api.put(`/api/admin/programs/${editingProgramId.value}`, {
        code: programForm.value.code,
        name: programForm.value.name,
        is_active: programForm.value.is_active,
      })
      showToast('Program updated.')
    } else {
      await api.post('/api/admin/programs', {
        department_id: programForm.value.department_id,
        code: programForm.value.code,
        name: programForm.value.name,
        is_active: programForm.value.is_active,
      })
      showToast('Program created.')
    }
    closeModal()
    // Departments reload too: their rows carry `programs_count`, which a create moves.
    await Promise.all([loadPrograms(), loadDepartments()])
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    const firstFieldError = data?.errors ? Object.values(data.errors as Record<string, string[]>)[0]?.[0] : null
    modalError.value = firstFieldError ?? data?.message ?? 'Unable to save program. Please check the fields and try again.'
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
    <ToastHost />

    <div class="flex flex-wrap items-center justify-between gap-4">
      <!--
        Department names are full sentences, so this select is as wide as its
        longest OPTION whatever is currently chosen. `w-full max-w-full` with
        `sm:w-auto` keeps it inside a 390px viewport; see the Gotchas section.
      -->
      <select v-model="departmentFilter" class="w-full min-w-0 max-w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm sm:w-auto">
        <option value="">All Departments</option>
        <option v-for="department in departments" :key="department.id" :value="department.id">
          {{ department.name }}
        </option>
      </select>

      <button
        type="button"
        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
        @click="openCreateModal"
      >
        + Add Program
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <template v-else>
      <!-- md and up: aligned table. -->
      <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 md:block">
        <table class="w-full table-fixed divide-y divide-slate-200">
          <colgroup>
            <col class="w-[120px]" />
            <col />
            <col class="w-[200px]" />
            <col class="w-[110px]" />
            <!--
              Sized to the real buttons, not the heading: View (~59px) + gap (8px)
              + Edit (~54px) = 121px, plus the cell's own 32px of px-4. A pinned
              column narrower than its content spills into the next one under
              `table-fixed`.
            -->
            <col class="w-[165px]" />
          </colgroup>
          <thead class="bg-slate-50">
            <tr>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Code</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Department</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
              <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="filteredPrograms.length === 0">
              <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">No programs found.</td>
            </tr>
            <tr v-for="program in filteredPrograms" :key="program.id">
              <td class="truncate px-4 py-3 text-sm font-semibold text-slate-900">{{ program.code ?? '—' }}</td>
              <td class="px-4 py-3 text-sm text-slate-700">
                <TooltipWrap :label="program.name" placement="top" class="max-w-full">
                  <span class="block max-w-full truncate">{{ program.name }}</span>
                </TooltipWrap>
              </td>
              <td class="px-4 py-3 text-sm text-slate-700">
                <TooltipWrap :label="program.department?.name ?? '—'" placement="top" class="max-w-full">
                  <span class="block max-w-full truncate">{{ program.department?.name ?? '—' }}</span>
                </TooltipWrap>
              </td>
              <td class="px-4 py-3">
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="program.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                >
                  {{ program.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    @click="openViewModal(program)"
                  >
                    View
                  </button>
                  <button
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                    @click="openEditModal(program)"
                  >
                    Edit
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Below md: one stacked card per program, so nothing scrolls sideways. -->
      <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden">
        <li v-if="filteredPrograms.length === 0" class="py-6 text-center text-sm text-slate-500">No programs found.</li>
        <li v-for="program in filteredPrograms" :key="program.id" class="py-4">
          <div class="flex items-start justify-between gap-3">
            <p class="min-w-0 truncate text-sm font-semibold text-slate-900">{{ program.code ?? '—' }} · {{ program.name }}</p>
            <span
              class="shrink-0 rounded-full px-3 py-1 text-xs font-bold"
              :class="program.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ program.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <p class="mt-1 truncate text-xs text-slate-500">{{ program.department?.name ?? '—' }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="openViewModal(program)"
            >
              View
            </button>
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="openEditModal(program)"
            >
              Edit
            </button>
          </div>
        </li>
      </ul>
    </template>

    <!-- Create / Edit modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <!-- Three-part flex shell: the body is the only scrolling element. -->
      <section class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingProgramId ? 'Edit Program' : 'Add Program' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Close</button>
        </div>

        <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="saveProgram">
          <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
            <p v-if="modalError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalError }}</p>

            <div>
              <label for="program-department" class="mb-1 block text-xs font-bold text-slate-600">Department</label>
              <select
                id="program-department"
                v-model="programForm.department_id"
                :disabled="editingProgramId !== null"
                required
                class="w-full max-w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-500"
              >
                <option value="" disabled>Select a department</option>
                <option v-for="department in departments" :key="department.id" :value="String(department.id)">
                  {{ department.name }}
                </option>
              </select>
              <p v-if="editingProgramId" class="mt-1 text-xs text-slate-500">
                A program cannot be moved to another department — that would reassign every batch and intern under it.
              </p>
            </div>

            <div>
              <label for="program-code" class="mb-1 block text-xs font-bold text-slate-600">Code</label>
              <input
                id="program-code"
                v-model="programForm.code"
                type="text"
                required
                maxlength="20"
                placeholder="BSIT"
                class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
              />
              <p class="mt-1 text-xs text-slate-500">Short identifier printed on the SIPP forms. Unique within its department.</p>
            </div>

            <div>
              <label for="program-name" class="mb-1 block text-xs font-bold text-slate-600">Name</label>
              <input
                id="program-name"
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
              @click="closeModal"
            >
              Cancel
            </button>
            <button
              type="submit"
              :disabled="isSaving"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {{ isSaving ? 'Saving...' : editingProgramId ? 'Save Changes' : 'Create Program' }}
            </button>
          </div>
        </form>
      </section>
    </div>

    <!-- View (read-only preview) modal -->
    <div v-if="isViewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <!-- Three-part flex shell: the body is the only scrolling element. -->
      <section class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">Program Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <p v-if="isViewLoading" class="text-sm text-slate-500">Loading...</p>
          <p v-else-if="viewError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

          <div v-else-if="viewedProgram" class="space-y-3 text-sm">
            <div>
              <span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Code</span>
              {{ viewedProgram.code ?? '—' }}
            </div>
            <div>
              <span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Name</span>
              {{ viewedProgram.name }}
            </div>
            <div>
              <span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Department</span>
              {{ viewedProgram.department?.name ?? '—' }}
            </div>
            <div>
              <span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Status</span>
              <span
                class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-bold"
                :class="viewedProgram.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ viewedProgram.is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

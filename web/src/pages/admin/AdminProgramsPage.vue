<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { Department, Program } from '@/types/api'

const programs = ref<Program[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const departmentFilter = ref('')

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedProgram = ref<Program | null>(null)

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

onMounted(() => {
  loadPrograms()
  loadDepartments()
})
</script>

<template>
  <section class="space-y-5">
    <h2 class="text-2xl font-bold text-slate-950">Programs</h2>

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

    <div v-else class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
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
                @click="openViewModal(program)"
              >
                View
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- View (read-only preview) modal -->
    <div v-if="isViewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Program Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <p v-if="isViewLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
        <p v-else-if="viewError" class="mt-6 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

        <div v-else-if="viewedProgram" class="mt-6 space-y-3 text-sm">
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
      </section>
    </div>
  </section>
</template>

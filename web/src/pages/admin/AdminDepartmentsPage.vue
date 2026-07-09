<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { Department, DepartmentDetail } from '@/types/api'

const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedDepartment = ref<DepartmentDetail | null>(null)

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

const openViewModal = async (department: Department) => {
  isViewOpen.value = true
  isViewLoading.value = true
  viewError.value = ''
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

onMounted(loadDepartments)
</script>

<template>
  <section class="space-y-5">
    <h2 class="text-2xl font-bold text-slate-950">Departments</h2>

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
          <span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wide text-blue-700">
            {{ department.code }}
          </span>
          <h3 class="mt-3 text-base font-bold text-slate-950">{{ department.name }}</h3>
          <p class="mt-2 text-sm text-slate-500">
            {{ department.programs_count ?? 0 }} program{{ (department.programs_count ?? 0) === 1 ? '' : 's' }}
          </p>
        </div>

        <button
          type="button"
          class="mt-4 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
          @click="openViewModal(department)"
        >
          View
        </button>
      </div>
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
        </div>
      </section>
    </div>
  </section>
</template>

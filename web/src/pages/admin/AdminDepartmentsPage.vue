<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { Department } from '@/types/api'

const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

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

onMounted(loadDepartments)
</script>

<template>
  <section>
    <h2 class="text-2xl font-bold text-slate-950">Departments</h2>

    <p v-if="isLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="mt-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>
    <div v-else class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Code</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Programs</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="departments.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="3">No departments found.</td>
          </tr>
          <tr v-for="department in departments" :key="department.id">
            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ department.code }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ department.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ department.programs_count ?? 0 }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

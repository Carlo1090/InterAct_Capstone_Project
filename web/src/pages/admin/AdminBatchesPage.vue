<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import api from '@/lib/axios'
import type { Batch, BatchDetail, Department, PaginatedResponse } from '@/types/api'

const batches = ref<Batch[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const departmentFilter = ref('')

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedBatch = ref<BatchDetail | null>(null)

const loadBatches = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<Batch>>('/api/admin/batches', {
      params: { department_id: departmentFilter.value || undefined },
    })
    batches.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load batches.'
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

watch(departmentFilter, loadBatches)

const openViewModal = async (batch: Batch) => {
  isViewOpen.value = true
  isViewLoading.value = true
  viewError.value = ''
  viewedBatch.value = null

  try {
    const response = await api.get<BatchDetail>(`/api/admin/batches/${batch.id}`)
    viewedBatch.value = response.data
  } catch {
    viewError.value = 'Unable to load batch details.'
  } finally {
    isViewLoading.value = false
  }
}

const closeViewModal = () => {
  isViewOpen.value = false
  viewedBatch.value = null
}

onMounted(() => {
  loadBatches()
  loadDepartments()
})
</script>

<template>
  <section>
    <h2 class="text-2xl font-bold text-slate-950">Batches</h2>

    <div class="mt-6 flex flex-wrap gap-3">
      <select v-model="departmentFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Departments</option>
        <option v-for="department in departments" :key="department.id" :value="department.id">
          {{ department.name }}
        </option>
      </select>
    </div>

    <p v-if="isLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="mt-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>

    <div v-else class="mt-6 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Batch Name</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Department</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Coordinator</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Start Date</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">End Date</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="batches.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="8">No batches found.</td>
          </tr>
          <tr v-for="batch in batches" :key="batch.id">
            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ batch.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.program?.name ?? 'No Program' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.program?.department?.name ?? 'No Department' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.coordinator?.name ?? 'No Coordinator' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.start_date }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.end_date }}</td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-3 py-1 text-xs font-bold"
                :class="batch.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ batch.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                @click="openViewModal(batch)"
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
      <section class="w-full max-w-3xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Batch Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <p v-if="isViewLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
        <p v-else-if="viewError" class="mt-6 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

        <div v-else-if="viewedBatch" class="mt-6 max-h-[70vh] space-y-6 overflow-y-auto pr-1">
          <div>
            <h4 class="text-xl font-bold text-slate-950">{{ viewedBatch.name }}</h4>
            <span
              class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-bold"
              :class="viewedBatch.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ viewedBatch.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>

          <div class="grid gap-x-6 gap-y-3 text-sm md:grid-cols-2">
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Program</span>{{ viewedBatch.program?.name ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Department</span>{{ viewedBatch.program?.department?.name ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Coordinator</span>{{ viewedBatch.coordinator?.name ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Academic Year / Semester</span>{{ viewedBatch.academic_year ?? '—' }} / {{ viewedBatch.semester ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Start Date</span>{{ viewedBatch.start_date }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">End Date</span>{{ viewedBatch.end_date }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Required Hours</span>{{ viewedBatch.required_hours }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Working Days / Week</span>{{ viewedBatch.working_days_per_week }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Daily Reminder Time</span>{{ viewedBatch.daily_reminder_time }}</div>
          </div>

          <div>
            <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Enrolled Students ({{ viewedBatch.batch_students.length }})</h5>
            <div v-if="viewedBatch.batch_students.length > 0" class="mt-2 overflow-x-auto rounded-lg ring-1 ring-slate-200">
              <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                  <tr>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Supervisor</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Enrolled</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="record in viewedBatch.batch_students" :key="record.id">
                    <td class="px-3 py-2 text-sm text-slate-900">
                      {{ record.student.name }}
                      <span class="block text-xs text-slate-400">{{ record.student.student_id_number ?? '—' }}</span>
                    </td>
                    <td class="px-3 py-2 text-sm text-slate-700">{{ record.company?.name ?? '—' }}</td>
                    <td class="px-3 py-2 text-sm text-slate-700">{{ record.supervisor?.name ?? '—' }}</td>
                    <td class="px-3 py-2">
                      <span
                        class="rounded-full px-2 py-0.5 text-xs font-bold capitalize"
                        :class="{
                          'bg-green-50 text-green-700': record.status === 'active',
                          'bg-blue-50 text-blue-700': record.status === 'completed',
                          'bg-red-50 text-red-700': record.status === 'dropped',
                        }"
                      >
                        {{ record.status }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-sm text-slate-500">{{ record.enrolled_at }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="mt-2 text-sm text-slate-400">No students enrolled yet.</p>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

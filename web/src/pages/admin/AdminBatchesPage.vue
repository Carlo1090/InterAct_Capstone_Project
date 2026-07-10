<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { Batch, BatchDetail, PaginatedResponse, Program, User } from '@/types/api'

type BatchPayload = {
  name: string
  program_id: number | null
  coordinator_id: number | null
  start_date: string
  end_date: string
  required_hours: number
  working_days_per_week: number
  daily_reminder_time: string
}

type EditBatchPayload = {
  name: string
  end_date: string
  coordinator_id: number | null
  is_active: boolean
}

const batches = ref<Batch[]>([])
const programs = ref<Program[]>([])
const coordinators = ref<User[]>([])
const coordinatorsLoaded = ref(false)
const isLoading = ref(true)
const isSaving = ref(false)
const isModalOpen = ref(false)
const errorMessage = ref('')
const modalError = ref('')

const batchForm = ref<BatchPayload>({
  name: '',
  program_id: null,
  coordinator_id: null,
  start_date: '',
  end_date: '',
  required_hours: 486,
  working_days_per_week: 5,
  daily_reminder_time: '08:00',
})

const isEditModalOpen = ref(false)
const editingBatchId = ref<number | null>(null)
const isEditSaving = ref(false)
const editModalError = ref('')
const editForm = ref<EditBatchPayload>({ name: '', end_date: '', coordinator_id: null, is_active: true })

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedBatch = ref<BatchDetail | null>(null)

const groupedPrograms = computed(() => {
  return programs.value.reduce<Record<string, Program[]>>((groups, program) => {
    const departmentName = program.department?.name ?? 'No Department'
    groups[departmentName] = groups[departmentName] ?? []
    groups[departmentName].push(program)
    return groups
  }, {})
})

const resetForm = () => {
  batchForm.value = {
    name: '',
    program_id: null,
    coordinator_id: null,
    start_date: '',
    end_date: '',
    required_hours: 486,
    working_days_per_week: 5,
    daily_reminder_time: '08:00',
  }
  modalError.value = ''
}

const loadBatches = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<Batch>>('/api/admin/batches')
    batches.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load batches.'
  } finally {
    isLoading.value = false
  }
}

const loadModalData = async () => {
  try {
    const [programResponse, coordinatorResponse] = await Promise.all([
      api.get<Program[]>('/api/admin/programs'),
      api.get<PaginatedResponse<User>>('/api/admin/users?role=coordinator'),
    ])
    programs.value = programResponse.data
    coordinators.value = coordinatorResponse.data.data
    coordinatorsLoaded.value = true
  } catch {
    modalError.value = 'Unable to load programs or coordinators.'
  }
}

const ensureCoordinatorsLoaded = async () => {
  if (coordinatorsLoaded.value) return

  try {
    const response = await api.get<PaginatedResponse<User>>('/api/admin/users?role=coordinator')
    coordinators.value = response.data.data
    coordinatorsLoaded.value = true
  } catch {
    editModalError.value = 'Unable to load coordinators.'
  }
}

const openModal = async () => {
  resetForm()
  isModalOpen.value = true
  await loadModalData()
}

const closeModal = () => {
  isModalOpen.value = false
  resetForm()
}

const createBatch = async () => {
  isSaving.value = true
  modalError.value = ''

  try {
    await api.post('/api/admin/batches', batchForm.value)
    closeModal()
    await loadBatches()
  } catch {
    modalError.value = 'Unable to create batch. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}

const openEditModal = async (batch: Batch) => {
  editingBatchId.value = batch.id
  editForm.value = {
    name: batch.name,
    end_date: batch.end_date,
    coordinator_id: batch.coordinator?.id ?? null,
    is_active: batch.is_active ?? true,
  }
  editModalError.value = ''
  isEditModalOpen.value = true
  await ensureCoordinatorsLoaded()
}

const closeEditModal = () => {
  isEditModalOpen.value = false
  editingBatchId.value = null
  editModalError.value = ''
}

const saveEdit = async () => {
  if (!editingBatchId.value) return

  isEditSaving.value = true
  editModalError.value = ''

  try {
    await api.put(`/api/admin/batches/${editingBatchId.value}`, editForm.value)
    closeEditModal()
    await loadBatches()
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    editModalError.value = data?.message ?? 'Unable to update batch. Please check the fields and try again.'
  } finally {
    isEditSaving.value = false
  }
}

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

onMounted(loadBatches)
</script>

<template>
  <section>
    <div class="flex items-center justify-between gap-4">
      <h2 class="text-2xl font-bold text-slate-950">Batches</h2>
      <button
        type="button"
        class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
        @click="openModal"
      >
        Create Batch
      </button>
    </div>

    <p v-if="isLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="mt-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>

    <div v-else class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
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
              <div class="flex gap-2">
                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                  @click="openViewModal(batch)"
                >
                  View
                </button>
                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                  @click="openEditModal(batch)"
                >
                  Edit
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-3xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Create Batch</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">
            Cancel
          </button>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-name">Batch Name</label>
            <input id="batch-name" v-model="batchForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-coordinator">Coordinator</label>
            <select id="batch-coordinator" v-model="batchForm.coordinator_id" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option :value="null">Select Coordinator</option>
              <option v-for="coordinator in coordinators" :key="coordinator.id" :value="coordinator.id">
                {{ coordinator.name }}
              </option>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-program">Program</label>
            <select id="batch-program" v-model="batchForm.program_id" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option :value="null">Select Program</option>
              <optgroup v-for="(departmentPrograms, departmentName) in groupedPrograms" :key="departmentName" :label="departmentName">
                <option v-for="program in departmentPrograms" :key="program.id" :value="program.id">
                  {{ program.name }}
                </option>
              </optgroup>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-start">Start Date</label>
            <input id="batch-start" v-model="batchForm.start_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-end">End Date</label>
            <input id="batch-end" v-model="batchForm.end_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-hours">Required Hours</label>
            <input id="batch-hours" v-model.number="batchForm.required_hours" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2" min="1" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-days">Working Days Per Week</label>
            <input id="batch-days" v-model.number="batchForm.working_days_per_week" type="number" class="w-full rounded-md border border-slate-300 px-3 py-2" min="1" max="7" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-reminder">Daily Reminder Time</label>
            <input id="batch-reminder" v-model="batchForm.daily_reminder_time" type="time" class="w-full rounded-md border border-slate-300 px-3 py-2" />
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
            @click="createBatch"
          >
            {{ isSaving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </section>
    </div>

    <!-- Edit modal -->
    <div v-if="isEditModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Edit Batch</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeEditModal">
            Cancel
          </button>
        </div>

        <div class="mt-6 space-y-4">
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="edit-batch-name">Batch Name</label>
            <input id="edit-batch-name" v-model="editForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="edit-batch-end">End Date</label>
            <input id="edit-batch-end" v-model="editForm.end_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="edit-batch-coordinator">Coordinator</label>
            <select id="edit-batch-coordinator" v-model="editForm.coordinator_id" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option :value="null">Select Coordinator</option>
              <option v-for="coordinator in coordinators" :key="coordinator.id" :value="coordinator.id">
                {{ coordinator.name }}
              </option>
            </select>
          </div>
          <div>
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="editForm.is_active" type="checkbox" />
              Active
            </label>
          </div>
        </div>

        <p v-if="editModalError" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ editModalError }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeEditModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400"
            :disabled="isEditSaving"
            @click="saveEdit"
          >
            {{ isEditSaving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </section>
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
            <div v-if="viewedBatch.batch_students.length > 0" class="mt-2 overflow-hidden rounded-lg ring-1 ring-slate-200">
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

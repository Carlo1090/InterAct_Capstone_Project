<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { Batch, PaginatedResponse, Program, User } from '@/types/api'

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

const batches = ref<Batch[]>([])
const programs = ref<Program[]>([])
const coordinators = ref<User[]>([])
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
  } catch {
    modalError.value = 'Unable to load programs or coordinators.'
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
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="batches.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="7">No batches found.</td>
          </tr>
          <tr v-for="batch in batches" :key="batch.id">
            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ batch.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.program?.name ?? 'No Program' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.program?.department?.name ?? 'No Department' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.coordinator?.name ?? 'No Coordinator' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.start_date }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.end_date }}</td>
            <td class="px-4 py-3 text-sm text-slate-400">Edit later</td>
          </tr>
        </tbody>
      </table>
    </div>

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
  </section>
</template>

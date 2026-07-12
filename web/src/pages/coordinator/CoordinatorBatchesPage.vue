<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import type {
  Batch,
  BatchRosterResponse,
  BatchRosterRow,
  JournalTemplateProgramOption,
  JournalTemplateRecord,
} from '@/types/api'

/** Format an ISO/Y-m-d date string to a human date, e.g. "May 9, 2026". */
const formatDate = (value: string | null | undefined): string => {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
}

type BatchForm = {
  program_id: number | null
  name: string
  academic_year: string
  semester: string
  start_date: string
  end_date: string
  required_hours: number
  working_days_per_week: number
  daily_reminder_time: string
  journal_template_id: number | null
  is_active: boolean
}

const batches = ref<Batch[]>([])
const programs = ref<JournalTemplateProgramOption[]>([])
const templates = ref<JournalTemplateRecord[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const isModalOpen = ref(false)
const editingBatchId = ref<number | null>(null)
const isSaving = ref(false)
const modalErrors = ref<Record<string, string[]>>({})
const modalMessage = ref('')
// The batch's is_active value as loaded, so save() can tell a true->false
// deactivation apart from a false->true reactivation (or no change at all).
const originalIsActive = ref(true)

const emptyForm = (): BatchForm => ({
  program_id: programs.value[0]?.id ?? null,
  name: '',
  academic_year: String(new Date().getFullYear()),
  semester: 'Internship',
  start_date: '',
  end_date: '',
  required_hours: 486,
  working_days_per_week: 5,
  daily_reminder_time: '21:00',
  journal_template_id: null,
  is_active: true,
})

const form = reactive<BatchForm>(emptyForm())

// Templates are many-programs-per-template now — filter on membership, not a
// single program_id (which no longer exists on the template).
const templatesForSelectedProgram = computed(() =>
  templates.value.filter((template) => template.programs.some((program) => program.id === form.program_id)),
)

const loadTemplates = async () => {
  try {
    const { data } = await api.get<{ templates: JournalTemplateRecord[]; programs: JournalTemplateProgramOption[] }>(
      '/api/coordinator/journal-templates',
    )
    templates.value = data.templates
    programs.value = data.programs
  } catch {
    // Non-fatal here — the template dropdown just stays as last loaded.
  }
}

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [batchesResponse] = await Promise.all([api.get<Batch[]>('/api/coordinator/batches'), loadTemplates()])
    batches.value = batchesResponse.data
  } catch {
    errorMessage.value = 'Unable to load batches.'
  } finally {
    isLoading.value = false
  }
}

const resetForm = () => {
  Object.assign(form, emptyForm())
  modalErrors.value = {}
  modalMessage.value = ''
}

const openCreateModal = () => {
  editingBatchId.value = null
  resetForm()
  isModalOpen.value = true
  // Refetch so a template just created (in another tab/moment) shows up now.
  loadTemplates()
}

const openEditModal = (batch: Batch) => {
  editingBatchId.value = batch.id
  form.program_id = batch.program.id
  form.name = batch.name
  form.academic_year = batch.academic_year ?? String(new Date().getFullYear())
  form.semester = batch.semester ?? 'Internship'
  // The API returns ISO datetimes; a <input type="date"> needs a bare Y-m-d.
  form.start_date = batch.start_date?.slice(0, 10) ?? ''
  form.end_date = batch.end_date?.slice(0, 10) ?? ''
  form.required_hours = batch.required_hours
  form.working_days_per_week = batch.working_days_per_week
  form.daily_reminder_time = batch.daily_reminder_time.slice(0, 5)
  form.journal_template_id = batch.journal_template_id ?? null
  form.is_active = batch.is_active ?? true
  originalIsActive.value = batch.is_active ?? true
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
  // Refetch so a template just created (in another tab/moment) shows up now.
  loadTemplates()
}

const closeModal = () => {
  isModalOpen.value = false
}

const save = async () => {
  // Deactivating a batch is a critical action — confirm with the truthful
  // consequence before it goes out. Reactivating needs no confirm.
  if (editingBatchId.value && originalIsActive.value && !form.is_active) {
    const confirmed = confirmAction(
      `Mark "${form.name}" as Inactive? Interns in this batch will stop receiving daily journal reminder emails. ` +
        'Enrollment, journal writing, and reports keep working as normal. You can reactivate it later.',
    )
    if (!confirmed) return
  }

  isSaving.value = true
  modalErrors.value = {}
  modalMessage.value = ''

  try {
    if (editingBatchId.value) {
      const { name, academic_year, semester, start_date, end_date, required_hours, working_days_per_week, daily_reminder_time, journal_template_id, is_active } = form
      await api.put(`/api/coordinator/batches/${editingBatchId.value}`, {
        name,
        academic_year,
        semester,
        start_date,
        end_date,
        required_hours,
        working_days_per_week,
        daily_reminder_time,
        journal_template_id,
        is_active,
      })
    } else {
      await api.post('/api/coordinator/batches', form)
    }

    await load()
    closeModal()
    showToast(editingBatchId.value ? 'Batch updated.' : 'Batch created.')
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      modalErrors.value = error.response.data.errors ?? {}
      modalMessage.value = 'Please fix the errors below.'
    } else if (axios.isAxiosError(error) && error.response?.status === 403) {
      modalMessage.value = 'You are not allowed to edit this batch.'
    } else {
      modalMessage.value = 'Unable to save this batch.'
    }
  } finally {
    isSaving.value = false
  }
}

// --- Roster management ------------------------------------------------------
const isRosterOpen = ref(false)
const rosterBatch = ref<Batch | null>(null)
const rosterRows = ref<BatchRosterRow[]>([])
const isRosterLoading = ref(false)
const rosterMessage = ref('')

const activeRoster = computed(() => rosterRows.value.filter((row) => row.status === 'active'))
const droppedRoster = computed(() => rosterRows.value.filter((row) => row.status === 'dropped'))

const loadRoster = async (batchId: number) => {
  isRosterLoading.value = true
  rosterMessage.value = ''
  try {
    const { data } = await api.get<BatchRosterResponse>(`/api/coordinator/batches/${batchId}/roster`)
    rosterRows.value = data.students
  } catch {
    rosterMessage.value = 'Unable to load this batch\'s roster.'
  } finally {
    isRosterLoading.value = false
  }
}

const openRoster = async (batch: Batch) => {
  rosterBatch.value = batch
  rosterRows.value = []
  rosterMessage.value = ''
  isRosterOpen.value = true

  await loadRoster(batch.id)
}

const closeRoster = () => {
  isRosterOpen.value = false
  rosterBatch.value = null
}

const removeIntern = async (row: BatchRosterRow) => {
  if (!rosterBatch.value) return
  if (!confirmAction(`Remove ${row.student.name} from "${rosterBatch.value.name}"? Their record will be marked dropped (history is kept).`)) return

  try {
    await api.patch(`/api/coordinator/batches/${rosterBatch.value.id}/roster/${row.id}/drop`)
    await loadRoster(rosterBatch.value.id)
    await load()
    showToast('Intern removed (dropped).')
  } catch {
    rosterMessage.value = 'Unable to remove this intern.'
  }
}

const deleteIntern = async (row: BatchRosterRow) => {
  if (!rosterBatch.value) return
  if (!confirmAction(`Permanently delete ${row.student.name}'s dropped record from this batch? This cannot be undone.`)) return

  try {
    await api.delete(`/api/coordinator/batches/${rosterBatch.value.id}/roster/${row.id}`)
    await loadRoster(rosterBatch.value.id)
    showToast('Record deleted.')
  } catch {
    rosterMessage.value = 'Unable to delete this record.'
  }
}

const reactivateIntern = async (row: BatchRosterRow) => {
  if (!rosterBatch.value) return
  if (!confirmAction(`Reactivate ${row.student.name} in "${rosterBatch.value.name}"? They'll be marked active again with their previous company and supervisor.`)) return

  rosterMessage.value = ''
  try {
    await api.patch(`/api/coordinator/batches/${rosterBatch.value.id}/roster/${row.id}/reactivate`)
    await loadRoster(rosterBatch.value.id)
    await load()
    showToast('Intern reactivated.')
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      rosterMessage.value = error.response.data.message ?? 'Unable to reactivate this intern.'
    } else {
      rosterMessage.value = 'Unable to reactivate this intern.'
    }
  }
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />
    <div class="flex items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold text-slate-950">Batches</h2>
        <p class="mt-1 text-sm text-slate-500">Create and manage OJT cohorts for your program(s).</p>
      </div>
      <button
        type="button"
        class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
        :disabled="programs.length === 0"
        @click="openCreateModal"
      >
        + Create Batch
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>
    <p v-else-if="programs.length === 0" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      You are not currently assigned to a program, so there are no batches to manage yet.
    </p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Batch</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">AY / Semester</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Start</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">End</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="batches.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="7">No batches yet.</td>
          </tr>
          <tr v-for="batch in batches" :key="batch.id">
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ batch.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ batch.program?.name ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ batch.academic_year }} · {{ batch.semester }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ formatDate(batch.start_date) }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ formatDate(batch.end_date) }}</td>
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
                <button type="button" class="rounded-md border border-blue-600 px-3 py-1.5 text-sm font-semibold text-blue-700 hover:bg-blue-50" @click="openRoster(batch)">
                  View Interns
                </button>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="openEditModal(batch)">
                  Edit
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingBatchId ? 'Edit Batch' : 'Create Batch' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2">
          <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-name">Batch Name</label>
            <input id="batch-name" v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-program">Program</label>
            <select
              id="batch-program"
              v-model.number="form.program_id"
              class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
              :disabled="!!editingBatchId"
            >
              <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.name }}</option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-template">Journal Template</label>
            <select id="batch-template" v-model.number="form.journal_template_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option :value="null">None yet</option>
              <option v-for="template in templatesForSelectedProgram" :key="template.id" :value="template.id">{{ template.name }}</option>
            </select>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-ay">Academic Year</label>
            <input id="batch-ay" v-model="form.academic_year" type="text" placeholder="2026" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-semester">Semester</label>
            <input id="batch-semester" v-model="form.semester" type="text" placeholder="Internship" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-start">Start Date</label>
            <input id="batch-start" v-model="form.start_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-end">End Date</label>
            <input id="batch-end" v-model="form.end_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-hours">Required Hours</label>
            <input id="batch-hours" v-model.number="form.required_hours" type="number" min="1" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-days">Working Days / Week</label>
            <input id="batch-days" v-model.number="form.working_days_per_week" type="number" min="1" max="7" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-reminder">Daily Reminder Time</label>
            <input id="batch-reminder" v-model="form.daily_reminder_time" type="time" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </div>
          <label v-if="editingBatchId" class="mt-7 flex items-center gap-2 text-sm font-medium" :class="form.is_active ? 'text-slate-700' : 'text-red-700'">
            <input v-model="form.is_active" type="checkbox" />
            Active
          </label>
        </div>

        <div
          v-if="editingBatchId && originalIsActive && !form.is_active"
          class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700"
        >
          Deactivating this batch stops daily journal reminder emails to its interns. Enrollment, journal writing, and reports
          keep working as normal.
        </div>

        <div v-if="Object.keys(modalErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
          <p v-for="(messages, field) in modalErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
        </div>
        <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400"
            :class="editingBatchId && originalIsActive && !form.is_active ? 'bg-red-600' : 'bg-slate-950'"
            :disabled="isSaving"
            @click="save"
          >
            {{ isSaving ? 'Saving...' : editingBatchId && originalIsActive && !form.is_active ? 'Deactivate & Save' : 'Save' }}
          </button>
        </div>
      </section>
    </div>

    <!-- Roster management modal -->
    <div v-if="isRosterOpen && rosterBatch" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-3xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-lg font-semibold text-slate-950">Interns — {{ rosterBatch.name }}</h3>
            <p class="mt-0.5 text-xs text-slate-500">{{ rosterBatch.program?.name }} · {{ activeRoster.length }} active</p>
          </div>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeRoster">Close</button>
        </div>

        <p v-if="rosterMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ rosterMessage }}</p>

        <!-- Program / Batch Details -->
        <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
          <p class="mb-3 text-sm font-semibold text-slate-800">Program / Batch Details</p>
          <div class="grid gap-x-6 gap-y-3 text-sm md:grid-cols-2">
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Program</span>
              {{ rosterBatch.program?.name ?? '—' }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Department</span>
              {{ rosterBatch.program?.department?.name ?? '—' }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Academic Year / Semester</span>
              {{ rosterBatch.academic_year ?? '—' }} / {{ rosterBatch.semester ?? '—' }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Journal Template</span>
              {{ rosterBatch.journal_template?.name ?? '—' }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Start Date</span>
              {{ formatDate(rosterBatch.start_date) }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">End Date</span>
              {{ formatDate(rosterBatch.end_date) }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Required Hours</span>
              {{ rosterBatch.required_hours }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Working Days / Week</span>
              {{ rosterBatch.working_days_per_week }}
            </div>
            <div>
              <span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Daily Reminder Time</span>
              {{ rosterBatch.daily_reminder_time?.slice(0, 5) ?? '—' }}
            </div>
          </div>
        </div>

        <p v-if="isRosterLoading" class="mt-5 text-sm text-slate-500">Loading roster...</p>

        <!-- Active interns -->
        <div v-else class="mt-5 space-y-5">
          <div>
            <p class="mb-2 text-sm font-semibold text-slate-800">Active interns ({{ activeRoster.length }})</p>
            <div class="overflow-hidden rounded-md ring-1 ring-slate-200">
              <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th class="px-3 py-2 text-left">Student</th>
                    <th class="px-3 py-2 text-left">Company</th>
                    <th class="px-3 py-2 text-left">Supervisor</th>
                    <th class="px-3 py-2 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-if="activeRoster.length === 0">
                    <td class="px-3 py-4 text-center text-slate-500" colspan="4">No active interns in this batch.</td>
                  </tr>
                  <tr v-for="row in activeRoster" :key="row.id">
                    <td class="px-3 py-2">
                      <p class="font-semibold text-slate-900">{{ row.student.name }}</p>
                      <p class="font-mono text-xs text-slate-400">{{ row.student.student_id_number ?? '—' }}</p>
                    </td>
                    <td class="px-3 py-2 text-slate-600">{{ row.company?.name ?? '—' }}</td>
                    <td class="px-3 py-2 text-slate-600">{{ row.supervisor?.name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">
                      <button type="button" class="rounded-md border border-amber-500 px-3 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50" @click="removeIntern(row)">
                        Remove
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Dropped interns (can be deleted) -->
          <div v-if="droppedRoster.length">
            <p class="mb-2 text-sm font-semibold text-slate-800">Dropped ({{ droppedRoster.length }})</p>
            <div class="overflow-hidden rounded-md ring-1 ring-slate-200">
              <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th class="px-3 py-2 text-left">Student</th>
                    <th class="px-3 py-2 text-left">Company</th>
                    <th class="px-3 py-2 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="row in droppedRoster" :key="row.id">
                    <td class="px-3 py-2 text-slate-600">{{ row.student.name }}</td>
                    <td class="px-3 py-2 text-slate-500">{{ row.company?.name ?? '—' }}</td>
                    <td class="px-3 py-2 text-right">
                      <button type="button" class="mr-2 rounded-md border border-green-600 px-3 py-1 text-xs font-semibold text-green-700 hover:bg-green-50" @click="reactivateIntern(row)">
                        Reactivate
                      </button>
                      <button type="button" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="deleteIntern(row)">
                        Delete
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

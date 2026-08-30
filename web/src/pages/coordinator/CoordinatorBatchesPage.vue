<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { confirmAction, showToast, type ConfirmTone } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import LoadStatus from '@/components/LoadStatus.vue'
import ValidationErrorList from '@/components/ui/ValidationErrorList.vue'
import type {
  Batch,
  BatchRosterResponse,
  BatchRosterRow,
  CoordinatorInternUser,
  EnrollmentOptions,
  JournalTemplateProgramOption,
  JournalTemplateRecord,
  OjtType,
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
  ojt_type: OjtType
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
// Roster size of the batch being edited. Non-zero freezes the OJT type, which
// UpdateBatchRequest also enforces — this only keeps the form from offering a
// change the server would refuse.
const editingInternsCount = ref(0)
const ojtTypeLocked = computed(() => editingBatchId.value !== null && editingInternsCount.value > 0)

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
  // Matches the column default: how every batch behaved before the choice
  // existed, so the safe answer is the one already in force.
  ojt_type: 'supervisor' as OjtType,
  journal_template_id: null,
  is_active: true,
})

const form = reactive<BatchForm>(emptyForm())

/**
 * What the selected OJT type turns on, stated plainly so the choice is not made
 * blind. Kept in the script rather than duplicated across two template blocks,
 * and worded to match the Journal Review page's own copy.
 */
const ojtTypeEffects = computed<{ on: boolean; text: string }[]>(() =>
  form.ojt_type === 'supervisor'
    ? [
        { on: true, text: 'Weekly journals go to the company supervisor. They Approve, or Return with a comment.' },
        { on: true, text: 'QR clock-in is available, if you have the Daily Time Record switched on.' },
        { on: false, text: 'An intern cannot be enrolled until their company has a supervisor login.' },
      ]
    : [
        { on: true, text: 'Weekly journals come to you, under Journal Review — the same Approve and Return.' },
        { on: true, text: 'Interns can be placed at a company that has no account here at all.' },
        { on: false, text: 'No QR clock-in for this batch. Hours come from the typed Weekly and Time Log Summary.' },
      ],
)

// End date must be strictly after start date (mirrors StoreBatchRequest's
// `after:start_date` rule) — checked client-side so the mistake is caught
// before a round trip, not just surfaced as a raw server error afterward.
const endDateInvalid = computed(() => Boolean(form.start_date && form.end_date && form.end_date <= form.start_date))

// Client-side guards only — the server rules are unchanged and still authoritative.
// `v-model.number` yields '' for a cleared input, which Number.isInteger rejects,
// so an emptied field is caught the same way an out-of-range one is.
const workingDaysInvalid = computed(
  () => !Number.isInteger(form.working_days_per_week) || form.working_days_per_week < 1 || form.working_days_per_week > 7,
)
const requiredHoursInvalid = computed(() => !Number.isInteger(form.required_hours) || form.required_hours < 1)

const hasFieldErrors = computed(() => endDateInvalid.value || workingDaysInvalid.value || requiredHoursInvalid.value)

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
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Unable to load batches.').message
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
  editingInternsCount.value = 0
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
  form.ojt_type = batch.ojt_type ?? 'supervisor'
  originalIsActive.value = batch.is_active ?? true
  editingInternsCount.value = batch.interns_count ?? 0
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
  // Refetch so a template just created (in another tab/moment) shows up now.
  loadTemplates()
}

/**
 * The OJT type on the list. Coordinator-centered takes the blue accent purely
 * because it is the exception in a list that is mostly the default — the colour
 * marks 'not the usual arrangement', not 'better'.
 */
const ojtTypeLabel = (batch: Batch): string =>
  (batch.ojt_type ?? 'supervisor') === 'coordinator' ? 'Coordinator-centered' : 'Supervisor-supported'

const ojtTypePillClass = (batch: Batch): string =>
  (batch.ojt_type ?? 'supervisor') === 'coordinator' ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'

const closeModal = () => {
  isModalOpen.value = false
}

const save = async () => {
  // Deactivating a batch is a critical action — confirm with the truthful
  // consequence before it goes out. Reactivating needs no confirm.
  if (editingBatchId.value && originalIsActive.value && !form.is_active) {
    const confirmed = await confirmAction({
      title: 'Deactivate this batch?',
      message:
        `Mark "${form.name}" as Inactive? Interns in this batch will stop receiving daily journal reminder emails. ` +
        'Enrollment, journal writing, and reports keep working as normal. You can reactivate it later.',
      confirmLabel: 'Deactivate Batch',
      tone: 'danger',
    })
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

const rosterCandidates = ref<CoordinatorInternUser[]>([])
const rosterOptions = ref<EnrollmentOptions>({ companies: [], supervisors: [] })
const isAddingIntern = ref(false)

const addForm = reactive({
  student_id: null as number | null,
  company_id: null as number | null,
  assigned_division: '',
})

// The supervisor is tied to the company, not a separate choice — this is
// read-only display of whichever supervisor the selected company resolves
// to (its one login account), matching what the backend will assign.
const addResolvedSupervisor = computed(() =>
  addForm.company_id
    ? rosterOptions.value.supervisors.find((supervisor) => supervisor.company_ids.includes(addForm.company_id as number))
    : undefined,
)

const activeRoster = computed(() => rosterRows.value.filter((row) => row.status === 'active'))
const completedRoster = computed(() => rosterRows.value.filter((row) => row.status === 'completed' && !row.archived_at))
const droppedRoster = computed(() => rosterRows.value.filter((row) => row.status === 'dropped' && !row.archived_at))
const archivedRoster = computed(() => rosterRows.value.filter((row) => row.archived_at))
const activeStudentIds = computed(() => activeRoster.value.map((row) => row.student.id))

// Students who may be added to THIS batch: same program, not already active here.
// A student active in ANOTHER batch stays selectable (adding them = a MOVE).
const addableStudents = computed(() => {
  if (!rosterBatch.value) return []
  return rosterCandidates.value.filter(
    (student) =>
      student.program?.id === rosterBatch.value?.program.id && !activeStudentIds.value.includes(student.id),
  )
})

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
  addForm.student_id = null
  addForm.company_id = null
  addForm.assigned_division = ''
  rosterMessage.value = ''
  isRosterOpen.value = true

  await loadRoster(batch.id)
  try {
    const [internsResponse, optionsResponse] = await Promise.all([
      api.get<CoordinatorInternUser[]>('/api/coordinator/users/interns'),
      api.get<EnrollmentOptions>('/api/coordinator/enrollment-options'),
    ])
    rosterCandidates.value = internsResponse.data
    rosterOptions.value = optionsResponse.data
  } catch {
    rosterMessage.value = 'Unable to load the student picker.'
  }
}

const closeRoster = () => {
  isRosterOpen.value = false
  rosterBatch.value = null
}

const addIntern = async () => {
  if (!rosterBatch.value || !addForm.student_id) return

  const candidate = rosterCandidates.value.find((student) => student.id === addForm.student_id)

  // Enrolled elsewhere -> this is a MOVE. Confirm first (guards a wrong-batch pick).
  if (candidate?.enrolled && candidate.enrollment && candidate.enrollment.batch.id !== rosterBatch.value.id) {
    const confirmed = await confirmAction({
      title: 'Move this intern to another batch?',
      message:
        `${candidate.name} is currently enrolled in "${candidate.enrollment.batch.name}". ` +
        `Adding them to "${rosterBatch.value.name}" will MOVE them: their "${candidate.enrollment.batch.name}" ` +
        `enrollment will be marked dropped and a new active one created here. ` +
        `Make sure "${rosterBatch.value.name}" is the correct batch.`,
      confirmLabel: 'Move Intern',
      tone: 'danger',
    })
    if (!confirmed) return
  }

  isAddingIntern.value = true
  rosterMessage.value = ''
  try {
    const { data } = await api.post<{ moved: boolean }>(`/api/coordinator/batches/${rosterBatch.value.id}/roster`, {
      student_id: addForm.student_id,
      company_id: addForm.company_id,
      assigned_division: addForm.assigned_division || null,
    })

    addForm.student_id = null
    addForm.company_id = null
    addForm.assigned_division = ''

    await loadRoster(rosterBatch.value.id)
    // Refresh candidates so enrolled-elsewhere state stays accurate.
    rosterCandidates.value = (await api.get<CoordinatorInternUser[]>('/api/coordinator/users/interns')).data
    showToast(data.moved ? 'Intern moved to this batch.' : 'Intern added to this batch.')
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      rosterMessage.value = error.response.data.message ?? 'Unable to add this student.'
    } else if (axios.isAxiosError(error) && error.response?.status === 403) {
      rosterMessage.value = 'That student or batch is outside your department scope.'
    } else {
      rosterMessage.value = 'Unable to add this student.'
    }
  } finally {
    isAddingIntern.value = false
  }
}

type RosterActionOptions = {
  /** Names the outcome as a question, e.g. "Remove this intern?" */
  confirmTitle: string
  confirmMessage: string
  /** Must match the button the user clicked to get here. */
  confirmLabel: string
  /** Only for actions that drop, archive, or destroy a record. */
  confirmTone?: ConfirmTone
  request: () => Promise<unknown>
  successMessage: string
  errorFallback: string
  reloadBatches?: boolean
  refetchCandidates?: boolean
}

/**
 * Every roster row action (drop/archive/restore/delete/complete/reopen/
 * reactivate) shares the same confirm -> call -> reload -> toast/catch
 * shape; this is the one place that logic lives instead of being
 * copy-pasted per action.
 */
const runRosterAction = async ({
  confirmTitle,
  confirmMessage,
  confirmLabel,
  confirmTone,
  request,
  successMessage,
  errorFallback,
  reloadBatches,
  refetchCandidates,
}: RosterActionOptions) => {
  if (!rosterBatch.value) return

  const confirmed = await confirmAction({
    title: confirmTitle,
    message: confirmMessage,
    confirmLabel,
    tone: confirmTone,
  })
  if (!confirmed) return

  rosterMessage.value = ''
  try {
    await request()
    await loadRoster(rosterBatch.value.id)
    if (reloadBatches) await load()
    if (refetchCandidates) {
      rosterCandidates.value = (await api.get<CoordinatorInternUser[]>('/api/coordinator/users/interns')).data
    }
    showToast(successMessage)
  } catch (error) {
    rosterMessage.value = categorizeError(error, errorFallback).message
  }
}

const removeIntern = (row: BatchRosterRow) =>
  runRosterAction({
    confirmTitle: 'Remove this intern from the batch?',
    confirmMessage: `Remove ${row.student.name} from "${rosterBatch.value?.name}"? Their record will be marked dropped (history is kept).`,
    confirmLabel: 'Remove Intern',
    confirmTone: 'danger',
    request: () => api.patch(`/api/coordinator/batches/${rosterBatch.value!.id}/roster/${row.id}/drop`),
    successMessage: 'Intern removed (dropped).',
    errorFallback: 'Unable to remove this intern.',
    reloadBatches: true,
  })

const archiveIntern = (row: BatchRosterRow) =>
  runRosterAction({
    confirmTitle: 'Archive this record?',
    confirmMessage: `Archive ${row.student.name}'s record from this batch? It moves to Archived and can be restored anytime within 30 days, after which it is permanently deleted automatically.`,
    confirmLabel: 'Archive Record',
    confirmTone: 'danger',
    request: () => api.patch(`/api/coordinator/batches/${rosterBatch.value!.id}/roster/${row.id}/archive`),
    successMessage: 'Record archived.',
    errorFallback: 'Unable to archive this record.',
  })

const restoreIntern = (row: BatchRosterRow) =>
  runRosterAction({
    confirmTitle: 'Restore this record?',
    confirmMessage: `Restore ${row.student.name}'s archived record? It returns to its previous status and stops counting down to automatic deletion.`,
    confirmLabel: 'Restore Record',
    request: () => api.patch(`/api/coordinator/batches/${rosterBatch.value!.id}/roster/${row.id}/restore`),
    successMessage: 'Record restored.',
    errorFallback: 'Unable to restore this record.',
  })

const deleteForeverIntern = (row: BatchRosterRow) =>
  runRosterAction({
    confirmTitle: 'Delete this record forever?',
    confirmMessage: `Permanently delete ${row.student.name}'s archived record from this batch? This cannot be undone.`,
    confirmLabel: 'Delete Forever',
    confirmTone: 'danger',
    request: () => api.delete(`/api/coordinator/batches/${rosterBatch.value!.id}/roster/${row.id}`),
    successMessage: 'Record deleted.',
    errorFallback: 'Unable to delete this record.',
  })

const completeIntern = (row: BatchRosterRow) =>
  runRosterAction({
    confirmTitle: "Mark this intern's OJT completed?",
    confirmMessage:
      `Mark ${row.student.name}'s OJT as COMPLETED? Their journal window freezes today: ` +
      `they can still view and download everything, but new journal dates will be locked. You can reopen this later.`,
    confirmLabel: 'Mark Completed',
    request: () => api.patch(`/api/coordinator/batches/${rosterBatch.value!.id}/roster/${row.id}/complete`),
    successMessage: 'Intern marked completed.',
    errorFallback: 'Unable to mark this intern completed.',
    reloadBatches: true,
  })

const reopenIntern = (row: BatchRosterRow) =>
  runRosterAction({
    confirmTitle: 'Reopen this completed OJT?',
    confirmMessage:
      `Reopen ${row.student.name}'s completed OJT in "${rosterBatch.value?.name}"? ` +
      `They'll be active again and their journal window resumes rolling forward.`,
    confirmLabel: 'Reopen OJT',
    request: () => api.patch(`/api/coordinator/batches/${rosterBatch.value!.id}/roster/${row.id}/reopen`),
    successMessage: 'Intern reopened (active again).',
    errorFallback: 'Unable to reopen this record.',
    reloadBatches: true,
  })

const reactivateIntern = (row: BatchRosterRow) =>
  runRosterAction({
    confirmTitle: 'Reactivate this intern?',
    confirmMessage: `Reactivate ${row.student.name} in "${rosterBatch.value?.name}"? They'll be marked active again with their previous company and supervisor.`,
    confirmLabel: 'Reactivate Intern',
    request: () => api.patch(`/api/coordinator/batches/${rosterBatch.value!.id}/roster/${row.id}/reactivate`),
    successMessage: 'Intern reactivated.',
    errorFallback: 'Unable to reactivate this intern.',
    reloadBatches: true,
    refetchCandidates: true,
  })

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <p class="text-sm text-slate-500">Create and manage OJT cohorts for your program(s).</p>
      </div>
      <button
        type="button"
        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:grayscale disabled:cursor-not-allowed"
        :disabled="programs.length === 0"
        @click="openCreateModal"
      >
        + Create Batch
      </button>
    </div>

    <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
    <p v-if="programs.length === 0" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      You have no programs assigned yet. Ask an admin to assign you to a department.
    </p>

    <template v-else>
      <!-- md and up: aligned table. -->
      <div class="hidden overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200 md:block">
        <table class="min-w-full divide-y divide-slate-200">
          <thead class="bg-slate-50">
            <tr>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Batch</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">AY / Semester</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">OJT Type</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Start</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">End</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
              <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="batches.length === 0">
              <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="8">No batches yet.</td>
            </tr>
            <tr v-for="batch in batches" :key="batch.id">
              <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ batch.name }}</td>
              <td class="px-4 py-3 text-sm text-slate-700">{{ batch.program?.name ?? '—' }}</td>
              <td class="px-4 py-3 text-sm text-slate-500">{{ batch.academic_year }} · {{ batch.semester }}</td>
              <td class="px-4 py-3">
                <span class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold" :class="ojtTypePillClass(batch)">
                  {{ ojtTypeLabel(batch) }}
                </span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-500">{{ formatDate(batch.start_date) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-500">{{ formatDate(batch.end_date) }}</td>
              <td class="px-4 py-3">
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="batch.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                >
                  {{ batch.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex gap-2 whitespace-nowrap">
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

      <!-- Below md: one stacked card per batch, so nothing scrolls sideways. -->
      <ul class="divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden">
        <li v-if="batches.length === 0" class="py-6 text-center text-sm text-slate-500">No batches yet.</li>
        <li v-for="batch in batches" :key="batch.id" class="py-4">
          <div class="flex items-start justify-between gap-3">
            <p class="min-w-0 truncate text-sm font-semibold text-slate-900">{{ batch.name }}</p>
            <span
              class="shrink-0 rounded-full px-3 py-1 text-xs font-bold"
              :class="batch.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ batch.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <p class="mt-1 truncate text-xs text-slate-500">{{ batch.program?.name ?? '—' }} · {{ batch.academic_year }} · {{ batch.semester }}</p>
          <p class="mt-1 text-xs text-slate-500">{{ formatDate(batch.start_date) }} – {{ formatDate(batch.end_date) }}</p>
          <span class="mt-2 inline-block whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold" :class="ojtTypePillClass(batch)">
            {{ ojtTypeLabel(batch) }}
          </span>
          <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" class="rounded-md border border-blue-600 px-3 py-1.5 text-sm font-semibold text-blue-700 hover:bg-blue-50" @click="openRoster(batch)">
              View Interns
            </button>
            <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="openEditModal(batch)">
              Edit
            </button>
          </div>
        </li>
      </ul>
    </template>
    </LoadStatus>

    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <!-- Three-part flex shell, matching the Manage Company modal: the body is the only scroller. -->
      <section class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingBatchId ? 'Edit Batch' : 'Create Batch' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <section class="space-y-4">
            <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Batch Details</h4>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-name">Batch Name</label>
              <input id="batch-name" v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
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
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-ay">Academic Year</label>
                <input id="batch-ay" v-model="form.academic_year" type="text" placeholder="2026" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-semester">Semester</label>
                <input id="batch-semester" v-model="form.semester" type="text" placeholder="Internship" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </div>
            </div>
          </section>

          <!--
            OJT Type sits here, straight after Batch Details, because it is a
            property of the cohort rather than of its schedule — and because it
            decides which accounts the interns need BEFORE anyone is enrolled.
          -->
          <section class="mt-5 border-t border-slate-100 pt-5 space-y-4">
            <div>
              <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">OJT Type</h4>
              <p class="mt-1.5 text-xs text-slate-500">
                Who checks this batch's weekly journals. Pick it now &mdash; it decides which accounts the interns need
                before you can enrol them.
              </p>
            </div>

            <div class="space-y-3">
              <label
                class="block rounded-lg border-2 p-4 transition"
                :class="[
                  form.ojt_type === 'supervisor' ? 'border-blue-600 bg-blue-50' : 'border-slate-200 bg-white',
                  ojtTypeLocked ? 'cursor-not-allowed opacity-70' : 'cursor-pointer',
                ]"
              >
                <div class="flex items-start gap-3">
                  <input
                    v-model="form.ojt_type"
                    type="radio"
                    value="supervisor"
                    name="batch-ojt-type"
                    class="mt-1"
                    :disabled="ojtTypeLocked"
                  />
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="text-sm font-semibold text-slate-900">Supervisor-supported</span>
                      <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
                        Default
                      </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">
                      The host company holds a login. Their supervisor reviews each week's journal and corrects the time
                      records.
                    </p>
                  </div>
                </div>
              </label>

              <label
                class="block rounded-lg border-2 p-4 transition"
                :class="[
                  form.ojt_type === 'coordinator' ? 'border-blue-600 bg-blue-50' : 'border-slate-200 bg-white',
                  ojtTypeLocked ? 'cursor-not-allowed opacity-70' : 'cursor-pointer',
                ]"
              >
                <div class="flex items-start gap-3">
                  <input
                    v-model="form.ojt_type"
                    type="radio"
                    value="coordinator"
                    name="batch-ojt-type"
                    class="mt-1"
                    :disabled="ojtTypeLocked"
                  />
                  <div class="min-w-0 flex-1">
                    <span class="text-sm font-semibold text-slate-900">Coordinator-centered</span>
                    <p class="mt-1 text-sm text-slate-500">
                      The company hosts the intern but has no account here. You review the weekly journals yourself.
                    </p>
                  </div>
                </div>
              </label>
            </div>

            <!-- What the choice actually turns on, so it is not made blind. -->
            <div class="rounded-lg bg-slate-50 p-4 ring-1 ring-slate-200/70">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">What this sets up</p>
              <ul class="mt-2.5 space-y-2 text-sm text-slate-600">
                <li v-for="(effect, index) in ojtTypeEffects" :key="index" class="flex gap-2.5">
                  <span
                    class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
                    :class="effect.on ? 'bg-emerald-500' : 'bg-slate-300'"
                  />
                  <span>{{ effect.text }}</span>
                </li>
              </ul>
            </div>

            <p
              v-if="form.ojt_type === 'coordinator' && !ojtTypeLocked"
              class="rounded-md border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-xs font-medium text-amber-800"
            >
              Every intern you enrol here adds one journal a week to your review queue.
            </p>

            <p v-if="ojtTypeLocked" class="rounded-md bg-slate-50 px-3.5 py-2.5 text-xs text-slate-500">
              This batch already has {{ editingInternsCount }} intern{{ editingInternsCount === 1 ? '' : 's' }} enrolled,
              so its OJT type is fixed. Changing it now would hand journals already waiting on one reviewer to another
              &mdash; create a new batch instead.
            </p>
          </section>

          <section class="mt-5 border-t border-slate-100 pt-5 space-y-4">
            <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Schedule</h4>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-start">Start Date</label>
                <input id="batch-start" v-model="form.start_date" type="date" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-end">End Date</label>
                <input
                  id="batch-end"
                  v-model="form.end_date"
                  type="date"
                  :min="form.start_date || undefined"
                  class="w-full rounded-md border px-3 py-2 text-sm"
                  :class="endDateInvalid ? 'border-red-400' : 'border-slate-300'"
                />
                <p v-if="endDateInvalid" class="mt-1 text-xs text-red-600">End date must be after the start date.</p>
              </div>
            </div>
          </section>

          <section class="mt-5 border-t border-slate-100 pt-5 space-y-4">
            <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Requirements</h4>
            <div class="grid gap-4 sm:grid-cols-2">
              <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-hours">Required Hours</label>
                <input
                  id="batch-hours"
                  v-model.number="form.required_hours"
                  type="number"
                  min="1"
                  step="1"
                  class="w-full rounded-md border px-3 py-2 text-sm tabular-nums"
                  :class="requiredHoursInvalid ? 'border-red-400' : 'border-slate-300'"
                />
                <p v-if="requiredHoursInvalid" class="mt-1 text-xs text-red-600">Required hours must be a whole number of 1 or more.</p>
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-days">Working Days / Week</label>
                <input
                  id="batch-days"
                  v-model.number="form.working_days_per_week"
                  type="number"
                  min="1"
                  max="7"
                  step="1"
                  class="w-full rounded-md border px-3 py-2 text-sm tabular-nums"
                  :class="workingDaysInvalid ? 'border-red-400' : 'border-slate-300'"
                />
                <p v-if="workingDaysInvalid" class="mt-1 text-xs text-red-600">Working days must be a whole number between 1 and 7.</p>
              </div>
            </div>
          </section>

          <section class="mt-5 border-t border-slate-100 pt-5 space-y-4">
            <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Reminders</h4>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="batch-reminder">Daily Reminder Time</label>
              <input id="batch-reminder" v-model="form.daily_reminder_time" type="time" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </div>
          </section>

          <section v-if="editingBatchId" class="mt-5 border-t border-slate-100 pt-5">
            <div class="rounded-md border border-slate-200 p-4">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Batch Status</p>
              <div class="mt-3 flex flex-wrap items-center gap-6">
                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                  <input v-model="form.is_active" type="radio" :value="true" name="batch-status" />
                  <span>Active</span>
                </label>
                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                  <input v-model="form.is_active" type="radio" :value="false" name="batch-status" />
                  <span>Inactive</span>
                </label>
              </div>
              <!--
                Wording checked against the code, not assumed: `batches.is_active` is
                read in exactly two places app-wide — the daily reminder command, which
                skips inactive batches, and the coordinator dashboard's active-batch
                count. Enrollment never consults it, so an inactive batch does still
                accept new enrollments.
              -->
              <p class="mt-2 text-xs text-slate-400">
                Inactive batches stop daily journal reminders to their interns. Enrollment, journal writing, and reports keep
                working as normal.
              </p>
            </div>
          </section>

          <div
            v-if="editingBatchId && originalIsActive && !form.is_active"
            class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700"
          >
            Deactivating this batch stops daily journal reminder emails to its interns. Enrollment, journal writing, and reports
            keep working as normal.
          </div>

          <ValidationErrorList :errors="modalErrors" class="mt-4" />
          <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>
        </div>

        <div class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed disabled:bg-slate-400"
            :class="editingBatchId && originalIsActive && !form.is_active ? 'bg-red-600' : 'bg-blue-600'"
            :disabled="isSaving || hasFieldErrors"
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

        <!-- Add intern -->
        <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
          <p class="mb-3 text-sm font-semibold text-slate-800">Add an intern</p>
          <div class="grid gap-3 md:grid-cols-2">
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600" for="roster-student">Student (same program)</label>
              <select id="roster-student" v-model.number="addForm.student_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option :value="null">Select Student</option>
                <option v-for="student in addableStudents" :key="student.id" :value="student.id">
                  {{ student.name }}<template v-if="student.enrolled && student.enrollment"> — currently in {{ student.enrollment.batch.name }}</template>
                </option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600" for="roster-company">Company</label>
              <select id="roster-company" v-model.number="addForm.company_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option :value="null">Select Company</option>
                <option v-for="company in rosterOptions.companies" :key="company.id" :value="company.id">{{ company.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600">Supervisor</label>
              <p class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                <template v-if="!addForm.company_id">Select a company first.</template>
                <template v-else-if="addResolvedSupervisor">{{ addResolvedSupervisor.name }}</template>
                <template v-else
                  ><span class="text-amber-600"
                    >This company has no supervisor account yet. Attach one on Partner Companies before enrolling interns
                    here.</span
                  ></template
                >
              </p>
              <p class="mt-1 text-xs text-slate-500">Assigned automatically from the company.</p>
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium text-slate-600" for="roster-division">Assigned Division (optional)</label>
              <input id="roster-division" v-model="addForm.assigned_division" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </div>
          </div>
          <div class="mt-3 flex justify-end">
            <button
              type="button"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:bg-blue-300"
              :disabled="isAddingIntern || !addForm.student_id || !addForm.company_id || !addResolvedSupervisor"
              @click="addIntern"
            >
              {{ isAddingIntern ? 'Adding...' : 'Add Intern' }}
            </button>
          </div>
        </div>

        <p v-if="isRosterLoading" class="mt-5 text-sm text-slate-500">Loading roster...</p>

        <!-- Active interns -->
        <div v-else class="mt-5 space-y-5">
          <div>
            <p class="mb-2 text-sm font-semibold text-slate-800">Active interns ({{ activeRoster.length }})</p>
            <!-- md and up: aligned table. -->
            <div class="hidden overflow-x-auto rounded-md ring-1 ring-slate-200 md:block">
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
                      <button type="button" class="mr-2 rounded-md border border-blue-600 px-3 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50" @click="completeIntern(row)">
                        Mark Completed
                      </button>
                      <button type="button" class="rounded-md border border-amber-500 px-3 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50" @click="removeIntern(row)">
                        Remove
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Below md: one stacked card per intern. -->
            <ul class="divide-y divide-slate-100 rounded-md ring-1 ring-slate-200 md:hidden">
              <li v-if="activeRoster.length === 0" class="px-3 py-4 text-center text-sm text-slate-500">No active interns in this batch.</li>
              <li v-for="row in activeRoster" :key="row.id" class="px-3 py-3">
                <p class="font-semibold text-slate-900">{{ row.student.name }}</p>
                <p class="font-mono text-xs text-slate-400">{{ row.student.student_id_number ?? '—' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ row.company?.name ?? '—' }} · {{ row.supervisor?.name ?? '—' }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button type="button" class="rounded-md border border-blue-600 px-3 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50" @click="completeIntern(row)">
                    Mark Completed
                  </button>
                  <button type="button" class="rounded-md border border-amber-500 px-3 py-1 text-xs font-semibold text-amber-700 hover:bg-amber-50" @click="removeIntern(row)">
                    Remove
                  </button>
                </div>
              </li>
            </ul>
          </div>

          <!-- Completed interns (journal window frozen; can be reopened) -->
          <div v-if="completedRoster.length">
            <p class="mb-2 text-sm font-semibold text-slate-800">Completed ({{ completedRoster.length }})</p>
            <!-- md and up: aligned table. -->
            <div class="hidden overflow-x-auto rounded-md ring-1 ring-slate-200 md:block">
              <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th class="px-3 py-2 text-left">Student</th>
                    <th class="px-3 py-2 text-left">Company</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="row in completedRoster" :key="row.id">
                    <td class="px-3 py-2">
                      <p class="font-semibold text-slate-900">{{ row.student.name }}</p>
                      <p class="font-mono text-xs text-slate-400">{{ row.student.student_id_number ?? '—' }}</p>
                    </td>
                    <td class="px-3 py-2 text-slate-600">{{ row.company?.name ?? '—' }}</td>
                    <td class="px-3 py-2">
                      <span class="inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-blue-200">Completed</span>
                    </td>
                    <td class="px-3 py-2 text-right">
                      <button type="button" class="mr-2 rounded-md border border-green-600 px-3 py-1 text-xs font-semibold text-green-700 hover:bg-green-50" @click="reopenIntern(row)">
                        Reopen
                      </button>
                      <button type="button" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="archiveIntern(row)">
                        Archive
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Below md: one stacked card per intern. -->
            <ul class="divide-y divide-slate-100 rounded-md ring-1 ring-slate-200 md:hidden">
              <li v-for="row in completedRoster" :key="row.id" class="px-3 py-3">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <p class="truncate font-semibold text-slate-900">{{ row.student.name }}</p>
                    <p class="font-mono text-xs text-slate-400">{{ row.student.student_id_number ?? '—' }}</p>
                  </div>
                  <span class="shrink-0 inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-blue-200">Completed</span>
                </div>
                <p class="mt-1 truncate text-xs text-slate-500">{{ row.company?.name ?? '—' }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button type="button" class="rounded-md border border-green-600 px-3 py-1 text-xs font-semibold text-green-700 hover:bg-green-50" @click="reopenIntern(row)">
                    Reopen
                  </button>
                  <button type="button" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="archiveIntern(row)">
                    Archive
                  </button>
                </div>
              </li>
            </ul>
          </div>

          <!-- Dropped interns (can be archived) -->
          <div v-if="droppedRoster.length">
            <p class="mb-2 text-sm font-semibold text-slate-800">Dropped ({{ droppedRoster.length }})</p>
            <!-- md and up: aligned table. -->
            <div class="hidden overflow-x-auto rounded-md ring-1 ring-slate-200 md:block">
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
                      <button type="button" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="archiveIntern(row)">
                        Archive
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Below md: one stacked card per intern. -->
            <ul class="divide-y divide-slate-100 rounded-md ring-1 ring-slate-200 md:hidden">
              <li v-for="row in droppedRoster" :key="row.id" class="px-3 py-3">
                <p class="text-sm text-slate-700">{{ row.student.name }}</p>
                <p class="mt-0.5 text-xs text-slate-500">{{ row.company?.name ?? '—' }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button type="button" class="rounded-md border border-green-600 px-3 py-1 text-xs font-semibold text-green-700 hover:bg-green-50" @click="reactivateIntern(row)">
                    Reactivate
                  </button>
                  <button type="button" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="archiveIntern(row)">
                    Archive
                  </button>
                </div>
              </li>
            </ul>
          </div>

          <!-- Archived interns (reversible for 30 days, then auto-purged) -->
          <div v-if="archivedRoster.length">
            <p class="mb-2 text-sm font-semibold text-slate-800">Archived ({{ archivedRoster.length }})</p>
            <!-- md and up: aligned table. -->
            <div class="hidden overflow-x-auto rounded-md ring-1 ring-slate-200 md:block">
              <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th class="px-3 py-2 text-left">Student</th>
                    <th class="px-3 py-2 text-left">Company</th>
                    <th class="px-3 py-2 text-left">Status</th>
                    <th class="px-3 py-2 text-right">Action</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="row in archivedRoster" :key="row.id">
                    <td class="px-3 py-2 text-slate-600">{{ row.student.name }}</td>
                    <td class="px-3 py-2 text-slate-500">{{ row.company?.name ?? '—' }}</td>
                    <td class="px-3 py-2 text-slate-500">{{ row.status === 'completed' ? 'Completed' : 'Dropped' }}</td>
                    <td class="px-3 py-2 text-right">
                      <button type="button" class="mr-2 rounded-md border border-green-600 px-3 py-1 text-xs font-semibold text-green-700 hover:bg-green-50" @click="restoreIntern(row)">
                        Restore
                      </button>
                      <button type="button" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="deleteForeverIntern(row)">
                        Delete Forever
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Below md: one stacked card per intern. -->
            <ul class="divide-y divide-slate-100 rounded-md ring-1 ring-slate-200 md:hidden">
              <li v-for="row in archivedRoster" :key="row.id" class="px-3 py-3">
                <p class="text-sm text-slate-700">{{ row.student.name }}</p>
                <p class="mt-0.5 text-xs text-slate-500">
                  {{ row.company?.name ?? '—' }} · {{ row.status === 'completed' ? 'Completed' : 'Dropped' }}
                </p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button type="button" class="rounded-md border border-green-600 px-3 py-1 text-xs font-semibold text-green-700 hover:bg-green-50" @click="restoreIntern(row)">
                    Restore
                  </button>
                  <button type="button" class="rounded-md border border-red-500 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-50" @click="deleteForeverIntern(row)">
                    Delete Forever
                  </button>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>

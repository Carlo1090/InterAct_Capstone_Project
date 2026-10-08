<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { confirmAction, showToast } from '@/lib/toast'
import { downloadCsv, toCsv } from '@/lib/csv'
import type {
  BulkImportConfirmResponse,
  BulkImportOutcome,
  BulkImportPreviewResponse,
  BulkImportResultRow,
  BulkImportRow,
  EnrollmentOptionBatch,
  EnrollmentOptionProgram,
} from '@/types/api'

/**
 * Bulk Import Students (Excel/CSV) — replaces typing accounts one at a time,
 * but is still ACCOUNT CREATION ONLY: each row goes through the same DRAFT
 * info-sheet scaffold as Create Student Account and stays NOT-ENROLLED until
 * the student submits their sheet and a coordinator Accepts it.
 *
 * Mounted with v-if, so every open starts from a clean slate and nothing about
 * an import (above all its one-time passwords) outlives the window.
 */
const props = defineProps<{
  programs: EnrollmentOptionProgram[]
  batches: EnrollmentOptionBatch[]
}>()

const emit = defineEmits<{
  close: []
  /** Accounts may have been created — the list behind the modal should reload. */
  changed: []
}>()

type Step = 'upload' | 'preview' | 'results'
type PreviewFilter = 'all' | 'ready' | 'invalid' | 'existing'
type ResultFilter = 'all' | BulkImportOutcome

const STEPS: { key: Step; label: string }[] = [
  { key: 'upload', label: 'Choose file' },
  { key: 'preview', label: 'Review rows' },
  { key: 'results', label: 'Accounts created' },
]

const ACCEPTED_EXTENSIONS = ['xlsx', 'xls', 'csv']
/** Mirrors BulkImportStudentsRequest's `max:2048` (kilobytes). */
const MAX_FILE_BYTES = 2048 * 1024
/** Mirrors StudentBulkImportService::MAX_ROWS. */
const MAX_ROWS = 100

/**
 * Rows per confirm request. Every row sends its welcome email inline, and the
 * deployed API sits behind a proxy that gives up at 120s — a whole 100-row file
 * in one request outlived it, the credentials table never arrived, and a retry
 * said every row was "already in use". Fifteen rows at a few seconds per
 * message stays well inside that. The server caps a slice at 25.
 */
const SLICE_SIZE = 15

const step = ref<Step>('upload')
const form = ref({ program_id: null as number | null, batch_id: null as number | null })
const file = ref<File | null>(null)
const fileError = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const message = ref('')

const isPreviewing = ref(false)
const previewRows = ref<BulkImportRow[]>([])
const counts = ref({ ready: 0, invalid: 0, existing: 0 })
const previewFilter = ref<PreviewFilter>('all')

const isConfirming = ref(false)
const results = ref<BulkImportResultRow[]>([])
const createdCount = ref(0)
const progress = ref<{ done: number; total: number } | null>(null)
/** Where to pick up after a slice failed mid-file; null when nothing is pending. */
const resumeOffset = ref<number | null>(null)
const credentialsDownloaded = ref(false)
const resultFilter = ref<ResultFilter>('all')
const copiedRow = ref<number | null>(null)
let copiedTimer: ReturnType<typeof setTimeout> | null = null

// --- Upload ------------------------------------------------------------------

const batchOptions = computed(() =>
  form.value.program_id ? props.batches.filter((batch) => batch.program_id === form.value.program_id) : [],
)

const selectedProgram = computed(() => props.programs.find((program) => program.id === form.value.program_id) ?? null)
const selectedBatch = computed(() => props.batches.find((batch) => batch.id === form.value.batch_id) ?? null)

/** A colleague's batch carries their name, so same-named cohorts stay distinct. */
const batchLabel = (batch: EnrollmentOptionBatch): string =>
  batch.coordinator_name ? `${batch.name} (${batch.coordinator_name})` : batch.name

/** "BSIT · Batch A" — named in the review, the confirm and the results so nobody imports into the wrong cohort. */
const destinationLabel = computed(() =>
  [selectedProgram.value?.code ?? selectedProgram.value?.name, selectedBatch.value ? batchLabel(selectedBatch.value) : null]
    .filter(Boolean)
    .join(' · '),
)

watch(
  () => form.value.program_id,
  () => {
    if (batchOptions.value.some((batch) => batch.id === form.value.batch_id)) return
    // A program with exactly one batch has nothing to choose.
    form.value.batch_id = batchOptions.value.length === 1 ? batchOptions.value[0].id : null
  },
)

const canPreview = computed(() => file.value !== null && form.value.program_id !== null && form.value.batch_id !== null)

const formatBytes = (bytes: number): string =>
  bytes < 1024 ? `${bytes} B` : bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`

/**
 * Checks what can be checked without the server, so a wrong file type or an
 * oversized file is named at once rather than after an upload.
 */
const setFile = (candidate: File | null | undefined) => {
  fileError.value = ''
  message.value = ''
  if (!candidate) return

  const extension = candidate.name.split('.').pop()?.toLowerCase() ?? ''
  if (!ACCEPTED_EXTENSIONS.includes(extension)) {
    fileError.value = `"${candidate.name}" is not a spreadsheet. Choose an .xlsx, .xls or .csv file.`
    return
  }
  if (candidate.size > MAX_FILE_BYTES) {
    fileError.value = `"${candidate.name}" is ${formatBytes(candidate.size)}. The limit is 2 MB; split the roster into smaller files.`
    return
  }

  file.value = candidate
}

const onFileChange = (event: Event) => {
  const input = event.target as HTMLInputElement
  setFile(input.files?.[0])
  // Cleared so choosing the same file again (after fixing it) still fires change.
  input.value = ''
}

const onDrop = (event: DragEvent) => {
  isDragging.value = false
  setFile(event.dataTransfer?.files?.[0])
}

const chooseFile = () => fileInput.value?.click()

const removeFile = () => {
  file.value = null
  fileError.value = ''
}

const formData = (): FormData => {
  const data = new FormData()
  data.append('file', file.value as File)
  data.append('program_id', String(form.value.program_id))
  data.append('batch_id', String(form.value.batch_id))
  return data
}

const preview = async () => {
  if (!canPreview.value) return
  isPreviewing.value = true
  message.value = ''

  try {
    const { data } = await api.post<BulkImportPreviewResponse>('/api/coordinator/accounts/bulk-import/preview', formData())
    previewRows.value = data.rows
    counts.value = { ready: data.valid_count, invalid: data.invalid_count, existing: data.existing_count ?? 0 }
    previewFilter.value = 'all'
    step.value = 'preview'
  } catch (error) {
    const { kind, message: text } = categorizeError(
      error,
      'Unable to read this file. Check that it matches the template and try again.',
    )
    // A file edited on disk after it was chosen can no longer be read by the
    // browser, which surfaces as a request that never left.
    message.value =
      kind === 'network'
        ? 'The file could not be sent. If you edited it after choosing it, choose it again; otherwise check your connection.'
        : text
  } finally {
    isPreviewing.value = false
  }
}

// --- Preview -----------------------------------------------------------------

const filteredPreviewRows = computed(() =>
  previewFilter.value === 'all' ? previewRows.value : previewRows.value.filter((row) => row.status === previewFilter.value),
)

const previewChips = computed(() => [
  { key: 'all' as const, label: 'All rows', count: previewRows.value.length, tone: 'slate' },
  { key: 'ready' as const, label: 'Ready', count: counts.value.ready, tone: 'green' },
  { key: 'invalid' as const, label: 'Needs fixing', count: counts.value.invalid, tone: 'red' },
  { key: 'existing' as const, label: 'Already has an account', count: counts.value.existing, tone: 'slate' },
])

const fullName = (row: BulkImportRow): string =>
  [row.first_name, row.middle_name, row.last_name].filter(Boolean).join(' ') || '—'

const fileStem = (): string => (file.value?.name ?? 'roster').replace(/\.[^.]+$/, '')

/**
 * Only the rows that need fixing, in the template's own columns plus a Problem
 * column, so the coordinator corrects them in Excel and uploads just that file.
 * The importer ignores columns it does not know, so Problem can stay in.
 */
const downloadRowsToFix = () => {
  const rows = previewRows.value.filter((row) => row.status === 'invalid')
  if (rows.length === 0) return

  const sexCell = (row: BulkImportRow) =>
    row.sex_input ?? (row.sex ? row.sex.charAt(0).toUpperCase() + row.sex.slice(1) : '')

  downloadCsv(
    `${fileStem()}-rows-to-fix.csv`,
    toCsv(
      ['First Name', 'Middle Name', 'Family Name', 'Sex', 'Student ID Number', 'Email', 'Problem'],
      rows.map((row) => [
        row.first_name,
        row.middle_name ?? '',
        row.last_name,
        sexCell(row),
        row.student_id_number,
        row.email,
        `Row ${row.row}: ${row.errors.join(' ')}`,
      ]),
    ),
  )
}

const backToUpload = () => {
  step.value = 'upload'
}

// --- Confirm -----------------------------------------------------------------

/** Closing the tab mid-import loses the passwords of every row created so far. */
const warnBeforeUnload = (event: BeforeUnloadEvent) => {
  event.preventDefault()
  event.returnValue = ''
}

watch(isConfirming, (busy) => {
  if (busy) window.addEventListener('beforeunload', warnBeforeUnload)
  else window.removeEventListener('beforeunload', warnBeforeUnload)
})

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', warnBeforeUnload)
  if (copiedTimer !== null) clearTimeout(copiedTimer)
})

const progressPercent = computed(() =>
  progress.value && progress.value.total > 0 ? Math.round((progress.value.done / progress.value.total) * 100) : 0,
)

const confirmImport = async () => {
  const ready = counts.value.ready
  if (ready === 0) return

  const skipped = [
    counts.value.invalid ? `${counts.value.invalid} row${counts.value.invalid === 1 ? '' : 's'} that need fixing` : '',
    counts.value.existing
      ? `${counts.value.existing} student${counts.value.existing === 1 ? '' : 's'} who already ${counts.value.existing === 1 ? 'has an account' : 'have accounts'}`
      : '',
  ].filter(Boolean)

  const proceed = await confirmAction({
    title: `Create ${ready} student account${ready === 1 ? '' : 's'}?`,
    message:
      `Into ${destinationLabel.value}. Each student is emailed their username and a temporary password.` +
      (skipped.length ? ` Skipped: ${skipped.join(' and ')}.` : ''),
    confirmLabel: `Create ${ready} Account${ready === 1 ? '' : 's'}`,
  })
  if (!proceed) return

  results.value = []
  createdCount.value = 0
  credentialsDownloaded.value = false
  await runSlices(0)
}

/**
 * Walks the file one slice at a time from `startOffset`, merging each slice's
 * results, until the server reports there is nothing left. A failed slice
 * stops the walk but keeps every result already received — those passwords
 * are real and are not shown anywhere else.
 */
const runSlices = async (startOffset: number) => {
  isConfirming.value = true
  message.value = ''
  resumeOffset.value = null
  progress.value = { done: startOffset, total: previewRows.value.length }
  let offset: number | null = startOffset

  try {
    while (offset !== null) {
      const data = formData()
      data.append('offset', String(offset))
      data.append('limit', String(SLICE_SIZE))

      const { data: slice } = await api.post<BulkImportConfirmResponse>('/api/coordinator/accounts/bulk-import/confirm', data)
      results.value.push(...slice.results)
      createdCount.value += slice.created_count
      progress.value = { done: slice.next_offset ?? slice.total_rows, total: slice.total_rows }
      offset = slice.next_offset
    }

    step.value = 'results'
    resultFilter.value = outcomeCounts.value.created_email_failed > 0 ? 'created_email_failed' : 'all'
    showToast(`Created ${createdCount.value} student account${createdCount.value === 1 ? '' : 's'}.`)
  } catch (error) {
    const { message: text } = categorizeError(error, 'The connection dropped while creating these accounts.')
    resumeOffset.value = offset
    message.value =
      `${text} Some rows in the unfinished part may already have been created, and their passwords were not received. ` +
      'Continue to finish the file (those rows will show as already having an account), then reissue their passwords from the Credential Manager.'
    if (results.value.length > 0) step.value = 'results'
  } finally {
    isConfirming.value = false
    progress.value = null
    // Always, not only on success: when a response is lost the server may
    // still have created accounts, and the list behind the modal should say so.
    emit('changed')
  }
}

const resume = async () => {
  if (resumeOffset.value === null) return
  await runSlices(resumeOffset.value)
}

// --- Results -----------------------------------------------------------------

const OUTCOME_LABELS: Record<BulkImportOutcome, string> = {
  created_and_emailed: 'Created, emailed',
  created_email_failed: 'Created, email failed',
  already_exists: 'Already has an account',
  skipped_invalid: 'Skipped',
}

const OUTCOME_CLASSES: Record<BulkImportOutcome, string> = {
  created_and_emailed: 'bg-green-50 text-green-700',
  created_email_failed: 'bg-amber-50 text-amber-800',
  already_exists: 'bg-slate-100 text-slate-600',
  skipped_invalid: 'bg-red-50 text-red-700',
}

const outcomeCounts = computed(() => {
  const tally: Record<BulkImportOutcome, number> = {
    created_and_emailed: 0,
    created_email_failed: 0,
    already_exists: 0,
    skipped_invalid: 0,
  }
  results.value.forEach((row) => {
    tally[row.outcome] += 1
  })
  return tally
})

const resultChips = computed(() =>
  (Object.keys(OUTCOME_LABELS) as BulkImportOutcome[])
    .filter((outcome) => outcomeCounts.value[outcome] > 0)
    .map((outcome) => ({ key: outcome, label: OUTCOME_LABELS[outcome], count: outcomeCounts.value[outcome] })),
)

const filteredResults = computed(() =>
  resultFilter.value === 'all' ? results.value : results.value.filter((row) => row.outcome === resultFilter.value),
)

const hasPasswords = computed(() => results.value.some((row) => row.temporary_password))

const copyPassword = async (row: BulkImportResultRow) => {
  if (!row.temporary_password) return
  try {
    await navigator.clipboard.writeText(row.temporary_password)
    copiedRow.value = row.row
    if (copiedTimer !== null) clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => {
      copiedRow.value = null
    }, 1500)
  } catch {
    // Clipboard refused (insecure origin or denied permission) — the password
    // is on screen and selectable, which is enough.
  }
}

/**
 * The one-time credentials backup for this import — never persisted (no
 * sessionStorage, no logging), generated entirely client-side from the confirm
 * responses, gone once the window is closed.
 */
const downloadCredentials = () => {
  const rows = results.value.filter((row) => row.temporary_password)
  if (rows.length === 0) return

  downloadCsv(
    `${fileStem()}-credentials.csv`,
    toCsv(
      ['Student ID Number', 'Name', 'Email', 'Username', 'Temporary Password', 'Status'],
      rows.map((row) => [
        row.student_id_number,
        fullName(row),
        row.email,
        row.student_id_number,
        row.temporary_password ?? '',
        OUTCOME_LABELS[row.outcome],
      ]),
    ),
  )
  credentialsDownloaded.value = true
}

// --- Close -------------------------------------------------------------------

/**
 * Students whose welcome email failed have no copy of their password except
 * the one on this screen, so closing it unread asks first.
 */
const hasUndeliveredPasswords = computed(
  () => !credentialsDownloaded.value && results.value.some((row) => row.outcome === 'created_email_failed'),
)

const close = async () => {
  if (isConfirming.value) return

  if (hasUndeliveredPasswords.value) {
    const proceed = await confirmAction({
      title: 'Close without downloading the passwords?',
      message:
        'Some students were not emailed, and these passwords are not shown again. Download the credentials first, or reissue each password later from the Credential Manager.',
      confirmLabel: 'Close Anyway',
      cancelLabel: 'Go Back',
      tone: 'danger',
    })
    if (!proceed) return
  }

  emit('close')
}

const stepIndex = computed(() => STEPS.findIndex((item) => item.key === step.value))
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
    <section
      class="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="bulk-import-title"
    >
      <!-- Header -->
      <div class="shrink-0 border-b border-slate-200 px-6 py-4">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h3 id="bulk-import-title" class="text-lg font-semibold text-slate-950">Bulk Import Students</h3>
            <p class="mt-0.5 text-xs text-slate-500">
              Creates login accounts from a spreadsheet. It does not enroll anyone: each student still submits their
              Info Sheet and you Accept it.
            </p>
          </div>
          <button
            type="button"
            class="shrink-0 text-sm font-medium text-slate-500 hover:text-slate-900 disabled:cursor-not-allowed disabled:text-slate-300"
            :disabled="isConfirming"
            @click="close"
          >
            Close
          </button>
        </div>

        <ol class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs" aria-label="Import steps">
          <li v-for="(item, index) in STEPS" :key="item.key" class="flex items-center gap-2">
            <span
              class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold"
              :class="
                index < stepIndex
                  ? 'bg-blue-600 text-white'
                  : index === stepIndex
                    ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-600'
                    : 'bg-slate-100 text-slate-400'
              "
            >
              <svg v-if="index < stepIndex" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3" aria-hidden="true">
                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.6l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
              </svg>
              <template v-else>{{ index + 1 }}</template>
            </span>
            <span :class="index === stepIndex ? 'font-semibold text-slate-900' : 'text-slate-500'" :aria-current="index === stepIndex ? 'step' : undefined">
              {{ item.label }}
            </span>
            <span v-if="index < STEPS.length - 1" class="mx-1 h-px w-6 bg-slate-200" aria-hidden="true" />
          </li>
        </ol>
      </div>

      <!-- Body: the only scrolling element -->
      <div class="flex-1 overflow-y-auto px-6 py-5">
        <!-- Step 1: Upload -->
        <div v-if="step === 'upload'" class="space-y-5">
          <div class="grid gap-4 md:grid-cols-2">
            <div class="min-w-0">
              <label class="mb-2 block text-sm font-medium text-slate-700" for="bulk-program">Program</label>
              <select id="bulk-program" v-model.number="form.program_id" class="w-full max-w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option :value="null">Select Program</option>
                <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.code ?? program.name }}</option>
              </select>
            </div>
            <div class="min-w-0">
              <label class="mb-2 block text-sm font-medium text-slate-700" for="bulk-batch">Batch</label>
              <select
                id="bulk-batch"
                v-model.number="form.batch_id"
                class="w-full max-w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100 disabled:text-slate-400"
                :disabled="!form.program_id"
              >
                <option :value="null">Select Batch</option>
                <option v-for="batch in batchOptions" :key="batch.id" :value="batch.id">{{ batchLabel(batch) }}</option>
              </select>
              <p v-if="!form.program_id" class="mt-1 text-xs text-slate-500">Select a program first.</p>
              <p v-else-if="batchOptions.length === 0" class="mt-1 text-xs text-amber-700">
                Your department has no batches for this program yet. Create one on the Batches page first.
              </p>
            </div>
          </div>

          <div>
            <span class="mb-2 block text-sm font-medium text-slate-700">Spreadsheet</span>
            <div
              class="rounded-lg border-2 border-dashed px-4 py-6 text-center transition"
              :class="
                isDragging
                  ? 'border-blue-500 bg-blue-50'
                  : fileError
                    ? 'border-red-300 bg-red-50/40'
                    : file
                      ? 'border-slate-300 bg-white'
                      : 'border-slate-300 bg-slate-50'
              "
              @dragenter.prevent="isDragging = true"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="onDrop"
            >
              <input
                id="bulk-file"
                ref="fileInput"
                type="file"
                accept=".xlsx,.xls,.csv"
                class="sr-only"
                @change="onFileChange"
              />

              <div v-if="file" class="flex flex-wrap items-center justify-center gap-3 text-left">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-700">
                  <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5" aria-hidden="true">
                    <path d="M7 3h7l5 5v13H7z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
                    <path d="M14 3v5h5M10 13l4 4m0-4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </span>
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-900">{{ file.name }}</p>
                  <p class="text-xs text-slate-500">{{ formatBytes(file.size) }}</p>
                </div>
                <div class="flex items-center gap-3">
                  <button type="button" class="text-sm font-semibold text-blue-600 hover:text-blue-700" @click="chooseFile">Choose another</button>
                  <button type="button" class="text-sm font-semibold text-slate-500 hover:text-slate-700" @click="removeFile">Remove</button>
                </div>
              </div>

              <div v-else class="space-y-1">
                <p class="text-sm text-slate-700">
                  Drag the spreadsheet here, or
                  <button type="button" class="font-semibold text-blue-600 hover:text-blue-700" @click="chooseFile">choose a file</button>
                </p>
                <p class="text-xs text-slate-500">.xlsx, .xls or .csv · up to {{ MAX_ROWS }} students · 2 MB</p>
              </div>

              <p v-if="fileError" class="mt-3 text-sm text-red-700" role="alert">{{ fileError }}</p>
            </div>
          </div>

          <div class="rounded-md bg-slate-50 px-4 py-3 text-xs text-slate-600">
            <p class="font-semibold text-slate-700">What the file needs</p>
            <ul class="mt-1.5 list-disc space-y-0.5 pl-4">
              <li>Headings in row 1: First Name, Middle Name, Family Name, Sex, Student ID Number, Email. Middle Name and Sex may be blank.</li>
              <li>The roster on the first sheet, one student per row.</li>
              <li>The template's sample row (Juan Dela Cruz) deleted.</li>
              <li>If you edit the file after choosing it, choose it again.</li>
            </ul>
            <a
              href="/templates/student-bulk-import-template.csv"
              download
              class="mt-2 inline-block font-semibold text-blue-600 hover:text-blue-700"
            >
              Download the template (.csv)
            </a>
          </div>

          <p v-if="message" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ message }}</p>
        </div>

        <!-- Step 2: Preview -->
        <div v-else-if="step === 'preview'" class="space-y-4">
          <p class="text-sm text-slate-600">
            Importing <span class="font-semibold text-slate-900">{{ file?.name }}</span> into
            <span class="font-semibold text-slate-900">{{ destinationLabel }}</span>.
          </p>

          <!-- Progress, while accounts are being created -->
          <div v-if="isConfirming && progress" class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3" aria-live="polite">
            <div class="flex items-center justify-between gap-3 text-sm">
              <p class="font-semibold text-blue-900">Creating accounts and sending welcome emails</p>
              <p class="shrink-0 tabular-nums text-blue-800">{{ progress.done }} of {{ progress.total }} rows</p>
            </div>
            <div
              class="mt-2 h-2 overflow-hidden rounded-full bg-blue-100"
              role="progressbar"
              :aria-valuenow="progressPercent"
              aria-valuemin="0"
              aria-valuemax="100"
            >
              <div class="h-full rounded-full bg-blue-600 transition-all duration-500" :style="{ width: `${Math.max(progressPercent, 4)}%` }" />
            </div>
            <p class="mt-2 text-xs text-blue-800">Keep this window open until it finishes. The passwords are shown only here.</p>
          </div>

          <div class="flex flex-wrap gap-2" role="group" aria-label="Filter rows">
            <button
              v-for="chip in previewChips"
              :key="chip.key"
              type="button"
              class="rounded-full border px-3 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-40"
              :class="
                previewFilter === chip.key
                  ? 'border-blue-600 bg-blue-50 text-blue-700'
                  : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
              "
              :aria-pressed="previewFilter === chip.key"
              :disabled="chip.count === 0 && chip.key !== 'all'"
              @click="previewFilter = chip.key"
            >
              {{ chip.label }}
              <span
                class="ml-1 tabular-nums"
                :class="chip.tone === 'green' ? 'text-green-700' : chip.tone === 'red' ? 'text-red-700' : 'text-slate-500'"
              >{{ chip.count }}</span>
            </button>
          </div>

          <div v-if="counts.invalid > 0" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="min-w-0 text-sm text-amber-900">
              <span class="font-semibold">{{ counts.invalid }} row{{ counts.invalid === 1 ? '' : 's' }} need{{ counts.invalid === 1 ? 's' : '' }} fixing</span>
              and will be skipped. Create the ready rows now, then fix these and import them as their own file.
            </p>
            <button
              type="button"
              class="shrink-0 rounded-md border border-amber-300 bg-white px-3 py-1.5 text-sm font-semibold text-amber-900 transition hover:bg-amber-100"
              @click="downloadRowsToFix"
            >
              Download rows to fix (.csv)
            </button>
          </div>
          <p v-if="counts.existing > 0" class="rounded-md bg-slate-50 px-4 py-2 text-sm text-slate-600">
            {{ counts.existing }} student{{ counts.existing === 1 ? ' already has an account' : 's already have accounts' }}
            with the same ID number and email. Nothing will be created for {{ counts.existing === 1 ? 'that row' : 'those rows' }}.
          </p>
          <p v-if="counts.ready === 0" class="rounded-md bg-red-50 px-4 py-2 text-sm text-red-700">
            No row is ready to create. Fix the file and choose it again.
          </p>

          <div class="overflow-x-auto rounded-lg ring-1 ring-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="bg-slate-50">
                <tr>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Row</th>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">ID Number</th>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Email</th>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="filteredPreviewRows.length === 0">
                  <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500">No rows in this view.</td>
                </tr>
                <tr v-for="row in filteredPreviewRows" :key="row.row" :class="row.status === 'invalid' ? 'bg-red-50/40' : ''">
                  <td class="px-3 py-2 tabular-nums text-slate-500">{{ row.row }}</td>
                  <td class="min-w-36 px-3 py-2 text-slate-900">{{ fullName(row) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-slate-700">{{ row.student_id_number || '—' }}</td>
                  <td class="min-w-48 max-w-72 break-all px-3 py-2 text-slate-700">{{ row.email || '—' }}</td>
                  <td class="px-3 py-2">
                    <span v-if="row.status === 'ready'" class="whitespace-nowrap rounded-full bg-green-50 px-2 py-1 text-xs font-bold text-green-700">Ready</span>
                    <span v-else-if="row.status === 'existing'" class="whitespace-nowrap rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">Already has an account</span>
                    <ul v-else class="list-disc space-y-0.5 pl-4 text-xs text-red-700">
                      <li v-for="error in row.errors" :key="error">{{ error }}</li>
                    </ul>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Step 3: Results -->
        <div v-else class="space-y-4">
          <div v-if="resumeOffset !== null" class="space-y-2 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            <p>{{ message }}</p>
            <button
              type="button"
              class="rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="isConfirming"
              @click="resume"
            >
              {{ isConfirming ? 'Continuing...' : 'Continue with the remaining rows' }}
            </button>
          </div>

          <div v-if="isConfirming && progress" class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3" aria-live="polite">
            <p class="text-sm font-semibold text-blue-900">Creating the remaining accounts — {{ progress.done }} of {{ progress.total }} rows</p>
            <div class="mt-2 h-2 overflow-hidden rounded-full bg-blue-100">
              <div class="h-full rounded-full bg-blue-600 transition-all duration-500" :style="{ width: `${Math.max(progressPercent, 4)}%` }" />
            </div>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-600">
              Created <span class="font-semibold text-green-700">{{ createdCount }}</span>
              account{{ createdCount === 1 ? '' : 's' }} in <span class="font-semibold text-slate-900">{{ destinationLabel }}</span>.
            </p>
            <button
              v-if="hasPasswords"
              type="button"
              class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="downloadCredentials"
            >
              {{ credentialsDownloaded ? 'Download again (.csv)' : 'Download credentials (.csv)' }}
            </button>
          </div>

          <div
            v-if="outcomeCounts.created_email_failed > 0"
            class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            role="alert"
          >
            <p class="font-semibold">
              {{ outcomeCounts.created_email_failed }} student{{ outcomeCounts.created_email_failed === 1 ? ' was' : 's were' }} not emailed.
            </p>
            <p class="mt-1">
              Their passwords below are the only copy. Copy each one or download the credentials now. You can also issue a new
              password later from the Credential Manager in your profile menu.
            </p>
          </div>
          <p v-else-if="hasPasswords" class="rounded-md bg-slate-50 px-4 py-2 text-xs text-slate-600">
            Every new student was emailed. These passwords are shown only once; download a backup if you need one.
          </p>

          <div v-if="resultChips.length > 1" class="flex flex-wrap gap-2" role="group" aria-label="Filter results">
            <button
              type="button"
              class="rounded-full border px-3 py-1 text-xs font-semibold transition"
              :class="resultFilter === 'all' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
              :aria-pressed="resultFilter === 'all'"
              @click="resultFilter = 'all'"
            >
              All <span class="ml-1 tabular-nums text-slate-500">{{ results.length }}</span>
            </button>
            <button
              v-for="chip in resultChips"
              :key="chip.key"
              type="button"
              class="rounded-full border px-3 py-1 text-xs font-semibold transition"
              :class="resultFilter === chip.key ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
              :aria-pressed="resultFilter === chip.key"
              @click="resultFilter = chip.key"
            >
              {{ chip.label }} <span class="ml-1 tabular-nums text-slate-500">{{ chip.count }}</span>
            </button>
          </div>

          <div class="overflow-x-auto rounded-lg ring-1 ring-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
              <thead class="bg-slate-50">
                <tr>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Username</th>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Temporary Password</th>
                  <th class="whitespace-nowrap px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="filteredResults.length === 0">
                  <td colspan="4" class="px-3 py-6 text-center text-sm text-slate-500">No rows in this view.</td>
                </tr>
                <tr v-for="row in filteredResults" :key="row.row">
                  <td class="min-w-36 px-3 py-2 text-slate-900">{{ fullName(row) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-slate-700">{{ row.student_id_number || '—' }}</td>
                  <td class="px-3 py-2">
                    <div v-if="row.temporary_password" class="flex items-center gap-2">
                      <span class="select-all font-mono text-xs text-slate-900">{{ row.temporary_password }}</span>
                      <button
                        type="button"
                        class="shrink-0 text-xs font-semibold text-slate-500 transition hover:text-slate-800"
                        :aria-label="`Copy the password for ${fullName(row)}`"
                        @click="copyPassword(row)"
                      >
                        {{ copiedRow === row.row ? 'Copied' : 'Copy' }}
                      </button>
                    </div>
                    <span v-else class="text-slate-400">—</span>
                  </td>
                  <td class="px-3 py-2">
                    <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-bold" :class="OUTCOME_CLASSES[row.outcome]">{{ OUTCOME_LABELS[row.outcome] }}</span>
                    <!-- Why a row was skipped — the preview showed it, the results used to drop it. -->
                    <p v-if="row.outcome === 'skipped_invalid' && row.errors.length" class="mt-1 text-xs text-red-700">{{ row.errors.join(' ') }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="createdCount > 0" class="rounded-md border border-slate-200 px-4 py-3">
            <p class="text-sm font-semibold text-slate-900">What happens next</p>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-slate-600">
              <li>Each student signs in with their ID number and temporary password, then sets their own password.</li>
              <li>They complete and submit their Student Information Sheet, choosing their company.</li>
              <li>You review it and Accept it to enroll them in {{ selectedBatch?.name ?? 'the batch' }}.</li>
            </ol>
            <p class="mt-2 text-xs text-slate-500">
              Follow their progress in the Stage column of the Interns list, or open
              <RouterLink to="/coordinator/info-sheets" class="font-semibold text-blue-600 hover:text-blue-700">Student Info Sheets</RouterLink>
              to review what has been submitted.
            </p>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
        <template v-if="step === 'upload'">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="close">Cancel</button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-blue-300"
            :disabled="!canPreview || isPreviewing"
            @click="preview"
          >
            {{ isPreviewing ? 'Reading file...' : 'Review Rows' }}
          </button>
        </template>
        <template v-else-if="step === 'preview'">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="isConfirming"
            @click="backToUpload"
          >
            Back
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-blue-300"
            :disabled="counts.ready === 0 || isConfirming"
            @click="confirmImport"
          >
            {{
              isConfirming
                ? 'Creating...'
                : counts.ready === 0
                  ? 'Nothing to Create'
                  : `Create ${counts.ready} Account${counts.ready === 1 ? '' : 's'}`
            }}
          </button>
        </template>
        <template v-else>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-blue-300"
            :disabled="isConfirming"
            @click="close"
          >
            Done
          </button>
        </template>
      </div>
    </section>
  </div>
</template>

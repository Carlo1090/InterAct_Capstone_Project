<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import { categorizeError } from '@/lib/apiError'
import { confirmAction, showToast } from '@/lib/toast'
import { isNotEnrolledError } from '@/lib/enrollment'
import type { WeeklyActivityEntryRecord, WeeklyActivityLogRecord } from '@/types/api'

type LogDetail = WeeklyActivityLogRecord & {
  entries: WeeklyActivityEntryRecord[]
  header?: {
    student_name: string | null
    program_and_year: string | null
    faculty_adviser: string | null
    company_name: string | null
    supervisor_name: string | null
  }
}

/** `idle` = nothing to do · `pending` = debounce running · `saved` = confirmed by the server. */
type SaveStatus = 'idle' | 'pending' | 'saving' | 'saved' | 'error'

/** One editable row of the printed table. `id` is null while it is still a draft. */
type RowModel = {
  uid: number
  id: number | null
  inclusive_date_start: string
  inclusive_date_end: string
  activities: string
  documents_records: string
  objectives: string
  supervisor_name: string
  supervisor_position: string
  status: SaveStatus
  errors: Record<string, string[]>
}

/**
 * Auto-save debounce. Long enough that ordinary typing does not fire a request
 * per keystroke, short enough that a student who tabs away still sees "Saved".
 */
const SAVE_DEBOUNCE_MS = 800

const logs = ref<WeeklyActivityLogRecord[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const notEnrolled = ref(false)

const selectedId = ref<number | null>(null)
const detail = ref<LogDetail | null>(null)
const isLoadingDetail = ref(false)
const detailError = ref('')

const rows = ref<RowModel[]>([])
const headerStatus = ref<SaveStatus>('idle')

const showCreate = ref(false)
const createForm = reactive({ week_start: '', week_end: '', area_assigned: '', no_of_hours: '' })
const createErrors = ref<Record<string, string[]>>({})
const isCreating = ref(false)

const headerForm = reactive({ area_assigned: '', no_of_hours: '' })

// Debounce timers live outside the reactive models so the template never sees them.
const rowTimers = new Map<number, ReturnType<typeof setTimeout>>()
let headerTimer: ReturnType<typeof setTimeout> | null = null
let uidCounter = 0

/**
 * Dates come back as `date`-cast columns (midnight UTC) or bare Y-m-d. Slice,
 * never parse — parsing can land a day earlier under Asia/Manila. (PROJECT.md)
 */
const dateOnly = (raw: string | null | undefined): string => (raw ?? '').slice(0, 10)

const formatDate = (raw: string | null | undefined): string => {
  const iso = dateOnly(raw)
  if (!iso) return ''
  const [y, m, d] = iso.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const formatRange = (start: string | null | undefined, end: string | null | undefined): string => {
  const a = formatDate(start)
  const b = formatDate(end)
  if (!a && !b) return ''
  if (!a || !b) return a || b
  return `${a} - ${b}`
}

const pdfUrl = computed(() =>
  selectedId.value ? `/api/student/weekly-activity-logs/${selectedId.value}/pdf` : '',
)

const createFieldError = (field: string): string | undefined => createErrors.value[field]?.[0]

const blankRow = (): RowModel => ({
  uid: ++uidCounter,
  id: null,
  inclusive_date_start: '',
  inclusive_date_end: '',
  activities: '',
  documents_records: '',
  objectives: '',
  supervisor_name: '',
  supervisor_position: '',
  status: 'idle',
  errors: {},
})

const toRowModel = (entry: WeeklyActivityEntryRecord): RowModel => ({
  ...blankRow(),
  id: entry.id,
  inclusive_date_start: dateOnly(entry.inclusive_date_start),
  inclusive_date_end: dateOnly(entry.inclusive_date_end),
  activities: entry.activities ?? '',
  documents_records: entry.documents_records ?? '',
  objectives: entry.objectives ?? '',
  supervisor_name: entry.supervisor_name ?? '',
  supervisor_position: entry.supervisor_position ?? '',
})

/** A draft the student has not touched at all — never saved, nothing to lose. */
const isRowUntouched = (row: RowModel): boolean =>
  !row.inclusive_date_start && !row.inclusive_date_end &&
  !row.activities.trim() && !row.documents_records.trim() && !row.objectives.trim() &&
  !row.supervisor_name.trim() && !row.supervisor_position.trim()

/**
 * A draft row is created as soon as ANY cell has something in it.
 *
 * It used to require both dates plus the Activities text, because those three
 * columns were NOT NULL — so a half-typed row lived only in this browser tab
 * and vanished on logout with nothing on screen admitting it. The columns are
 * nullable now and the server only refuses a completely blank row, so the two
 * rules line up and nothing typed is left unsaved.
 */
const isRowCreatable = (row: RowModel): boolean => !isRowUntouched(row)

/** One page-level status, so the student always knows where their work stands. */
const saveState = computed<SaveStatus>(() => {
  const all = [...rows.value.map((r) => r.status), headerStatus.value]
  if (all.includes('error')) return 'error'
  if (all.includes('saving')) return 'saving'
  if (all.includes('pending')) return 'pending'
  if (all.includes('saved')) return 'saved'
  return 'idle'
})

const saveStateLabel = computed(() => ({
  idle: '',
  pending: 'Saving...',
  saving: 'Saving...',
  saved: 'All changes saved',
  error: 'Could not save — check the highlighted fields',
}[saveState.value]))

/**
 * Anything typed that the server has not confirmed yet — a debounce still
 * running, a request in flight, or a row whose last save failed. Used to warn
 * before the tab closes; see the beforeunload handler.
 */
const hasUnsavedWork = computed(
  () =>
    headerStatus.value === 'pending' ||
    headerStatus.value === 'saving' ||
    headerStatus.value === 'error' ||
    rows.value.some((row) => ['pending', 'saving', 'error'].includes(row.status)),
)

async function load() {
  isLoading.value = true
  errorMessage.value = ''
  notEnrolled.value = false
  try {
    const { data } = await api.get<WeeklyActivityLogRecord[]>('/api/student/weekly-activity-logs')
    logs.value = data
    if (data.length && selectedId.value === null) {
      await openLog(data[0].id)
    }
  } catch (error) {
    if (isNotEnrolledError(error)) {
      notEnrolled.value = true
    } else {
      errorMessage.value = categorizeError(error, 'Could not load your weekly activity logs.').message
    }
  } finally {
    isLoading.value = false
  }
}

async function refreshSheetList() {
  const { data } = await api.get<WeeklyActivityLogRecord[]>('/api/student/weekly-activity-logs')
  logs.value = data
}

async function openLog(id: number) {
  selectedId.value = id
  isLoadingDetail.value = true
  detailError.value = ''
  headerStatus.value = 'idle'
  rowTimers.forEach(clearTimeout)
  rowTimers.clear()
  try {
    const { data } = await api.get<LogDetail>(`/api/student/weekly-activity-logs/${id}`)
    detail.value = { ...data, entries: data.entries ?? [] }
    headerForm.area_assigned = data.area_assigned ?? ''
    headerForm.no_of_hours = data.no_of_hours === null ? '' : String(data.no_of_hours)
    // Always leave one blank row at the bottom so the template stays typeable.
    rows.value = [...(data.entries ?? []).map(toRowModel), blankRow()]
  } catch (error) {
    detailError.value = categorizeError(error, 'Could not load this log sheet.').message
  } finally {
    isLoadingDetail.value = false
  }
}

async function createLog() {
  isCreating.value = true
  createErrors.value = {}
  try {
    const { data } = await api.post<WeeklyActivityLogRecord>('/api/student/weekly-activity-logs', {
      week_start: createForm.week_start,
      week_end: createForm.week_end,
      area_assigned: createForm.area_assigned || null,
      no_of_hours: createForm.no_of_hours === '' ? null : Number(createForm.no_of_hours),
    })
    showToast('Log sheet created. Start typing in the template below.', 'success')
    showCreate.value = false
    Object.assign(createForm, { week_start: '', week_end: '', area_assigned: '', no_of_hours: '' })
    await refreshSheetList()
    await openLog(data.id)
  } catch (error) {
    const info = categorizeError(error, 'Could not create the log sheet.')
    createErrors.value = info.fieldErrors ?? {}
    if (info.kind !== 'validation') showToast(info.message, 'error')
  } finally {
    isCreating.value = false
  }
}

// ---------------------------------------------------------------- auto-save

/** Area Assigned / No. of hours — both nullable, so any edit can be saved as-is. */
function touchHeader() {
  headerStatus.value = 'pending'
  if (headerTimer) clearTimeout(headerTimer)
  headerTimer = setTimeout(flushHeader, SAVE_DEBOUNCE_MS)
}

async function flushHeader() {
  if (!selectedId.value) return
  headerTimer = null
  headerStatus.value = 'saving'
  try {
    await api.put(`/api/student/weekly-activity-logs/${selectedId.value}`, {
      area_assigned: headerForm.area_assigned || null,
      no_of_hours: headerForm.no_of_hours === '' ? null : Number(headerForm.no_of_hours),
    })
    headerStatus.value = 'saved'
    await refreshSheetList()
  } catch (error) {
    headerStatus.value = 'error'
    showToast(categorizeError(error, 'Could not save the form details.').message, 'error')
  }
}

function touchRow(row: RowModel) {
  row.status = 'pending'
  const existing = rowTimers.get(row.uid)
  if (existing) clearTimeout(existing)
  rowTimers.set(row.uid, setTimeout(() => flushRow(row), SAVE_DEBOUNCE_MS))
}

async function flushRow(row: RowModel) {
  rowTimers.delete(row.uid)

  if (!selectedId.value) return

  // A draft with nothing in it is not an edit; and a partial draft cannot be
  // created yet because the server requires both dates plus activities.
  if (row.id === null && !isRowCreatable(row)) {
    row.status = 'idle'
    return
  }

  // Guard against a second flush racing the first and POSTing a duplicate row.
  if (row.status === 'saving') {
    touchRow(row)
    return
  }

  row.status = 'saving'
  row.errors = {}

  // Empty means "not filled in yet", not an empty string — a bare '' would
  // fail the `date` rule and reject the whole row over a cell the student has
  // simply not reached.
  const payload = {
    inclusive_date_start: row.inclusive_date_start || null,
    inclusive_date_end: row.inclusive_date_end || null,
    activities: row.activities || null,
    documents_records: row.documents_records || null,
    objectives: row.objectives || null,
    supervisor_name: row.supervisor_name || null,
    supervisor_position: row.supervisor_position || null,
  }

  try {
    if (row.id === null) {
      const { data } = await api.post<WeeklyActivityEntryRecord>(
        `/api/student/weekly-activity-logs/${selectedId.value}/entries`,
        payload,
      )
      row.id = data.id
      // The bottom row just became real — open a fresh one beneath it.
      if (!rows.value.some((r) => r.id === null)) rows.value.push(blankRow())
    } else {
      await api.put(`/api/student/weekly-activity-logs/${selectedId.value}/entries/${row.id}`, payload)
    }
    row.status = 'saved'
  } catch (error) {
    const info = categorizeError(error, 'Could not save this row.')
    row.errors = info.fieldErrors ?? {}
    row.status = 'error'
    if (info.kind !== 'validation') showToast(info.message, 'error')
  }
}

/** Commit anything still inside its debounce window before the view goes away. */
function flushPending() {
  if (headerTimer) {
    clearTimeout(headerTimer)
    void flushHeader()
  }
  rows.value.forEach((row) => {
    const timer = rowTimers.get(row.uid)
    if (timer) {
      clearTimeout(timer)
      void flushRow(row)
    }
  })
}

function addRow() {
  rows.value.push(blankRow())
}

async function deleteRow(row: RowModel, index: number) {
  // An untouched or never-saved draft is discarded locally — nothing to confirm.
  if (row.id === null) {
    const timer = rowTimers.get(row.uid)
    if (timer) clearTimeout(timer)
    rowTimers.delete(row.uid)
    rows.value.splice(index, 1)
    if (!rows.value.some((r) => r.id === null)) rows.value.push(blankRow())
    return
  }
  if (!selectedId.value) return

  const ok = await confirmAction({
    title: 'Delete this row?',
    message: 'This row will be removed from your Weekly Activity Log. This cannot be undone.',
    confirmLabel: 'Delete Row',
    tone: 'danger',
  })
  if (!ok) return

  const timer = rowTimers.get(row.uid)
  if (timer) clearTimeout(timer)
  rowTimers.delete(row.uid)
  row.status = 'saving'

  try {
    await api.delete(`/api/student/weekly-activity-logs/${selectedId.value}/entries/${row.id}`)
    rows.value.splice(index, 1)
    if (!rows.value.some((r) => r.id === null)) rows.value.push(blankRow())
    showToast('Row deleted.', 'success')
  } catch (error) {
    row.status = 'error'
    showToast(categorizeError(error, 'Could not delete this row.').message, 'error')
  }
}

/**
 * Two extra safety nets over the unmount flush, both aimed at the same failure:
 * work sitting inside an 800ms debounce when the student leaves.
 *
 * `visibilitychange` fires when the tab is backgrounded or the phone is
 * locked — the point at which a browser is free to freeze or discard the page —
 * and unlike `beforeunload` it is reliable on mobile. `beforeunload` then
 * covers a hard close or reload, and only speaks up when there is genuinely
 * something unconfirmed: a prompt on every navigation would be noise, and
 * students would learn to click through it.
 */
function onVisibilityChange() {
  if (document.visibilityState === 'hidden') flushPending()
}

function onBeforeUnload(event: BeforeUnloadEvent) {
  flushPending()
  if (!hasUnsavedWork.value) return
  event.preventDefault()
  // Legacy browsers need returnValue set; the string itself is never shown.
  event.returnValue = ''
}

onMounted(() => {
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.addEventListener('beforeunload', onBeforeUnload)
  void load()
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange)
  window.removeEventListener('beforeunload', onBeforeUnload)
  flushPending()
})
</script>

<template>
  <div class="space-y-6">
    <ToastHost />

    <NotEnrolledNotice v-if="notEnrolled" />

    <template v-else>
      <p class="text-sm text-slate-500">
        The official MDC Weekly Activity Log and Time Log Summary. Type straight into
        the template — your work saves automatically — then download it as a PDF
        matching the printed form.
      </p>

      <LoadStatus :loading="isLoading" :error="errorMessage" :retry="load">
        <!-- ---------- Sheet picker ---------- -->
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 class="text-sm font-semibold text-slate-900">Log Sheets</h2>
              <p class="mt-1 text-xs text-slate-500">One sheet per period covered.</p>
            </div>
            <button
              type="button"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:grayscale disabled:cursor-not-allowed"
              @click="showCreate = !showCreate"
            >
              {{ showCreate ? 'Cancel' : 'New Log Sheet' }}
            </button>
          </div>

          <form
            v-if="showCreate"
            class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 xl:grid-cols-4"
            @submit.prevent="createLog"
          >
            <label class="block">
              <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Period Covered — From</span>
              <input v-model="createForm.week_start" type="date" required class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <span v-if="createFieldError('week_start')" class="mt-1 block text-xs text-red-600">{{ createFieldError('week_start') }}</span>
            </label>
            <label class="block">
              <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Period Covered — To</span>
              <input v-model="createForm.week_end" type="date" required :min="createForm.week_start" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <span v-if="createFieldError('week_end')" class="mt-1 block text-xs text-red-600">{{ createFieldError('week_end') }}</span>
            </label>
            <label class="block">
              <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Area Assigned</span>
              <input v-model="createForm.area_assigned" type="text" maxlength="150" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-medium uppercase tracking-wide text-slate-400">No. of hours</span>
              <input v-model="createForm.no_of_hours" type="number" step="0.5" min="0" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <span v-if="createFieldError('no_of_hours')" class="mt-1 block text-xs text-red-600">{{ createFieldError('no_of_hours') }}</span>
            </label>
            <div class="sm:col-span-2 xl:col-span-4">
              <button
                type="submit"
                :disabled="isCreating"
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:grayscale disabled:cursor-not-allowed"
              >
                {{ isCreating ? 'Creating...' : 'Create Log Sheet' }}
              </button>
            </div>
          </form>

          <div v-if="logs.length" class="mt-4 flex flex-wrap gap-2">
            <button
              v-for="log in logs"
              :key="log.id"
              type="button"
              class="rounded-full border px-4 py-1.5 text-sm transition"
              :class="log.id === selectedId
                ? 'border-blue-600 bg-blue-50 font-medium text-blue-700'
                : 'border-slate-300 text-slate-600 hover:bg-slate-50'"
              @click="openLog(log.id)"
            >
              {{ formatRange(log.week_start, log.week_end) }}
            </button>
          </div>
          <p v-else-if="!showCreate" class="mt-4 text-sm text-slate-500">
            No log sheets yet. Create one to start filling in the template.
          </p>
        </div>

        <!-- ---------- Selected sheet ---------- -->
        <LoadStatus
          v-if="selectedId"
          :loading="isLoadingDetail"
          :error="detailError"
          :retry="() => openLog(selectedId!)"
        >
          <div v-if="detail" class="space-y-6">
            <!-- Form header block, mirroring the printed form -->
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-900">Form Details</h2>
                <a
                  :href="pdfUrl"
                  target="_blank"
                  rel="noopener"
                  class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                    <path d="M12 4v10m0 0 3.5-3.5M12 14l-3.5-3.5M5 17.5v1A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5v-1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  Download PDF
                </a>
              </div>

              <dl class="mt-4 grid gap-x-6 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Name of Student Intern</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header?.student_name || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Program and Year</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header?.program_and_year || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Faculty Adviser</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header?.faculty_adviser || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Name of Company</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header?.company_name || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Name of Supervisor</dt>
                  <dd class="text-sm text-slate-800">{{ detail.header?.supervisor_name || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Period Covered</dt>
                  <dd class="text-sm text-slate-800">{{ formatRange(detail.week_start, detail.week_end) }}</dd>
                </div>
              </dl>

              <div class="mt-5 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:max-w-2xl">
                <label class="block">
                  <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Area Assigned</span>
                  <input
                    v-model="headerForm.area_assigned"
                    type="text"
                    maxlength="150"
                    placeholder="e.g. Accounting Office"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                    @input="touchHeader"
                  />
                </label>
                <label class="block">
                  <span class="text-xs font-medium uppercase tracking-wide text-slate-400">No. of hours</span>
                  <input
                    v-model="headerForm.no_of_hours"
                    type="number"
                    step="0.5"
                    min="0"
                    placeholder="e.g. 40"
                    class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                    @input="touchHeader"
                  />
                </label>
              </div>
            </div>

            <!-- ---------- The template itself ---------- -->
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200/70">
              <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-6 py-4">
                <div>
                  <h2 class="text-sm font-semibold text-slate-900">Activity Log Template</h2>
                  <p class="mt-1 text-xs text-slate-500">
                    One row per inclusive date range. Everything saves automatically as you type.
                  </p>
                </div>

                <!-- Live save indicator -->
                <p
                  v-if="saveStateLabel"
                  class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium"
                  :class="{
                    'bg-amber-50 text-amber-700': saveState === 'pending' || saveState === 'saving',
                    'bg-emerald-50 text-emerald-700': saveState === 'saved',
                    'bg-red-50 text-red-700': saveState === 'error',
                  }"
                  aria-live="polite"
                >
                  <svg v-if="saveState === 'pending' || saveState === 'saving'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-3.5 w-3.5 animate-spin">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" stroke-opacity="0.25" />
                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
                  </svg>
                  <svg v-else-if="saveState === 'saved'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-3.5 w-3.5">
                    <path d="m5 12.5 4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                  <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-3.5 w-3.5">
                    <path d="M12 7.5v5.5M12 16.5h.01" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" />
                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.6" />
                  </svg>
                  {{ saveStateLabel }}
                </p>
              </div>

              <p class="px-6 pt-4 text-xs text-slate-400 sm:hidden">
                ↔ This table is wide — scroll sideways to see every column.
              </p>
              <div class="overflow-x-auto px-2 py-5 sm:px-6">
                <table class="w-full min-w-248 table-fixed border-collapse text-sm">
                  <colgroup>
                    <col class="w-10" />
                    <col class="w-40" />
                    <col class="w-64" />
                    <col class="w-48" />
                    <col class="w-48" />
                    <col class="w-48" />
                    <col class="w-14" />
                  </colgroup>
                  <thead>
                    <tr class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600">
                      <th class="border border-slate-300 px-2 py-2.5 text-center">#</th>
                      <th class="border border-slate-300 px-2 py-2.5 text-center">Inclusive Dates</th>
                      <th class="border border-slate-300 px-2 py-2.5 text-center">Activities</th>
                      <th class="border border-slate-300 px-2 py-2.5 text-center">Document/Records</th>
                      <th class="border border-slate-300 px-2 py-2.5 text-center">Objective/s</th>
                      <th class="border border-slate-300 px-2 py-2.5 text-center">Supervisor's name,<br />position, and Signature</th>
                      <th class="border border-slate-300 px-2 py-2.5 text-center"><span class="sr-only">Delete row</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(row, index) in rows"
                      :key="row.uid"
                      class="align-top transition-colors"
                      :class="{
                        'bg-red-50/40': row.status === 'error',
                        'bg-slate-50/40': row.id === null,
                      }"
                    >
                      <!-- Row number / status -->
                      <td class="border border-slate-300 px-1 py-2 text-center">
                        <span class="text-xs font-medium text-slate-400">{{ index + 1 }}</span>
                        <span
                          v-if="row.status === 'saved'"
                          class="mt-1 block text-[10px] font-medium text-emerald-600"
                        >saved</span>
                        <span
                          v-else-if="row.status === 'pending' || row.status === 'saving'"
                          class="mt-1 block text-[10px] font-medium text-amber-600"
                        >saving</span>
                      </td>

                      <!-- Inclusive Dates: explicitly labelled From / To -->
                      <td class="border border-slate-300 p-2">
                        <label class="block">
                          <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">From</span>
                          <input
                            v-model="row.inclusive_date_start"
                            type="date"
                            class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-xs"
                            :class="row.errors.inclusive_date_start && 'border-red-400'"
                            @input="touchRow(row)"
                          />
                        </label>
                        <label class="mt-1.5 block">
                          <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">To</span>
                          <input
                            v-model="row.inclusive_date_end"
                            type="date"
                            :min="row.inclusive_date_start"
                            class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-xs"
                            :class="row.errors.inclusive_date_end && 'border-red-400'"
                            @input="touchRow(row)"
                          />
                        </label>
                        <p v-if="row.errors.inclusive_date_start" class="mt-1 text-[11px] text-red-600">
                          {{ row.errors.inclusive_date_start[0] }}
                        </p>
                        <p v-if="row.errors.inclusive_date_end" class="mt-1 text-[11px] text-red-600">
                          {{ row.errors.inclusive_date_end[0] }}
                        </p>
                      </td>

                      <!-- Activities -->
                      <td class="border border-slate-300 p-2">
                        <textarea
                          v-model="row.activities"
                          rows="6"
                          placeholder="What you did during this period"
                          class="w-full resize-y rounded border border-slate-200 px-2 py-1.5 text-xs leading-relaxed"
                          :class="row.errors.activities && 'border-red-400'"
                          @input="touchRow(row)"
                        ></textarea>
                        <p v-if="row.errors.activities" class="mt-1 text-[11px] text-red-600">
                          {{ row.errors.activities[0] }}
                        </p>
                      </td>

                      <!-- Document/Records -->
                      <td class="border border-slate-300 p-2">
                        <textarea
                          v-model="row.documents_records"
                          rows="6"
                          placeholder="Documents or records handled"
                          class="w-full resize-y rounded border border-slate-200 px-2 py-1.5 text-xs leading-relaxed"
                          @input="touchRow(row)"
                        ></textarea>
                      </td>

                      <!-- Objective/s -->
                      <td class="border border-slate-300 p-2">
                        <textarea
                          v-model="row.objectives"
                          rows="6"
                          placeholder="Objective of the task"
                          class="w-full resize-y rounded border border-slate-200 px-2 py-1.5 text-xs leading-relaxed"
                          @input="touchRow(row)"
                        ></textarea>
                      </td>

                      <!-- Supervisor -->
                      <td class="border border-slate-300 p-2">
                        <label class="block">
                          <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Name</span>
                          <input
                            v-model="row.supervisor_name"
                            type="text"
                            maxlength="150"
                            class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-xs"
                            @input="touchRow(row)"
                          />
                        </label>
                        <label class="mt-1.5 block">
                          <span class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Position</span>
                          <input
                            v-model="row.supervisor_position"
                            type="text"
                            maxlength="100"
                            class="mt-0.5 w-full rounded border border-slate-200 px-2 py-1 text-xs"
                            @input="touchRow(row)"
                          />
                        </label>
                        <p class="mt-1.5 text-[10px] leading-snug text-slate-400">
                          Signed by hand on the printed copy.
                        </p>
                      </td>

                      <!-- Delete only -->
                      <td class="border border-slate-300 px-1 py-2 text-center">
                        <TooltipWrap label="Delete row" placement="top">
                          <button
                            type="button"
                            aria-label="Delete row"
                            class="rounded-md p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                            @click="deleteRow(row, index)"
                          >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                              <path d="M5 7h14M10 7V5.5A1.5 1.5 0 0 1 11.5 4h1A1.5 1.5 0 0 1 14 5.5V7m-7 0 .8 11a1.5 1.5 0 0 0 1.5 1.4h5.4a1.5 1.5 0 0 0 1.5-1.4L18 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                          </button>
                        </TooltipWrap>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-4">
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                  @click="addRow"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
                    <path d="M12 6v12M6 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                  </svg>
                  Add Another Row
                </button>
                <p class="text-xs text-slate-500">
                  Rows save on their own as you type — even half-filled ones. Nothing here is lost if you log out.
                </p>
              </div>
            </div>
          </div>
        </LoadStatus>
      </LoadStatus>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import ToastHost from '@/components/ToastHost.vue'
import { confirmAction, showToast } from '@/lib/toast'
import { isNotEnrolledError } from '@/lib/enrollment'
import type { WeeklyActivityEntryRecord, WeeklyActivityLogRecord, WeeklyLogDetail, WeeklyLogSummary } from '@/types/api'

const weeks = ref<WeeklyLogSummary[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const notEnrolled = ref(false)

const details = reactive<Record<string, WeeklyLogDetail>>({})
const loadingDetail = reactive<Record<string, boolean>>({})
const savingDetail = reactive<Record<string, boolean>>({})
const saveMessage = reactive<Record<string, string>>({})

const loadWeeks = async () => {
  isLoading.value = true
  errorMessage.value = ''
  notEnrolled.value = false

  try {
    const { data } = await api.get<{ weeks: WeeklyLogSummary[] }>('/api/student/weekly-logs')
    weeks.value = data.weeks
  } catch (error) {
    if (isNotEnrolledError(error)) {
      notEnrolled.value = true
    } else {
      errorMessage.value = 'Unable to load your weekly journals.'
    }
  } finally {
    isLoading.value = false
  }
}

const loadDetail = async (weekStart: string) => {
  if (details[weekStart] || loadingDetail[weekStart]) {
    return
  }

  loadingDetail[weekStart] = true

  try {
    const { data } = await api.get<WeeklyLogDetail>(`/api/student/weekly-logs/${weekStart}`)
    details[weekStart] = data
  } catch {
    saveMessage[weekStart] = 'Unable to load this week.'
  } finally {
    loadingDetail[weekStart] = false
  }
}

const downloadWeeklyLogPdf = (weekStart: string) => {
  window.open(`/api/student/weekly-logs/${weekStart}/pdf`, '_blank')
}

const saveNarrative = async (weekStart: string) => {
  const detail = details[weekStart]
  if (!detail) {
    return
  }

  savingDetail[weekStart] = true
  saveMessage[weekStart] = ''

  try {
    await api.post('/api/student/weekly-logs', {
      week_start: weekStart,
      narrative: detail.narrative,
    })
    saveMessage[weekStart] = 'Saved.'
    await loadWeeks()
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    saveMessage[weekStart] = data?.message ?? 'Unable to save.'
  } finally {
    savingDetail[weekStart] = false
  }
}

const activityLogs = ref<WeeklyActivityLogRecord[]>([])
const activityLogLoading = reactive<Record<string, boolean>>({})
const entrySaving = reactive<Record<number, boolean>>({})
const newEntryForms = reactive<Record<number, {
  inclusive_date_start: string
  inclusive_date_end: string
  activities: string
  documents_records: string
  objectives: string
  supervisor_name: string
  supervisor_position: string
}>>({})

const loadActivityLogs = async () => {
  try {
    const { data } = await api.get<WeeklyActivityLogRecord[]>('/api/student/weekly-activity-logs')
    activityLogs.value = data
  } catch {
    // Section falls back to "create" state if this fails; not critical to page load.
  }
}

const weeksWithActivityLog = computed(() =>
  weeks.value.map((week) => ({
    ...week,
    activityLog: activityLogs.value.find((log) => log.week_start === week.week_start) ?? null,
  })),
)

const createActivityLog = async (week: WeeklyLogSummary) => {
  activityLogLoading[week.week_start] = true

  try {
    const { data } = await api.post<WeeklyActivityLogRecord>('/api/student/weekly-activity-logs', {
      week_start: week.week_start,
      week_end: week.week_end,
    })
    activityLogs.value.push({ ...data, entries: [] })
  } catch {
    saveMessage[week.week_start] = 'Unable to create weekly activity log.'
  } finally {
    activityLogLoading[week.week_start] = false
  }
}

const ensureEntryForm = (logId: number) => {
  if (!newEntryForms[logId]) {
    newEntryForms[logId] = {
      inclusive_date_start: '',
      inclusive_date_end: '',
      activities: '',
      documents_records: '',
      objectives: '',
      supervisor_name: '',
      supervisor_position: '',
    }
  }

  return newEntryForms[logId]
}

const addEntry = async (log: WeeklyActivityLogRecord) => {
  const form = ensureEntryForm(log.id)
  entrySaving[log.id] = true

  try {
    const { data } = await api.post<WeeklyActivityEntryRecord>(`/api/student/weekly-activity-logs/${log.id}/entries`, form)
    log.entries = [...(log.entries ?? []), data]
    newEntryForms[log.id] = {
      inclusive_date_start: '',
      inclusive_date_end: '',
      activities: '',
      documents_records: '',
      objectives: '',
      supervisor_name: '',
      supervisor_position: '',
    }
  } catch {
    // Keep the form values in place so the student can fix and retry.
  } finally {
    entrySaving[log.id] = false
  }
}

const removeEntry = async (log: WeeklyActivityLogRecord, entryId: number) => {
  if (!confirmAction('Remove this entry from your Weekly Activity Log? This cannot be undone.')) return

  try {
    await api.delete(`/api/student/weekly-activity-logs/${log.id}/entries/${entryId}`)
    log.entries = (log.entries ?? []).filter((entry) => entry.id !== entryId)
    showToast('Entry removed.')
  } catch {
    showToast('Unable to remove this entry.', 'error')
  }
}

const downloadPdf = (logId: number) => {
  window.open(`/api/student/weekly-activity-logs/${logId}/pdf`, '_blank')
}

const statusLabel = (status: WeeklyLogSummary['status']): string => {
  if (status === 'approved') return 'Approved by Supervisor'
  if (status === 'returned') return 'Returned for Revision'
  return 'Pending Supervisor'
}

const statusClass = (status: WeeklyLogSummary['status']): string => {
  if (status === 'approved') return 'bg-green-50 text-green-700'
  if (status === 'returned') return 'bg-amber-50 text-amber-700'
  return 'bg-blue-50 text-blue-700'
}

onMounted(() => {
  loadWeeks()
  loadActivityLogs()
})
</script>

<template>
  <section class="space-y-4">
    <ToastHost />
    <div class="flex items-start justify-between gap-4 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      <p>Write a short narrative for each week alongside your daily entries. Approval happens with your company supervisor.</p>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <NotEnrolledNotice v-else-if="notEnrolled" />
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>
    <p v-else-if="weeks.length === 0" class="text-sm text-slate-500">No weeks found in your OJT range yet.</p>

    <details
      v-for="week in weeksWithActivityLog"
      :key="week.week_start"
      class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200"
      @toggle="loadDetail(week.week_start)"
    >
      <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50">
        <div>
          <h2 class="text-sm font-bold text-slate-900">{{ week.week_start }} to {{ week.week_end }}</h2>
          <p class="mt-1 text-xs text-slate-500">{{ week.entries_count }} daily entries</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(week.status)">
          {{ statusLabel(week.status) }}
        </span>
      </summary>

      <div class="border-t border-slate-100 p-5">
        <p v-if="loadingDetail[week.week_start]" class="text-sm text-slate-500">Loading...</p>

        <template v-else-if="details[week.week_start]">
          <div>
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Daily Entries (Reference)</h3>
            <table class="mt-2 min-w-full divide-y divide-slate-200">
              <thead>
                <tr>
                  <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Date</th>
                  <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
                  <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Summary</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-if="details[week.week_start].daily_entries.length === 0">
                  <td colspan="3" class="py-3 text-sm text-slate-400">No daily entries this week.</td>
                </tr>
                <tr v-for="entry in details[week.week_start].daily_entries" :key="entry.entry_date">
                  <td class="py-3 font-mono text-sm text-slate-600">{{ entry.entry_date }}</td>
                  <td class="py-3 text-sm capitalize text-slate-600">{{ entry.status }}</td>
                  <td class="py-3 text-sm text-slate-800">{{ Object.values(entry.content)[0] ?? '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mt-5">
            <label class="block text-sm font-medium text-slate-700">
              Weekly Narrative
              <textarea
                v-model="details[week.week_start].narrative"
                :disabled="week.status === 'approved'"
                class="mt-2 min-h-32 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
              />
            </label>
          </div>

          <div class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">SIPP Notes (Read-Only)</h3>
            <p class="mt-1 text-xs text-slate-400">
              Compiled from this week's daily journal entries. Captured in the daily journal, not the weekly narrative.
            </p>

            <p v-if="details[week.week_start].sipp_notes.length === 0" class="mt-3 text-sm text-slate-400">
              No SIPP notes recorded this week.
            </p>

            <div v-else class="mt-3 space-y-3">
              <div v-for="day in details[week.week_start].sipp_notes" :key="day.entry_date" class="rounded-md border border-slate-200 bg-white p-3">
                <p class="text-xs font-mono font-semibold text-slate-500">{{ day.entry_date }}</p>
                <div class="mt-2 space-y-2">
                  <div v-for="field in day.fields" :key="field.key">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ field.label }}</p>
                    <p class="mt-1 text-sm text-slate-700">{{ field.text }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="week.status === 'returned' && week.supervisor_comment" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Supervisor's note: {{ week.supervisor_comment }}
          </div>

          <div class="mt-5 rounded-md border border-slate-200 p-4">
            <div class="flex items-center justify-between">
              <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Weekly Activity Log (SIPP)</h3>
              <button
                v-if="week.activityLog"
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                @click="downloadPdf(week.activityLog.id)"
              >
                Download PDF
              </button>
            </div>

            <div v-if="!week.activityLog" class="mt-3">
              <button
                type="button"
                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                :disabled="activityLogLoading[week.week_start]"
                @click="createActivityLog(week)"
              >
                {{ activityLogLoading[week.week_start] ? 'Creating...' : 'Create Weekly Activity Log' }}
              </button>
            </div>

            <template v-else>
              <table class="mt-3 min-w-full divide-y divide-slate-200">
                <thead>
                  <tr>
                    <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Dates</th>
                    <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Activities</th>
                    <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Documents</th>
                    <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Objectives</th>
                    <th class="py-2"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-if="!week.activityLog.entries?.length">
                    <td colspan="5" class="py-3 text-sm text-slate-400">No rows yet.</td>
                  </tr>
                  <tr v-for="entry in week.activityLog.entries" :key="entry.id">
                    <td class="py-2 font-mono text-xs text-slate-600">{{ entry.inclusive_date_start }} to {{ entry.inclusive_date_end }}</td>
                    <td class="py-2 text-sm text-slate-700">{{ entry.activities }}</td>
                    <td class="py-2 text-sm text-slate-500">{{ entry.documents_records }}</td>
                    <td class="py-2 text-sm text-slate-500">{{ entry.objectives }}</td>
                    <td class="py-2 text-right">
                      <button type="button" class="text-xs font-semibold text-red-600" @click="removeEntry(week.activityLog, entry.id)">
                        Remove
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>

              <div class="mt-3 grid gap-2 md:grid-cols-2">
                <input
                  v-model="ensureEntryForm(week.activityLog.id).inclusive_date_start"
                  type="date"
                  class="rounded-md border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                  v-model="ensureEntryForm(week.activityLog.id).inclusive_date_end"
                  type="date"
                  class="rounded-md border border-slate-300 px-3 py-2 text-sm"
                />
                <textarea
                  v-model="ensureEntryForm(week.activityLog.id).activities"
                  placeholder="Activities"
                  class="rounded-md border border-slate-300 px-3 py-2 text-sm md:col-span-2"
                />
                <input
                  v-model="ensureEntryForm(week.activityLog.id).supervisor_name"
                  placeholder="Supervisor name"
                  class="rounded-md border border-slate-300 px-3 py-2 text-sm"
                />
                <input
                  v-model="ensureEntryForm(week.activityLog.id).supervisor_position"
                  placeholder="Supervisor position"
                  class="rounded-md border border-slate-300 px-3 py-2 text-sm"
                />
              </div>
              <button
                type="button"
                class="mt-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 disabled:opacity-50"
                :disabled="entrySaving[week.activityLog.id]"
                @click="addEntry(week.activityLog)"
              >
                + Add Row
              </button>
            </template>
          </div>

          <div class="mt-4 flex items-center justify-end gap-3">
            <span v-if="saveMessage[week.week_start]" class="text-sm text-slate-500">{{ saveMessage[week.week_start] }}</span>
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700"
              @click="downloadWeeklyLogPdf(week.week_start)"
            >
              Download PDF
            </button>
            <button
              type="button"
              class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
              :disabled="savingDetail[week.week_start] || week.status === 'approved'"
              @click="saveNarrative(week.week_start)"
            >
              {{ savingDetail[week.week_start] ? 'Saving...' : 'Save Narrative' }}
            </button>
          </div>
        </template>
      </div>
    </details>
  </section>
</template>

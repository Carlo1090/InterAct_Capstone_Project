<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { WeeklyLogDetail, WeeklyLogSummary } from '@/types/api'

const weeks = ref<WeeklyLogSummary[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const details = reactive<Record<string, WeeklyLogDetail>>({})
const loadingDetail = reactive<Record<string, boolean>>({})
const savingDetail = reactive<Record<string, boolean>>({})
const saveMessage = reactive<Record<string, string>>({})

const loadWeeks = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<{ weeks: WeeklyLogSummary[] }>('/api/student/weekly-logs')
    weeks.value = data.weeks
  } catch {
    errorMessage.value = 'Unable to load your weekly journals.'
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
      issues_concerns: detail.issues_concerns,
      solutions: detail.solutions,
      recommendations: detail.recommendations,
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

onMounted(loadWeeks)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-start justify-between gap-4 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      <p>Write a short narrative for each week alongside your daily entries. Approval happens with your company supervisor.</p>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>
    <p v-else-if="weeks.length === 0" class="text-sm text-slate-500">No weeks found in your OJT range yet.</p>

    <details
      v-for="week in weeks"
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

          <details class="mt-4 rounded-md border border-slate-200 bg-slate-50 p-3">
            <summary class="cursor-pointer text-xs font-bold uppercase tracking-wide text-slate-500">Optional SIPP Notes</summary>
            <div class="mt-3 space-y-3">
              <label class="block text-sm font-medium text-slate-700">
                Issues / Concerns
                <textarea v-model="details[week.week_start].issues_concerns" class="mt-2 min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="block text-sm font-medium text-slate-700">
                Solutions
                <textarea v-model="details[week.week_start].solutions" class="mt-2 min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </label>
              <label class="block text-sm font-medium text-slate-700">
                Recommendations
                <textarea v-model="details[week.week_start].recommendations" class="mt-2 min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </label>
            </div>
          </details>

          <div v-if="week.status === 'returned' && week.supervisor_comment" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Supervisor's note: {{ week.supervisor_comment }}
          </div>

          <div class="mt-4 flex items-center justify-end gap-3">
            <span v-if="saveMessage[week.week_start]" class="text-sm text-slate-500">{{ saveMessage[week.week_start] }}</span>
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

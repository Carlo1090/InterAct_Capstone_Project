<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { showToast } from '@/lib/toast'
import type { ReminderPreferences } from '@/types/api'

// ISO day numbers, so Monday is 1 and Sunday is 7 — matching what the API
// stores and what Carbon's dayOfWeekIso returns on the backend.
const DAYS = [
  { iso: 1, short: 'Mon' },
  { iso: 2, short: 'Tue' },
  { iso: 3, short: 'Wed' },
  { iso: 4, short: 'Thu' },
  { iso: 5, short: 'Fri' },
  { iso: 6, short: 'Sat' },
  { iso: 7, short: 'Sun' },
]

const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')

const enabled = ref(true)
const selectedDays = ref<number[]>([])
const usingDefaultDays = ref(true)
const time = ref('')
const defaults = ref<ReminderPreferences['defaults']>({ days: [], time: '21:00' })

const defaultDayLabel = computed(() =>
  defaults.value.days.length
    ? DAYS.filter((day) => defaults.value.days.includes(day.iso)).map((day) => day.short).join(', ')
    : 'none',
)

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<ReminderPreferences>('/api/student/reminder-preferences')

    enabled.value = data.reminder_enabled
    defaults.value = data.defaults
    usingDefaultDays.value = data.reminder_days === null
    // Pre-fill the checkboxes with whatever is in force, so switching off
    // "follow my batch" starts from the days that were already being used.
    selectedDays.value = [...(data.reminder_days ?? data.defaults.days)]
    time.value = data.reminder_time ?? ''
  } catch (error) {
    errorMessage.value = categorizeError(error, 'Could not load your reminder settings.').message
  } finally {
    isLoading.value = false
  }
}

const toggleDay = (iso: number) => {
  selectedDays.value = selectedDays.value.includes(iso)
    ? selectedDays.value.filter((day) => day !== iso)
    : [...selectedDays.value, iso]
}

const save = async () => {
  isSaving.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.put<ReminderPreferences>('/api/student/reminder-preferences', {
      reminder_enabled: enabled.value,
      // null (not []) means "follow my batch's working days" — an empty array
      // would read as a deliberate choice of no days, which is what the
      // enabled toggle is for.
      reminder_days: usingDefaultDays.value ? null : selectedDays.value,
      reminder_time: time.value === '' ? null : time.value,
    })

    defaults.value = data.defaults
    usingDefaultDays.value = data.reminder_days === null
    showToast('Reminder settings saved.', 'success')
  } catch (error) {
    const categorized = categorizeError(error, 'Could not save your reminder settings.')
    errorMessage.value = categorized.message
    showToast(categorized.message, 'error')
  } finally {
    isSaving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="px-4 py-4">
    <p v-if="isLoading" class="text-sm text-slate-500">Loading your reminder settings...</p>

    <template v-else>
      <p class="text-xs text-slate-500">
        We will nudge you when you have not submitted a daily journal entry. These settings are yours
        alone — changing them never affects the days your coordinator expects work on.
      </p>

      <label class="mt-4 flex items-start gap-3">
        <input v-model="enabled" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300" />
        <span class="text-sm font-medium text-slate-700">
          Remind me about missing journal entries
        </span>
      </label>

      <div :class="enabled ? '' : 'pointer-events-none opacity-50'">
        <div class="mt-5">
          <p class="text-sm font-semibold text-slate-800">Which days?</p>

          <label class="mt-2 flex items-start gap-3">
            <input
              v-model="usingDefaultDays"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-slate-300"
            />
            <span class="text-sm text-slate-700">
              Follow my batch's schedule
              <span class="block text-xs text-slate-500">Currently {{ defaultDayLabel }}</span>
            </span>
          </label>

          <div v-if="!usingDefaultDays" class="mt-3 flex flex-wrap gap-2">
            <button
              v-for="day in DAYS"
              :key="day.iso"
              type="button"
              class="rounded-full border px-3 py-1 text-xs font-semibold transition"
              :class="
                selectedDays.includes(day.iso)
                  ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700'
                  : 'border-slate-300 bg-white text-slate-600 hover:border-slate-400 hover:bg-slate-50'
              "
              :aria-pressed="selectedDays.includes(day.iso)"
              @click="toggleDay(day.iso)"
            >
              {{ day.short }}
            </button>
          </div>
          <p v-if="!usingDefaultDays" class="mt-2 text-xs text-slate-500">
            Pick Sat or Sun if you are rostered on a weekend.
          </p>
        </div>

        <div class="mt-5">
          <label for="reminder-time" class="text-sm font-semibold text-slate-800">What time?</label>
          <input
            id="reminder-time"
            v-model="time"
            type="time"
            class="mt-2 block w-auto rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          />
          <p class="mt-1 text-xs text-slate-500">
            Leave blank to use your batch's default ({{ defaults.time }}). Reminders are sent on the
            hour, so minutes are ignored.
          </p>
        </div>
      </div>

      <p v-if="errorMessage" class="mt-4 text-sm text-red-600">{{ errorMessage }}</p>

      <button
        type="button"
        class="mt-5 w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:grayscale disabled:cursor-not-allowed"
        :disabled="isSaving"
        @click="save"
      >
        {{ isSaving ? 'Saving...' : 'Save Reminder Settings' }}
      </button>
    </template>
  </div>
</template>

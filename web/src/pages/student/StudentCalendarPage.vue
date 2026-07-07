<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/axios'
import type { CalendarDay, CalendarDayStatus, JournalCalendar } from '@/types/api'

const router = useRouter()

const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

const currentMonth = ref(new Date().toISOString().slice(0, 7))
const days = ref<CalendarDay[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const monthLabel = computed(() => {
  const [year, month] = currentMonth.value.split('-').map(Number)
  return new Date(year, month - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
})

const leadingBlanks = computed(() => {
  const [year, month] = currentMonth.value.split('-').map(Number)
  return new Date(year, month - 1, 1).getDay()
})

const today = new Date().toISOString().slice(0, 10)

const labels: Record<CalendarDayStatus, string> = {
  submitted: 'Submitted',
  draft: 'Draft',
  missing: 'Missing',
  no_entry: 'No Entry',
  future: '',
}

const statusClasses: Record<CalendarDayStatus, string> = {
  submitted: 'bg-blue-50 text-blue-700',
  draft: 'bg-amber-50 text-amber-700',
  missing: 'bg-red-50 text-red-700',
  no_entry: 'bg-slate-100 text-slate-500',
  future: '',
}

const isClickable = (status: CalendarDayStatus) => status === 'submitted' || status === 'draft' || status === 'missing'

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<JournalCalendar>('/api/student/journal-calendar', {
      params: { month: currentMonth.value },
    })
    days.value = data.days
  } catch {
    errorMessage.value = 'Unable to load your journal calendar.'
  } finally {
    isLoading.value = false
  }
}

const shiftMonth = (delta: number) => {
  const [year, month] = currentMonth.value.split('-').map(Number)
  const next = new Date(year, month - 1 + delta, 1)
  currentMonth.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`
}

const selectDay = (day: CalendarDay) => {
  if (day.status === 'submitted') {
    router.push('/student/journals')
  } else if (day.status === 'missing' || day.status === 'draft') {
    router.push({ path: '/student/write-journal', query: { date: day.date } })
  }
}

watch(currentMonth, load)
onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-2">
        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Submitted</span>
        <span class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">Draft</span>
        <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">Missing</span>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Weekend / No Entry</span>
      </div>
      <div class="flex items-center gap-2 text-sm text-slate-500">
        <span>{{ monthLabel }}</span>
        <button type="button" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700" @click="shiftMonth(-1)">Prev</button>
        <button type="button" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700" @click="shiftMonth(1)">Next</button>
      </div>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="grid grid-cols-7 gap-2">
        <div v-for="dayName in dayNames" :key="dayName" class="py-2 text-center text-xs font-bold uppercase text-slate-400">
          {{ dayName }}
        </div>
        <div v-for="blank in leadingBlanks" :key="`blank-${blank}`" class="min-h-24 rounded-md border border-transparent bg-transparent"></div>
        <div
          v-for="day in days"
          :key="day.date"
          class="min-h-24 rounded-md border border-slate-100 bg-white p-2"
          :class="isClickable(day.status) ? 'cursor-pointer hover:border-blue-200' : ''"
          @click="isClickable(day.status) && selectDay(day)"
        >
          <div
            class="mb-2 flex h-7 w-7 items-center justify-center text-sm font-bold"
            :class="day.date === today ? 'rounded-full bg-blue-600 text-white' : 'text-slate-700'"
          >
            {{ Number(day.date.slice(-2)) }}
          </div>
          <span
            v-if="labels[day.status]"
            class="inline-flex rounded px-2 py-1 text-xs font-semibold"
            :class="statusClasses[day.status]"
          >
            {{ labels[day.status] }}
          </span>
        </div>
      </div>
    </div>

    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Automated email reminders are sent at <strong>9:00 PM</strong> for missing daily entries. Daily journals are compiled every <strong>Sunday evening</strong>.
    </div>
  </section>
</template>

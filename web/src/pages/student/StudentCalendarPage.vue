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
  submitted: 'bg-blue-100 text-blue-700',
  draft: 'bg-amber-100 text-amber-800',
  missing: 'bg-red-100 text-red-700',
  no_entry: 'bg-slate-200 text-slate-600',
  future: '',
}

const dayClasses: Record<CalendarDayStatus, string> = {
  submitted: 'border-blue-100 bg-blue-50/60',
  draft: 'border-amber-100 bg-amber-50/70',
  missing: 'border-red-100 bg-red-50/70',
  no_entry: 'border-slate-100 bg-slate-50 text-slate-500',
  future: 'border-slate-100 bg-white text-slate-400',
}

const legendItems = [
  { label: 'Submitted', class: 'bg-blue-50 text-blue-700 ring-blue-100' },
  { label: 'Draft', class: 'bg-amber-50 text-amber-700 ring-amber-100' },
  { label: 'Missing', class: 'bg-red-50 text-red-700 ring-red-100' },
  { label: 'Weekend / No Entry', class: 'bg-slate-100 text-slate-600 ring-slate-200' },
]

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
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Automated email reminders are sent at <strong>9:00 PM</strong> for missing daily entries. Daily journals are compiled every <strong>Sunday evening</strong>.
    </div>

    <div class="flex justify-center">
      <div class="grid w-full max-w-md grid-cols-[1fr_auto_1fr] items-center gap-3">
        <button
          type="button"
          class="justify-self-end rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-blue-600 hover:bg-blue-600 hover:text-white active:border-blue-700 active:bg-blue-700"
          @click="shiftMonth(-1)"
        >
          Prev
        </button>
        <h2 class="min-w-36 text-center text-xl font-bold text-slate-950">{{ monthLabel }}</h2>
        <button
          type="button"
          class="justify-self-start rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-blue-600 hover:bg-blue-600 hover:text-white active:border-blue-700 active:bg-blue-700"
          @click="shiftMonth(1)"
        >
          Next
        </button>
      </div>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <div class="grid grid-cols-7 gap-px bg-slate-100 p-px">
        <div v-for="dayName in dayNames" :key="dayName" class="bg-slate-50 py-2.5 text-center text-[11px] font-bold uppercase text-slate-500">
          {{ dayName }}
        </div>
        <div v-for="blank in leadingBlanks" :key="`blank-${blank}`" class="min-h-20 bg-slate-50/70"></div>
        <div
          v-for="day in days"
          :key="day.date"
          class="group relative flex min-h-20 flex-col border p-2.5 transition"
          :class="[
            dayClasses[day.status],
            isClickable(day.status) ? 'cursor-pointer hover:z-10 hover:border-blue-300 hover:bg-white hover:shadow-md' : '',
          ]"
          @click="isClickable(day.status) && selectDay(day)"
        >
          <div class="flex items-start justify-between gap-2">
            <div
              class="flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold"
              :class="day.date === today ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-800'"
            >
              {{ Number(day.date.slice(-2)) }}
            </div>
            <span v-if="day.date === today" class="rounded-full bg-blue-600/10 px-1.5 py-0.5 text-[10px] font-bold text-blue-700">Today</span>
          </div>
          <span
            v-if="labels[day.status]"
            class="mt-auto inline-flex w-fit rounded-full px-2 py-0.5 text-[11px] font-semibold"
            :class="statusClasses[day.status]"
          >
            {{ labels[day.status] }}
          </span>
        </div>
      </div>
    </div>

    <div v-if="!isLoading && !errorMessage" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
      <div
        v-for="item in legendItems"
        :key="item.label"
        class="flex items-center gap-2 rounded-md px-3 py-2 text-xs font-semibold ring-1"
        :class="item.class"
      >
        <span class="h-2.5 w-2.5 rounded-full bg-current"></span>
        <span>{{ item.label }}</span>
      </div>
    </div>
  </section>
</template>

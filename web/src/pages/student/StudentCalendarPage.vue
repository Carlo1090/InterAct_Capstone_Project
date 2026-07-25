<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/lib/axios'
import NotEnrolledNotice from '@/components/student/NotEnrolledNotice.vue'
import { isNotEnrolledError } from '@/lib/enrollment'
import type { CalendarDay, CalendarDayStatus, JournalCalendar } from '@/types/api'

const router = useRouter()

const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

const currentMonth = ref(new Date().toISOString().slice(0, 7))
const days = ref<CalendarDay[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const notEnrolled = ref(false)

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

// Status pill inside a day cell.
const statusClasses: Record<CalendarDayStatus, string> = {
  submitted: 'bg-blue-100 text-blue-700',
  draft: 'bg-amber-100 text-amber-700',
  missing: 'bg-red-100 text-red-700',
  no_entry: 'bg-slate-200 text-slate-600',
  future: '',
}

// Whole-cell tint so the grid reads with the system's colour hues instead of a
// flat sheet of white.
const cellTone: Record<CalendarDayStatus, string> = {
  submitted: 'border-blue-200 bg-blue-50',
  draft: 'border-amber-200 bg-amber-50',
  missing: 'border-red-200 bg-red-50',
  no_entry: 'border-slate-200 bg-slate-50',
  future: 'border-slate-100 bg-white',
}

// Small legend swatch.
const legendDot: Record<Exclude<CalendarDayStatus, 'future'>, string> = {
  submitted: 'bg-blue-500',
  draft: 'bg-amber-500',
  missing: 'bg-red-500',
  no_entry: 'bg-slate-400',
}

const isClickable = (status: CalendarDayStatus) => status === 'submitted' || status === 'draft' || status === 'missing'

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''
  notEnrolled.value = false

  try {
    const { data } = await api.get<JournalCalendar>('/api/student/journal-calendar', {
      params: { month: currentMonth.value },
    })
    days.value = data.days
  } catch (error) {
    if (isNotEnrolledError(error)) {
      notEnrolled.value = true
    } else {
      errorMessage.value = 'Unable to load your journal calendar.'
    }
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
  <section class="space-y-4">
    <!-- Month header — carries the system's blue→indigo hues -->
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-linear-to-r from-blue-600 to-indigo-700 px-5 py-4 text-white shadow-sm">
      <div>
        <h2 class="text-xl font-bold">{{ monthLabel }}</h2>
        <p class="mt-0.5 text-xs text-blue-100">Tap a highlighted day to write or review that entry.</p>
      </div>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="rounded-md bg-white/15 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-white/25"
          @click="shiftMonth(-1)"
        >
          ‹ Prev
        </button>
        <button
          type="button"
          class="rounded-md bg-white/15 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-white/25"
          @click="shiftMonth(1)"
        >
          Next ›
        </button>
      </div>
    </div>

    <!-- Legend -->
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold text-slate-600">
      <span class="text-slate-400">Legend</span>
      <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :class="legendDot.submitted" />Submitted</span>
      <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :class="legendDot.draft" />Draft</span>
      <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :class="legendDot.missing" />Missing</span>
      <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :class="legendDot.no_entry" />Weekend / No Entry</span>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <NotEnrolledNotice v-else-if="notEnrolled" />
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200 sm:p-5">
      <div class="grid grid-cols-7 gap-1.5 sm:gap-2">
        <div v-for="dayName in dayNames" :key="dayName" class="py-2 text-center text-xs font-bold uppercase tracking-wide text-slate-400">
          {{ dayName }}
        </div>
        <div v-for="blank in leadingBlanks" :key="`blank-${blank}`" class="min-h-20 rounded-lg sm:min-h-24"></div>
        <div
          v-for="day in days"
          :key="day.date"
          class="min-h-20 rounded-lg border p-1.5 transition sm:min-h-24 sm:p-2"
          :class="[
            cellTone[day.status],
            day.date === today ? 'ring-2 ring-blue-500 ring-offset-1' : '',
            isClickable(day.status) ? 'cursor-pointer hover:border-blue-400 hover:shadow-md' : '',
          ]"
          @click="isClickable(day.status) && selectDay(day)"
        >
          <div
            class="mb-1.5 flex h-7 w-7 items-center justify-center text-sm font-bold"
            :class="day.date === today ? 'rounded-full bg-linear-to-br from-blue-600 to-indigo-700 text-white shadow' : 'text-slate-700'"
          >
            {{ Number(day.date.slice(-2)) }}
          </div>
          <span
            v-if="labels[day.status]"
            class="inline-flex rounded px-1.5 py-0.5 text-[11px] font-semibold"
            :class="statusClasses[day.status]"
          >
            {{ labels[day.status] }}
          </span>
        </div>
      </div>
    </div>

    <!-- Prominent guidance banner -->
    <div class="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="mt-0.5 h-5 w-5 shrink-0 text-indigo-600">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
        <path d="M12 11v5M12 8h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
      </svg>
      <p class="leading-relaxed">
        <strong class="font-bold">Stay on track.</strong>
        A reminder is emailed at <strong>9:00 PM</strong> for any missing daily entry, and your daily journals are
        compiled into a weekly log every <strong>Sunday evening</strong>. Red <strong>Missing</strong> days still need an entry.
      </p>
    </div>
  </section>
</template>

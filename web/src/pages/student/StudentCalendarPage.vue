<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
type CalendarEntry = 'submitted' | 'missing' | 'weekend' | ''

const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const entries: Record<number, CalendarEntry> = {
  1: 'weekend',
  2: 'weekend',
  3: 'submitted',
  4: 'submitted',
  5: 'submitted',
  6: 'submitted',
  7: 'submitted',
  8: 'weekend',
  9: 'weekend',
  10: 'submitted',
  11: 'submitted',
  12: 'submitted',
  13: 'submitted',
  14: 'submitted',
  15: 'weekend',
  16: 'weekend',
  17: 'submitted',
  18: 'submitted',
  19: 'submitted',
  20: 'submitted',
  21: 'submitted',
  22: 'weekend',
  23: 'weekend',
  24: 'missing',
  25: 'missing',
  26: 'submitted',
}

const calendarDays = Array.from({ length: 35 }, (_, index) => {
  const day = index - 3
  return {
    day,
    status: day >= 1 && day <= 28 ? entries[day] ?? '' : '',
    isVisible: day >= 1 && day <= 28,
    isToday: day === 26,
  }
})

const labels: Record<Exclude<CalendarEntry, ''>, string> = {
  submitted: 'Submitted',
  missing: 'Missing',
  weekend: 'No Entry',
}

const statusClass = (status: CalendarEntry): string => {
  const classes: Record<CalendarEntry, string> = {
    submitted: 'bg-blue-50 text-blue-700',
    missing: 'bg-red-50 text-red-700',
    weekend: 'bg-slate-100 text-slate-500',
    '': '',
  }

  return classes[status]
}
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap gap-2">
        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">Submitted</span>
        <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">Missing</span>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Weekend / No Entry</span>
      </div>
      <div class="flex items-center gap-2 text-sm text-slate-500">
        <span>May 2025</span>
        <button type="button" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700">Prev</button>
        <button type="button" class="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700">Next</button>
      </div>
    </div>

    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="grid grid-cols-7 gap-2">
        <div v-for="dayName in days" :key="dayName" class="py-2 text-center text-xs font-bold uppercase text-slate-400">
          {{ dayName }}
        </div>
        <div
          v-for="calendarDay in calendarDays"
          :key="calendarDay.day"
          class="min-h-24 rounded-md border p-2"
          :class="calendarDay.isVisible ? 'border-slate-100 bg-white' : 'border-transparent bg-transparent'"
        >
          <template v-if="calendarDay.isVisible">
            <div
              class="mb-2 flex h-7 w-7 items-center justify-center text-sm font-bold"
              :class="calendarDay.isToday ? 'rounded-full bg-blue-600 text-white' : 'text-slate-700'"
            >
              {{ calendarDay.day }}
            </div>
            <span
              v-if="calendarDay.status"
              class="inline-flex rounded px-2 py-1 text-xs font-semibold"
              :class="statusClass(calendarDay.status)"
            >
              {{ labels[calendarDay.status as Exclude<CalendarEntry, ''>] }}
            </span>
          </template>
        </div>
      </div>
    </div>

    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Automated email reminders are sent at <strong>9:00 PM</strong> for missing daily entries. Daily journals are compiled every <strong>Sunday evening</strong>.
    </div>
  </section>
</template>

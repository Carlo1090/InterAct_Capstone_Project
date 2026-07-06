<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
const stats = [
  { label: 'Assigned Students', value: '8', sub: 'TechPH Inc.', tone: 'blue' },
  { label: 'Pending Approval', value: '5', sub: 'Weekly journals', tone: 'amber' },
  { label: 'Approved This Week', value: '3', sub: 'Week 8', tone: 'green' },
  { label: 'Returned', value: '1', sub: 'Needs revision', tone: 'red' },
]

const pendingJournals = [
  { name: 'Juan Dela Cruz', id: '2021-IT-001', week: 8, submitted: 'May 25, 9:02 PM' },
  { name: 'Michael Tan', id: '2021-IT-004', week: 8, submitted: 'May 25, 9:14 PM' },
  { name: 'Sarah Jane Ocampo', id: '2021-IT-006', week: 8, submitted: 'May 25, 8:47 PM' },
  { name: 'Rico Bautista', id: '2021-IT-009', week: 8, submitted: 'May 25, 9:30 PM' },
  { name: 'Faith Anne Custodio', id: '2021-IT-012', week: 8, submitted: 'May 25, 9:55 PM' },
]

const compliance = [
  { name: 'Juan Dela Cruz', value: 92 },
  { name: 'Michael Tan', value: 88 },
  { name: 'Sarah Jane Ocampo', value: 76 },
  { name: 'Rico Bautista', value: 58 },
  { name: 'Faith Anne Custodio', value: 81 },
  { name: 'Dennis Yap', value: 47 },
]

const statToneClass = (tone: string): string => {
  const classes: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-700',
    green: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  }

  return classes[tone]
}

const progressClass = (value: number): string => {
  if (value >= 80) return 'bg-green-600'
  if (value >= 60) return 'bg-blue-500'
  return 'bg-amber-600'
}
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      You are viewing interns assigned to <strong>TechPH Inc.</strong> Weekly journals are compiled every
      <strong>Sunday evening</strong> for your review.
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <article v-for="stat in stats" :key="stat.label" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4 flex h-9 w-9 items-center justify-center rounded-md text-sm font-bold" :class="statToneClass(stat.tone)">
          {{ stat.value }}
        </div>
        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ stat.label }}</p>
        <p class="mt-1 text-3xl font-bold text-slate-950">{{ stat.value }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ stat.sub }}</p>
      </article>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
      <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900">Pending Weekly Journals</h2>
        <div class="mt-4 divide-y divide-slate-100">
          <div v-for="journal in pendingJournals" :key="journal.id" class="flex items-center justify-between gap-4 py-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ journal.name }}</p>
              <p class="mt-1 text-xs text-slate-400">Week {{ journal.week }} · Submitted {{ journal.submitted }}</p>
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="rounded-md border border-green-200 bg-green-50 px-3 py-1.5 text-sm font-semibold text-green-700 transition hover:bg-green-100"
              >
                Approve
              </button>
              <button
                type="button"
                class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-semibold text-red-700 transition hover:bg-red-100"
              >
                Return
              </button>
            </div>
          </div>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900">Student Compliance Overview</h2>
        <div class="mt-5 space-y-4">
          <div v-for="student in compliance" :key="student.name">
            <div class="mb-2 flex justify-between text-sm">
              <span class="text-slate-600">{{ student.name }}</span>
              <span class="font-bold text-slate-700">{{ student.value }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full" :class="progressClass(student.value)" :style="{ width: `${student.value}%` }"></div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>
